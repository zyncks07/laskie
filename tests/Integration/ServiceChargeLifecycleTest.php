<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Regression cover for the phantom-charge fix in payments/api_payment.php.
 *
 * A service payment always leaves a unit_charges row behind, but the two
 * sources have opposite lifecycles and that asymmetry is easy to break:
 *
 *   pre_billed     — the bill existed BEFORE anyone paid. Voiding/deleting the
 *                    payment must only release the link (payment_id = NULL) so
 *                    the charge goes back to being outstanding.
 *   auto_collected — the row was conjured by save_payment and has no
 *                    pre-existing counterpart. Voiding/deleting the payment
 *                    must DELETE it; leaving it behind produces a phantom
 *                    "Unpaid" charge that inflates the outstanding balance.
 *
 * The invariant, stated once: an auto_collected charge exists if and only if
 * its payment is live. Every test below is a corner of that.
 *
 * ISOLATION — runs against `laskie_test` only; self-skips when absent.
 * See IsolatedDbTestCase.
 */
final class ServiceChargeLifecycleTest extends IsolatedDbTestCase
{
    private const MONEY_TABLES = [
        'rent_charge_voids', 'payments', 'cash_transactions',
        'unit_charges', 'refunds', 'rental_units', 'tenants', 'system_logs',
    ];

    private const AMOUNT = '1200.00';

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

        self::$pdo->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-SVC-UNIT', 5000.00, 5, 'occupied')"
        );
        $this->unitId = (int) self::$pdo->lastInsertId();

        $ins = self::$pdo->prepare(
            "INSERT INTO tenants (full_name, unit_id, monthly_rate, contract_start, status)
             VALUES ('Service Test Tenant', ?, 5000.00, ?, 'active')"
        );
        $ins->execute([$this->unitId, date('Y-m-d', strtotime('-2 years'))]);
        $this->tenantId = (int) self::$pdo->lastInsertId();

        // install.sql seeds the lookup table; pick a real service type rather
        // than assuming an id.
        $this->serviceId = (int) self::$pdo->query(
            "SELECT id FROM service_types WHERE name='Prepaid Internet' LIMIT 1"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$pdo) return;
        $this->truncate(self::MONEY_TABLES);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** Record a service payment through the real save_payment handler. */
    private function recordServicePayment(string $notes = 'internet'): int
    {
        [$res] = $this->callApiAction([
            'action'          => 'save_payment',
            'unit_id'         => (string) $this->unitId,
            'tenant_id'       => (string) $this->tenantId,
            'payment_type'    => 'service',
            'service_type_id' => (string) $this->serviceId,
            'amount'          => self::AMOUNT,
            'payment_date'    => sprintf('%04d-%02d-05', $this->year, $this->month),
            'period_month'    => (string) $this->month,
            'period_year'     => (string) $this->year,
            'notes'           => $notes,
        ]);
        $this->assertTrue($res['success'] ?? false, 'save_payment failed: ' . ($res['error'] ?? '?'));
        return (int) $res['id'];
    }

    /** Pre-bill a charge the way the collection page's "Add Service Charge" does. */
    private function preBillCharge(): int
    {
        [$res] = $this->callApiAction([
            'action'          => 'save_charge',
            'unit_id'         => (string) $this->unitId,
            'tenant_id'       => (string) $this->tenantId,
            'service_type_id' => (string) $this->serviceId,
            'amount'          => self::AMOUNT,
            'description'     => 'Prepaid Internet',
            'charge_date'     => sprintf('%04d-%02d-05', $this->year, $this->month),
            'period_month'    => (string) $this->month,
            'period_year'     => (string) $this->year,
        ]);
        $this->assertTrue($res['success'] ?? false, 'save_charge failed: ' . ($res['error'] ?? '?'));
        return (int) $res['id'];
    }

    /** @return array<int, array<string, mixed>> every charge row on the test unit */
    private function charges(): array
    {
        $q = self::$pdo->prepare('SELECT * FROM unit_charges WHERE unit_id=? ORDER BY id');
        $q->execute([$this->unitId]);
        return $q->fetchAll();
    }

    private function receivedCashRows(int $paymentId): int
    {
        $q = self::$pdo->prepare(
            "SELECT COUNT(*) FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'"
        );
        $q->execute([$paymentId]);
        return (int) $q->fetchColumn();
    }

    /**
     * What the collection grid would show as outstanding for the period — the
     * figure a phantom charge silently inflates.
     */
    private function outstandingPerGrid(): string
    {
        [$res] = $this->callApiAction([
            'action' => 'monthly_summary',
            'month'  => (string) $this->month,
            'year'   => (string) $this->year,
        ]);
        $this->assertTrue($res['success'] ?? false, 'monthly_summary failed: ' . ($res['error'] ?? '?'));
        foreach ($res['summary'] as $row) {
            if ((int) $row['id'] === $this->unitId) {
                return number_format((float) $row['outstanding_charges'], 2, '.', '');
            }
        }
        $this->fail('test unit missing from monthly_summary');
    }

    // ── auto_collected: created with the payment ─────────────────

    #[Test]
    public function collecting_an_unbilled_service_creates_one_auto_collected_charge(): void
    {
        $paymentId = $this->recordServicePayment();

        $charges = $this->charges();
        $this->assertCount(1, $charges);
        $this->assertSame('auto_collected', $charges[0]['source']);
        $this->assertSame($paymentId, (int) $charges[0]['payment_id']);
        $this->assertSame(self::AMOUNT, $charges[0]['amount']);
        $this->assertSame('0.00', $this->outstandingPerGrid(), 'a paid charge is not outstanding');
    }

    // ── auto_collected: void / restore ───────────────────────────

    #[Test]
    public function voiding_deletes_the_auto_collected_charge_leaving_no_phantom(): void
    {
        $paymentId = $this->recordServicePayment();

        [$res] = $this->callApiAction(['action' => 'void_payment', 'id' => (string) $paymentId]);
        $this->assertTrue($res['success'] ?? false, 'void failed: ' . ($res['error'] ?? '?'));

        $this->assertSame([], $this->charges(),
            'the auto_collected row must go with its payment — otherwise it reappears as a phantom "Unpaid" charge');
        $this->assertSame('0.00', $this->outstandingPerGrid(),
            'a voided service payment must not leave an outstanding balance behind');
        $this->assertSame(0, $this->receivedCashRows($paymentId), 'the received cash row must go too');
    }

    #[Test]
    public function restoring_a_voided_payment_recreates_exactly_one_auto_collected_charge(): void
    {
        $paymentId = $this->recordServicePayment('internet — feb');
        $this->callApiAction(['action' => 'void_payment', 'id' => (string) $paymentId]);

        [$res] = $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $paymentId]);
        $this->assertTrue($res['success'] ?? false, 'restore failed: ' . ($res['error'] ?? '?'));

        $charges = $this->charges();
        $this->assertCount(1, $charges, 'restore must not double up the charge');
        $this->assertSame('auto_collected', $charges[0]['source']);
        $this->assertSame($paymentId, (int) $charges[0]['payment_id']);
        $this->assertSame(self::AMOUNT, $charges[0]['amount']);
        $this->assertSame('internet — feb', $charges[0]['description'], 'the payment note names the recreated charge');
        $this->assertSame(1, $this->receivedCashRows($paymentId), 'exactly one received cash row after restore');
        $this->assertSame('0.00', $this->outstandingPerGrid());
    }

    #[Test]
    public function repeated_void_restore_cycles_never_accumulate_charges(): void
    {
        $paymentId = $this->recordServicePayment();

        for ($i = 0; $i < 3; $i++) {
            $this->callApiAction(['action' => 'void_payment',    'id' => (string) $paymentId]);
            $this->assertSame([], $this->charges(), "charge survived void on cycle {$i}");
            $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $paymentId]);
            $this->assertCount(1, $this->charges(), "duplicate charge after restore on cycle {$i}");
        }

        $this->assertSame(1, $this->receivedCashRows($paymentId), 'cash rows must not accumulate either');
    }

    // ── auto_collected: soft delete / restore / purge ────────────

    #[Test]
    public function soft_deleting_removes_the_auto_collected_charge(): void
    {
        $paymentId = $this->recordServicePayment();

        [$res] = $this->callApiAction(['action' => 'delete_payment', 'id' => (string) $paymentId]);
        $this->assertTrue($res['success'] ?? false, 'delete failed: ' . ($res['error'] ?? '?'));

        $this->assertSame([], $this->charges());
        $this->assertSame('0.00', $this->outstandingPerGrid(),
            'a trashed service payment must not leave an outstanding balance behind');
    }

    #[Test]
    public function restoring_from_trash_recreates_the_auto_collected_charge(): void
    {
        $paymentId = $this->recordServicePayment();
        $this->callApiAction(['action' => 'delete_payment', 'id' => (string) $paymentId]);

        [$res] = $this->callApiAction(['action' => 'restore_deleted_payment', 'id' => (string) $paymentId]);
        $this->assertTrue($res['success'] ?? false, 'restore failed: ' . ($res['error'] ?? '?'));

        $charges = $this->charges();
        $this->assertCount(1, $charges);
        $this->assertSame('auto_collected', $charges[0]['source']);
        $this->assertSame($paymentId, (int) $charges[0]['payment_id']);
        $this->assertSame(1, $this->receivedCashRows($paymentId));
    }

    #[Test]
    public function purging_from_trash_leaves_no_orphan_charge(): void
    {
        $paymentId = $this->recordServicePayment();
        $this->callApiAction(['action' => 'delete_payment', 'id' => (string) $paymentId]);

        [$res] = $this->callApiAction(['action' => 'purge_payment', 'id' => (string) $paymentId]);
        $this->assertTrue($res['success'] ?? false, 'purge failed: ' . ($res['error'] ?? '?'));

        $this->assertSame([], $this->charges());
        $this->assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    // ── pre_billed: the opposite lifecycle ───────────────────────

    #[Test]
    public function collecting_a_pre_billed_charge_links_it_instead_of_creating_a_second_row(): void
    {
        $chargeId  = $this->preBillCharge();
        $this->assertSame(self::AMOUNT, $this->outstandingPerGrid(), 'an unpaid pre-billed charge is outstanding');

        $paymentId = $this->recordServicePayment();

        $charges = $this->charges();
        $this->assertCount(1, $charges, 'paying a pre-billed charge must not create a duplicate');
        $this->assertSame($chargeId, (int) $charges[0]['id']);
        $this->assertSame('pre_billed', $charges[0]['source']);
        $this->assertSame($paymentId, (int) $charges[0]['payment_id']);
        $this->assertSame('0.00', $this->outstandingPerGrid());
    }

    #[Test]
    public function voiding_releases_a_pre_billed_charge_back_to_outstanding(): void
    {
        $chargeId  = $this->preBillCharge();
        $paymentId = $this->recordServicePayment();

        $this->callApiAction(['action' => 'void_payment', 'id' => (string) $paymentId]);

        $charges = $this->charges();
        $this->assertCount(1, $charges, 'a pre-billed charge existed before the payment and must survive it');
        $this->assertSame($chargeId, (int) $charges[0]['id']);
        $this->assertNull($charges[0]['payment_id'], 'the link must be released');
        $this->assertSame(self::AMOUNT, $this->outstandingPerGrid(), 'the bill is owed again');
    }

    #[Test]
    public function restoring_relinks_the_same_pre_billed_row(): void
    {
        $chargeId  = $this->preBillCharge();
        $paymentId = $this->recordServicePayment();
        $this->callApiAction(['action' => 'void_payment',    'id' => (string) $paymentId]);
        $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $paymentId]);

        $charges = $this->charges();
        $this->assertCount(1, $charges, 'restore must reuse the pre-billed row, not add an auto_collected one');
        $this->assertSame($chargeId, (int) $charges[0]['id']);
        $this->assertSame('pre_billed', $charges[0]['source']);
        $this->assertSame($paymentId, (int) $charges[0]['payment_id']);
        $this->assertSame('0.00', $this->outstandingPerGrid());
    }

    #[Test]
    public function a_voided_charge_is_not_recollected_when_a_payment_is_restored(): void
    {
        // An admin waives the outstanding bill, then restores an unrelated
        // voided payment for the same unit/period. The waived row is settled and
        // must not be picked up as the link target — that would silently
        // un-waive it. save_payment/restore only look for non-voided rows.
        $chargeId  = $this->preBillCharge();
        $paymentId = $this->recordServicePayment();
        $this->callApiAction(['action' => 'void_payment', 'id' => (string) $paymentId]);
        $this->callApiAction(['action' => 'delete_charge', 'id' => (string) $chargeId, 'reason' => 'written off']);

        $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $paymentId]);

        $rows   = $this->charges();
        $waived = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $chargeId));
        $this->assertCount(1, $waived);
        $this->assertNotNull($waived[0]['voided_at'], 'the waived charge must stay waived');
        $this->assertNull($waived[0]['payment_id'], 'a waived charge must not be relinked to the restored payment');

        // The payment still needs a charge of its own, so a fresh
        // auto_collected row is created rather than the waived one reused.
        $linked = array_values(array_filter($rows, fn($r) => (int) $r['payment_id'] === $paymentId));
        $this->assertCount(1, $linked);
        $this->assertSame('auto_collected', $linked[0]['source']);
        $this->assertSame(self::AMOUNT, $linked[0]['amount']);

        // Waived charge settled by its credit line + one paid charge = nothing owed.
        $this->assertSame('0.00', $this->outstandingPerGrid());
    }
}
