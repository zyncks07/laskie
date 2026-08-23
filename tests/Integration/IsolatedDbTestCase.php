<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Base class for integration tests that must NEVER touch the live
 * `laskie_rental` database: everything runs against a dedicated `laskie_test`
 * schema, rebuilt from install.sql on every run.
 *
 * One-time setup (run once as a MySQL admin):
 *
 *     CREATE DATABASE IF NOT EXISTS laskie_test
 *         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *     GRANT ALL PRIVILEGES ON laskie_test.* TO 'laskie_db_user'@'localhost';
 *     FLUSH PRIVILEGES;
 *
 * If `laskie_test` is missing or unreachable, every subclass self-skips — so a
 * `--testsuite Integration` run on a box without it skips instead of failing,
 * and can never write to live data.
 *
 * Contrast with IntegrationTestCase, which DOES run against the live DB and
 * therefore tags + cleans its own rows.
 */
abstract class IsolatedDbTestCase extends TestCase
{
    protected const TEST_DB = 'laskie_test';

    protected static ?PDO   $pdo        = null;
    protected static bool   $skip       = false;
    protected static string $skipReason = '';

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
                'mysql:host=' . $host . ';dbname=' . static::TEST_DB . ';charset=utf8mb4',
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
        if ($current !== static::TEST_DB) {
            self::$skip = true;
            self::$skipReason = "Connected DB is '{$current}', expected '" . static::TEST_DB . "' — refusing to run.";
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
    protected static function loadSchema(PDO $pdo): void
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

    /** Call from setUp() before touching self::$pdo. */
    protected function skipUnlessTestDb(): void
    {
        if (self::$skip) {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /** Empty the transactional tables between tests (FK order handled). */
    protected function truncate(array $tables): void
    {
        if (!self::$pdo) return;
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            self::$pdo->exec("TRUNCATE TABLE {$t}");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Ensure user #1 exists — callApiAction's session is user #1, and handlers
     * write it into received_by / voided_by / recorded_by.
     */
    protected function seedAdminUser(): void
    {
        self::$pdo->exec(
            "INSERT INTO users (id, username, password_hash, full_name, role, status)
             VALUES (1, 'admin', '" . password_hash('x', PASSWORD_BCRYPT) . "', 'Test Admin', 'admin', 'active')
             ON DUPLICATE KEY UPDATE status='active'"
        );
    }

    /**
     * Invoke a payments/api_payment.php action against laskie_test in a
     * subprocess (jsonOk/jsonErr call exit(), which would kill the test runner).
     * DB_NAME is pre-defined so config/db.php connects to laskie_test, not live.
     *
     * @return array{0: ?array, 1: string} decoded JSON (or null) + raw output
     */
    protected function callApiAction(array $post): array
    {
        $post['csrf_token'] = str_repeat('c', 64);
        $postExport = var_export($post, true);
        $db   = static::TEST_DB;
        $root = dirname(__DIR__, 2);

        $code = <<<PHP
<?php
session_start();
define('JSON_RESPONSE', true);
define('DB_NAME', '{$db}');   // redirect the API to the isolated test DB
require_once '{$root}/config/db.php';
require_once '{$root}/config/functions.php';
\$_SESSION['user']       = ['id'=>1,'username'=>'admin','full_name'=>'Test Admin','role'=>'admin'];
\$_SESSION['csrf_token'] = str_repeat('c', 64);
\$_POST = {$postExport};
chdir('{$root}/payments');
include '{$root}/payments/api_payment.php';
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'laskie_isolated_test_');
        file_put_contents($tmp, $code);
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        $json = json_decode((string) $out, true);
        return [is_array($json) ? $json : null, (string) $out];
    }
}
