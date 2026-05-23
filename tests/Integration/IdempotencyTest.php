<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Idempotency-key tests for save_payment.
 * Ported from /tmp/test_idempotency.php we used during issue #5.
 */
final class IdempotencyTest extends IntegrationTestCase
{
    private const TEST_UNIT_ID = 1;

    private function basePost(string $key): array
    {
        return [
            'action'          => 'save_payment',
            'unit_id'         => (string) self::TEST_UNIT_ID,
            'payment_type'    => 'rent',
            'amount'          => '0.01',
            'payment_date'    => date('Y-m-d'),
            'period_month'    => date('n'),
            'period_year'     => date('Y'),
            'notes'           => self::PHPUNIT_MARKER . ' — idempotency',
            'idempotency_key' => $key,
        ];
    }

    #[Test]
    public function fresh_key_creates_one_payment(): void
    {
        $before = $this->paymentsCount();
        [$json] = $this->callApiAction($this->basePost(bin2hex(random_bytes(16))));

        $this->assertNotNull($json,             'response was not JSON');
        $this->assertTrue($json['success'],     'response not success');
        $this->assertNotEmpty($json['id']);
        $this->assertArrayNotHasKey('idempotent_replay', $json);
        $this->assertSame($before + 1, $this->paymentsCount());
    }

    #[Test]
    public function replay_with_same_key_returns_existing_payment_and_does_not_double_insert(): void
    {
        $key       = bin2hex(random_bytes(16));
        $before    = $this->paymentsCount();
        $beforeCT  = $this->cashTransactionsCount();

        [$first]   = $this->callApiAction($this->basePost($key));
        [$replay]  = $this->callApiAction($this->basePost($key));

        $this->assertNotNull($first);
        $this->assertNotNull($replay);
        $this->assertTrue($replay['success']);
        $this->assertSame($first['id'],         $replay['id'],        'replay must return the same payment id');
        $this->assertSame($first['invoice_no'], $replay['invoice_no'],'replay must return the same invoice_no');
        $this->assertTrue($replay['idempotent_replay'] ?? false,      'replay should be flagged');

        $this->assertSame($before + 1,   $this->paymentsCount(),         'replay must not create a 2nd payment');
        $this->assertSame($beforeCT + 1, $this->cashTransactionsCount(), 'replay must not create a 2nd cash_transaction');
    }

    #[Test]
    public function different_keys_create_separate_payments(): void
    {
        $before = $this->paymentsCount();
        [$a] = $this->callApiAction($this->basePost(bin2hex(random_bytes(16))));
        [$b] = $this->callApiAction($this->basePost(bin2hex(random_bytes(16))));

        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame($before + 2, $this->paymentsCount());
    }

    #[Test]
    public function backward_compat_request_without_key_still_works(): void
    {
        $post = $this->basePost('');
        unset($post['idempotency_key']);

        $before = $this->paymentsCount();
        [$json] = $this->callApiAction($post);

        $this->assertNotNull($json);
        $this->assertTrue($json['success']);
        $this->assertSame($before + 1, $this->paymentsCount());

        $stmt = $this->pdo->prepare("SELECT idempotency_key FROM payments WHERE id=?");
        $stmt->execute([$json['id']]);
        $this->assertNull($stmt->fetchColumn(), 'no-key submissions should store NULL, not empty string');
    }

    #[Test]
    public function race_recovery_pre_existing_key_returns_existing_row(): void
    {
        // Pre-insert a payment with a known key so the API call's INSERT
        // trips the UNIQUE constraint and falls back to "return existing".
        $key = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            "INSERT INTO payments
             (invoice_no,unit_id,payment_type,amount,period_month,period_year,payment_date,received_by,notes,idempotency_key)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            'INV-PHPUNIT-RACE',
            self::TEST_UNIT_ID,
            'rent',
            '0.01',
            (int) date('n'),
            (int) date('Y'),
            date('Y-m-d'),
            1,
            self::PHPUNIT_MARKER . ' — race',
            $key,
        ]);
        $preExistingId = (int) $this->pdo->lastInsertId();

        [$json] = $this->callApiAction($this->basePost($key));

        $this->assertNotNull($json);
        $this->assertTrue($json['success']);
        $this->assertSame($preExistingId, (int) $json['id'], 'race must return the pre-existing row');
        $this->assertTrue($json['idempotent_replay'] ?? false);

        $dup = $this->pdo->prepare("SELECT COUNT(*) FROM payments WHERE idempotency_key=?");
        $dup->execute([$key]);
        $this->assertSame(1, (int) $dup->fetchColumn(), 'unique constraint must have prevented a duplicate row');
    }
}
