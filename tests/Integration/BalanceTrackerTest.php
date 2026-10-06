<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Dashboard Balance Tracker (config/balance_functions.php) and demand letters
 * (admin/demand_letter.php), against the isolated laskie_test database.
 *
 * What these pin:
 *   - only ACTIVE tenancies are listed; former tenants are left out
 *   - balance = rent billed (post-paid, due by the as-of date) − rent paid
 *     + unpaid service charges; months behind = rent owed ÷ current rent
 *   - "needs intervention" starts at exactly 2 months of rent owed
 *   - unpaid bills are itemised FIFO, so they sum to the rent owed even when a
 *     payment was tagged to the wrong month
 *   - the last payment ignores non-cash transfer entries
 *   - a demand letter is admin-only, numbered DL-YYYY-#### without reuse,
 *     recorded with what it demanded, refused when nothing is owed
 */
final class BalanceTrackerTest extends IsolatedDbTestCase
{
    private int $unit = 0;
    private int $tenant = 0;
    private const AS_OF = '2026-10-05';

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
        $this->truncate(['demand_letters', 'charge_payments', 'refunds', 'cash_transactions', 'rent_charge_voids',
                         'unit_charges', 'payments', 'tenant_transfers', 'contracts', 'tenants']);
        self::$db->exec("DELETE FROM settings WHERE setting_key LIKE 'demand_seq_%'");
        self::$db->exec("INSERT INTO rental_units (unit_name, monthly_rate, due_day, status) VALUES ('BT-A', 6000, 5, 'occupied')");
        $this->unit = (int) self::$db->lastInsertId();
        // Post-paid: bills Jan..Oct 2026 (Dec 5 – Oct 4) are due by Oct 5 → 10 × ₱6,000.
        self::$db->prepare("INSERT INTO tenants (unit_id, full_name, contract_start, status) VALUES (?, 'Balance Tenant', '2025-12-05', 'active')")
            ->execute([$this->unit]);
        $this->tenant = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(['demand_letters', 'unit_charges', 'payments', 'tenants']);
        self::$db->prepare("DELETE FROM rental_units WHERE id=?")->execute([$this->unit]);
    }

    private function pay(int $month, string $amount = '6000.00', ?string $date = null, ?int $transferId = null): void
    {
        self::$db->prepare("INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount, period_month, period_year, payment_date, received_by, status, transfer_id)
                            VALUES (?, ?, ?, 'rent', ?, ?, 2026, ?, 1, 'paid', ?)")
            ->execute(['BT-' . bin2hex(random_bytes(4)), $this->unit, $this->tenant, $amount, $month, $date ?? sprintf('2026-%02d-06', $month), $transferId]);
    }

    private function statement(): array
    {
        $rows = activeTenantBalances(self::$db, self::AS_OF);
        $this->assertCount(1, $rows);
        return $rows[0]['st'];
    }

    private function issue(array $post, ?array $user = null): array
    {
        return $this->callScript('admin/demand_letter.php', $post + ['tenant_id' => (string) $this->tenant], $user, ['REQUEST_METHOD' => 'POST']);
    }

    #[Test]
    public function only_active_tenants_are_listed(): void
    {
        self::$db->prepare("INSERT INTO tenants (unit_id, full_name, contract_start, contract_end, status) VALUES (?, 'Gone', '2025-01-05', '2025-12-04', 'former')")
            ->execute([$this->unit]);
        $names = array_map(fn($r) => $r['tenant']['full_name'], activeTenantBalances(self::$db, self::AS_OF));
        $this->assertSame(['Balance Tenant'], $names);
    }

    #[Test]
    public function balance_months_behind_and_charges(): void
    {
        for ($m = 1; $m <= 8; $m++) $this->pay($m);    // September and October bills unpaid
        self::$db->prepare("INSERT INTO unit_charges (unit_id, tenant_id, amount, description, charge_date, period_month, period_year, source)
                            VALUES (?, ?, 500, 'Internet', '2026-09-01', 9, 2026, 'pre_billed')")->execute([$this->unit, $this->tenant]);
        $st = $this->statement();
        $this->assertSame('12000.00', $st['rent_owed']);
        $this->assertSame('500.00', $st['svc_owed']);
        $this->assertSame('12500.00', $st['balance']);
        $this->assertSame(2.0, $st['months_behind']);
        $this->assertTrue($st['flagged'], 'exactly two months of rent owed is flagged');
        $this->assertSame(['September 2026', 'October 2026'], array_column($st['bills'], 'period'));
    }

    #[Test]
    public function under_two_months_is_not_flagged_and_a_credit_is_negative(): void
    {
        for ($m = 1; $m <= 8; $m++) $this->pay($m);
        $this->pay(9, '5999.99');
        $st = $this->statement();
        $this->assertSame('6000.01', $st['rent_owed']);
        $this->assertFalse($st['flagged']);

        $this->pay(10, '6000.00'); $this->pay(11, '6000.00');   // paid November ahead
        $st = $this->statement();
        $this->assertSame('-5999.99', $st['balance']);
        $this->assertSame([], $st['bills']);
    }

    #[Test]
    public function unpaid_bills_are_itemised_oldest_first_regardless_of_payment_tags(): void
    {
        // Nine payments, one mis-tagged to October instead of August.
        foreach ([1, 2, 3, 4, 5, 6, 7, 9, 10] as $m) $this->pay($m);
        $st = $this->statement();
        $this->assertSame('6000.00', $st['rent_owed']);
        $this->assertSame(['October 2026'], array_column($st['bills'], 'period'), 'FIFO: the newest bill is the unpaid one');
        $this->assertSame('6000.00', $st['bills'][0]['unpaid']);
    }

    private function charge(string $amount, string $description, ?string $serviceType = null, string $date = '2026-01-01'): int
    {
        $stId = $serviceType === null ? null
            : (int) self::$db->query("SELECT id FROM service_types WHERE name=" . self::$db->quote($serviceType))->fetchColumn();
        self::$db->prepare("INSERT INTO unit_charges (unit_id, tenant_id, service_type_id, amount, description, charge_date, period_month, period_year, source)
                            VALUES (?, ?, ?, ?, ?, ?, 1, 2026, 'pre_billed')")
            ->execute([$this->unit, $this->tenant, $stId, $amount, $description, $date]);
        return (int) self::$db->lastInsertId();
    }

    #[Test]
    public function opening_and_transfer_arrears_count_as_rent_behind(): void
    {
        // Rent itself is one bill behind; the 2025 opening balance and a
        // transfer's Arrears charge are unpaid rent too, so 3 months in all.
        for ($m = 1; $m <= 9; $m++) $this->pay($m);
        $this->charge('7000.00', 'Balance brought forward (2025 arrears)');
        $this->charge('5000.00', 'Rent balance carried from BT-Z', 'Arrears', '2026-10-05');
        $this->charge('500.00', 'Internet', null, '2026-09-01');           // untyped but not arrears → still a charge
        $st = $this->statement();
        $this->assertSame('12000.00', $st['arrears_owed']);
        $this->assertSame('18000.00', $st['rent_owed']);
        $this->assertSame('500.00', $st['svc_owed']);
        $this->assertSame('18500.00', $st['balance']);
        $this->assertSame(3.0, $st['months_behind']);
        $this->assertTrue($st['flagged']);
        $this->assertSame(['Balance brought forward (2025 arrears)', 'Rent balance carried from BT-Z', 'October 2026'],
                          array_column($st['bills'], 'period'), 'oldest first; an arrears charge precedes a same-day bill');
        $this->assertSame(['Internet'], array_column($st['charges'], 'description'));
        $this->assertSame($st['rent_owed'], money_sum(array_column($st['bills'], 'unpaid')));
    }

    #[Test]
    public function arrears_charge_counts_only_its_unpaid_part_and_absorbs_a_rent_credit(): void
    {
        for ($m = 1; $m <= 10; $m++) $this->pay($m);                       // rent fully paid
        $id = $this->charge('7000.00', 'Balance brought forward (2025 arrears)');
        self::$db->prepare("INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount, period_month, period_year, payment_date, received_by, status)
                            VALUES ('BT-SVC', ?, ?, 'service', 2000, 1, 2026, '2026-03-01', 1, 'paid')")->execute([$this->unit, $this->tenant]);
        self::$db->prepare("INSERT INTO charge_payments (charge_id, payment_id, amount) VALUES (?, ?, 2000)")
            ->execute([$id, (int) self::$db->lastInsertId()]);
        $st = $this->statement();
        $this->assertSame('5000.00', $st['rent_owed']);
        $this->assertSame([['Balance brought forward (2025 arrears)', '7000.00', '5000.00']],
                          array_map(fn($b) => [$b['period'], $b['amount'], $b['unpaid']], $st['bills']));
        $this->assertFalse($st['flagged']);

        $this->pay(11, '3000.00');                                          // ₱3,000 rent paid ahead
        $st = $this->statement();
        $this->assertSame('2000.00', $st['rent_owed']);
        $this->assertSame('2000.00', $st['bills'][0]['unpaid'], 'a rent credit reduces the itemised arrears too');
    }

    #[Test]
    public function last_payment_ignores_non_cash_transfer_entries(): void
    {
        $this->pay(1, '6000.00', '2026-01-06');
        $this->pay(2, '6000.00', '2026-02-20');
        $this->pay(3, '1234.00', '2026-03-01', 99);          // non-cash transfer credit
        $st = $this->statement();
        $this->assertSame(['6000.00', '2026-02-20'], [$st['last_payment']['amount'], $st['last_payment']['payment_date']]);
    }

    #[Test]
    public function demand_letter_is_numbered_recorded_and_returned_as_pdf(): void
    {
        for ($m = 1; $m <= 7; $m++) $this->pay($m);    // three months behind
        [, $raw] = $this->issue(['letter_type' => 'vacate', 'deadline_days' => '15']);
        $this->assertStringStartsWith('%PDF', $raw);

        $d = self::$db->query("SELECT * FROM demand_letters")->fetch();
        $this->assertSame('DL-' . date('Y') . '-0001', $d['ref_no']);
        $this->assertSame(['vacate', '15', (string) $this->tenant], [$d['letter_type'], (string) $d['deadline_days'], (string) $d['tenant_id']]);
        $snap = json_decode($d['snapshot_json'], true);
        $this->assertSame($d['amount_due'], $snap['total']);
        $this->assertSame('Final Demand to Pay and Vacate', $snap['title']);

        [, $raw2] = $this->issue(['letter_type' => 'pay', 'deadline_days' => '15']);
        $this->assertStringStartsWith('%PDF', $raw2);
        $this->assertSame('DL-' . date('Y') . '-0002', self::$db->query("SELECT ref_no FROM demand_letters ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    #[Test]
    public function demand_letter_guards(): void
    {
        // Nothing owed (all bills due by today paid) → refused.
        for ($m = 1; $m <= 12; $m++) $this->pay($m);
        [, $raw] = $this->issue(['letter_type' => 'pay', 'deadline_days' => '15']);
        $this->assertStringContainsString('nothing to demand', $raw);

        // Bad deadline / type.
        [, $raw] = $this->issue(['letter_type' => 'pay', 'deadline_days' => '3']);
        $this->assertStringContainsString('between 5 and 60 days', $raw);
        [, $raw] = $this->issue(['letter_type' => 'evict', 'deadline_days' => '15']);
        $this->assertStringContainsString('Choose the type', $raw);

        // Non-admins are refused.
        [$json] = $this->issue(['letter_type' => 'pay', 'deadline_days' => '15'],
                               ['id' => 1, 'username' => 'acct', 'full_name' => 'Accountant', 'role' => 'accountant']);
        $this->assertFalse($json['success'] ?? true);
        $this->assertSame('0', (string) self::$db->query("SELECT COUNT(*) FROM demand_letters")->fetchColumn());
    }
}
