<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the tenant document sections in admin/tenants.php.
 *
 * The Documents modal has exactly three sections — Contract / IDs / Supporting
 * Documents — and the section IS the `tenant_docs.doc_type` column. What these
 * tests pin:
 *
 *   - an unrecognised doc_type buckets into 'other' (no fourth section can be
 *     invented by a stale client or a hand-crafted POST)
 *   - the 20-item cap is per category, not per tenant
 *   - a row must carry either a file or a link, never neither
 *   - external links must be http(s) — the scheme check is server-side, not
 *     only in the page's _docSafeUrl() renderer
 *   - delete_doc stays scoped to its tenant
 *
 * $_FILES cannot be injected into the callScript() subprocess, so every case
 * here uses link-only rows. That exercises each new branch except
 * handleUpload() itself, which tests/Unit/ImageCompressionTest.php covers.
 */
final class TenantDocsTest extends IntegrationTestCase
{
    private int $tenantId  = 0;
    private int $tenant2Id = 0;

    protected function setUp(): void
    {
        $this->skipUnlessTestDb();
        $this->pdo = self::$db;
        $this->seedAdminUser();
        $this->truncate(['tenant_docs']);

        $ins = self::$db->prepare(
            "INSERT INTO tenants (full_name, monthly_rate, contract_start, status)
             VALUES (?, 5000.00, ?, 'active')"
        );
        $ins->execute(['Docs Test Tenant', date('Y-m-d', strtotime('-1 year'))]);
        $this->tenantId = (int) self::$db->lastInsertId();
        $ins->execute(['Docs Test Tenant 2', date('Y-m-d', strtotime('-1 year'))]);
        $this->tenant2Id = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skip || !self::$db) return;
        $this->truncate(['tenant_docs']);
        self::$db->prepare("DELETE FROM tenants WHERE id IN (?,?)")
                 ->execute([$this->tenantId, $this->tenant2Id]);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function callTenants(array $post): ?array
    {
        [$json] = $this->callScript('admin/tenants.php', $post, null, ['REQUEST_METHOD' => 'POST']);
        return $json;
    }

    /** Add a link-only document through the real handler. */
    private function addLink(string $cat, string $url = 'https://drive.google.com/file/abc', string $label = ''): ?array
    {
        return $this->callTenants([
            'action'       => 'upload_doc',
            'tenant_id'    => (string) $this->tenantId,
            'doc_type'     => $cat,
            'external_url' => $url,
            'label'        => $label,
        ]);
    }

    private function countIn(string $cat, ?int $tenantId = null): int
    {
        $q = self::$db->prepare("SELECT COUNT(*) FROM tenant_docs WHERE tenant_id=? AND doc_type=?");
        $q->execute([$tenantId ?? $this->tenantId, $cat]);
        return (int) $q->fetchColumn();
    }

    /** Bulk-fill a category straight through SQL — far faster than N subprocesses. */
    private function fillCategory(string $cat, int $n): void
    {
        $ins = self::$db->prepare(
            "INSERT INTO tenant_docs (tenant_id, doc_name, doc_type, external_url, uploaded_by)
             VALUES (?, ?, ?, ?, 1)"
        );
        for ($i = 1; $i <= $n; $i++) {
            $ins->execute([$this->tenantId, "seed-$cat-$i", $cat, "https://example.com/$cat/$i"]);
        }
    }

    // ── tests ────────────────────────────────────────────────────

    #[Test]
    public function link_lands_in_the_requested_category(): void
    {
        foreach (['contract', 'id', 'other'] as $cat) {
            $res = $this->addLink($cat);
            $this->assertTrue($res['success'] ?? false, "adding a $cat link should succeed");
            $this->assertSame(1, $this->countIn($cat));
        }
    }

    #[Test]
    public function unrecognised_doc_type_buckets_into_other(): void
    {
        // 'permit' was a value in the old free-text dropdown; anything outside
        // the three canonical categories must land in Other.
        $this->assertTrue($this->addLink('permit')['success'] ?? false);
        $this->assertTrue($this->addLink('')['success'] ?? false);
        $this->assertTrue($this->addLink('<script>')['success'] ?? false);

        $this->assertSame(3, $this->countIn('other'));
        $this->assertSame(0, $this->countIn('contract'));
        $this->assertSame(0, $this->countIn('id'));
    }

    #[Test]
    public function cap_is_twenty_per_category_not_per_tenant(): void
    {
        $this->fillCategory('contract', 19);

        // #20 still fits.
        $this->assertTrue($this->addLink('contract')['success'] ?? false, '20th contract item should fit');
        $this->assertSame(20, $this->countIn('contract'));

        // #21 is refused, and nothing is written.
        $res = $this->addLink('contract');
        $this->assertFalse($res['success'] ?? true, '21st contract item must be refused');
        $this->assertStringContainsString('Limit reached', (string) ($res['error'] ?? ''));
        $this->assertSame(20, $this->countIn('contract'));

        // A full Contract section must not block the other two.
        $this->assertTrue($this->addLink('id')['success'] ?? false);
        $this->assertTrue($this->addLink('other')['success'] ?? false);
        $this->assertSame(1, $this->countIn('id'));
        $this->assertSame(1, $this->countIn('other'));
    }

    #[Test]
    public function row_needs_either_a_file_or_a_link(): void
    {
        $res = $this->callTenants([
            'action'    => 'upload_doc',
            'tenant_id' => (string) $this->tenantId,
            'doc_type'  => 'contract',
        ]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(0, $this->countIn('contract'));
    }

    #[Test]
    public function external_url_must_be_http_or_https(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>x</script>', 'file:///etc/passwd', 'drive.google.com/x'] as $bad) {
            $res = $this->addLink('other', $bad);
            $this->assertFalse($res['success'] ?? true, "must reject: $bad");
        }
        $this->assertSame(0, $this->countIn('other'));

        // Both real schemes are accepted, case-insensitively.
        $this->assertTrue($this->addLink('other', 'HTTPS://docs.google.com/d/1')['success'] ?? false);
        $this->assertTrue($this->addLink('other', 'http://intranet.local/scan.pdf')['success'] ?? false);
        $this->assertSame(2, $this->countIn('other'));
    }

    #[Test]
    public function link_name_falls_back_to_the_host_when_no_label_given(): void
    {
        $this->addLink('other', 'https://docs.google.com/document/d/xyz');
        $this->addLink('other', 'https://docs.google.com/document/d/abc', 'Lease addendum');

        $names = self::$db->prepare(
            "SELECT doc_name FROM tenant_docs WHERE tenant_id=? ORDER BY id"
        );
        $names->execute([$this->tenantId]);
        $this->assertSame(['docs.google.com', 'Lease addendum'], $names->fetchAll(\PDO::FETCH_COLUMN));
    }

    #[Test]
    public function unknown_tenant_is_rejected_before_anything_is_written(): void
    {
        $res = $this->callTenants([
            'action'       => 'upload_doc',
            'tenant_id'    => '99999999',
            'doc_type'     => 'contract',
            'external_url' => 'https://example.com/x.pdf',
        ]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('Tenant not found', (string) ($res['error'] ?? ''));
    }

    #[Test]
    public function delete_doc_is_scoped_to_its_tenant(): void
    {
        $this->addLink('contract');
        $docId = (int) self::$db->query("SELECT MAX(id) FROM tenant_docs")->fetchColumn();

        // Another tenant's id must not reach this row.
        $res = $this->callTenants([
            'action'    => 'delete_doc',
            'id'        => (string) $docId,
            'tenant_id' => (string) $this->tenant2Id,
        ]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(1, $this->countIn('contract'));

        // The owning tenant can.
        $res = $this->callTenants([
            'action'    => 'delete_doc',
            'id'        => (string) $docId,
            'tenant_id' => (string) $this->tenantId,
        ]);
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, $this->countIn('contract'));
    }
}
