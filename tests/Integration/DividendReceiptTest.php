<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Proof-of-distribution attachments on The Vault's "Distribute Dividend" flow.
 * Drives the REAL add_distribution / edit_distribution handlers in
 * admin/vault.php and asserts the receipt_path / receipt_url columns added by
 * migrations/012_add_distribution_receipt.sql behave correctly.
 *
 * ISOLATION — runs entirely against the dedicated `laskie_test` schema and
 * self-skips when it is absent; see IsolatedDbTestCase for the harness and the
 * one-time setup command.
 *
 * Note on the uploaded-file path: PHP's move_uploaded_file() only accepts files
 * that arrived over an HTTP POST, so the literal file-bytes branch of
 * handleUpload() can't be exercised from CLI — the same limitation documented
 * in PaymentReceiptTest. What IS covered is every line of new logic: the
 * receipt columns on INSERT, the http(s)-scheme guard, the two-branch edit
 * UPDATE, and that an edit without a new file preserves an existing
 * receipt_path.
 */
final class DividendReceiptTest extends IsolatedDbTestCase
{
    /** Tables this class rewrites between tests. */
    private const VAULT_TABLES = ['dividend_distributions', 'dividend_returns', 'dividend_recipients', 'cash_transactions'];

    /** Real upload dir — the delete test writes a throwaway file here. */
    private const UPLOAD_DIR = __DIR__ . '/../../uploads/dividends';

    private int $recipientId = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->truncate(self::VAULT_TABLES);
        $this->seedAdminUser();

        self::$db->exec(
            "INSERT INTO dividend_recipients (name, notes, is_active)
             VALUES ('TEST-RECIPIENT', 'integration test', 1)"
        );
        $this->recipientId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(self::VAULT_TABLES);
    }

    /** Invoke an admin/vault.php action in a subprocess pinned to laskie_test. */
    private function callVault(array $post): ?array
    {
        [$json] = $this->callScript('admin/vault.php', $post, null, ['REQUEST_METHOD' => 'POST']);
        return $json;
    }

    private function latestDistribution(): array
    {
        return self::$db->query(
            'SELECT * FROM dividend_distributions ORDER BY id DESC LIMIT 1'
        )->fetch() ?: [];
    }

    private function basePost(array $extra = []): array
    {
        return $extra + [
            'action'            => 'add_distribution',
            'recipient_id'      => (string) $this->recipientId,
            'amount'            => '1500.00',
            'distribution_date' => date('Y-m-d'),
            'notes'             => 'integration test',
        ];
    }

    #[Test]
    public function distribution_with_external_url_persists_the_url(): void
    {
        $res = $this->callVault($this->basePost([
            'receipt_url' => 'https://drive.google.com/file/d/div123/view',
        ]));

        $this->assertNotNull($res, 'No JSON returned from add_distribution');
        $this->assertTrue($res['success'] ?? false, 'add_distribution failed: ' . ($res['error'] ?? '?'));

        $row = $this->latestDistribution();
        $this->assertSame('https://drive.google.com/file/d/div123/view', $row['receipt_url']);
        $this->assertNull($row['receipt_path'], 'receipt_path should be NULL when only a URL is given');
        // The distribution itself must still be recorded normally (regression guard).
        $this->assertSame('1500.00', $row['amount']);
        $this->assertSame($this->recipientId, (int) $row['recipient_id']);
    }

    #[Test]
    public function distribution_without_proof_leaves_both_columns_null(): void
    {
        $res = $this->callVault($this->basePost());

        $this->assertTrue($res['success'] ?? false, 'add_distribution failed: ' . ($res['error'] ?? '?'));
        $row = $this->latestDistribution();
        $this->assertNull($row['receipt_path']);
        $this->assertNull($row['receipt_url']);
    }

    #[Test]
    public function distribution_rejects_a_non_http_url_scheme(): void
    {
        $res = $this->callVault($this->basePost([
            'receipt_url' => 'javascript:alert(1)',
        ]));

        $this->assertNotNull($res);
        $this->assertFalse($res['success'] ?? true, 'javascript: URL was accepted');
        $this->assertSame(
            0,
            (int) self::$db->query('SELECT COUNT(*) FROM dividend_distributions')->fetchColumn(),
            'a rejected distribution must not be written'
        );
    }

    #[Test]
    public function editing_a_distribution_updates_the_receipt_url(): void
    {
        $this->callVault($this->basePost(['receipt_url' => 'https://example.com/old.pdf']));
        $id = (int) $this->latestDistribution()['id'];

        $res = $this->callVault([
            'action'            => 'edit_distribution',
            'id'                => (string) $id,
            'recipient_id'      => (string) $this->recipientId,
            'amount'            => '1500.00',
            'distribution_date' => date('Y-m-d'),
            'notes'             => 'integration test',
            'receipt_url'       => 'https://example.com/new.pdf',
        ]);

        $this->assertTrue($res['success'] ?? false, 'edit failed: ' . ($res['error'] ?? '?'));
        $this->assertSame('https://example.com/new.pdf', $this->latestDistribution()['receipt_url']);
    }

    #[Test]
    public function editing_without_a_new_file_preserves_the_existing_receipt_path(): void
    {
        // Record a distribution, then stamp a receipt_path on it directly —
        // standing in for a file uploaded earlier (move_uploaded_file can't run
        // under CLI). This is the branch the two-statement UPDATE exists for.
        $this->callVault($this->basePost());
        $id = (int) $this->latestDistribution()['id'];

        $seeded = '/uploads/dividends/20260913_seededproof.jpg';
        self::$db->prepare('UPDATE dividend_distributions SET receipt_path=? WHERE id=?')
                 ->execute([$seeded, $id]);

        $res = $this->callVault([
            'action'            => 'edit_distribution',
            'id'                => (string) $id,
            'recipient_id'      => (string) $this->recipientId,
            'amount'            => '1750.00',   // also changing amount
            'distribution_date' => date('Y-m-d'),
            'notes'             => 'edited',
            'receipt_url'       => 'https://example.com/added-later.pdf',
        ]);

        $this->assertTrue($res['success'] ?? false, 'edit failed: ' . ($res['error'] ?? '?'));
        $row = $this->latestDistribution();
        $this->assertSame($seeded, $row['receipt_path'], 'existing receipt_path was wiped on edit');
        $this->assertSame('https://example.com/added-later.pdf', $row['receipt_url']);
        $this->assertSame('1750.00', $row['amount'], 'amount edit did not apply');
    }

    #[Test]
    public function editing_rejects_a_non_http_url_scheme(): void
    {
        $this->callVault($this->basePost(['receipt_url' => 'https://example.com/good.pdf']));
        $id = (int) $this->latestDistribution()['id'];

        $res = $this->callVault([
            'action'            => 'edit_distribution',
            'id'                => (string) $id,
            'recipient_id'      => (string) $this->recipientId,
            'amount'            => '1500.00',
            'distribution_date' => date('Y-m-d'),
            'notes'             => 'integration test',
            'receipt_url'       => 'data:text/html,<script>alert(1)</script>',
        ]);

        $this->assertFalse($res['success'] ?? true, 'data: URL was accepted');
        $this->assertSame(
            'https://example.com/good.pdf',
            $this->latestDistribution()['receipt_url'],
            'the stored URL must be untouched by a rejected edit'
        );
    }

    #[Test]
    public function deleting_a_distribution_unlinks_its_proof_file(): void
    {
        $this->callVault($this->basePost());
        $id = (int) $this->latestDistribution()['id'];

        // Put a real throwaway file where an upload would have landed, then
        // point the row at it (move_uploaded_file can't run under CLI).
        @mkdir(self::UPLOAD_DIR, 0775, true);
        $name = 'phpunit_delete_' . bin2hex(random_bytes(6)) . '.jpg';
        $abs  = self::UPLOAD_DIR . '/' . $name;
        file_put_contents($abs, 'not-a-real-jpeg');
        $this->assertFileExists($abs, 'test fixture was not created');

        self::$db->prepare('UPDATE dividend_distributions SET receipt_path=? WHERE id=?')
                 ->execute(['/uploads/dividends/' . $name, $id]);

        $res = $this->callVault(['action' => 'delete_distribution', 'id' => (string) $id]);

        $this->assertTrue($res['success'] ?? false, 'delete failed: ' . ($res['error'] ?? '?'));
        $this->assertSame(
            0,
            (int) self::$db->query('SELECT COUNT(*) FROM dividend_distributions')->fetchColumn(),
            'the row should be gone'
        );
        $this->assertFileDoesNotExist($abs, 'the proof file was left orphaned on disk');
        @unlink($abs);   // belt, in case the assertion above failed
    }

    #[Test]
    public function deleting_a_distribution_without_a_proof_still_succeeds(): void
    {
        $this->callVault($this->basePost(['receipt_url' => 'https://example.com/only-a-link.pdf']));
        $id = (int) $this->latestDistribution()['id'];

        $res = $this->callVault(['action' => 'delete_distribution', 'id' => (string) $id]);

        $this->assertTrue($res['success'] ?? false, 'delete failed: ' . ($res['error'] ?? '?'));
        $this->assertSame(
            0,
            (int) self::$db->query('SELECT COUNT(*) FROM dividend_distributions')->fetchColumn()
        );
    }

    /**
     * The Transaction Log UNION pulls the attachment from a different pair of
     * columns per branch (cash_transactions.doc_* vs dividend_distributions
     * .receipt_*, NULL for dividend_returns). Assert each branch lands in
     * proof_path / proof_url — a collation mismatch between branches would
     * make the whole query throw, so this covers that too.
     */
    #[Test]
    public function transaction_log_exposes_the_proof_of_every_branch(): void
    {
        $this->callVault($this->basePost([
            'receipt_url' => 'https://example.com/dividend-proof.pdf',
        ]));

        // A remittance and a vault return, both with a doc_path.
        self::$db->exec(
            "INSERT INTO cash_transactions (user_id,transaction_type,amount,transaction_date,notes,doc_path)
             VALUES (1,'remitted',900.00,'" . date('Y-m-d') . "','log test','/uploads/remittance/rem_proof.jpg')"
        );
        self::$db->exec(
            "INSERT INTO cash_transactions (user_id,transaction_type,amount,transaction_date,notes,doc_url)
             VALUES (1,'vault_return',150.00,'" . date('Y-m-d') . "','log test','https://example.com/vr.pdf')"
        );
        // A dividend return, which has no attachment columns at all.
        self::$db->prepare(
            "INSERT INTO dividend_returns (recipient_id,amount,return_date,notes,created_by)
             VALUES (?,?,?,'log test',1)"
        )->execute([$this->recipientId, '300.00', date('Y-m-d')]);

        $res = $this->callVault([
            'action' => 'get_logs',
            'month'  => (string) (int) date('n'),
            'year'   => (string) (int) date('Y'),
        ]);

        $this->assertNotNull($res, 'get_logs returned no JSON (a UNION collation mismatch throws here)');
        $this->assertTrue($res['success'] ?? false, 'get_logs failed: ' . ($res['error'] ?? '?'));

        $byType = [];
        foreach ($res['logs'] as $row) $byType[$row['log_type']] = $row;

        $this->assertArrayHasKey('distribution', $byType);
        $this->assertSame('https://example.com/dividend-proof.pdf', $byType['distribution']['proof_url']);
        $this->assertNull($byType['distribution']['proof_path']);

        $this->assertArrayHasKey('remittance', $byType);
        $this->assertSame('/uploads/remittance/rem_proof.jpg', $byType['remittance']['proof_path']);

        $this->assertArrayHasKey('user_return', $byType);
        $this->assertSame('https://example.com/vr.pdf', $byType['user_return']['proof_url']);

        $this->assertArrayHasKey('return', $byType);
        $this->assertNull($byType['return']['proof_path'], 'dividend_returns has no attachment columns');
        $this->assertNull($byType['return']['proof_url']);
    }
}
