<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the "Return cash from Vault to User" flow.
 *
 *   - vault_return INCREASES the user's cash_on_hand (the inverse of remitted)
 *   - vault_return DECREASES the vault balance
 *   - admin-only (gated by requireAdmin in admin/vault.php)
 *
 * Tests insert their own cash_transactions rows tagged with the PHPUNIT_MARKER
 * via the action handlers, then check the math against SQL truth.
 */
final class VaultUserReturnTest extends IntegrationTestCase
{
    /**
     * Invoke an admin/vault.php action. Routed through callScript() so the
     * subprocess is pinned to laskie_test — this suite used to build its own
     * and omitted DB_NAME, which sent every write to the live database.
     */
    private function callVaultAction(array $post): ?array
    {
        [$json] = $this->callScript('admin/vault.php', $post, null, ['REQUEST_METHOD' => 'POST']);
        return $json;
    }

    protected function tearDown(): void
    {
        // Marker-tagged cleanup
        $this->pdo->prepare(
            "DELETE FROM cash_transactions
             WHERE transaction_type='vault_return' AND notes LIKE ?"
        )->execute(['%' . self::PHPUNIT_MARKER . '%']);
        parent::tearDown();
    }

    private function userCashOnHand(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(
                SUM(CASE
                    WHEN transaction_type='received'     THEN amount
                    WHEN transaction_type='remitted'     THEN -amount
                    WHEN transaction_type='expense'      THEN -amount
                    WHEN transaction_type='vault_return' THEN amount
                    ELSE 0
                END), 0)
             FROM cash_transactions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return from_cents(to_cents($stmt->fetchColumn()));
    }

    private function vaultBalance(): string
    {
        $rem    = $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='remitted'")->fetchColumn();
        $dist   = $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_distributions")->fetchColumn();
        $retd   = $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_returns")->fetchColumn();
        $vret   = $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='vault_return'")->fetchColumn();
        return money_sub(money_add(money_sub($rem, $dist), $retd), $vret);
    }

    #[Test]
    public function add_user_return_increases_user_cash_and_decreases_vault(): void
    {
        $userBefore  = $this->userCashOnHand(1);
        $vaultBefore = $this->vaultBalance();

        $json = $this->callVaultAction([
            'action'      => 'add_user_return',
            'user_id'     => '1',
            'amount'      => '500.00',
            'return_date' => date('Y-m-d'),
            'notes'       => self::PHPUNIT_MARKER . ' — add',
        ]);

        $this->assertNotNull($json,           'response was not JSON');
        $this->assertTrue($json['success'],   'expected success, got: ' . ($json['error'] ?? '(no error)'));

        $userAfter  = $this->userCashOnHand(1);
        $vaultAfter = $this->vaultBalance();

        $this->assertSame(money_add($userBefore,  '500.00'), $userAfter,  'user cash should +500');
        $this->assertSame(money_sub($vaultBefore, '500.00'), $vaultAfter, 'vault balance should -500');
    }

    #[Test]
    public function delete_user_return_undoes_both_sides(): void
    {
        $userBefore  = $this->userCashOnHand(1);
        $vaultBefore = $this->vaultBalance();

        $add = $this->callVaultAction([
            'action'      => 'add_user_return',
            'user_id'     => '1',
            'amount'      => '750.00',
            'return_date' => date('Y-m-d'),
            'notes'       => self::PHPUNIT_MARKER . ' — to-be-deleted',
        ]);
        $this->assertTrue($add['success']);

        // Find the row just inserted
        $row = $this->pdo->query("SELECT id FROM cash_transactions WHERE transaction_type='vault_return' AND notes LIKE '%to-be-deleted%' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotNull($row);

        $del = $this->callVaultAction(['action' => 'delete_user_return', 'id' => (string)$row['id']]);
        $this->assertTrue($del['success']);

        $this->assertSame($userBefore,  $this->userCashOnHand(1), 'user cash should return to baseline');
        $this->assertSame($vaultBefore, $this->vaultBalance(),    'vault balance should return to baseline');
    }

    #[Test]
    public function rejects_inactive_user(): void
    {
        // Seed the fixture rather than depending on one happening to exist —
        // this used to rely on an inactive user in the live DB and skipped
        // itself once the suite moved to a freshly built schema.
        $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status)
             VALUES ('phpunit_inactive', ?, 'PHPUnit Inactive', 'staff', 'inactive')
             ON DUPLICATE KEY UPDATE status='inactive'"
        )->execute([password_hash('x', PASSWORD_BCRYPT)]);
        $inactiveId = (int) $this->pdo->query("SELECT id FROM users WHERE status='inactive' LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $inactiveId, 'inactive fixture user was not created');

        $json = $this->callVaultAction([
            'action'      => 'add_user_return',
            'user_id'     => (string)$inactiveId,
            'amount'      => '100.00',
            'return_date' => date('Y-m-d'),
            'notes'       => self::PHPUNIT_MARKER . ' — should-reject',
        ]);
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('not active', $json['error']);
    }

    #[Test]
    public function rejects_zero_amount(): void
    {
        $json = $this->callVaultAction([
            'action'      => 'add_user_return',
            'user_id'     => '1',
            'amount'      => '0',
            'return_date' => date('Y-m-d'),
            'notes'       => self::PHPUNIT_MARKER,
        ]);
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('greater than zero', $json['error']);
    }
}
