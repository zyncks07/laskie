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
     * Invoke a vault.php action via subprocess (jsonOk/jsonErr exit).
     */
    private function callVaultAction(array $post): ?array
    {
        $post['csrf_token'] = str_repeat('c', 64);
        $postExport = var_export($post, true);
        $code = <<<PHP
<?php
session_start();
define('JSON_RESPONSE', true);
require_once '/home/bulik/apps/laskie/config/db.php';
require_once '/home/bulik/apps/laskie/config/functions.php';
\$_SESSION['user']           = ['id'=>1,'username'=>'admin','full_name'=>'NJ','role'=>'admin'];
\$_SESSION['csrf_token']     = str_repeat('c', 64);
\$_SERVER['REQUEST_METHOD']  = 'POST';
\$_POST = $postExport;
chdir('/home/bulik/apps/laskie/admin');
include '/home/bulik/apps/laskie/admin/vault.php';
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'laskie_phpunit_vault_');
        file_put_contents($tmp, $code);
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        $json = json_decode((string)$out, true);
        return is_array($json) ? $json : null;
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
        // There's already an inactive user (id=10, "test") in the seed data per earlier audit.
        $inactiveId = (int)$this->pdo->query("SELECT id FROM users WHERE status='inactive' LIMIT 1")->fetchColumn();
        if (!$inactiveId) $this->markTestSkipped('no inactive user available');

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
