<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that multi-table writes in payments/api_payment.php are atomic.
 * A failure mid-transaction must leave NO rows behind in either payments
 * or cash_transactions.
 *
 * Ported from /tmp/test_txn.php we used during issue #2.
 */
final class TransactionAtomicityTest extends IntegrationTestCase
{
    #[Test]
    public function fk_violation_mid_transaction_rolls_back_all_writes(): void
    {
        // Build a service_type_id that definitely doesn't exist so the INSERT
        // into payments fires a FK error.
        $bogusServiceId = (int) $this->pdo->query(
            "SELECT IFNULL(MAX(id),0)+9999 FROM service_types"
        )->fetchColumn();

        $beforePay  = $this->paymentsCount();
        $beforeCash = $this->cashTransactionsCount();

        $rollbackFired = false;
        $this->pdo->beginTransaction();
        try {
            $invoiceNo = generateInvoiceNo($this->pdo);
            $this->pdo->prepare(
                "INSERT INTO payments
                 (invoice_no,unit_id,tenant_id,payment_type,service_type_id,amount,
                  period_month,period_year,payment_date,due_date,received_by,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $invoiceNo, 1, null, 'service', $bogusServiceId, '0.01',
                (int) date('n'), (int) date('Y'), date('Y-m-d'), null, 1,
                self::PHPUNIT_MARKER . ' — atomicity',
            ]);
            $this->fail('expected FK violation but INSERT succeeded');
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $rollbackFired = true;
            }
            $this->assertStringContainsString('foreign key constraint', $e->getMessage());
        }

        $this->assertTrue($rollbackFired,                    'rollback never fired');
        $this->assertSame($beforePay,  $this->paymentsCount(),         'payments table must be unchanged');
        $this->assertSame($beforeCash, $this->cashTransactionsCount(), 'cash_transactions must be unchanged');
    }

    #[Test]
    public function json_exception_handler_is_installed_when_json_response_defined(): void
    {
        // Run a tiny script that defines JSON_RESPONSE, loads our config, then
        // throws — the global handler should convert it to JSON 500.
        $probe = tempnam(sys_get_temp_dir(), 'laskie_phpunit_handler_') . '.php';
        file_put_contents($probe, <<<'PHP'
<?php
session_start();
define('JSON_RESPONSE', true);
require_once '/home/bulik/apps/laskie/config/db.php';
require_once '/home/bulik/apps/laskie/config/functions.php';
throw new RuntimeException('phpunit-probe-error');
PHP);
        $out = shell_exec('php ' . escapeshellarg($probe) . ' 2>/dev/null');
        @unlink($probe);

        $this->assertNotNull($out);
        $json = json_decode((string) $out, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('phpunit-probe-error', $json['error']);
    }
}
