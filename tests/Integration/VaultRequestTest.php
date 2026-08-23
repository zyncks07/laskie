<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Part C — vault cash-request workflow + notifications (api/requests_api.php).
 *
 * Skips automatically until migration 009 creates vault_requests/notifications,
 * so the Integration suite stays green before the DB is migrated. Uses a
 * throwaway staff requester; deleting that user CASCADE-cleans its requests,
 * notifications, and vault_return cash rows.
 */
final class VaultRequestTest extends IntegrationTestCase
{
    private int $requesterId = 0;
    private const ADMIN = ['id' => 1, 'username' => 'admin', 'full_name' => 'NJ', 'role' => 'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $has = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vault_requests'"
        )->fetchColumn();
        if (!$has) {
            $this->markTestSkipped('migration 009 (vault_requests) not applied');
        }

        $this->pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status)
             VALUES (?,?,?, 'staff', 'active')"
        )->execute([
            'phpunit_req_' . bin2hex(random_bytes(4)),
            password_hash('x', PASSWORD_BCRYPT),
            self::PHPUNIT_MARKER . ' Requester',
        ]);
        $this->requesterId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->requesterId) {
            // CASCADE on users(id) removes vault_requests, notifications, cash rows.
            $this->pdo->prepare("DELETE FROM vault_requests WHERE requested_by=?")->execute([$this->requesterId]);
            $this->pdo->prepare("DELETE FROM users WHERE id=?")->execute([$this->requesterId]);
        }
        parent::tearDown();
    }

    /**
     * Invoke api/requests_api.php as a given session user. Routed through
     * callScript() so the subprocess is pinned to laskie_test.
     */
    private function callRequestsApi(array $post, array $sessionUser): ?array
    {
        [$json] = $this->callScript('api/requests_api.php', $post, $sessionUser);
        return $json;
    }

    private function requester(): array
    {
        return ['id' => $this->requesterId, 'username' => 'phpunit_req', 'full_name' => 'PHPUNIT Requester', 'role' => 'staff'];
    }

    private function createRequest(string $amount = '25.00'): int
    {
        $res = $this->callRequestsApi([
            'action' => 'create_request', 'amount' => $amount,
            'purpose' => self::PHPUNIT_MARKER . ' deposit return', 'request_type' => 'refund_fund',
        ], $this->requester());
        $this->assertNotNull($res);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'create failed');
        return (int) $res['id'];
    }

    #[Test]
    public function staff_can_create_a_request_and_admins_are_notified(): void
    {
        $id = $this->createRequest();

        $row = $this->pdo->prepare("SELECT * FROM vault_requests WHERE id=?");
        $row->execute([$id]);
        $r = $row->fetch();
        $this->assertSame('pending', $r['status']);
        $this->assertSame($this->requesterId, (int) $r['requested_by']);

        $n = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE vault_request_id=? AND type='request_created'");
        $n->execute([$id]);
        $this->assertGreaterThanOrEqual(1, (int) $n->fetchColumn(), 'at least one admin must be notified');
    }

    #[Test]
    public function approval_auto_issues_vault_return_and_credits_requester(): void
    {
        $before = getUserCashOnHand($this->pdo, $this->requesterId);
        $id = $this->createRequest('25.00');

        $res = $this->callRequestsApi(['action' => 'approve_request', 'id' => (string) $id], self::ADMIN);
        $this->assertNotNull($res);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'approve failed');

        $row = $this->pdo->prepare("SELECT status, cash_tx_id FROM vault_requests WHERE id=?");
        $row->execute([$id]);
        $r = $row->fetch();
        $this->assertSame('approved', $r['status']);
        $this->assertNotEmpty($r['cash_tx_id'], 'approval must record the issued cash tx');

        // The issued cash row is a vault_return for the requester.
        $ct = $this->pdo->prepare("SELECT transaction_type, user_id, amount FROM cash_transactions WHERE id=?");
        $ct->execute([(int) $r['cash_tx_id']]);
        $cash = $ct->fetch();
        $this->assertSame('vault_return', $cash['transaction_type']);
        $this->assertSame($this->requesterId, (int) $cash['user_id']);

        // Requester's cash-on-hand rose by the approved amount.
        $this->assertSame(money_add($before, '25.00'), getUserCashOnHand($this->pdo, $this->requesterId));

        $n = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE vault_request_id=? AND type='request_approved' AND user_id=?");
        $n->execute([$id, $this->requesterId]);
        $this->assertSame(1, (int) $n->fetchColumn());
    }

    #[Test]
    public function rejection_marks_request_and_notifies_requester(): void
    {
        $id = $this->createRequest();
        $res = $this->callRequestsApi(['action' => 'reject_request', 'id' => (string) $id, 'decision_note' => 'not needed'], self::ADMIN);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? 'reject failed');

        $row = $this->pdo->prepare("SELECT status FROM vault_requests WHERE id=?");
        $row->execute([$id]);
        $this->assertSame('rejected', $row->fetchColumn());

        $n = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE vault_request_id=? AND type='request_rejected' AND user_id=?");
        $n->execute([$id, $this->requesterId]);
        $this->assertSame(1, (int) $n->fetchColumn());
    }
}
