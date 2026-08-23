<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * Base class for tests that exercise real DB writes via the HTTP-style
 * API handlers in payments/api_payment.php.
 *
 * ISOLATION — these used to run against the LIVE `laskie_rental` database,
 * cleaning up after themselves via the PHPUNIT_MARKER tag. That worked for the
 * money tables but not for `system_logs`, which is append-only: every run left
 * audit rows behind on production, and a failed run could leave money rows too.
 * The class now sits on IsolatedDbTestCase, so the whole Integration suite
 * targets the throwaway `laskie_test` schema and can never touch live data.
 *
 * The surface subclasses rely on is unchanged — `$this->pdo`, `callApiAction()`,
 * `paymentsCount()`, `cashTransactionsCount()`, and the PHPUNIT_MARKER tearDown
 * all behave as before — so no test bodies needed rewriting.
 *
 * Conventions
 * - Test rows are tagged with notes containing PHPUNIT_MARKER so tearDown
 *   can clean up any leftovers even when an assertion fails mid-test.
 * - callApiAction() shells out to a subprocess because jsonOk/jsonErr both
 *   exit(), which would otherwise terminate the test runner. The subprocess is
 *   pinned to `laskie_test` by IsolatedDbTestCase.
 * - The suite self-skips when `laskie_test` is absent; see that class for the
 *   one-time CREATE DATABASE + GRANT.
 */
abstract class IntegrationTestCase extends IsolatedDbTestCase
{
    public const PHPUNIT_MARKER = 'PHPUNIT_INTEGRATION_TEST';

    /** Instance alias for the isolated connection, kept for subclass ergonomics. */
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->pdo = self::$db;
        // These suites predate the isolated schema and assume the live DB's
        // user #1 / unit #1 exist as FK targets.
        $this->seedAdminUser();
        $this->seedDefaultUnit();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
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

    protected function paymentsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    }

    protected function cashTransactionsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM cash_transactions")->fetchColumn();
    }
}
