<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Lease-contract lifecycle through the real handlers (admin/contract_edit.php
 * and admin/contracts.php), against the isolated laskie_test database.
 *
 * What these pin:
 *   - a draft has no number; approval draws LSE-YYYY-#### from a monotonic
 *     counter, and a voided contract keeps (and spends) its number
 *   - an approved contract is locked: no save, no delete — only void
 *   - deposits / totals are recomputed server-side, never trusted from POST
 *   - an overlapping approved contract on the same unit blocks approval, and
 *     a failed approval never loses the saved draft
 *   - approving a contract never touches the tenants row (billing input)
 *   - signed copies can't be attached to a draft
 *   - every field is HTML-escaped in the rendered document
 *   - non-admins are refused
 */
final class ContractLifecycleTest extends IsolatedDbTestCase
{
    private int $unitA = 0;
    private int $unitB = 0;
    private int $tenantId = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->seedAdminUser();
        $this->truncate(['contract_files', 'contracts']);
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'contract_seq_%' OR setting_key LIKE 'contract_default_%'");

        $u = self::$db->prepare("INSERT INTO rental_units (unit_name, monthly_rate, due_day, status) VALUES (?, ?, 5, 'occupied')");
        $u->execute(['CT-UNIT-A', '8000.00']); $this->unitA = (int) self::$db->lastInsertId();
        $u->execute(['CT-UNIT-B', '6500.00']); $this->unitB = (int) self::$db->lastInsertId();

        self::$db->prepare(
            "INSERT INTO tenants (unit_id, full_name, email, phone, address, contract_start, contract_end, status)
             VALUES (?, 'Contract Test Tenant', 'ct@example.com', '0917', '12 Rizal Ave.', '2025-01-01', NULL, 'active')"
        )->execute([$this->unitA]);
        $this->tenantId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(['contract_files', 'contracts']);
        self::$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$this->tenantId]);
        self::$db->prepare("DELETE FROM rental_units WHERE id IN (?,?)")->execute([$this->unitA, $this->unitB]);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function edit(array $post, ?array $user = null): ?array
    {
        [$json] = $this->callScript('admin/contract_edit.php', $post, $user, ['REQUEST_METHOD' => 'POST']);
        return $json;
    }

    private function register(array $post, ?array $user = null): ?array
    {
        [$json] = $this->callScript('admin/contracts.php', $post, $user, ['REQUEST_METHOD' => 'POST']);
        return $json;
    }

    /** A complete, approvable set of fields. */
    private function fields(array $over = []): array
    {
        return array_merge([
            'contract_date' => '2026-10-04', 'lessor_name' => 'Laskie Rental Properties', 'lessor_address' => '359 Sample St.',
            'lessee_name' => 'Contract Test Tenant', 'lessee_nationality' => 'Filipino', 'lessee_civil_status' => 'single',
            'lessee_address' => '12 Rizal Ave.', 'lessee_id' => 'PhilSys 1234', 'building_address' => '359 Sample St.',
            'barangay_no' => '176', 'caloocan_side' => 'North', 'title_ref' => 'TCT 1', 'unit_no' => 'CT-UNIT-A',
            'floor_area_sqm' => '28', 'bedrooms' => '1', 'toilet_baths' => '1', 'max_occupants' => '3',
            'start_date' => '2026-10-05', 'end_date' => '2027-10-04', 'monthly_rent' => '8000',
            'rent_due_day' => '5th', 'payment_methods' => 'GCash', 'late_penalty_pct' => 'one percent (1%)',
            'bounced_check_fee' => '500', 'association_dues_payer' => 'the LESSOR',
            'dst_payer' => 'shared equally by the Parties', 'attorneys_fee_min' => '10000',
        ], $over);
    }

    private function savePost(array $fields, array $extra = []): array
    {
        $post = ['action' => 'save', 'tenant_id' => (string) $this->tenantId, 'unit_id' => (string) $this->unitA,
                 'auto_rent_words' => '1', 'auto_term_words' => '1'];
        foreach ($fields as $k => $v) $post["f"][$k] = $v;
        return array_merge($post, $extra);
    }

    private function row(int $id): array
    {
        $q = self::$db->prepare("SELECT * FROM contracts WHERE id=?");
        $q->execute([$id]);
        return $q->fetch() ?: [];
    }

    // ── tests ────────────────────────────────────────────────────

    #[Test]
    public function draft_has_no_number_and_money_is_recomputed_server_side(): void
    {
        $res = $this->edit($this->savePost($this->fields(['security_deposit' => '1.00', 'move_in_total' => '2.00'])));
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $row = $this->row((int) $res['id']);

        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['contract_no']);
        $f = json_decode($row['fields_json'], true);
        $this->assertSame('16000.00', $f['security_deposit']);
        $this->assertSame('19000.00', $f['move_in_total'], 'post-paid template: 2 months deposit + ₱3,000, no advance');
        $this->assertSame('EIGHT THOUSAND', $f['monthly_rent_words'], 'automatic words follow the amount');
        $this->assertSame('one (1) year', $f['term_words']);
    }

    #[Test]
    public function approval_numbers_sequentially_and_locks_the_contract(): void
    {
        $a = $this->edit($this->savePost($this->fields()));
        $ok = $this->register(['action' => 'approve', 'id' => (string) $a['id']]);
        $this->assertTrue($ok['success'] ?? false, json_encode($ok));
        $year = date('Y');
        $this->assertSame("LSE-$year-0001", $ok['contract_no']);

        // Second contract on another unit draws the next number.
        $b = $this->edit($this->savePost($this->fields(['unit_no' => 'CT-UNIT-B']), ['unit_id' => (string) $this->unitB]));
        $ok2 = $this->register(['action' => 'approve', 'id' => (string) $b['id']]);
        $this->assertSame("LSE-$year-0002", $ok2['contract_no'] ?? null, json_encode($ok2));

        // Locked: no save, no delete, no second approval.
        $save = $this->edit($this->savePost($this->fields(['lessee_name' => 'Changed']), ['id' => (string) $a['id']]));
        $this->assertFalse($save['success'] ?? true);
        $this->assertStringContainsString('locked', $save['error']);
        $del = $this->register(['action' => 'delete_draft', 'id' => (string) $a['id']]);
        $this->assertFalse($del['success'] ?? true);
        $again = $this->register(['action' => 'approve', 'id' => (string) $a['id']]);
        $this->assertFalse($again['success'] ?? true);
        $this->assertSame('Contract Test Tenant', $this->row((int) $a['id'])['lessee_name']);
    }

    #[Test]
    public function voiding_needs_a_reason_and_the_number_is_never_reissued(): void
    {
        $a = $this->edit($this->savePost($this->fields()));
        $this->register(['action' => 'approve', 'id' => (string) $a['id']]);

        $noReason = $this->register(['action' => 'cancel', 'id' => (string) $a['id'], 'reason' => ' ']);
        $this->assertFalse($noReason['success'] ?? true);

        $void = $this->register(['action' => 'cancel', 'id' => (string) $a['id'], 'reason' => 'Wrong unit number']);
        $this->assertTrue($void['success'] ?? false, json_encode($void));
        $row = $this->row((int) $a['id']);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('Wrong unit number', $row['cancel_reason']);
        $this->assertNotNull($row['contract_no'], 'a voided contract keeps its number');

        // The replacement (same unit, same dates — the voided one no longer
        // blocks it) gets the NEXT number, not the voided one's.
        $b = $this->edit($this->savePost($this->fields()));
        $ok = $this->register(['action' => 'approve', 'id' => (string) $b['id']]);
        $this->assertTrue($ok['success'] ?? false, json_encode($ok));
        $this->assertNotSame($row['contract_no'], $ok['contract_no']);
        $this->assertSame('LSE-' . date('Y') . '-0002', $ok['contract_no']);
    }

    #[Test]
    public function overlapping_approval_is_refused_but_the_draft_is_kept(): void
    {
        $a = $this->edit($this->savePost($this->fields()));
        $this->register(['action' => 'approve', 'id' => (string) $a['id']]);

        $res = $this->edit($this->savePost($this->fields(['start_date' => '2027-04-01', 'end_date' => '2028-03-31']), ['approve' => '1']));
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $this->assertFalse($res['approved']);
        $this->assertStringContainsString('overlaps', $res['approve_error']);
        $this->assertSame('draft', $this->row((int) $res['id'])['status'], 'the draft survives a refused approval');

        // Starting the day after the first one ends is fine.
        $res2 = $this->edit($this->savePost($this->fields(['start_date' => '2027-10-05', 'end_date' => '2028-10-04']), ['approve' => '1']));
        $this->assertTrue($res2['approved'] ?? false, json_encode($res2));
    }

    #[Test]
    public function incomplete_draft_cannot_be_approved(): void
    {
        $res = $this->edit($this->savePost($this->fields(['lessee_id' => '', 'title_ref' => ''])));
        $ok = $this->register(['action' => 'approve', 'id' => (string) $res['id']]);
        $this->assertFalse($ok['success'] ?? true);
        $this->assertStringContainsString('ID type & number is required', $ok['error']);
        $this->assertStringContainsString('TCT / Tax Dec. no. is required', $ok['error']);
    }

    #[Test]
    public function approval_never_touches_the_tenant_record(): void
    {
        $before = self::$db->query("SELECT unit_id, contract_start, contract_end, status, monthly_rate FROM tenants WHERE id={$this->tenantId}")->fetch();
        $a = $this->edit($this->savePost($this->fields()));
        $this->register(['action' => 'approve', 'id' => (string) $a['id']]);
        $after = self::$db->query("SELECT unit_id, contract_start, contract_end, status, monthly_rate FROM tenants WHERE id={$this->tenantId}")->fetch();
        $this->assertSame($before, $after);
    }

    #[Test]
    public function drafts_can_be_deleted_and_renewal_rolls_the_term_forward(): void
    {
        $a = $this->edit($this->savePost($this->fields()));
        $this->register(['action' => 'approve', 'id' => (string) $a['id']]);
        $renew = $this->register(['action' => 'duplicate', 'id' => (string) $a['id'], 'mode' => 'renew']);
        $this->assertTrue($renew['success'] ?? false, json_encode($renew));
        $r = $this->row((int) $renew['id']);
        $this->assertSame('draft', $r['status']);
        $this->assertSame('2027-10-05', $r['start_date']);
        $this->assertSame('2028-10-04', $r['end_date']);
        $this->assertSame('one (1) year', json_decode($r['fields_json'], true)['term_words']);

        $del = $this->register(['action' => 'delete_draft', 'id' => (string) $renew['id']]);
        $this->assertTrue($del['success'] ?? false);
        $this->assertSame([], $this->row((int) $renew['id']));
    }

    #[Test]
    public function signed_copies_cannot_attach_to_a_draft(): void
    {
        $a = $this->edit($this->savePost($this->fields()));
        $res = $this->register(['action' => 'upload_file', 'contract_id' => (string) $a['id']]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('Approve the contract first', $res['error']);

        $this->register(['action' => 'approve', 'id' => (string) $a['id']]);
        $files = $this->register(['action' => 'get_files', 'contract_id' => (string) $a['id']]);
        $this->assertTrue($files['can_upload'] ?? false);
        $this->assertSame([], $files['files']);
    }

    #[Test]
    public function preview_escapes_every_field_and_leaves_no_placeholders(): void
    {
        $post = $this->savePost($this->fields(['lessee_name' => '<script>alert(1)</script>']));
        $post['action'] = 'preview';
        $res = $this->edit($post);
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $this->assertStringNotContainsString('<script>alert(1)', $res['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $res['html']);
        $this->assertStringNotContainsString('{{', $res['html']);
        $this->assertStringContainsString('EIGHT THOUSAND</span> PESOS', $res['html']);
        $this->assertSame([], $res['missing']);
    }

    #[Test]
    public function prefill_reads_tenant_unit_and_rate_history(): void
    {
        $res = $this->edit(['action' => 'prefill', 'tenant_id' => (string) $this->tenantId]);
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $this->assertSame($this->unitA, $res['unit_id']);
        $this->assertSame('Contract Test Tenant', $res['fields']['lessee_name']);
        $this->assertSame('tenant', $res['sources']['lessee_name']);
        $this->assertSame('CT-UNIT-A', $res['fields']['unit_no']);
        $this->assertSame('8000.00', $res['fields']['monthly_rent']);
        $this->assertSame('5th', $res['fields']['rent_due_day']);
        $this->assertSame('16000.00', $res['fields']['security_deposit']);
    }

    #[Test]
    public function non_admins_are_refused(): void
    {
        $staff = ['id' => 1, 'username' => 'staff', 'full_name' => 'Staff', 'role' => 'accountant'];
        $res = $this->edit($this->savePost($this->fields()), $staff);
        $this->assertFalse($res['success'] ?? true);
        $res2 = $this->register(['action' => 'get_files', 'contract_id' => '1'], $staff);
        $this->assertFalse($res2['success'] ?? true);
        $this->assertSame(0, (int) self::$db->query("SELECT COUNT(*) FROM contracts")->fetchColumn());
    }
}
