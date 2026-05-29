<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Part B — refunds are hard-gated on the chosen cashier's cash-on-hand, and the
 * refund debits THAT cashier (admin picks who returns the cash).
 *
 * Self-contained: creates a throwaway staff user (zero cash) + a test payment,
 * and cleans both up in tearDown. callApiAction() runs as admin (id 1), which
 * is what process_refund requires.
 */
final class RefundCashGateTest extends IntegrationTestCase
{
    private const TEST_UNIT_ID = 1;
    private int $tmpUserId = 0;
    private int $payId = 0;
    private string $invoiceNo = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status)
             VALUES (?,?,?, 'staff', 'active')"
        )->execute([
            'phpunit_cashier_' . bin2hex(random_bytes(4)),
            password_hash('x', PASSWORD_BCRYPT),
            self::PHPUNIT_MARKER . ' Cashier',
        ]);
        $this->tmpUserId = (int) $this->pdo->lastInsertId();

        $this->invoiceNo = 'INV-PHPUNIT-RFG-' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            "INSERT INTO payments
             (invoice_no, unit_id, payment_type, amount, period_month, period_year, payment_date, received_by, notes, status)
             VALUES (?,?, 'rent', '100.00', ?, ?, ?, ?, ?, 'paid')"
        )->execute([
            $this->invoiceNo, self::TEST_UNIT_ID,
            (int) date('n'), (int) date('Y'), date('Y-m-d'),
            $this->tmpUserId, self::PHPUNIT_MARKER . ' — refund gate',
        ]);
        $this->payId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Dependency order: refunds → cash rows → payment → user.
        $this->pdo->prepare("DELETE FROM refunds WHERE payment_id=?")->execute([$this->payId]);
        $this->pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=? OR user_id=?")
            ->execute([$this->payId, $this->tmpUserId]);
        $this->pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$this->payId]);
        $this->pdo->prepare("DELETE FROM users WHERE id=?")->execute([$this->tmpUserId]);
        parent::tearDown();
    }

    private function refundsTotal(): string
    {
        $s = $this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE payment_id=?");
        $s->execute([$this->payId]);
        return (string) $s->fetchColumn();
    }

    #[Test]
    public function refund_is_blocked_when_chosen_cashier_has_insufficient_cash(): void
    {
        // tmp cashier has zero cash_transactions → 0.00 on hand.
        [$json] = $this->callApiAction([
            'action'     => 'process_refund',
            'payment_id' => (string) $this->payId,
            'amount'     => '50.00',
            'reason'     => 'gate test',
            'cashier_id' => (string) $this->tmpUserId,
        ]);

        $this->assertNotNull($json, 'response was not JSON');
        $this->assertFalse($json['success'] ?? true, 'refund should be blocked');
        $this->assertStringContainsStringIgnoringCase('cash on hand', $json['error'] ?? '');
        $this->assertSame('0.00', $this->refundsTotal(), 'no refund row may be written when blocked');
    }

    #[Test]
    public function refund_succeeds_and_debits_the_chosen_cashier(): void
    {
        // Fund the cashier with exactly the original payment (a normal collection).
        $this->pdo->prepare(
            "INSERT INTO cash_transactions (user_id, transaction_type, amount, reference_payment_id, notes, transaction_date)
             VALUES (?, 'received', '100.00', ?, ?, ?)"
        )->execute([$this->tmpUserId, $this->payId, self::PHPUNIT_MARKER . ' — seed', date('Y-m-d')]);

        $this->assertSame('100.00', getUserCashOnHand($this->pdo, $this->tmpUserId));

        [$json] = $this->callApiAction([
            'action'     => 'process_refund',
            'payment_id' => (string) $this->payId,
            'amount'     => '40.00',
            'reason'     => 'partial deposit return',
            'cashier_id' => (string) $this->tmpUserId,
        ]);

        $this->assertNotNull($json);
        $this->assertTrue($json['success'] ?? false, $json['error'] ?? 'refund failed');

        // Refund recorded, payment partially refunded.
        $this->assertSame('40.00', $this->refundsTotal());
        $st = $this->pdo->prepare("SELECT status FROM payments WHERE id=?");
        $st->execute([$this->payId]);
        $this->assertSame('partially_refunded', $st->fetchColumn());

        // The 'refunded' cash row is attributed to the chosen cashier.
        $rc = $this->pdo->prepare(
            "SELECT amount FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='refunded' AND user_id=?"
        );
        $rc->execute([$this->payId, $this->tmpUserId]);
        $this->assertSame('40.00', $rc->fetchColumn(), "refund cash row must belong to the chosen cashier");

        // Net cash-on-hand drops by the refund: 100 − 40 = 60.
        $this->assertSame('60.00', getUserCashOnHand($this->pdo, $this->tmpUserId));
    }
}
