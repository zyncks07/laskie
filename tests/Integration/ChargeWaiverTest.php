<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end cover for the admin charge-waiver flow, driven through the REAL
 * handlers in payments/api_payment.php (void_rent_charge, restore_rent_charge,
 * delete_charge, restore_charge, bulk_void_charges).
 *
 * Rent charges are virtual, so the things worth pinning are: the cap (never
 * waive more than the unpaid part), that a waiver actually reduces what the
 * ledger says is owed, that a paid month is refused, and that voiding a service
 * charge soft-voids the row instead of deleting it.
 *
 * ISOLATION — runs against `laskie_test` only; self-skips when absent.
 * See IsolatedDbTestCase.
 */
final class ChargeWaiverTest extends IsolatedDbTestCase
{
    // system_logs is included so the audit-trail assertion counts only this
    // test's own rows rather than everything the class logged before it.
    private const MONEY_TABLES = [
        'rent_charge_voids', 'payments', 'cash_transactions',
        'unit_charges', 'refunds', 'rental_units', 'tenants', 'system_logs',
    ];

    private const RATE = '10000.00';

    private int $unitId   = 0;
    private int $tenantId = 0;
    private int $month    = 0;
    private int $year     = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->truncate(self::MONEY_TABLES);
        $this->seedAdminUser();

        // Period under test: last month, so "is it overdue" logic is settled and
        // the contract clearly covers it.
        $this->month = (int) date('n', strtotime('first day of last month'));
        $this->year  = (int) date('Y', strtotime('first day of last month'));

        self::$db->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-WAIVER-UNIT', " . self::RATE . ", 5, 'occupied')"
        );
        $this->unitId = (int) self::$db->lastInsertId();

        // Contract starts well before the period so the charge is never prorated.
        $start = date('Y-m-d', strtotime('-2 years'));
        $ins = self::$db->prepare(
            "INSERT INTO tenants (full_name, unit_id, monthly_rate, contract_start, status)
             VALUES ('Waiver Test Tenant', ?, ?, ?, 'active')"
        );
        $ins->execute([$this->unitId, self::RATE, $start]);
        $this->tenantId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(self::MONEY_TABLES);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function voidRent(array $overrides = []): array
    {
        [$res] = $this->callApiAction($overrides + [
            'action'       => 'void_rent_charge',
            'unit_id'      => (string) $this->unitId,
            'tenant_id'    => (string) $this->tenantId,
            'period_month' => (string) $this->month,
            'period_year'  => (string) $this->year,
            'amount'       => self::RATE,
            'reason'       => 'advance rent applied',
        ]);
        return $res ?? [];
    }

    private function recordRentPayment(string $amount): int
    {
        [$res] = $this->callApiAction([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'tenant_id'    => (string) $this->tenantId,
            'payment_type' => 'rent',
            'amount'       => $amount,
            'payment_date' => sprintf('%04d-%02d-05', $this->year, $this->month),
            'period_month' => (string) $this->month,
            'period_year'  => (string) $this->year,
        ]);
        $this->assertTrue($res['success'] ?? false, 'save_payment failed: ' . ($res['error'] ?? '?'));
        return (int) $res['id'];
    }

    private function activeWaiverTotal(): string
    {
        $q = self::$db->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM rent_charge_voids
             WHERE unit_id=? AND period_month=? AND period_year=? AND restored_at IS NULL"
        );
        $q->execute([$this->unitId, $this->month, $this->year]);
        return number_format((float) $q->fetchColumn(), 2, '.', '');
    }

    private function addServiceCharge(string $amount = '850.00'): int
    {
        [$res] = $this->callApiAction([
            'action'       => 'save_charge',
            'unit_id'      => (string) $this->unitId,
            'tenant_id'    => (string) $this->tenantId,
            'amount'       => $amount,
            'description'  => 'Water',
            'charge_date'  => sprintf('%04d-%02d-05', $this->year, $this->month),
            'period_month' => (string) $this->month,
            'period_year'  => (string) $this->year,
        ]);
        $this->assertTrue($res['success'] ?? false, 'save_charge failed: ' . ($res['error'] ?? '?'));
        return (int) $res['id'];
    }

    // ── rent waivers ─────────────────────────────────────────────

    #[Test]
    public function waiving_an_unpaid_month_records_the_full_charge(): void
    {
        $res = $this->voidRent();
        $this->assertTrue($res['success'] ?? false, 'void failed: ' . ($res['error'] ?? '?'));
        $this->assertSame(self::RATE, $this->activeWaiverTotal());
    }

    #[Test]
    public function a_fully_paid_month_cannot_be_waived(): void
    {
        $this->recordRentPayment(self::RATE);
        $res = $this->voidRent();
        $this->assertFalse($res['success'] ?? true, 'a paid month must not be waivable');
        $this->assertStringContainsString('Refund', (string) ($res['error'] ?? ''));
        $this->assertSame('0.00', $this->activeWaiverTotal());
    }

    #[Test]
    public function waiving_more_than_the_unpaid_part_is_refused(): void
    {
        $this->recordRentPayment('6000.00');           // ₱4,000 left unpaid
        $res = $this->voidRent(['amount' => '5000.00']);
        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('Maximum waivable', (string) ($res['error'] ?? ''));
        $this->assertSame('0.00', $this->activeWaiverTotal());
    }

    #[Test]
    public function the_unpaid_remainder_of_a_part_paid_month_is_waivable(): void
    {
        $this->recordRentPayment('6000.00');
        $res = $this->voidRent(['amount' => '4000.00']);
        $this->assertTrue($res['success'] ?? false, 'void failed: ' . ($res['error'] ?? '?'));
        $this->assertSame('4000.00', $this->activeWaiverTotal());
    }

    #[Test]
    public function partial_waivers_accumulate_and_stop_at_the_full_charge(): void
    {
        $this->assertTrue($this->voidRent(['amount' => '3000.00'])['success'] ?? false);
        $this->assertTrue($this->voidRent(['amount' => '7000.00'])['success'] ?? false);
        $this->assertSame(self::RATE, $this->activeWaiverTotal());

        $third = $this->voidRent(['amount' => '1000.00']);
        $this->assertFalse($third['success'] ?? true, 'the charge was already fully waived');
        $this->assertSame(self::RATE, $this->activeWaiverTotal());
    }

    #[Test]
    public function a_waiver_requires_a_reason(): void
    {
        $res = $this->voidRent(['reason' => '   ']);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame('0.00', $this->activeWaiverTotal());
    }

    #[Test]
    public function restoring_a_waiver_makes_the_rent_owed_again(): void
    {
        $this->assertTrue($this->voidRent()['success'] ?? false);
        $waiverId = (int) self::$db->query('SELECT id FROM rent_charge_voids ORDER BY id DESC LIMIT 1')->fetchColumn();

        [$res] = $this->callApiAction(['action' => 'restore_rent_charge', 'id' => (string) $waiverId]);
        $this->assertTrue($res['success'] ?? false, 'restore failed: ' . ($res['error'] ?? '?'));

        // Soft restore: the row survives for the audit trail but stops counting.
        $this->assertSame('0.00', $this->activeWaiverTotal());
        $this->assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM rent_charge_voids')->fetchColumn());

        // ...and the period is waivable again.
        $this->assertTrue($this->voidRent()['success'] ?? false);
        $this->assertSame(self::RATE, $this->activeWaiverTotal());
    }

    #[Test]
    public function a_waiver_moves_no_cash(): void
    {
        $this->assertTrue($this->voidRent()['success'] ?? false);
        $this->assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM cash_transactions')->fetchColumn(),
            'waiving a charge must never create a cash row');
    }

    #[Test]
    public function the_waiver_is_written_to_the_audit_log(): void
    {
        $this->assertTrue($this->voidRent()['success'] ?? false);
        $n = (int) self::$db->query("SELECT COUNT(*) FROM system_logs WHERE action='VOID_RENT_CHARGE'")->fetchColumn();
        $this->assertSame(1, $n);
    }

    // ── service-charge voids ─────────────────────────────────────

    #[Test]
    public function voiding_a_service_charge_soft_voids_it_instead_of_deleting(): void
    {
        $chargeId = $this->addServiceCharge();

        [$res] = $this->callApiAction([
            'action' => 'delete_charge', 'id' => (string) $chargeId, 'reason' => 'moved out',
        ]);
        $this->assertTrue($res['success'] ?? false, 'void failed: ' . ($res['error'] ?? '?'));

        $row = self::$db->query("SELECT * FROM unit_charges WHERE id={$chargeId}")->fetch();
        $this->assertNotEmpty($row, 'the charge row must survive a void');
        $this->assertNotNull($row['voided_at']);
        $this->assertSame('moved out', $row['void_reason']);
        $this->assertSame(1, (int) $row['voided_by']);
    }

    #[Test]
    public function voiding_a_service_charge_requires_a_reason(): void
    {
        $chargeId = $this->addServiceCharge();
        [$res] = $this->callApiAction(['action' => 'delete_charge', 'id' => (string) $chargeId]);
        $this->assertFalse($res['success'] ?? true);

        $row = self::$db->query("SELECT voided_at FROM unit_charges WHERE id={$chargeId}")->fetch();
        $this->assertNull($row['voided_at']);
    }

    #[Test]
    public function a_voided_service_charge_can_be_restored(): void
    {
        $chargeId = $this->addServiceCharge();
        $this->callApiAction(['action' => 'delete_charge', 'id' => (string) $chargeId, 'reason' => 'oops']);

        [$res] = $this->callApiAction(['action' => 'restore_charge', 'id' => (string) $chargeId]);
        $this->assertTrue($res['success'] ?? false, 'restore failed: ' . ($res['error'] ?? '?'));

        $row = self::$db->query("SELECT * FROM unit_charges WHERE id={$chargeId}")->fetch();
        $this->assertNull($row['voided_at']);
        $this->assertNull($row['void_reason']);
    }

    // ── bulk ─────────────────────────────────────────────────────

    #[Test]
    public function bulk_void_clears_rent_and_service_arrears_together(): void
    {
        $chargeId = $this->addServiceCharge('850.00');
        $items = json_encode([
            ['type' => 'rent', 'period_month' => $this->month, 'period_year' => $this->year, 'tenant_id' => $this->tenantId],
            ['type' => 'service', 'id' => $chargeId],
        ]);

        [$res] = $this->callApiAction([
            'action'  => 'bulk_void_charges',
            'unit_id' => (string) $this->unitId,
            'reason'  => 'tenant moved out with arrears',
            'items'   => $items,
        ]);

        $this->assertTrue($res['success'] ?? false, 'bulk void failed: ' . ($res['error'] ?? '?'));
        $this->assertSame(2, (int) $res['voided']);
        $this->assertSame('10850.00', $res['total']);
        $this->assertSame(self::RATE, $this->activeWaiverTotal());

        $row = self::$db->query("SELECT voided_at FROM unit_charges WHERE id={$chargeId}")->fetch();
        $this->assertNotNull($row['voided_at']);
    }

    #[Test]
    public function bulk_void_skips_what_it_cannot_waive_and_keeps_the_rest(): void
    {
        $this->recordRentPayment(self::RATE);          // rent settled → not waivable
        $chargeId = $this->addServiceCharge('850.00'); // still outstanding

        [$res] = $this->callApiAction([
            'action'  => 'bulk_void_charges',
            'unit_id' => (string) $this->unitId,
            'reason'  => 'partial cleanup',
            'items'   => json_encode([
                ['type' => 'rent', 'period_month' => $this->month, 'period_year' => $this->year, 'tenant_id' => $this->tenantId],
                ['type' => 'service', 'id' => $chargeId],
            ]),
        ]);

        $this->assertTrue($res['success'] ?? false, 'bulk void failed: ' . ($res['error'] ?? '?'));
        $this->assertSame(1, (int) $res['voided']);
        $this->assertCount(1, $res['skipped'] ?? []);
        $this->assertSame('0.00', $this->activeWaiverTotal(), 'the paid rent month must not be waived');
    }
}
