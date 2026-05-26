<?php
/**
 * config/env.php — minimal .env loader for Laskie RMS
 *
 * Reads KEY=VALUE pairs from the project's .env file (one level above this
 * file) and define()s them as PHP constants if they aren't already defined.
 *
 * Why this exists:
 *   Pre-migration, config/db.php was a generated PHP file with the DB password
 *   hardcoded into a define(). That meant the password lived inside a file
 *   committed to deploys, with no way to rotate it without redeploying the
 *   whole project. With this loader, deploy.sh writes a chmod-600 .env that
 *   the web user owns; config/db.php becomes static and committable.
 *
 * Format:
 *   - One assignment per line: KEY=VALUE
 *   - Blank lines and lines starting with `#` are ignored
 *   - Values may be wrapped in "double" or 'single' quotes; only "..." honors
 *     the standard \\, \", \n, \r, \t escapes (single quotes are literal)
 *   - Leading/trailing whitespace on the value is trimmed
 *
 * Behavior when .env is missing:
 *   No-op. Constants must then be defined elsewhere (legacy config/db.php
 *   with inline define()s still works — env.php only fills in gaps).
 *
 * Safety:
 *   - Wrapped in an immediately-invoked static closure so no locals leak.
 *   - Only define()s constants that aren't already defined — values that
 *     came from an earlier load path win.
 */

(static function () {
    $envPath = realpath(__DIR__ . '/../.env');
    if (!$envPath || !is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $val = rtrim($m[2]);

        // Strip surrounding quotes if present; only double quotes honor escapes.
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last  = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
                if ($first === '"') {
                    $val = strtr($val, [
                        '\\\\' => '\\',
                        '\\"'  => '"',
                        '\\n'  => "\n",
                        '\\r'  => "\r",
                        '\\t'  => "\t",
                    ]);
                }
            }
        }

        if (!defined($key)) {
            define($key, $val);
        }
    }
})();
