<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PDO;

/**
 * Automated counterpart to the manual proof-of-payment test: records payments
 * through the REAL save_payment handler (payments/api_payment.php) and asserts
 * the receipt_path / receipt_url columns behave correctly.
 *
 * ISOLATION — this test NEVER touches the live `laskie_rental` database.
 * It runs entirely against a dedicated `laskie_test` schema. One-time setup
 * (run once as a MySQL admin):
 *
 *     CREATE DATABASE IF NOT EXISTS laskie_test
 *         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *     GRANT ALL PRIVILEGES ON laskie_test.* TO 'laskie_db_user'@'localhost';
 *     FLUSH PRIVILEGES;
 *
 * If `laskie_test` is missing/unreachable the whole class self-skips (so a
 * normal `--testsuite Integration` run on a box without it just skips, never
 * fails and never writes to live data).
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
final class PaymentReceiptTest extends TestCase
{
    private const TEST_DB = 'laskie_test';

    private static ?PDO $pdo = null;
    private static bool  $skip = false;
    private static string $skipReason = '';

    private int $unitId = 0;

    public static function setUpBeforeClass(): void
    {
        // Pull DB_USER / DB_PASS / DB_HOST from the project .env WITHOUT opening
        // the live connection (env.php only define()s constants; it connects to
        // nothing). We then build our own DSN pinned to laskie_test.
        require_once __DIR__ . '/../../config/env.php';
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $user = defined('DB_USER') ? DB_USER : 'laskie_db_user';
        $pass = defined('DB_PASS') ? DB_PASS : '';

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname=" . self::TEST_DB . ";charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (\Throwable $e) {
            self::$skip = true;
            self::$skipReason = 'laskie_test DB not reachable — run the one-time '
                . 'CREATE DATABASE laskie_test + GRANT (see class docblock). (' . $e->getMessage() . ')';
            return;
        }

        // HARD SAFETY GUARD: refuse to run any DDL/DML unless we are certainly
        // on laskie_test. This makes it impossible to ever scribble on live data.
        $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== self::TEST_DB) {
            self::$skip = true;
            self::$skipReason = "Connected DB is '{$current}', expected '" . self::TEST_DB . "' — refusing to run.";
            return;
        }

        self::$pdo = $pdo;
        self::loadSchema($pdo);
    }

    /**
     * Load the real schema + lookup seeds from install.sql into laskie_test,
     * with the CREATE DATABASE / USE lines stripped so it can only ever apply
     * to the already-selected laskie_test connection.
     */
    private static function loadSchema(PDO $pdo): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../install.sql');

        // Strip full-line `--` comments and the CREATE DATABASE / USE directives
        // FIRST — a comment line in install.sql contains a ';' (".. outstanding;
        // source distinguishes .."), so splitting on ';' before stripping would
        // glue a comment fragment onto the next statement. install.sql has no
        // ';' inside quoted values (verified), so this is safe.
        $clean = [];
        foreach (explode("\n", $sql) as $line) {
            $t = ltrim($line);
            if ($t === '' || str_starts_with($t, '--')) continue;
            if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $t)) continue;
            $clean[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $clean)) as $chunk) {
            $stmt = trim($chunk);
            if ($stmt !== '') $statements[] = $stmt;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // Drop whatever a previous run left behind so the (non-idempotent) seed
        // INSERTs in install.sql apply cleanly to an empty schema every time.
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
        }
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function setUp(): void
    {
        if (self::$skip) {
            $this->markTestSkipped(self::$skipReason);
        }

        $pdo = self::$pdo;
        // Clean slate for the money tables on every test; re-seed one unit.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payments', 'cash_transactions', 'unit_charges', 'refunds', 'rental_units', 'tenants'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // A user with id=1 is needed because callApiAction's session is user #1
        // and save_payment writes received_by=1. install.sql seeds the admin as
        // the first row (id=1); make sure it exists regardless of seed changes.
        $pdo->exec(
            "INSERT INTO users (id, username, password_hash, full_name, role, status)
             VALUES (1, 'admin', '" . password_hash('x', PASSWORD_BCRYPT) . "', 'Test Admin', 'admin', 'active')
             ON DUPLICATE KEY UPDATE status='active'"
        );

        $pdo->exec(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES ('TEST-UNIT-A', 5000.00, 5, 'occupied')"
        );
        $this->unitId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$pdo) return;
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payments', 'cash_transactions', 'unit_charges', 'refunds', 'rental_units', 'tenants'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Invoke a payments/api_payment.php action against laskie_test in a
     * subprocess (jsonOk/jsonErr call exit(), which would kill the test runner).
     * DB_NAME is pre-defined so config/db.php connects to laskie_test, not live.
     */
    private function callApiAction(array $post): array
    {
        $post['csrf_token'] = str_repeat('c', 64);
        $postExport = var_export($post, true);
        $db = self::TEST_DB;

        $code = <<<PHP
<?php
session_start();
define('JSON_RESPONSE', true);
define('DB_NAME', '{$db}');   // redirect the API to the isolated test DB
require_once '/home/bulik/apps/laskie/config/db.php';
require_once '/home/bulik/apps/laskie/config/functions.php';
\$_SESSION['user']       = ['id'=>1,'username'=>'admin','full_name'=>'Test Admin','role'=>'admin'];
\$_SESSION['csrf_token'] = str_repeat('c', 64);
\$_POST = {$postExport};
chdir('/home/bulik/apps/laskie/payments');
include '/home/bulik/apps/laskie/payments/api_payment.php';
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'laskie_receipt_test_');
        file_put_contents($tmp, $code);
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        $json = json_decode((string) $out, true);
        return [is_array($json) ? $json : null, (string) $out];
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
