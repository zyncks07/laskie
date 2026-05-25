<?php
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
csrfRequirePost();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Verify master password ────────────────────────────────────
if ($action === 'verify_master') {
    $pass   = $_POST['password'] ?? '';
    $stored = getSetting($pdo, 'master_password', '');
    if ($stored && password_verify($pass, $stored)) {
        $_SESSION['settings_unlocked'] = true;
        logActivity($pdo, 'SETTINGS_UNLOCK', 'Settings', 'Settings unlocked');
        jsonOk(['msg' => 'Settings unlocked.']);
    }
    jsonErr('Incorrect master password.');
}

if ($action === 'lock') {
    unset($_SESSION['settings_unlocked']);
    jsonOk();
}

// ── All other actions require unlock ─────────────────────────
if (empty($_SESSION['settings_unlocked'])) {
    jsonErr('Settings are locked. Enter master password first.', 403);
}

$uid = currentUser()['id'];

switch ($action) {

    // ── General settings ─────────────────────────────────────
    case 'save_settings': {
        $allowed = [
            'app_name', 'company_name', 'company_address', 'company_phone',
            'company_email', 'invoice_prefix', 'currency_symbol', 'currency_code', 'default_due_day'
        ];
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by)
                               VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                               updated_by=VALUES(updated_by), updated_at=NOW()");
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_POST)) {
                $stmt->execute([$key, trim($_POST[$key]), $uid]);
            }
        }
        logActivity($pdo, 'UPDATE_SETTINGS', 'Settings', 'General settings updated');
        jsonOk(['msg' => 'Settings saved.']);
    }

    // ── Timezone ─────────────────────────────────────────────
    case 'save_timezone': {
        $tz = trim($_POST['timezone'] ?? '');
        if (!$tz || !in_array($tz, DateTimeZone::listIdentifiers())) {
            jsonErr('Invalid timezone identifier.');
        }
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by)
                               VALUES ('db_timezone',?,?)
                               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                               updated_by=VALUES(updated_by), updated_at=NOW()");
        $stmt->execute([$tz, $uid]);
        // Apply immediately for this request
        date_default_timezone_set($tz);
        $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
        $pdo->prepare("SET time_zone = ?")->execute([$offset]);
        logActivity($pdo, 'UPDATE_TIMEZONE', 'Settings', "Timezone set to $tz ($offset)");
        jsonOk(['msg' => "Timezone set to $tz ($offset)."]);
    }

    // ── Change master password ────────────────────────────────
    case 'change_master': {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stored  = getSetting($pdo, 'master_password', '');
        if (!$stored || !password_verify($current, $stored)) jsonErr('Current password is incorrect.');
        if (strlen($new) < 8)  jsonErr('New password must be at least 8 characters.');
        if ($new !== $confirm) jsonErr('Passwords do not match.');
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by)
                       VALUES ('master_password',?,?)
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                       updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$hash, $uid]);
        logActivity($pdo, 'CHANGE_MASTER_PASS', 'Settings', 'Master password changed');
        jsonOk(['msg' => 'Master password updated.']);
    }

    // ── Export: full database ─────────────────────────────────
    case 'export_db': {
        $filename = 'laskie_db_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');
        $cmd = sprintf(
            '/usr/bin/mysqldump --single-transaction --add-drop-table --routines --triggers -h %s -u %s %s',
            escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_NAME)
        );
        $proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, ['MYSQL_PWD' => DB_PASS]);
        if (!is_resource($proc)) { echo '-- mysqldump failed'; exit; }
        fclose($pipes[0]);
        while (!feof($pipes[1])) echo fread($pipes[1], 65536);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        logActivity($pdo, 'EXPORT_DB', 'Settings', 'Full database exported');
        exit;
    }

    // ── Export: receipts & documents ZIP ─────────────────────
    case 'export_receipts': {
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if (!$uploadsDir) jsonErr('Uploads directory not found.');
        $tmpFile = tempnam(sys_get_temp_dir(), 'laskie_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonErr('Could not create ZIP archive.');
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $relative = 'uploads/' . str_replace('\\', '/', substr($file->getRealPath(), strlen($uploadsDir) + 1));
                $zip->addFile($file->getRealPath(), $relative);
                $count++;
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="laskie_uploads_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-cache, no-store');
        readfile($tmpFile);
        unlink($tmpFile);
        logActivity($pdo, 'EXPORT_RECEIPTS', 'Settings', "Uploaded files exported: $count files");
        exit;
    }

    // ── Export: user accounts JSON ────────────────────────────
    case 'export_accounts': {
        $users = $pdo->query(
            "SELECT id, username, full_name, role, email, phone, phone2, address, status, created_at
             FROM users ORDER BY id"
        )->fetchAll();
        $out = json_encode(['exported_at' => date('Y-m-d H:i:s'), 'version' => '1.0', 'users' => $users], JSON_PRETTY_PRINT);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="laskie_accounts_' . date('Ymd_His') . '.json"');
        header('Cache-Control: no-cache, no-store');
        echo $out;
        logActivity($pdo, 'EXPORT_ACCOUNTS', 'Settings', 'User accounts exported');
        exit;
    }

    // ── Export: settings JSON ─────────────────────────────────
    case 'export_settings': {
        $rows = $pdo->query(
            "SELECT setting_key, setting_value FROM settings
             WHERE setting_key != 'master_password' ORDER BY setting_key"
        )->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[$r['setting_key']] = $r['setting_value'];
        $out = json_encode(['exported_at' => date('Y-m-d H:i:s'), 'version' => '1.0', 'settings' => $map], JSON_PRETTY_PRINT);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="laskie_settings_' . date('Ymd_His') . '.json"');
        header('Cache-Control: no-cache, no-store');
        echo $out;
        logActivity($pdo, 'EXPORT_SETTINGS', 'Settings', 'Settings exported');
        exit;
    }

    // ── Import: SQL database ──────────────────────────────────
    case 'import_db': {
        if (empty($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            jsonErr('No file uploaded or upload error.');
        }
        $f   = $_FILES['sql_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') jsonErr('Only .sql files are accepted.');
        if ($f['size'] > 100 * 1024 * 1024) jsonErr('File too large (max 100 MB).');
        set_time_limit(300);
        $cmd = sprintf('mysql -h %s -u %s %s',
            escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_NAME));
        $proc = proc_open($cmd,
            [0 => ['file', $f['tmp_name'], 'r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes, null, ['MYSQL_PWD' => DB_PASS]);
        if (!is_resource($proc)) jsonErr('Failed to start mysql import process.');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) jsonErr('Import failed: ' . ($err ?: 'unknown error'));
        // Re-lock since master password may have changed in the imported data
        unset($_SESSION['settings_unlocked']);
        logActivity($pdo, 'IMPORT_DB', 'Settings', 'Database restored from: ' . $f['name']);
        jsonOk(['msg' => 'Database imported. Please re-enter the master password.', 'relock' => true]);
    }

    // ── Import: receipts ZIP ──────────────────────────────────
    case 'import_receipts': {
        if (empty($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            jsonErr('No file uploaded or upload error.');
        }
        $f   = $_FILES['zip_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') jsonErr('Only .zip files are accepted.');
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) jsonErr('Invalid or corrupt ZIP file.');
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '..') !== false || strpos($name, 'uploads/') !== 0) continue;
            if (str_ends_with($name, '/')) {
                $dir = $uploadsDir . '/' . substr($name, strlen('uploads/'));
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                continue;
            }
            // Whitelist extensions — block PHP and other executable types.
            $fileExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowedExts = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx'];
            if (!in_array($fileExt, $allowedExts, true)) continue;
            $target = $uploadsDir . '/' . substr($name, strlen('uploads/'));
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $data = $zip->getFromIndex($i);
            if ($data !== false) { file_put_contents($target, $data); $count++; }
        }
        $zip->close();
        logActivity($pdo, 'IMPORT_RECEIPTS', 'Settings', "Receipts imported: $count files");
        jsonOk(['msg' => "$count files restored."]);
    }

    // ── Import: accounts JSON ─────────────────────────────────
    case 'import_accounts': {
        if (empty($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
            jsonErr('No file uploaded or upload error.');
        }
        $data = json_decode(file_get_contents($_FILES['json_file']['tmp_name']), true);
        if (!$data || empty($data['users'])) jsonErr('Invalid or empty accounts file.');
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, full_name, role, email, phone, phone2, address, status, password_hash)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), role=VALUES(role),
             email=VALUES(email), phone=VALUES(phone), phone2=VALUES(phone2),
             address=VALUES(address), status=VALUES(status), updated_at=NOW()"
        );
        // Whitelist role/status so an attacker can't elevate accounts via a crafted
        // JSON. The placeholder hash is intentionally invalid — admins must reset
        // each imported user's password through the Accounts page.
        $allowedRoles    = ['admin', 'accountant', 'staff'];
        $allowedStatuses = ['active', 'inactive'];
        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $count = 0;
        foreach ($data['users'] as $u) {
            $username = trim((string)($u['username'] ?? ''));
            $fullName = trim((string)($u['full_name'] ?? ''));
            if ($username === '' || $fullName === '') continue;
            $role   = in_array($u['role']   ?? '', $allowedRoles,    true) ? $u['role']   : 'staff';
            $status = in_array($u['status'] ?? '', $allowedStatuses, true) ? $u['status'] : 'active';
            $stmt->execute([
                $username, $fullName, $role,
                $u['email'] ?? null, $u['phone'] ?? null, $u['phone2'] ?? null,
                $u['address'] ?? null, $status,
                $placeholderHash,
            ]);
            $count++;
        }
        logActivity($pdo, 'IMPORT_ACCOUNTS', 'Settings', "Accounts imported: $count records");
        jsonOk(['msg' => "$count account(s) imported/updated."]);
    }

    // ── Import: settings JSON ─────────────────────────────────
    case 'import_settings': {
        if (empty($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
            jsonErr('No file uploaded or upload error.');
        }
        $data = json_decode(file_get_contents($_FILES['json_file']['tmp_name']), true);
        if (!$data || empty($data['settings'])) jsonErr('Invalid or empty settings file.');
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, updated_by)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
             updated_by=VALUES(updated_by), updated_at=NOW()"
        );
        $count = 0;
        foreach ($data['settings'] as $key => $value) {
            if ($key === 'master_password') continue;
            $stmt->execute([$key, $value, $uid]);
            $count++;
        }
        logActivity($pdo, 'IMPORT_SETTINGS', 'Settings', "Settings imported: $count keys");
        jsonOk(['msg' => "$count setting(s) imported."]);
    }

    // ── Factory Reset ─────────────────────────────────────────
    case 'factory_reset': {
        // Server-side confirmation: the client typed RESET, but a settings-unlocked
        // admin could otherwise wipe data with a single curl call. Require the
        // exact phrase to land in POST before we touch anything.
        if (trim($_POST['confirm'] ?? '') !== 'RESET') {
            jsonErr('Confirmation phrase missing. Send confirm=RESET to proceed.');
        }
        // Truncate every table that holds operational/transactional rows.
        // Tables previously omitted (unit_charges, unit_rate_history, refunds,
        // dividend_*) left orphan rows pointing at FK targets we already wiped.
        $tables = [
            'refunds',
            'cash_transactions',
            'unit_charges',
            'unit_rate_history',
            'payments',
            'expenses',
            'tenant_docs',
            'tenants',
            'rental_units',
            'unit_types',
            'service_types',
            'expense_categories',
            'dividend_returns',
            'dividend_distributions',
            'dividend_recipients',
            'system_logs',
        ];
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // Delete uploaded files
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if ($uploadsDir) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
            }
        }

        logActivity($pdo, 'FACTORY_RESET', 'Settings', 'Factory reset performed by user #' . $uid);
        jsonOk(['msg' => 'Factory reset complete. All data has been erased.']);
    }

    default:
        jsonErr('Unknown action: ' . htmlspecialchars($action));
}
