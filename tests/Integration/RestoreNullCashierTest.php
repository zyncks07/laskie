<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Regression: restoring a voided payment whose original collector was deleted
 * (received_by SET NULL) used to crash — cash_transactions.user_id is NOT NULL.
 * restore_payment now falls back to the acting admin. callApiAction() runs as
 * admin id 1, so the re-created cash row must belong to user 1.
 */
final class RestoreNullCashierTest extends IntegrationTestCase
{
    private const TEST_UNIT_ID = 1;
    private int $tmpUserId = 0;
    private int $payId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status)
             VALUES (?,?,?, 'staff', 'active')"
        )->execute([
            'phpunit_collector_' . bin2hex(random_bytes(4)),
            password_hash('x', PASSWORD_BCRYPT),
            self::PHPUNIT_MARKER . ' Collector',
        ]);
        $this->tmpUserId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO payments
             (invoice_no, unit_id, payment_type, amount, period_month, period_year, payment_date, received_by, notes, status)
             VALUES (?,?, 'rent', '7.00', ?, ?, ?, ?, ?, 'paid')"
        )->execute([
            'INV-PHPUNIT-RNC-' . bin2hex(random_bytes(3)), self::TEST_UNIT_ID,
            (int) date('n'), (int) date('Y'), date('Y-m-d'),
            $this->tmpUserId, self::PHPUNIT_MARKER . ' — restore null cashier',
        ]);
        $this->payId = (int) $this->pdo->lastInsertId();

        // The collection's cash row (will be removed on void).
        $this->pdo->prepare(
            "INSERT INTO cash_transactions (user_id, transaction_type, amount, reference_payment_id, notes, transaction_date)
             VALUES (?, 'received', '7.00', ?, ?, ?)"
        )->execute([$this->tmpUserId, $this->payId, self::PHPUNIT_MARKER . ' — seed', date('Y-m-d')]);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=? OR user_id=?")
            ->execute([$this->payId, $this->tmpUserId]);
        $this->pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$this->payId]);
        $this->pdo->prepare("DELETE FROM users WHERE id=?")->execute([$this->tmpUserId]);
        parent::tearDown();
    }

    #[Test]
    public function restore_of_voided_payment_with_deleted_collector_attributes_cash_to_admin(): void
    {
        // Void it (drops the 'received' cash row), then delete the collector so
        // payments.received_by becomes NULL via ON DELETE SET NULL.
        [$void] = $this->callApiAction(['action' => 'void_payment', 'id' => (string) $this->payId]);
        $this->assertTrue($void['success'] ?? false, $void['error'] ?? 'void failed');

        $this->pdo->prepare("DELETE FROM users WHERE id=?")->execute([$this->tmpUserId]);
        $chk = $this->pdo->prepare("SELECT received_by FROM payments WHERE id=?");
        $chk->execute([$this->payId]);
        $this->assertNull($chk->fetchColumn(), 'received_by should be NULL after collector deletion');

        // Restore must NOT crash, and must re-create the cash row for the acting admin.
        [$restore] = $this->callApiAction(['action' => 'restore_payment', 'id' => (string) $this->payId]);
        $this->assertNotNull($restore, 'restore returned non-JSON (likely a fatal)');
        $this->assertTrue($restore['success'] ?? false, $restore['error'] ?? 'restore failed');

        $st = $this->pdo->prepare("SELECT status FROM payments WHERE id=?");
        $st->execute([$this->payId]);
        $this->assertSame('paid', $st->fetchColumn());

        $rc = $this->pdo->prepare(
            "SELECT user_id FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'"
        );
        $rc->execute([$this->payId]);
        $this->assertSame(1, (int) $rc->fetchColumn(), 're-created cash row must belong to the acting admin (id 1)');
    }
}
