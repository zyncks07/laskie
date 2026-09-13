<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the tenant-handover invariants: what happens to a unit's billing and its
 * Statement of Account when one tenant moves out owing money and another moves
 * in.
 *
 * Every case here was a live defect before this suite existed:
 *   • a departed tenant with a blank contract_end billed rent forever, and
 *     double-charged every month the successor was also billed (measured:
 *     18 charge rows / ₱153,000 on a unit that should bill ₱102,000)
 *   • a mid-month handover billed the outgoing tenant a FULL final month on top
 *     of the incoming tenant's prorated one
 *   • the SoA was unit-scoped, so the incoming tenant's statement opened
 *     carrying the departed tenant's arrears
 *   • the waiver cap was unit-wide while the charge is per-tenant, so the
 *     outgoing tenant's genuinely unpaid period could not be waived once the
 *     incoming tenant had paid the same period
 *
 * ISOLATION — runs against `laskie_test` only; self-skips when absent.
 * See IsolatedDbTestCase.
 */
final class TenantHandoverTest extends IsolatedDbTestCase
{
    private const MONEY_TABLES = [
        'rent_charge_voids', 'payments', 'cash_transactions',
        'unit_charges', 'refunds', 'rental_units', 'tenants', 'system_logs',
    ];

    private const RATE = '8500.00';
    private const YEAR = 2026;

    private int $unitId = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->truncate(self::MONEY_TABLES);
        $this->seedAdminUser();

        self::$db->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-HANDOVER-UNIT', " . self::RATE . ", 5, 'occupied')"
        );
        $this->unitId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(self::MONEY_TABLES);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function addTenant(string $name, ?string $start, ?string $end, string $status): int
    {
        $ins = self::$db->prepare(
            "INSERT INTO tenants (full_name, unit_id, contract_start, contract_end, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$name, $this->unitId, $start, $end, $status]);
        return (int) self::$db->lastInsertId();
    }

    /** The occupants a unit-ledger SoA would render, with ends resolved. */
    private function occupants(): array
    {
        $q = self::$db->prepare(
            "SELECT * FROM tenants WHERE unit_id = ? AND status IN ('active','former','inactive')
             ORDER BY COALESCE(contract_start,'1970-01-01') ASC"
        );
        $q->execute([$this->unitId]);
        return resolveOccupancyEnds(self::$db, $q->fetchAll());
    }

    /** Rent charge rows over the full year, for the given occupants. */
    private function rentRows(array $occupants, array $voidMap = []): array
    {
        return buildRentChargeRows(
            self::$db, $this->unitId, $occupants, 5, (float) self::RATE,
            self::YEAR . '-01-01', self::YEAR . '-12-31', $voidMap
        );
    }

    private static function grossTotal(array $rows): string
    {
        return money_sum(array_column($rows, 'gross'));
    }

    /** period key => number of charge rows, to catch double-billing. */
    private static function rowsPerPeriod(array $rows): array
    {
        $n = [];
        foreach ($rows as $r) {
            $k = $r['period_year'] . '-' . $r['period_month'];
            $n[$k] = ($n[$k] ?? 0) + 1;
        }
        return $n;
    }

    /** period key => summed gross across every occupant in that period. */
    private static function grossPerPeriod(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $k = $r['period_year'] . '-' . $r['period_month'];
            $g[$k] = money_add($g[$k] ?? '0.00', $r['gross']);
        }
        return $g;
    }

    // ── 1. clean handover ────────────────────────────────────────

    #[Test]
    public function clean_handover_bills_each_month_exactly_once(): void
    {
        $this->addTenant('Outgoing', '2026-01-01', '2026-06-30', 'former');
        $this->addTenant('Incoming', '2026-07-01', null,         'active');

        $rows = $this->rentRows($this->occupants());

        $this->assertCount(12, $rows, 'twelve months, one charge each');
        $this->assertSame('102000.00', self::grossTotal($rows));
        foreach (self::rowsPerPeriod($rows) as $period => $count) {
            $this->assertSame(1, $count, "period $period must carry exactly one rent charge");
        }
    }

    // ── 2. blank contract_end must not bill forever ──────────────

    #[Test]
    public function departed_tenant_without_contract_end_stops_at_the_successor(): void
    {
        // Exactly the shape that used to produce 18 rows / ₱153,000: the admin
        // flipped the status to 'former' and left the end date blank.
        $this->addTenant('Outgoing', '2026-01-01', null, 'former');
        $this->addTenant('Incoming', '2026-07-01', null, 'active');

        $rows = $this->rentRows($this->occupants());

        $this->assertCount(12, $rows);
        $this->assertSame('102000.00', self::grossTotal($rows));
        foreach (self::rowsPerPeriod($rows) as $period => $count) {
            $this->assertSame(1, $count, "period $period must not be double-charged");
        }
        // The backstop clips the outgoing tenant the day before the successor.
        $lastOutgoing = null;
        foreach ($rows as $r) {
            if ($r['tenant_name'] === 'Outgoing') $lastOutgoing = $r;
        }
        $this->assertNotNull($lastOutgoing);
        $this->assertSame(6, $lastOutgoing['period_month'], 'outgoing tenant stops billing in June');
    }

    #[Test]
    public function departed_tenant_with_no_successor_stops_at_their_last_payment(): void
    {
        $tid = $this->addTenant('Outgoing', '2026-01-01', null, 'former');
        // They paid rent through March and then left.
        $ins = self::$db->prepare(
            "INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount,
                                   period_month, period_year, payment_date, received_by, status)
             VALUES (?, ?, ?, 'rent', ?, ?, ?, ?, 1, 'paid')"
        );
        foreach ([1, 2, 3] as $m) {
            $ins->execute([
                sprintf('INV-2026-%05d', $m), $this->unitId, $tid, self::RATE,
                $m, self::YEAR, sprintf('2026-%02d-05', $m),
            ]);
        }

        $rows = $this->rentRows($this->occupants());

        // Without the backstop this billed all twelve months of 2026.
        $this->assertCount(3, $rows);
        $this->assertSame('25500.00', self::grossTotal($rows));
    }

    #[Test]
    public function active_tenant_with_open_contract_still_bills_the_whole_range(): void
    {
        // The backstop must only ever touch a tenancy that has ENDED — an open
        // contract on an active tenant is the normal case and bills through.
        $this->addTenant('Sitting', '2026-01-01', null, 'active');

        $rows = $this->rentRows($this->occupants());

        $this->assertCount(12, $rows);
        $this->assertSame('102000.00', self::grossTotal($rows));
    }

    // ── 3. mid-month handover ────────────────────────────────────

    #[Test]
    public function mid_month_handover_splits_the_month_instead_of_billing_it_twice(): void
    {
        $this->addTenant('Outgoing', '2026-01-01', '2026-07-15', 'former');
        $this->addTenant('Incoming', '2026-07-16', null,         'active');

        $rows = $this->rentRows($this->occupants());

        // July legitimately carries two rows — one per occupant — but they must
        // sum to exactly one month's rent, not full + prorated.
        $perPeriod = self::rowsPerPeriod($rows);
        $this->assertSame(2, $perPeriod['2026-7'], 'both occupants are billed for July');
        $this->assertSame(self::RATE, self::grossPerPeriod($rows)['2026-7'],
            'the two July charges must add up to exactly one month of rent');
        $this->assertSame('102000.00', self::grossTotal($rows),
            'the year still bills twelve months of rent in total');

        // 8500/31 × 15 = 4112.90 out, 8500/31 × 16 = 4387.10 in.
        $july = array_values(array_filter($rows, fn($r) => $r['period_month'] === 7));
        $this->assertSame('4112.90', $july[0]['gross']);
        $this->assertSame('4387.10', $july[1]['gross']);
    }

    #[Test]
    public function final_month_is_prorated_by_days_occupied(): void
    {
        $this->addTenant('Leaver', '2026-01-01', '2026-09-01', 'former');

        $rows = $this->rentRows($this->occupants());
        $sept = array_values(array_filter($rows, fn($r) => $r['period_month'] === 9));

        $this->assertCount(1, $sept);
        // One day of a 30-day month — it used to bill the full ₱8,500.
        $this->assertSame('283.33', $sept[0]['gross']);
        $this->assertStringContainsString('prorated', $sept[0]['description']);
    }

    // ── 4. the SoA is scoped to one tenancy ──────────────────────

    #[Test]
    public function incoming_tenants_statement_excludes_the_departed_tenants_arrears(): void
    {
        $outId = $this->addTenant('Outgoing', '2026-01-01', '2026-06-30', 'former');
        $inId  = $this->addTenant('Incoming', '2026-07-01', null,         'active');

        // Outgoing never paid a peso: six months of rent plus a pre-billed
        // service charge left outstanding.
        self::$db->prepare(
            "INSERT INTO unit_charges (unit_id, tenant_id, amount, description, charge_date,
                                       period_month, period_year, source, created_by)
             VALUES (?, ?, '2500.00', 'Unpaid utilities', '2026-06-05', 6, 2026, 'pre_billed', 1)"
        )->execute([$this->unitId, $outId]);

        // Incoming pays July on time.
        self::$db->prepare(
            "INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount,
                                   period_month, period_year, payment_date, received_by, status)
             VALUES ('INV-2026-09001', ?, ?, 'rent', ?, 7, 2026, '2026-07-04', 1, 'paid')"
        )->execute([$this->unitId, $inId, self::RATE]);

        $all = $this->occupants();
        $out = array_values(array_filter($all, fn($o) => (int) $o['id'] === $outId));
        $in  = array_values(array_filter($all, fn($o) => (int) $o['id'] === $inId));

        // Outgoing tenant's own statement carries their six unpaid months.
        $this->assertSame('51000.00', self::grossTotal($this->rentRows($out)));

        // The incoming tenant's statement starts in July — none of the
        // outgoing tenant's charges appear on it at all.
        $inRows = $this->rentRows($in);
        $this->assertSame(7, min(array_column($inRows, 'period_month')),
            "the incoming tenant's ledger opens at their contract start, not January");
        foreach ($inRows as $r) {
            $this->assertSame($inId, $r['tenant_id'], 'every charge belongs to the incoming tenant');
        }

        // And the arrears are still visible to the landlord, attributed to the
        // tenant who actually owes them: 6 × 8500 + 2500 utilities.
        $arrears = getPastTenantArrears(self::$db, $this->unitId);
        $this->assertSame('53500.00', $arrears[$this->unitId] ?? '0.00');
    }

    #[Test]
    public function tenant_scoped_rent_paid_ignores_the_other_tenants_payments(): void
    {
        $outId = $this->addTenant('Outgoing', '2026-01-01', '2026-07-15', 'former');
        $inId  = $this->addTenant('Incoming', '2026-07-16', null,         'active');

        // Only the incoming tenant paid for July.
        self::$db->prepare(
            "INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount,
                                   period_month, period_year, payment_date, received_by, status)
             VALUES ('INV-2026-09002', ?, ?, 'rent', '4387.10', 7, 2026, '2026-07-16', 1, 'paid')"
        )->execute([$this->unitId, $inId]);

        $this->assertSame('4387.10', getRentPaidForPeriod(self::$db, $this->unitId, 7, self::YEAR, $inId));
        $this->assertSame('0.00',    getRentPaidForPeriod(self::$db, $this->unitId, 7, self::YEAR, $outId),
            "the incoming tenant's payment must not settle the outgoing tenant's July");
        // Unit-wide still reports the whole period — the default is unchanged.
        $this->assertSame('4387.10', getRentPaidForPeriod(self::$db, $this->unitId, 7, self::YEAR));
    }

    // ── 5. the waiver cap is per tenancy ─────────────────────────

    #[Test]
    public function outgoing_tenants_unpaid_period_stays_waivable_after_the_successor_pays(): void
    {
        $outId = $this->addTenant('Outgoing', '2026-01-01', '2026-07-15', 'former');
        $inId  = $this->addTenant('Incoming', '2026-07-16', null,         'active');

        // Incoming settles their prorated July share; outgoing never paid theirs.
        self::$db->prepare(
            "INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount,
                                   period_month, period_year, payment_date, received_by, status)
             VALUES ('INV-2026-09003', ?, ?, 'rent', '4387.10', 7, 2026, '2026-07-16', 1, 'paid')"
        )->execute([$this->unitId, $inId]);

        // With a unit-wide cap this was refused as "already settled".
        [$json] = $this->callApiAction([
            'action'       => 'void_rent_charge',
            'unit_id'      => $this->unitId,
            'tenant_id'    => $outId,
            'period_month' => 7,
            'period_year'  => self::YEAR,
            'reason'       => 'Arrears written off on move-out',
        ]);

        $this->assertNotNull($json, 'handler returned no JSON');
        $this->assertTrue($json['success'] ?? false, $json['error'] ?? 'waiver was refused');

        $row = self::$db->prepare(
            "SELECT amount, tenant_id FROM rent_charge_voids
             WHERE unit_id=? AND period_month=7 AND period_year=? AND restored_at IS NULL"
        );
        $row->execute([$this->unitId, self::YEAR]);
        $void = $row->fetch();
        $this->assertNotFalse($void, 'no waiver row was written');
        $this->assertSame($outId, (int) $void['tenant_id'], 'the waiver is booked against the outgoing tenant');
        $this->assertSame('4112.90', from_cents(to_cents($void['amount'])),
            'the waiver covers the outgoing tenant\'s prorated July share only');
    }

    #[Test]
    public function a_waiver_larger_than_its_charge_is_capped_not_credited(): void
    {
        // The live 359-D shape: a full-month waiver was recorded against a
        // September that move-out proration later shrank to a single day.
        // The rendered credit must be capped, or the running balance goes
        // negative by the difference — a phantom credit.
        $tid = $this->addTenant('Leaver', '2026-01-01', '2026-09-01', 'former');
        self::$db->prepare(
            "INSERT INTO rent_charge_voids (unit_id, tenant_id, period_month, period_year, amount, reason, voided_by)
             VALUES (?, ?, 9, 2026, ?, 'Arrears write-off', 1)"
        )->execute([$this->unitId, $tid, self::RATE]);

        $voidMap = getRentVoidMap(self::$db, $this->unitId, '2026-01-01', '2026-12-31', $tid);
        $rows    = $this->rentRows($this->occupants(), $voidMap);
        $sept    = array_values(array_filter($rows, fn($r) => $r['period_month'] === 9))[0];

        $this->assertSame('283.33', $sept['gross']);
        $this->assertSame('283.33', $sept['voided'], 'the waiver is capped at the charge');
        $this->assertSame('0.00',   $sept['net']);
        $this->assertSame('283.33', $sept['waivers'][0]['applied'],
            'renderers credit the applied amount, never the raw stored figure');
        $this->assertSame(self::RATE, from_cents(to_cents($sept['waivers'][0]['amount'])),
            'the stored waiver is left intact for the audit trail');
    }

    // ── 6. tenancy windows are closed and non-overlapping ────────

    #[Test]
    public function saving_an_overlapping_tenancy_is_rejected(): void
    {
        $this->addTenant('Sitting', '2026-01-01', null, 'active');

        [$json] = $this->callScript('admin/tenants.php', [
            'action'         => 'save_tenant',
            'full_name'      => 'Overlapper',
            'unit_id'        => $this->unitId,
            'contract_start' => '2026-07-01',
            'status'         => 'active',
        ], null, ['REQUEST_METHOD' => 'POST']);

        $this->assertNotNull($json, 'handler returned no JSON');
        $this->assertFalse($json['success'] ?? true, 'an overlapping tenancy must be refused');
        $this->assertStringContainsString('Sitting', $json['error'] ?? '',
            'the error names the tenant it clashes with');

        $n = self::$db->prepare("SELECT COUNT(*) FROM tenants WHERE unit_id=?");
        $n->execute([$this->unitId]);
        $this->assertSame(1, (int) $n->fetchColumn(), 'nothing was written');
    }

    #[Test]
    public function a_non_active_tenancy_must_carry_a_contract_end(): void
    {
        $id = $this->addTenant('Leaver', '2026-01-01', null, 'active');

        [$json] = $this->callScript('admin/tenants.php', [
            'action'         => 'save_tenant',
            'id'             => $id,
            'full_name'      => 'Leaver',
            'unit_id'        => $this->unitId,
            'contract_start' => '2026-01-01',
            'contract_end'   => '',
            'status'         => 'former',
        ], null, ['REQUEST_METHOD' => 'POST']);

        $this->assertNotNull($json, 'handler returned no JSON');
        $this->assertFalse($json['success'] ?? true,
            'a former tenant with no end date would bill rent indefinitely');

        $q = self::$db->prepare("SELECT status FROM tenants WHERE id=?");
        $q->execute([$id]);
        $this->assertSame('active', $q->fetchColumn(), 'the status change was not applied');
    }

    #[Test]
    public function a_clean_succeeding_tenancy_saves(): void
    {
        $this->addTenant('Outgoing', '2026-01-01', '2026-06-30', 'former');

        [$json] = $this->callScript('admin/tenants.php', [
            'action'         => 'save_tenant',
            'full_name'      => 'Incoming',
            'unit_id'        => $this->unitId,
            'contract_start' => '2026-07-01',
            'status'         => 'active',
        ], null, ['REQUEST_METHOD' => 'POST']);

        $this->assertNotNull($json, 'handler returned no JSON');
        $this->assertTrue($json['success'] ?? false, $json['error'] ?? 'a non-overlapping tenancy was refused');
    }
}
