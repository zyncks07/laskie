<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for csrfToken / csrfField / csrfRequirePost in config/functions.php.
 * The HTTP-flow integration (POST to a JSON endpoint rejected without a token)
 * lives in tests/Integration/CsrfEnforcementTest.php — these are the pure-PHP
 * helper unit tests.
 */
final class CsrfHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        // Fresh $_SESSION + $_SERVER for each test.
        $_SESSION = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST = [];
    }

    #[Test]
    public function csrf_token_is_64_hex_chars(): void
    {
        $t = csrfToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $t);
    }

    #[Test]
    public function csrf_token_is_stable_within_a_session(): void
    {
        $a = csrfToken();
        $b = csrfToken();
        $this->assertSame($a, $b);
    }

    #[Test]
    public function csrf_token_changes_after_session_reset(): void
    {
        $a = csrfToken();
        $_SESSION = [];
        $b = csrfToken();
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function csrf_field_emits_safe_hidden_input(): void
    {
        $field = csrfField();
        $this->assertStringContainsString('type="hidden"',          $field);
        $this->assertStringContainsString('name="csrf_token"',      $field);
        $this->assertStringContainsString('value="' . csrfToken() . '"', $field);
    }

    #[Test]
    public function csrf_require_post_is_a_noop_for_get_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['csrf_token']    = str_repeat('a', 64);
        // No exception, no exit — just returns. If it errored, the test would fail.
        csrfRequirePost();
        $this->assertTrue(true);
    }
}
