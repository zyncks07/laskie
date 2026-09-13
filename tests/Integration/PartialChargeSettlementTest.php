<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Partial settlement of a service charge (charge_payments).
 *
 * unit_charges used to model a charge as settled by EXACTLY ONE payment, and
 * save_payment overwrote unit_charges.amount when it linked. A charge paid in
 * instalments therefore broke two ways, both reproduced against live data:
 *
 *   * charge with a NULL service_type_id — the link lookup keys on
 *     `service_type_id = ?`, which never matches NULL, so a matching
 *     auto_collected charge was invented beside the payment. Charge and credit
 *     cancelled and THE BALANCE NEVER MOVED. Every carried-over arrears row in
 *     the live database is exactly that shape (9 rows, ₱50,000).
 *   * charge with a service_type_id — the link fired and rewrote amount
 *     12,500 → 4,000 as paid, DESTROYING ₱8,500 of the receivable.
 *
 * Settlement now lives in charge_payments: outstanding is
 * `amount − SUM(allocations whose payment is live)`. Only LIVE payments count,
 * so void/soft-delete releases a charge and restore re-settles it with no
 * re-linking at all.
 *
 * ServiceChargeLifecycleTest still pins the other half of this: an
 * auto_collected charge exists if and only if its payment is live.
 *
 * ISOLATION — runs against `laskie_test` only; self-skips when absent.
 * See IsolatedDbTestCase.
 */
final class PartialChargeSettlementTest extends IsolatedDbTestCase
{
    private const MONEY_TABLES = [
        'charge_payments', 'rent_charge_voids', 'payments', 'cash_transactions',
        'unit_charges', 'refunds', 'rental_units', 'tenants', 'system_logs',
    ];

    private const CHARGE = '12500.00';

    private int $unitId    = 0;
    private int $tenantId  = 0;
    private int $serviceId = 0;
    private int $month     = 0;
    private int $year      = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->truncate(self::MONEY_TABLES);
        $this->seedAdminUser();

        $this->month = (int) date('n', strtotime('first day of last month'));
        $this->year  = (int) date('Y', strtotime('first day of last month'));

        self::$db->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-PARTIAL-UNIT', 0.00, 5, 'occupied')"
        );
        $this->unitId = (int) self::$db->lastInsertId();

        $ins = self::$db->prepare(
            "INSERT INTO tenants (full_name, unit_id, contract_start, status)
             VALUES ('Partial Test Tenant', ?, ?, 'active')"
        );
        $ins->execute([$this->unitId, date('Y-m-d', strtotime('-2 years'))]);
        $this->tenantId = (int) self::$db->lastInsertId();

        self::$db->exec("INSERT INTO service_types (name, is_active) VALUES ('Arrears', 1)");
        $this->serviceId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(self::MONEY_TABLES);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** A carried-over arrears charge: pre_billed, and deliberately NO service type. */
    private function arrearsCharge(?int $serviceId = null): int
    {
        $ins = self::$db->prepare(
            "INSERT INTO unit_charges (unit_id, tenant_id, service_type_id, amount, description,
                                       charge_date, period_month, period_year, source, created_by)
             VALUES (?,?,?,?,'Balance brought forward (2025 arrears)',?,?,?, 'pre_billed', 1)"
        );
        $ins->execute([
            $this->unitId, $this->tenantId, $serviceId, self::CHARGE,
            sprintf('%04d-%02d-01', $this->year, $this->month), $this->month, $this->year,
        ]);
        return (int) self::$db->lastInsertId();
    }

    /** @return array{0: ?array, 1: string} */
    private function pay(string $amount, ?int $chargeId, array $extra = []): array
    {
        return $this->callApiAction(array_merge([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'tenant_id'    => (string) $this->tenantId,
            'payment_type' => 'service',
            'amount'       => $amount,
            'period_month' => (string) $this->month,
            'period_year'  => (string) $this->year,
            'payment_date' => date('Y-m-d'),
        ], $chargeId !== null ? ['charge_id' => (string) $chargeId] : [], $extra));
    }

    private function outstanding(int $chargeId): string
    {
        return getChargeOutstanding(self::$db, $chargeId);
    }

    private function settled(int $chargeId): string
    {
        return getChargeSettled(self::$db, $chargeId);
    }

    private function chargeAmount(int $chargeId): string
    {
        $q = self::$db->prepare("SELECT amount FROM unit_charges WHERE id=?");
        $q->execute([$chargeId]);
        return from_cents(to_cents($q->fetchColumn()));
    }

    private function chargeCount(): int
    {
        $q = self::$db->prepare("SELECT COUNT(*) FROM unit_charges WHERE unit_id=?");
        $q->execute([$this->unitId]);
        return (int) $q->fetchColumn();
    }

    private function allocCount(int $chargeId): int
    {
        $q = self::$db->prepare("SELECT COUNT(*) FROM charge_payments WHERE charge_id=?");
        $q->execute([$chargeId]);
        return (int) $q->fetchColumn();
    }

    /** What the tenant still owes on service charges — the figure staff chase. */
    private function tenantOwes(): string
    {
        $a = getCurrentTenantArrears(self::$db, $this->unitId);
        return $a[$this->unitId] ?? '0.00';
    }

    // ── 1. the case that silently failed ─────────────────────────

    #[Test]
    public function partial_payment_moves_the_balance_and_leaves_the_charge_intact(): void
    {
        $chargeId = $this->arrearsCharge();          // NULL service_type_id
        $this->assertSame(self::CHARGE, $this->tenantOwes());

        [$res] = $this->pay('4000.00', $chargeId);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'payment refused');

        // The whole point: the balance moves by exactly what was paid.
        $this->assertSame('8500.00',  $this->tenantOwes());
        $this->assertSame('8500.00',  $this->outstanding($chargeId));
        $this->assertSame('4000.00',  $this->settled($chargeId));
        // The charge itself is untouched — the old link path rewrote it to 4000.
        $this->assertSame(self::CHARGE, $this->chargeAmount($chargeId));
        // And no phantom charge was invented alongside the payment.
        $this->assertSame(1, $this->chargeCount(), 'no auto_collected row may be created');
        $this->assertSame(1, $this->allocCount($chargeId));
    }

    #[Test]
    public function a_charge_with_a_service_type_settles_the_same_way(): void
    {
        $chargeId = $this->arrearsCharge($this->serviceId);
        [$res] = $this->pay('4000.00', $chargeId, ['service_type_id' => (string) $this->serviceId]);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'payment refused');

        $this->assertSame(self::CHARGE, $this->chargeAmount($chargeId), 'amount must never be rewritten');
        $this->assertSame('8500.00', $this->outstanding($chargeId));
        $this->assertSame(1, $this->chargeCount());
    }

    // ── 2. instalments ───────────────────────────────────────────

    #[Test]
    public function instalments_settle_the_charge_exactly_and_never_overshoot(): void
    {
        $chargeId = $this->arrearsCharge();

        foreach ([['4000.00', '8500.00'], ['4000.00', '4500.00'], ['4500.00', '0.00']] as [$amt, $left]) {
            [$res] = $this->pay($amt, $chargeId);
            $this->assertTrue($res['success'] ?? false, $res['error'] ?? "instalment $amt refused");
            $this->assertSame($left, $this->outstanding($chargeId));
        }

        $this->assertSame(self::CHARGE, $this->settled($chargeId));
        $this->assertSame('0.00', $this->tenantOwes(), 'balance lands on zero, never negative');
        $this->assertSame(3, $this->allocCount($chargeId));
        $this->assertSame(1, $this->chargeCount(), 'three instalments, still one charge');
    }

    // ── 3. overpayment is refused ────────────────────────────────

    #[Test]
    public function overpaying_a_charge_is_refused_and_writes_nothing(): void
    {
        $chargeId = $this->arrearsCharge();
        $this->pay('4000.00', $chargeId);

        [$res] = $this->pay('9000.00', $chargeId);
        $this->assertFalse($res['success'] ?? true, 'overpayment must be refused');
        $this->assertStringContainsString('500.00',   $res['error'] ?? '', 'names the excess');
        $this->assertStringContainsString('8,500.00', $res['error'] ?? '', 'names what is outstanding');

        $this->assertSame('8500.00', $this->outstanding($chargeId), 'nothing was written');
        $this->assertSame(1, $this->allocCount($chargeId));
    }

    #[Test]
    public function paying_an_already_settled_charge_is_refused(): void
    {
        $chargeId = $this->arrearsCharge();
        $this->pay(self::CHARGE, $chargeId);
        $this->assertSame('0.00', $this->outstanding($chargeId));

        [$res] = $this->pay('100.00', $chargeId);
        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('fully settled', $res['error'] ?? '');
    }

    // ── 4. reversal releases, restore re-settles ────────────────

    #[Test]
    public function voiding_an_instalment_releases_only_that_amount(): void
    {
        $chargeId = $this->arrearsCharge();
        [$r1] = $this->pay('4000.00', $chargeId);
        [$r2] = $this->pay('4000.00', $chargeId);
        $this->assertSame('4500.00', $this->outstanding($chargeId));

        $this->callApiAction(['action' => 'void_payment', 'id' => (string) $r2['id'], 'reason' => 'test']);
        $this->assertSame('8500.00', $this->outstanding($chargeId), 'the voided instalment is owed again');
        $this->assertSame('4000.00', $this->settled($chargeId));
        // The allocation is NOT deleted — a dead payment simply stops counting.
        $this->assertSame(2, $this->allocCount($chargeId));

        $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $r2['id']]);
        $this->assertSame('4500.00', $this->outstanding($chargeId), 'restoring re-settles it');
    }

    #[Test]
    public function soft_delete_and_restore_round_trip_a_partial_payment(): void
    {
        $chargeId = $this->arrearsCharge();
        [$r] = $this->pay('4000.00', $chargeId);

        $this->callApiAction(['action' => 'delete_payment', 'id' => (string) $r['id']]);
        $this->assertSame(self::CHARGE, $this->outstanding($chargeId));

        $this->callApiAction(['action' => 'restore_deleted_payment', 'id' => (string) $r['id']]);
        $this->assertSame('8500.00', $this->outstanding($chargeId));
        $this->assertSame(1, $this->chargeCount(), 'the round trip creates no extra charge');
    }

    #[Test]
    public function purging_a_partial_payment_cascades_its_allocation_away(): void
    {
        $chargeId = $this->arrearsCharge();
        [$r] = $this->pay('4000.00', $chargeId);
        $this->assertSame(1, $this->allocCount($chargeId));

        $this->callApiAction(['action' => 'delete_payment', 'id' => (string) $r['id']]);
        $this->callApiAction(['action' => 'purge_payment',  'id' => (string) $r['id']]);

        $this->assertSame(0, $this->allocCount($chargeId), 'FK cascade removes the allocation');
        $this->assertSame(self::CHARGE, $this->outstanding($chargeId));
    }

    // ── 5. admin guards ──────────────────────────────────────────

    #[Test]
    public function a_partly_paid_charge_may_be_edited_but_never_below_what_is_settled(): void
    {
        $chargeId = $this->arrearsCharge();
        $this->pay('4000.00', $chargeId);

        [$down] = $this->callApiAction([
            'action' => 'save_charge', 'id' => (string) $chargeId,
            'unit_id' => (string) $this->unitId, 'amount' => '3000.00',
            'description' => 'Balance brought forward (2025 arrears)',
            'charge_date' => sprintf('%04d-%02d-01', $this->year, $this->month),
            'period_month' => (string) $this->month, 'period_year' => (string) $this->year,
        ]);
        $this->assertFalse($down['success'] ?? true, 'cannot drop below the settled amount');
        $this->assertStringContainsString('4,000.00', $down['error'] ?? '');
        $this->assertSame(self::CHARGE, $this->chargeAmount($chargeId));

        [$ok] = $this->callApiAction([
            'action' => 'save_charge', 'id' => (string) $chargeId,
            'unit_id' => (string) $this->unitId, 'amount' => '10000.00',
            'description' => 'Balance brought forward (2025 arrears, corrected)',
            'charge_date' => sprintf('%04d-%02d-01', $this->year, $this->month),
            'period_month' => (string) $this->month, 'period_year' => (string) $this->year,
        ]);
        $this->assertTrue($ok['success'] ?? false, $ok['error'] ?? 'correction above the settled amount refused');
        $this->assertSame('10000.00', $this->chargeAmount($chargeId));
        $this->assertSame('6000.00',  $this->outstanding($chargeId), 'outstanding follows the corrected total');
    }

    #[Test]
    public function a_partly_paid_charge_cannot_be_waived(): void
    {
        $chargeId = $this->arrearsCharge();
        $this->pay('4000.00', $chargeId);

        [$res] = $this->callApiAction([
            'action' => 'delete_charge', 'id' => (string) $chargeId,
            'reason' => 'write off the rest',
        ]);
        $this->assertFalse($res['success'] ?? true, 'waiving a part-paid charge would cancel a paid receivable');
        $this->assertStringContainsString('4,000.00', $res['error'] ?? '');

        $q = self::$db->prepare("SELECT voided_at FROM unit_charges WHERE id=?");
        $q->execute([$chargeId]);
        $this->assertNull($q->fetchColumn(), 'the charge was not voided');
    }

    // ── 6. the untouched path still behaves ─────────────────────

    #[Test]
    public function an_uninvoiced_service_payment_still_raises_its_own_charge(): void
    {
        // No pre-existing charge and no charge_id: save_payment must still
        // conjure the auto_collected row that makes the statement balance, and
        // allocate the payment to it in full.
        [$res] = $this->pay('600.00', null, ['service_type_id' => (string) $this->serviceId]);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'payment refused');

        $q = self::$db->prepare("SELECT * FROM unit_charges WHERE unit_id=?");
        $q->execute([$this->unitId]);
        $rows = $q->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('auto_collected', $rows[0]['source']);
        $this->assertSame('600.00', from_cents(to_cents($rows[0]['amount'])));
        $this->assertSame('0.00',   $this->outstanding((int) $rows[0]['id']), 'settled in full');
        $this->assertSame('0.00',   $this->tenantOwes(), 'nets to zero, as before');
    }

    #[Test]
    public function equivalence_a_wholly_unpaid_and_a_wholly_settled_charge_read_as_before(): void
    {
        // The two states that existed before charge_payments must report
        // identically under the new rule — this is the backfill's guarantee.
        $unpaid = $this->arrearsCharge();
        $this->assertSame(self::CHARGE, $this->outstanding($unpaid));
        $this->assertSame('0.00',       $this->settled($unpaid));

        $settled = $this->arrearsCharge();
        $this->pay(self::CHARGE, $settled);
        $this->assertSame('0.00',       $this->outstanding($settled));
        $this->assertSame(self::CHARGE, $this->settled($settled));

        // Only the unpaid one is chased.
        $this->assertSame(self::CHARGE, $this->tenantOwes());
    }
}
