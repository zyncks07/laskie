<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Base class for tests that exercise real DB writes via the HTTP-style
 * API handlers in payments/api_payment.php.
 *
 * Conventions
 * - Test rows are tagged with notes containing PHPUNIT_MARKER so tearDown
 *   can clean up any leftovers even when an assertion fails mid-test.
 * - callApiAction() shells out to a subprocess because jsonOk/jsonErr both
 *   exit(), which would otherwise terminate the test runner.
 * - $this->pdo is loaded lazily so the Unit suite never touches MySQL.
 */
abstract class IntegrationTestCase extends TestCase
{
    public const PHPUNIT_MARKER = 'PHPUNIT_INTEGRATION_TEST';

    protected PDO $pdo;

    /** Connection cached for the whole test process to avoid require_once
     *  losing $pdo to setUp's local scope on the second call. */
    private static ?PDO $sharedPdo = null;

    protected function setUp(): void
    {
        if (self::$sharedPdo === null) {
            // Pull in DB_* constants from the project's config (require_once is
            // safe to keep no-oping; the constants persist across setUp calls).
            require_once __DIR__ . '/../../config/db.php';
            self::$sharedPdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        $this->pdo = self::$sharedPdo;
    }

    protected function tearDown(): void
    {
        // Belt-and-suspenders cleanup: kill anything notes-tagged with our marker.
        $this->pdo->prepare(
            "DELETE ct FROM cash_transactions ct
             JOIN payments p ON ct.reference_payment_id = p.id
             WHERE p.notes LIKE ?"
        )->execute(['%' . self::PHPUNIT_MARKER . '%']);
        $this->pdo->prepare(
            "DELETE FROM payments WHERE notes LIKE ?"
        )->execute(['%' . self::PHPUNIT_MARKER . '%']);
    }

    /**
     * Invoke an action handler in payments/api_payment.php with a fake session
     * + given $_POST. Returns [decodedJsonOrNull, rawStdout].
     */
    protected function callApiAction(array $post): array
    {
        $post['csrf_token'] = str_repeat('c', 64);
        $postExport = var_export($post, true);

        $code = <<<PHP
<?php
session_start();
define('JSON_RESPONSE', true);
require_once '/home/bulik/apps/laskie/config/db.php';
require_once '/home/bulik/apps/laskie/config/functions.php';
\$_SESSION['user']       = ['id'=>1,'username'=>'admin','full_name'=>'NJ','role'=>'admin'];
\$_SESSION['csrf_token'] = str_repeat('c', 64);
\$_POST = $postExport;
chdir('/home/bulik/apps/laskie/payments');
include '/home/bulik/apps/laskie/payments/api_payment.php';
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'laskie_phpunit_');
        file_put_contents($tmp, $code);
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        $json = json_decode((string) $out, true);
        return [is_array($json) ? $json : null, (string) $out];
    }

    protected function paymentsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    }

    protected function cashTransactionsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM cash_transactions")->fetchColumn();
    }
}
