<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for assetUrl() / pageUrl() — the BASE_URL-aware path builders.
 *
 * BASE_URL is a runtime constant defined in config/functions.php (overridable
 * by config/db.php). In CI / tests it defaults to ''. The behaviour we care
 * about: leading-slash normalisation works, and the helpers never produce
 * double slashes or missing slashes regardless of whether the caller passes
 * '/foo' or 'foo'.
 */
final class UrlHelpersTest extends TestCase
{
    #[Test]
    public function asset_url_strips_leading_slash_and_prefixes_with_base(): void
    {
        $this->assertSame(BASE_URL . '/assets/css/app.css', assetUrl('assets/css/app.css'));
        $this->assertSame(BASE_URL . '/assets/css/app.css', assetUrl('/assets/css/app.css'));
    }

    #[Test]
    public function page_url_strips_leading_slash_and_prefixes_with_base(): void
    {
        $this->assertSame(BASE_URL . '/dashboard.php',         pageUrl('dashboard.php'));
        $this->assertSame(BASE_URL . '/dashboard.php',         pageUrl('/dashboard.php'));
        $this->assertSame(BASE_URL . '/admin/units.php',       pageUrl('admin/units.php'));
        $this->assertSame(BASE_URL . '/payments/collection.php', pageUrl('/payments/collection.php'));
    }

    #[Test]
    public function url_helpers_never_produce_double_slashes(): void
    {
        foreach (['foo', '/foo', '//foo', 'foo/bar', '/foo/bar'] as $path) {
            $this->assertStringNotContainsString('//', ltrim(assetUrl($path), BASE_URL));
            $this->assertStringNotContainsString('//', ltrim(pageUrl($path),  BASE_URL));
        }
    }

    #[Test]
    public function base_url_constant_is_a_string(): void
    {
        // Sanity guard: BASE_URL must always be a string (default '')
        // so callers can concatenate without type errors.
        $this->assertIsString(BASE_URL);
    }
}
