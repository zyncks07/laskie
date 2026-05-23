<?php
// tests/bootstrap.php
// Loads the project's config/functions.php so test cases can call helpers
// (money_*, csrf*, prorateFirstMonth, etc.) directly.
//
// We do NOT eagerly load config/db.php here — it would try to connect to
// MySQL even for pure-Unit runs and fail noisily if the DB is down.
// Integration tests load it lazily via Tests\Integration\IntegrationTestCase.

declare(strict_types=1);

require_once __DIR__ . '/../config/functions.php';

// Initialise $_SESSION as a plain array so helpers that touch it
// (csrfToken, requireLogin) don't trigger undefined-variable warnings
// under E_ALL when no real PHP session is active.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = $_SESSION ?? [];
}
