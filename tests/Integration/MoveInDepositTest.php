<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Money collected from a new tenant BEFORE they move in — a security deposit or
 * advance rent handed over ahead of the first post-paid bill (2026-10-17).
 *
 * The tenant is assigned to their unit with a future contract_start; the
 * Tenants page's "Collect payment" button opens Record Payment on the
 * Collection page (?collect_tenant=ID) for them. Pins:
 *   • the opener only fires for an active tenant that has a unit
 *   • get_unit_tenants flags status, so the active tenant is preselected over
 *     the unit's former tenant
 *   • a deposit dated before move-in is a normal service payment: cash row,
 *     zero balance, and the default SoA range starts early enough to list it
 *
 * ISOLATION — runs against `laskie_test` only; self-skips when absent.
 */
final class MoveInDepositTest extends IsolatedDbTestCase
{
    private const TABLES = ['charge_payments', 'refunds', 'cash_transactions', 'rent_charge_voids',
                            'unit_charges', 'payments', 'tenants', 'system_logs'];

    private int $unit = 0;
    private int $former = 0;
    private int $newcomer = 0;
    private int $unassigned = 0;
    private string $moveIn = '';

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
        $this->truncate(self::TABLES);
        self::$db->exec("INSERT INTO rental_units (unit_name, monthly_rate, due_day, status) VALUES ('MI-K', 7000, 5, 'occupied')");
        $this->unit   = (int) self::$db->lastInsertId();
        $this->moveIn = date('Y-m-d', strtotime('+2 days'));
        $ins = self::$db->prepare("INSERT INTO tenants (unit_id, full_name, contract_start, contract_end, status) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$this->unit, 'Former Tenant', '2025-12-05', date('Y-m-d', strtotime('-10 days')), 'former']);
        $this->former = (int) self::$db->lastInsertId();
        $ins->execute([$this->unit, 'New Tenant', $this->moveIn, date('Y-m-d', strtotime('+1 year')), 'active']);
        $this->newcomer = (int) self::$db->lastInsertId();
        $ins->execute([null, 'No Unit Yet', $this->moveIn, null, 'active']);
        $this->unassigned = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(self::TABLES);
        self::$db->prepare("DELETE FROM rental_units WHERE id=?")->execute([$this->unit]);
    }

    #[Test]
    public function collect_link_opens_record_payment_for_an_assigned_tenant_only(): void
    {
        [, $html] = $this->callPage('payments/collection.php', ['collect_tenant' => (string) $this->newcomer]);
        $this->assertStringContainsString("openPaymentForTenant({$this->unit}, {$this->newcomer});", $html);

        [, $html] = $this->callPage('payments/collection.php', ['collect_tenant' => (string) $this->unassigned]);
        $this->assertStringNotContainsString('openPaymentForTenant(' . $this->unit, $html);
        $this->assertStringNotContainsString("openPaymentForTenant(0", $html);
    }

    #[Test]
    public function unit_tenant_list_flags_who_is_active(): void
    {
        [$res] = $this->callApiAction(['action' => 'get_unit_tenants', 'unit_id' => (string) $this->unit]);
        $this->assertTrue($res['success'] ?? false);
        $byId = array_column($res['tenants'], 'status', 'id');
        $this->assertSame('active', $byId[$this->newcomer]);
        $this->assertSame('former', $byId[$this->former]);
    }

    #[Test]
    public function deposit_before_move_in_is_cash_on_hand_and_on_the_default_statement(): void
    {
        $depositId = (int) self::$db->query("SELECT id FROM service_types WHERE name='Security Deposit'")->fetchColumn();
        $today = date('Y-m-d');
        [$res, $raw] = $this->callApiAction([
            'action' => 'save_payment', 'unit_id' => (string) $this->unit, 'tenant_id' => (string) $this->newcomer,
            'payment_type' => 'service', 'service_type_id' => (string) $depositId, 'amount' => '14000.00',
            'payment_date' => $today, 'period_month' => date('n'), 'period_year' => date('Y'),
            'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
        $this->assertTrue($res['success'] ?? false, $raw);
        $pid = (int) $res['id'];

        $p = self::$db->query("SELECT tenant_id, invoice_no FROM payments WHERE id=$pid")->fetch();
        $this->assertSame($this->newcomer, (int) $p['tenant_id']);
        $cash = self::$db->query("SELECT amount FROM cash_transactions WHERE reference_payment_id=$pid AND transaction_type='received'")->fetchColumn();
        $this->assertSame('14000.00', $cash);

        $t = self::$db->query("SELECT t.*, u.due_day, u.monthly_rate AS unit_rate FROM tenants t JOIN rental_units u ON u.id=t.unit_id WHERE t.id={$this->newcomer}")->fetch();
        $this->assertSame('0.00', tenantStatement(self::$db, $t)['balance']);

        // Default SoA range: from the deposit date, not the later move-in date.
        $this->assertSame($this->moveIn, soaDefaultFrom($t));
        $this->assertSame($today, soaDefaultFrom($t, self::$db));
        [, $html] = $this->callPage('payments/history.php', ['unit_id' => (string) $this->unit, 'tenant_id' => (string) $this->newcomer]);
        $this->assertStringContainsString($p['invoice_no'], $html);
    }
}
