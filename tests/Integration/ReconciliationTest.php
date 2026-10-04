<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Records-mismatch guard: every page that shows a tenant's balance must close on
 * the same figure, whatever range it is opened with (CLAUDE.md §5 invariant 16).
 * Renders the real SoA page and preview against laskie_test and compares the
 * figures they emit (reconEmit) with tenantStatement() — the definition the
 * Balance Tracker and dashboard receivables use.
 *
 * What these pin:
 *   - a statement opened on a range that starts mid-tenancy carries a
 *     "Balance brought forward" line, so it closes on the tenancy's balance
 *   - in the unit ledger each occupancy carries its own brought-forward figure
 *   - a final move-out bill dated in the month BEFORE its charge period keeps its
 *     waiver when the range ends in that month
 *   - dashboard receivables are tenantStatement() balances (a future-dated
 *     service charge is not owed yet)
 *   - a charge posted without a tenant is attributed to the occupant
 *   - the preview with no dates opens on the tenancy, like the SoA page
 *   - the unit and category charts count General and uncategorised spending
 *
 * The full live-data sweep is scripts/reconcile.php; this is its fixture-sized,
 * always-on counterpart.
 */
final class ReconciliationTest extends IsolatedDbTestCase
{
    private const AS_OF = '2026-06-30';
    private int $unit = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/../../config/balance_functions.php';
    }

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->seedAdminUser();
        resetRateHistoryCache();
        $this->truncate(['charge_payments', 'refunds', 'cash_transactions', 'rent_charge_voids', 'unit_charges',
                         'payments', 'tenant_transfers', 'contracts', 'tenants', 'expenses']);
        self::$db->exec("INSERT INTO rental_units (unit_name, monthly_rate, due_day, status) VALUES ('RC-1', 6000, 5, 'occupied')");
        $this->unit = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(['refunds', 'rent_charge_voids', 'unit_charges', 'payments', 'tenants', 'expenses']);
        self::$db->prepare("DELETE FROM rental_units WHERE id=?")->execute([$this->unit]);
    }

    private function tenant(string $name, string $start, ?string $end = null, string $status = 'active'): array
    {
        self::$db->prepare("INSERT INTO tenants (unit_id, full_name, contract_start, contract_end, status) VALUES (?, ?, ?, ?, ?)")
            ->execute([$this->unit, $name, $start, $end, $status]);
        $id = (int) self::$db->lastInsertId();
        $t = self::$db->query("SELECT t.*, ru.monthly_rate AS unit_rate, ru.due_day FROM tenants t JOIN rental_units ru ON ru.id=t.unit_id WHERE t.id=$id")->fetch();
        return $t;
    }

    private function pay(int $tenantId, int $month, string $date, string $amount = '6000.00'): int
    {
        self::$db->prepare("INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount, period_month, period_year, payment_date, received_by, status)
                            VALUES (?, ?, ?, 'rent', ?, ?, 2026, ?, 1, 'paid')")
            ->execute(['RC-' . bin2hex(random_bytes(4)), $this->unit, $tenantId, $amount, $month, $date]);
        return (int) self::$db->lastInsertId();
    }

    private function charge(?int $tenantId, string $amount, string $date): void
    {
        self::$db->prepare("INSERT INTO unit_charges (unit_id, tenant_id, amount, description, charge_date, period_month, period_year, source)
                            VALUES (?, ?, ?, 'Water', ?, ?, ?, 'pre_billed')")
            ->execute([$this->unit, $tenantId, $amount, $date, (int) substr($date, 5, 2), (int) substr($date, 0, 4)]);
    }

    private function waive(int $tenantId, int $month, string $amount): void
    {
        self::$db->prepare("INSERT INTO rent_charge_voids (unit_id, tenant_id, period_month, period_year, amount, reason, voided_by)
                            VALUES (?, ?, ?, 2026, ?, 'test', 1)")->execute([$this->unit, $tenantId, $month, $amount]);
    }

    /** SoA page and preview closing balances for one range. */
    private function balances(string $tenant, string $from, string $to): array
    {
        $get = ['unit_id' => $this->unit, 'tenant_id' => $tenant, 'date_from' => $from, 'date_to' => $to];
        [$page]    = $this->callPage('payments/history.php', $get);
        [$preview] = $this->callPage('payments/soa_pdf.php', $get);
        $this->assertArrayHasKey('soa', $page, 'SoA page rendered');
        $this->assertArrayHasKey('soa_preview', $preview, 'preview rendered');
        return [$page['soa'], $preview['soa_preview']];
    }

    #[Test]
    public function partial_range_statements_carry_the_balance_forward(): void
    {
        $t = $this->tenant('Forward Tenant', '2025-12-05');
        $this->pay((int) $t['id'], 1, '2026-01-06');
        $feb = $this->pay((int) $t['id'], 2, '2026-02-06');
        $this->pay((int) $t['id'], 4, '2026-03-06');                 // tagged to the wrong month
        $this->charge((int) $t['id'], '500.00', '2026-02-10');       // never paid
        $this->waive((int) $t['id'], 4, '1000.00');
        self::$db->prepare("INSERT INTO refunds (payment_id, amount, reason, refunded_by, refunded_at) VALUES (?, 200, 'test', 1, '2026-02-20 10:00:00')")
            ->execute([$feb]);

        $truth = tenantStatement(self::$db, $t, self::AS_OF)['balance'];
        $this->assertSame('17700.00', $truth, 'fixture: 6 bills − 3 paid + 200 refunded − 1000 waived + 500 water');

        foreach (['2025-12-05', '2026-02-15', '2026-04-01', '2026-06-06'] as $from) {
            [$page, $preview] = $this->balances((string) $t['id'], $from, self::AS_OF);
            $this->assertSame($truth, $page['balance'], "SoA page from $from");
            $this->assertSame($truth, $preview['balance'], "preview from $from");
            $opening = array_values(array_filter($page['rows'], fn($r) => $r['type'] === 'opening'));
            $this->assertCount($from === '2025-12-05' ? 0 : 1, $opening, "brought-forward line from $from");
        }
    }

    #[Test]
    public function unit_ledger_carries_each_occupancy_forward_separately(): void
    {
        $a = $this->tenant('Outgoing', '2025-12-05', '2026-03-04', 'former');
        $b = $this->tenant('Incoming', '2026-03-05');
        $this->pay((int) $a['id'], 1, '2026-02-10');                  // A leaves owing Feb + Mar; Jan unpaid before Feb 1
        $this->pay((int) $b['id'], 4, '2026-04-06');
        $ta = tenantStatement(self::$db, $a, self::AS_OF)['balance'];
        $tb = tenantStatement(self::$db, $b, self::AS_OF)['balance'];

        [$page, $preview] = $this->balances('all', '2026-02-01', self::AS_OF);
        $this->assertSame(money_add($ta, $tb), $page['balance']);
        $this->assertSame(money_add($ta, $tb), $preview['balance']);
        $openings = array_values(array_filter($page['rows'], fn($r) => $r['type'] === 'opening'));
        $this->assertSame([(int) $a['id']], array_column($openings, 'owner'), 'only the outgoing tenant had history before Feb');

        // A range after the handover is the incoming tenancy alone.
        [$page] = $this->balances('all', '2026-04-01', self::AS_OF);
        $this->assertSame($tb, $page['balance']);
    }

    #[Test]
    public function final_bill_waiver_survives_a_range_ending_before_its_period(): void
    {
        // Moved out Mar 20: the April bill (cycle Mar 5 – Apr 4) is dated Mar 21.
        $t = $this->tenant('Leaver', '2026-03-05', '2026-03-20', 'former');
        $this->waive((int) $t['id'], 4, '4000.00');   // capped at the prorated bill
        [$page, $preview] = $this->balances((string) $t['id'], '2026-03-05', '2026-03-31');
        $this->assertSame('0.00', $page['balance'], 'the waiver offsets the final bill');
        $this->assertSame('0.00', $preview['balance']);
        $this->assertSame('0.00', tenantStatement(self::$db, $t, '2026-03-31')['balance']);
    }

    #[Test]
    public function receivables_are_statement_balances_and_ignore_future_charges(): void
    {
        $t = $this->tenant('Owing Tenant', '2025-12-05');
        $this->pay((int) $t['id'], 1, '2026-01-06');
        $owed = tenantStatement(self::$db, $t, date('Y-m-d'))['balance'];
        $this->assertSame([$this->unit => $owed], getCurrentTenantArrears(self::$db, $this->unit));

        $this->charge((int) $t['id'], '750.00', date('Y-m-d', strtotime('+20 days')));
        $this->assertSame([$this->unit => $owed], getCurrentTenantArrears(self::$db, $this->unit),
            'a charge dated after today is not owed yet');
    }

    #[Test]
    public function a_charge_posted_without_a_tenant_is_attributed_to_the_occupant(): void
    {
        $t = $this->tenant('Occupant', '2025-12-05');
        [$json] = $this->callApiAction(['action' => 'save_charge', 'unit_id' => $this->unit, 'amount' => '300',
                                        'description' => 'Repair', 'charge_date' => '2026-05-10', 'period_month' => 5, 'period_year' => 2026]);
        $this->assertTrue($json['success'] ?? false, json_encode($json));
        $this->assertSame((string) $t['id'], (string) self::$db->query("SELECT tenant_id FROM unit_charges")->fetchColumn());
    }

    #[Test]
    public function preview_without_dates_opens_on_the_tenancy(): void
    {
        $t = $this->tenant('Since December', '2025-12-05');
        [$page]    = $this->callPage('payments/history.php', ['unit_id' => $this->unit, 'tenant_id' => $t['id']]);
        [$preview] = $this->callPage('payments/soa_pdf.php', ['unit_id' => $this->unit, 'tenant_id' => $t['id']]);
        $this->assertSame('2025-12-05', $page['soa']['from']);
        $this->assertSame('2025-12-05', $preview['soa_preview']['from']);
        $this->assertSame($page['soa']['balance'], $preview['soa_preview']['balance']);
    }

    #[Test]
    public function charts_count_general_and_uncategorized_spending(): void
    {
        self::$db->prepare("INSERT INTO expenses (unit_id, category_id, amount, expense_date, description, recorded_by) VALUES (NULL, NULL, 1234.50, '2026-05-10', 'Gate', 1)")->execute();
        self::$db->prepare("INSERT INTO expenses (unit_id, category_id, amount, expense_date, description, recorded_by) VALUES (?, NULL, 100, '2026-05-11', 'Bulb', 1)")->execute([$this->unit]);
        [, $u] = $this->callPage('api/unit_chart_api.php', ['period_type' => 'year', 'year' => 2026]);
        [, $c] = $this->callPage('api/cat_chart_api.php', ['period_type' => 'year', 'year' => 2026]);
        $u = json_decode($u, true); $c = json_decode($c, true);
        $this->assertEqualsWithDelta(1334.50, $u['totalExp'], 0.001);
        $this->assertContains('General', $u['labels']);
        $this->assertEqualsWithDelta(1334.50, $c['total'], 0.001);
        $this->assertSame(['Uncategorized'], $c['labels']);
    }
}
