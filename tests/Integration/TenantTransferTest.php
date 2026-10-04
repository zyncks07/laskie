<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tenant transfer through the real handlers (admin/tenants.php actions,
 * payments/api_payment.php locks), against the isolated laskie_test database.
 *
 * What these pin:
 *   - rent is POST-PAID: the old unit's last cycle is billed at the move and
 *     the new unit bills after its first cycle; a mid-cycle move splits that
 *     cycle by days — never a cycle plus a few days
 *   - the old tenancy closes at a zero balance: prepaid rent moves as a
 *     NON-CASH pair, unpaid rent is carried as an Arrears charge
 *   - nobody's cash-on-hand moves and total net income is unchanged unless
 *     cash really changes hands (a deposit refund)
 *   - deposit top-up / credit (recorded and unrecorded) / cash refund + gate
 *   - the old contract is voided and the person's particulars carry over
 *   - transfer rows are locked against piecemeal edits; undo is exact and is
 *     refused once anything new has been recorded
 */
final class TenantTransferTest extends IsolatedDbTestCase
{
    private int $unitA = 0;   // ₱6,000 — where the tenant lives
    private int $unitB = 0;   // ₱8,500 — more expensive
    private int $unitC = 0;   // ₱5,000 — cheaper
    private int $tenant = 0;
    private int $cashier = 2;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/../../config/transfer_functions.php';
    }

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->seedAdminUser();
        self::$db->exec("INSERT INTO users (id, username, password_hash, full_name, role, status)
                         VALUES (2, 'cashier', 'x', 'Test Cashier', 'staff', 'active') ON DUPLICATE KEY UPDATE status='active'");
        // rental_units is NOT truncated: getRateForMonth() caches history per
        // unit id for the life of the process, so ids must never be reused
        // (TRUNCATE resets AUTO_INCREMENT). Units are deleted in tearDown.
        $this->truncate(['tenant_transfers', 'contract_files', 'contracts', 'charge_payments', 'refunds', 'cash_transactions',
                         'rent_charge_voids', 'unit_charges', 'payments', 'tenant_docs', 'tenants']);
        resetRateHistoryCache();
        self::$db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('transfers_enabled','1')
                         ON DUPLICATE KEY UPDATE setting_value='1'");
        $u = self::$db->prepare("INSERT INTO rental_units (unit_name, monthly_rate, due_day, status) VALUES (?, ?, 5, ?)");
        $u->execute(['TR-A', '6000.00', 'occupied']); $this->unitA = (int) self::$db->lastInsertId();
        $u->execute(['TR-B', '8500.00', 'vacant']);   $this->unitB = (int) self::$db->lastInsertId();
        $u->execute(['TR-C', '5000.00', 'vacant']);   $this->unitC = (int) self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO tenants (unit_id, full_name, phone, contract_start, status) VALUES (?, 'Transfer Tenant', '0917', '2025-12-05', 'active')")
            ->execute([$this->unitA]);
        $this->tenant = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(['tenant_transfers', 'contract_files', 'contracts', 'charge_payments', 'refunds', 'cash_transactions',
                         'rent_charge_voids', 'unit_charges', 'payments', 'tenant_docs', 'tenants']);
        self::$db->prepare("DELETE FROM rental_units WHERE id IN (?,?,?)")->execute([$this->unitA, $this->unitB, $this->unitC]);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function tenants(array $post, ?array $user = null): ?array
    {
        [$json, $raw] = $this->callScript('admin/tenants.php', $post, $user, ['REQUEST_METHOD' => 'POST']);
        $this->assertNotNull($json, "Non-JSON response: " . substr($raw, 0, 400));
        return $json;
    }

    private function transfer(int $unit, string $date, array $extra = []): ?array
    {
        return $this->tenants(array_merge(['action' => 'transfer_execute', 'tenant_id' => (string) $this->tenant,
            'unit_id' => (string) $unit, 'move_date' => $date, 'idempotency_key' => bin2hex(random_bytes(8))], $extra));
    }

    /** Record a cash rent payment at unit A, with its cash row, as save_payment would. */
    private function payRent(int $month, string $amount = '6000.00', string $type = 'rent', ?int $serviceType = null): int
    {
        self::$db->prepare("INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, service_type_id, amount, period_month, period_year, payment_date, received_by, status)
                            VALUES (?,?,?,?,?,?,?,2026,?,2,'paid')")
            ->execute(['T-' . bin2hex(random_bytes(4)), $this->unitA, $this->tenant, $type, $serviceType, $amount, $month, sprintf('2026-%02d-05', $month)]);
        $id = (int) self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO cash_transactions (user_id, transaction_type, amount, reference_payment_id, transaction_date) VALUES (2,'received',?,?,?)")
            ->execute([$amount, $id, sprintf('2026-%02d-05', $month)]);
        return $id;
    }

    private function scalar(string $sql, array $args = []): string
    {
        $q = self::$db->prepare($sql);
        $q->execute($args);
        return (string) $q->fetchColumn();
    }

    private function row(string $sql, array $args = []): array
    {
        $q = self::$db->prepare($sql);
        $q->execute($args);
        return $q->fetch() ?: [];
    }

    private function successor(): array
    {
        return $this->row("SELECT * FROM tenants WHERE transferred_from_id=?", [$this->tenant]);
    }

    /** Balance through the cycle ending Nov 4, 2026 (an open tenancy needs an "as of" end). */
    private function balance(int $tenantId): array
    {
        $t = $this->row("SELECT * FROM tenants WHERE id=?", [$tenantId]);
        $t['contract_end'] = $t['contract_end'] ?: '2026-11-04';
        return tenancyBalance(self::$db, $t);
    }

    private function cashTotal(): string
    {
        return $this->scalar("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('received','vault_return') THEN amount ELSE -amount END),0) FROM cash_transactions");
    }

    private function netIncome(): string
    {
        return $this->scalar("SELECT COALESCE(SUM(p.amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id=p.id),0)),0)
                              FROM payments p WHERE p.deleted_at IS NULL AND p.status != 'voided'");
    }

    private function grossFor(int $unit, int $month, int $tenantId): string
    {
        return getGrossRentCharge(self::$db, $unit, $month, 2026, $tenantId)['gross'];
    }

    // ── tests ────────────────────────────────────────────────────

    #[Test]
    public function moving_on_the_due_day_bills_the_last_cycle_and_carries_it_with_a_deposit_top_up(): void
    {
        // January–September bills paid (Dec 5 – Sep 4); moving on the Oct 5 due day.
        for ($m = 1; $m <= 9; $m++) $this->payRent($m);
        $cash = $this->cashTotal();

        $res = $this->transfer($this->unitB, '2026-10-05');
        $this->assertTrue($res['success'] ?? false, json_encode($res));

        $old = $this->row("SELECT * FROM tenants WHERE id=?", [$this->tenant]);
        $new = $this->successor();
        $this->assertSame(['2026-10-04', 'former'], [$old['contract_end'], $old['status']]);
        $this->assertSame([(string) $this->unitB, '2026-10-05', 'active'], [(string) $new['unit_id'], $new['contract_start'], $new['status']]);
        $this->assertSame('vacant',   $this->scalar("SELECT status FROM rental_units WHERE id=?", [$this->unitA]));
        $this->assertSame('occupied', $this->scalar("SELECT status FROM rental_units WHERE id=?", [$this->unitB]));

        // A's October bill (Sep 5 – Oct 4) is the full ₱6,000; B owes nothing for
        // October and bills a full ₱8,500 for Oct 5 – Nov 4, due Nov 5.
        $this->assertSame('6000.00', $this->grossFor($this->unitA, 10, $this->tenant));
        $this->assertSame('0.00',    $this->grossFor($this->unitB, 10, (int) $new['id']));
        $this->assertSame('8500.00', $this->grossFor($this->unitB, 11, (int) $new['id']));

        // The unpaid ₱6,000 moves to B; A closes at zero.
        $this->assertSame('0.00', $this->balance($this->tenant)['balance'], 'old tenancy closes at zero');
        $charges = self::$db->prepare("SELECT st.name, uc.amount, uc.source FROM unit_charges uc JOIN service_types st ON st.id=uc.service_type_id WHERE uc.tenant_id=? ORDER BY st.name");
        $charges->execute([(int) $new['id']]);
        $this->assertSame([
            ['name' => 'Arrears',          'amount' => '6000.00', 'source' => 'pre_billed'],
            ['name' => 'Security Deposit', 'amount' => '5000.00', 'source' => 'pre_billed'],   // 2 × ₱8,500 − assumed 2 × ₱6,000
        ], $charges->fetchAll());
        $this->assertSame($cash, $this->cashTotal(), 'no cash moves');
    }

    #[Test]
    public function split_move_carries_prepaid_rent_as_a_non_cash_pair(): void
    {
        // Every bill through November paid at A — November (Oct 5 – Nov 4) in advance.
        for ($m = 1; $m <= 11; $m++) $this->payRent($m);
        [$cash, $income] = [$this->cashTotal(), $this->netIncome()];

        $res = $this->transfer($this->unitB, '2026-10-15', ['deposit_action' => 'none']);
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $new = $this->successor();

        // The November cycle (Oct 5 – Nov 4, 31 days) splits: 10 days at ₱6,000 on A,
        // 21 days at ₱8,500 on B.
        $this->assertSame('1935.48', $this->grossFor($this->unitA, 11, $this->tenant));
        $this->assertSame('5758.06', $this->grossFor($this->unitB, 11, (int) $new['id']));

        // A was overpaid by ₱6,000 − ₱1,935.48; that moves to B's November bill, non-cash.
        $this->assertSame('0.00', $this->balance($this->tenant)['balance']);
        $credit = $this->row("SELECT * FROM payments WHERE tenant_id=? AND transfer_id IS NOT NULL", [(int) $new['id']]);
        $this->assertSame(['4064.52', '11'], [$credit['amount'], (string) $credit['period_month']]);
        $this->assertNull($credit['received_by']);
        $this->assertSame('0', $this->scalar("SELECT COUNT(*) FROM cash_transactions WHERE reference_payment_id=?", [(int) $credit['id']]));
        $this->assertSame('1', $this->scalar("SELECT COUNT(*) FROM refunds WHERE non_cash=1 AND amount='4064.52'"));
        $this->assertSame('1693.54', $this->balance((int) $new['id'])['balance'], 'only the rest of B\'s first bill is owed');

        $this->assertSame($cash, $this->cashTotal(), 'cash-on-hand unchanged');
        $this->assertSame($income, $this->netIncome(), 'net income unchanged');
    }

    #[Test]
    public function unpaid_rent_is_carried_to_the_new_unit_as_arrears(): void
    {
        for ($m = 1; $m <= 8; $m++) $this->payRent($m);   // September and October bills unpaid
        $res = $this->transfer($this->unitB, '2026-10-05', ['deposit_action' => 'none']);
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $new = $this->successor();

        $this->assertSame('0.00', $this->balance($this->tenant)['balance']);
        $w = self::$db->prepare("SELECT kind, period_month, amount FROM rent_charge_voids WHERE tenant_id=? ORDER BY period_month");
        $w->execute([$this->tenant]);
        $this->assertSame([['kind' => 'transfer', 'period_month' => 9,  'amount' => '6000.00'],
                           ['kind' => 'transfer', 'period_month' => 10, 'amount' => '6000.00']], $w->fetchAll());
        $arrears = $this->row("SELECT uc.amount, st.name FROM unit_charges uc JOIN service_types st ON st.id=uc.service_type_id WHERE uc.tenant_id=?", [(int) $new['id']]);
        $this->assertSame(['12000.00', 'Arrears'], [$arrears['amount'], $arrears['name']]);
        $this->assertSame('20500.00', $this->balance((int) $new['id'])['balance'], 'first bill ₱8,500 + carried ₱12,000');
    }

    #[Test]
    public function unrecorded_deposit_excess_is_credited_to_rent(): void
    {
        for ($m = 1; $m <= 10; $m++) $this->payRent($m);   // nothing left to carry
        $cash = $this->cashTotal();
        $res = $this->transfer($this->unitC, '2026-10-05');   // default action: credit
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $new = $this->successor();

        // Held 2 × ₱6,000, needed 2 × ₱5,000 → ₱2,000 credited to C's first bill (November).
        $this->assertSame('2000.00', $this->scalar("SELECT SUM(amount) FROM payments WHERE tenant_id=? AND transfer_id IS NOT NULL", [(int) $new['id']]));
        $this->assertSame('0', $this->scalar("SELECT COUNT(*) FROM refunds"), 'nothing recorded to refund against');
        $this->assertSame('3000.00', $this->balance((int) $new['id'])['balance']);
        $this->assertSame($cash, $this->cashTotal());
    }

    #[Test]
    public function recorded_deposit_excess_moves_to_rent_income_neutrally(): void
    {
        $depType = (int) $this->scalar("SELECT id FROM service_types WHERE name='Security Deposit'");
        $dep = $this->payRent(1, '12000.00', 'service', $depType);
        for ($m = 1; $m <= 10; $m++) $this->payRent($m);
        [$cash, $income] = [$this->cashTotal(), $this->netIncome()];

        $res = $this->transfer($this->unitC, '2026-10-05', ['deposit_action' => 'credit']);
        $this->assertTrue($res['success'] ?? false, json_encode($res));

        $this->assertSame('2000.00', $this->scalar("SELECT SUM(amount) FROM refunds WHERE payment_id=? AND non_cash=1", [$dep]));
        $this->assertSame('partially_refunded', $this->scalar("SELECT status FROM payments WHERE id=?", [$dep]));
        $this->assertSame($income, $this->netIncome(), 'deposit income became rent income');
        $this->assertSame($cash, $this->cashTotal());
    }

    #[Test]
    public function cash_deposit_refund_is_gated_on_the_cashiers_cash(): void
    {
        $short = $this->transfer($this->unitC, '2026-10-05', ['deposit_action' => 'refund', 'cashier_id' => (string) $this->cashier]);
        $this->assertFalse($short['success'] ?? true);
        $this->assertStringContainsString('cash on hand', $short['error']);
        $this->assertSame([], $this->successor(), 'a refused transfer changes nothing');

        $this->payRent(1);   // cashier now holds ₱6,000
        $res = $this->transfer($this->unitC, '2026-10-05', ['deposit_action' => 'refund', 'cashier_id' => (string) $this->cashier]);
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $this->assertSame('2000.00', $this->scalar("SELECT amount FROM cash_transactions WHERE transaction_type='refunded' AND user_id=?", [$this->cashier]));
    }

    #[Test]
    public function old_contract_is_voided_and_the_new_one_prefills_the_persons_details(): void
    {
        $fields = json_encode(['lessee_name' => 'Transfer Tenant', 'lessee_id' => 'PhilSys 999', 'lessee_civil_status' => 'married']);
        self::$db->prepare("INSERT INTO contracts (contract_no, tenant_id, unit_id, lessee_name, unit_label, start_date, end_date, monthly_rent, fields_json, status)
                            VALUES ('LSE-2026-0099', ?, ?, 'Transfer Tenant', 'TR-A', '2026-01-05', '2027-01-04', 6000, ?, 'approved')")
            ->execute([$this->tenant, $this->unitA, $fields]);
        $res = $this->transfer($this->unitB, '2026-10-05');
        $this->assertTrue($res['success'] ?? false, json_encode($res));

        $c = $this->row("SELECT status, cancel_reason FROM contracts WHERE contract_no='LSE-2026-0099'");
        $this->assertSame('cancelled', $c['status']);
        $this->assertStringContainsString('Transferred to TR-B', $c['cancel_reason']);

        [$pre] = $this->callScript('admin/contract_edit.php', ['action' => 'prefill', 'tenant_id' => (string) $this->successor()['id']], null, ['REQUEST_METHOD' => 'POST']);
        $this->assertSame('PhilSys 999', $pre['fields']['lessee_id'] ?? null, json_encode($pre));
        $this->assertSame('TR-B', $pre['fields']['unit_no']);
        $this->assertSame('2026-10-05', $pre['fields']['start_date']);
    }

    #[Test]
    public function transfer_rows_are_locked_and_undo_is_exact(): void
    {
        for ($m = 1; $m <= 11; $m++) $this->payRent($m);   // November paid ahead → a credit pair
        [$cash, $income] = [$this->cashTotal(), $this->netIncome()];
        $res = $this->transfer($this->unitB, '2026-10-15');
        $this->assertTrue($res['success'] ?? false, json_encode($res));
        $xid = (int) $res['transfer_id'];
        $credit = (int) $this->scalar("SELECT id FROM payments WHERE transfer_id=? LIMIT 1", [$xid]);

        foreach (['void_payment' => ['id' => $credit], 'delete_payment' => ['id' => $credit],
                  'process_refund' => ['payment_id' => $credit, 'amount' => '1', 'reason' => 'x']] as $action => $args) {
            $r = $this->callApiAction(['action' => $action] + array_map('strval', $args))[0];
            $this->assertFalse($r['success'] ?? true, $action);
            $this->assertStringContainsString('tenant transfer', $r['error'], $action);
        }

        $undo = $this->tenants(['action' => 'transfer_undo', 'transfer_id' => (string) $xid, 'reason' => 'Wrong unit']);
        $this->assertTrue($undo['success'] ?? false, json_encode($undo));

        $old = $this->row("SELECT contract_end, status FROM tenants WHERE id=?", [$this->tenant]);
        $this->assertSame([null, 'active'], [$old['contract_end'], $old['status']]);
        $this->assertSame([], $this->successor());
        $this->assertSame('0', $this->scalar("SELECT COUNT(*) FROM refunds"));
        $this->assertSame('0', $this->scalar("SELECT COUNT(*) FROM payments WHERE status != 'paid' AND deleted_at IS NULL"));
        $this->assertSame(['occupied', 'vacant'], [
            $this->scalar("SELECT status FROM rental_units WHERE id=?", [$this->unitA]),
            $this->scalar("SELECT status FROM rental_units WHERE id=?", [$this->unitB])]);
        $this->assertSame([$cash, $income], [$this->cashTotal(), $this->netIncome()]);
        $this->assertSame('undone', $this->scalar("SELECT status FROM tenant_transfers WHERE id=?", [$xid]));
    }

    #[Test]
    public function undo_is_refused_once_something_new_is_recorded(): void
    {
        $res = $this->transfer($this->unitB, '2026-10-05', ['deposit_action' => 'none']);
        $new = $this->successor();
        self::$db->prepare("INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount, period_month, period_year, payment_date, received_by, status)
                            VALUES ('T-NEW', ?, ?, 'rent', 8500, 10, 2026, '2026-10-05', 2, 'paid')")->execute([$this->unitB, (int) $new['id']]);
        $undo = $this->tenants(['action' => 'transfer_undo', 'transfer_id' => (string) $res['transfer_id'], 'reason' => 'Oops']);
        $this->assertFalse($undo['success'] ?? true);
        $this->assertStringContainsString('payments recorded on the new unit', $undo['error']);
    }

    #[Test]
    public function an_occupied_or_same_unit_or_non_admin_transfer_is_refused(): void
    {
        self::$db->prepare("INSERT INTO tenants (unit_id, full_name, contract_start, status) VALUES (?, 'Someone Else', '2026-03-01', 'active')")
            ->execute([$this->unitB]);
        $busy = $this->transfer($this->unitB, '2026-10-05');
        $this->assertFalse($busy['success'] ?? true);
        $this->assertStringContainsString('occupied by Someone Else', $busy['error']);

        $same = $this->transfer($this->unitA, '2026-10-05');
        $this->assertFalse($same['success'] ?? true);

        $staff = ['id' => 2, 'username' => 'cashier', 'full_name' => 'Test Cashier', 'role' => 'staff'];
        [$json, $raw] = $this->callScript('admin/tenants.php', ['action' => 'transfer_execute', 'tenant_id' => (string) $this->tenant,
            'unit_id' => (string) $this->unitC, 'move_date' => '2026-10-05'], $staff, ['REQUEST_METHOD' => 'POST']);
        $this->assertFalse($json['success'] ?? true, $raw);
        $this->assertSame('0', $this->scalar("SELECT COUNT(*) FROM tenant_transfers"));
    }

    #[Test]
    public function a_replayed_request_does_not_transfer_twice(): void
    {
        $post = ['action' => 'transfer_execute', 'tenant_id' => (string) $this->tenant, 'unit_id' => (string) $this->unitB,
                 'move_date' => '2026-10-05', 'idempotency_key' => 'replay-key-123'];
        $a = $this->tenants($post);
        $b = $this->tenants($post);
        $this->assertTrue($a['success'] && $b['success']);
        $this->assertSame($a['transfer_id'], $b['transfer_id']);
        $this->assertSame('1', $this->scalar("SELECT COUNT(*) FROM tenant_transfers"));
    }
}
