<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Automated counterpart to the manual proof-of-payment test: records payments
 * through the REAL save_payment handler (payments/api_payment.php) and asserts
 * the receipt_path / receipt_url columns behave correctly.
 *
 * ISOLATION — runs entirely against the dedicated `laskie_test` schema and
 * self-skips when it is absent; see IsolatedDbTestCase for the harness and the
 * one-time setup command.
 *
 * Note on the uploaded-file path: PHP's move_uploaded_file() only accepts files
 * that arrived over an HTTP POST, so the literal file-bytes branch of
 * handleUpload() can't be exercised from a CLI test (the same limitation is why
 * the Expenses upload isn't integration-tested either). What IS covered here is
 * every line of NEW logic this feature added: the receipt columns on INSERT,
 * the two-branch edit UPDATE, and — crucially — that an edit without a new file
 * preserves an existing receipt_path. The latter seeds a receipt_path directly
 * (standing in for a prior upload) and verifies save_payment leaves it intact.
 */
final class PaymentReceiptTest extends IsolatedDbTestCase
{
    /** Tables this class rewrites between tests. */
    private const MONEY_TABLES = ['payments', 'cash_transactions', 'unit_charges', 'refunds', 'rental_units', 'tenants'];

    private int $unitId = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->truncate(self::MONEY_TABLES);
        $this->seedAdminUser();

        self::$pdo->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-UNIT-A', 5000.00, 5, 'occupied')"
        );
        $this->unitId = (int) self::$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$pdo) return;
        $this->truncate(self::MONEY_TABLES);
    }

    private function fetchPayment(int $id): array
    {
        $s = self::$pdo->prepare('SELECT * FROM payments WHERE id=?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    }

    #[Test]
    public function record_payment_with_external_url_persists_the_url(): void
    {
        [$res] = $this->callApiAction([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5000.00',
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'receipt url case',
            'receipt_url'  => 'https://drive.google.com/file/d/abc123/view',
        ]);

        $this->assertNotNull($res, 'No JSON returned from save_payment');
        $this->assertTrue($res['success'] ?? false, 'save_payment failed: ' . ($res['error'] ?? '?'));

        $row = $this->fetchPayment((int) $res['id']);
        $this->assertSame('https://drive.google.com/file/d/abc123/view', $row['receipt_url']);
        $this->assertNull($row['receipt_path'], 'receipt_path should be NULL when only a URL is given');

        // The payment itself must still be wired up normally (regression guard).
        $cash = self::$pdo->prepare("SELECT COUNT(*) FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'");
        $cash->execute([(int) $res['id']]);
        $this->assertSame(1, (int) $cash->fetchColumn(), 'received cash row missing');
    }

    #[Test]
    public function record_payment_without_receipt_leaves_both_columns_null(): void
    {
        [$res] = $this->callApiAction([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5000.00',
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'no receipt case',
        ]);

        $this->assertTrue($res['success'] ?? false, 'save_payment failed: ' . ($res['error'] ?? '?'));
        $row = $this->fetchPayment((int) $res['id']);
        $this->assertNull($row['receipt_path']);
        $this->assertNull($row['receipt_url']);
    }

    #[Test]
    public function edit_payment_updates_the_receipt_url(): void
    {
        [$create] = $this->callApiAction([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5000.00',
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'edit url case',
            'receipt_url'  => 'https://example.com/old.pdf',
        ]);
        $id = (int) $create['id'];

        [$edit] = $this->callApiAction([
            'action'       => 'save_payment',
            'id'           => (string) $id,
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5000.00',
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'edit url case',
            'receipt_url'  => 'https://example.com/new.pdf',
        ]);

        $this->assertTrue($edit['success'] ?? false, 'edit failed: ' . ($edit['error'] ?? '?'));
        $row = $this->fetchPayment($id);
        $this->assertSame('https://example.com/new.pdf', $row['receipt_url']);
    }

    #[Test]
    public function edit_without_new_file_preserves_existing_receipt_path(): void
    {
        // Create a payment, then stamp a receipt_path on it directly — standing
        // in for a file that was uploaded earlier (move_uploaded_file can't run
        // under CLI). This is the exact scenario the manual edit test covered.
        [$create] = $this->callApiAction([
            'action'       => 'save_payment',
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5000.00',
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'preserve path case',
        ]);
        $id = (int) $create['id'];

        $seeded = '/uploads/payments/20260603_seededproof.jpg';
        $up = self::$pdo->prepare('UPDATE payments SET receipt_path=? WHERE id=?');
        $up->execute([$seeded, $id]);

        // Edit the payment (admin) WITHOUT sending a new file. The two-branch
        // UPDATE must omit receipt_path so the existing one survives, while
        // still letting receipt_url change.
        [$edit] = $this->callApiAction([
            'action'       => 'save_payment',
            'id'           => (string) $id,
            'unit_id'      => (string) $this->unitId,
            'payment_type' => 'rent',
            'amount'       => '5500.00',   // also changing amount
            'payment_date' => date('Y-m-d'),
            'period_month' => (string) (int) date('n'),
            'period_year'  => (string) (int) date('Y'),
            'notes'        => 'preserve path case edited',
            'receipt_url'  => 'https://example.com/added-later.pdf',
        ]);

        $this->assertTrue($edit['success'] ?? false, 'edit failed: ' . ($edit['error'] ?? '?'));
        $row = $this->fetchPayment($id);
        $this->assertSame($seeded, $row['receipt_path'], 'existing receipt_path was wiped on edit');
        $this->assertSame('https://example.com/added-later.pdf', $row['receipt_url']);
        $this->assertSame('5500.00', $row['amount'], 'amount edit did not apply');
    }
}
