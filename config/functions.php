<?php
// config/functions.php — Core Helper Functions

define('UPLOAD_BASE', __DIR__ . '/../uploads/');
define('UPLOAD_URL_BASE', '/uploads/');
define('APP_VERSION', '1.0.0');

// ─── Currency & Number Formatting ───────────────────────────
function money(float $amount): string {
    global $pdo;
    static $symbol = null;
    if ($symbol === null) {
        try {
            $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='currency_symbol'")->fetchColumn();
            $symbol = $s ?: '₱';
        } catch (Exception $e) { $symbol = '₱'; }
    }
    return $symbol . number_format($amount, 2);
}

function fmtDate(?string $date, string $format = 'M j, Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function monthName(int $m): string {
    return date('F', mktime(0,0,0,$m,1,2000));
}

// ─── Authentication Helpers ──────────────────────────────────
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function isAccountant(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'accountant';
}

function requireLogin(): void {
    if (!isset($_SESSION['user'])) {
        if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
            exit;
        }
        header('Location: ' . rtrim(dirname($_SERVER['PHP_SELF']), '/admin/payments') . '/index.php?err=session');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin access required.']);
            exit;
        }
        http_response_code(403);
        die(render403());
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user']['role'], $roles)) {
        if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Insufficient permissions.']);
            exit;
        }
        http_response_code(403);
        die(render403());
    }
}

function render403(): string {
    return '<!DOCTYPE html><html><head><title>Access Denied</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;color:#64748b;"><h2 style="color:#dc2626;">&#128683; Access Denied</h2><p>You do not have permission to access this page.</p><a href="../dashboard.php" style="color:#1d4ed8;">Return to Dashboard</a></body></html>';
}

// ─── System Logging ──────────────────────────────────────────
function logActivity(PDO $pdo, string $action, string $module, string $details = ''): void {
    $user = currentUser();
    $userId = $user['id'] ?? null;
    $username = $user['username'] ?? 'system';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, username, action, module, details, ip_address) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId, $username, $action, $module, $details, $ip]);
    } catch (Exception $e) { /* silent */ }
}

// ─── Invoice Number Generator ────────────────────────────────
function generateInvoiceNo(PDO $pdo): string {
    $prefix = 'INV';
    try {
        $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn();
        if ($row) $prefix = $row;
    } catch (Exception $e) {}
    $year = date('Y');
    $seq = $pdo->query("SELECT COUNT(*)+1 FROM payments WHERE YEAR(created_at) = $year")->fetchColumn();
    return $prefix . '-' . $year . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

// ─── File Upload Handler ─────────────────────────────────────
function handleUpload(string $fieldName, string $subDir): array {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            1 => 'File exceeds server upload limit.',
            2 => 'File exceeds form upload limit.',
            3 => 'File only partially uploaded.',
            6 => 'No temporary folder on server.',
            7 => 'Server cannot write temp file.',
        ];
        return ['path' => null, 'error' => $uploadErrors[$file['error']] ?? 'Upload error code: ' . $file['error']];
    }
    $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','zip'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        return ['path' => null, 'error' => 'File type not allowed: .' . $ext];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['path' => null, 'error' => 'File too large (max 10MB). Your file: ' . round($file['size']/1048576,1) . 'MB'];
    }
    $dir = UPLOAD_BASE . $subDir . '/';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            return ['path' => null, 'error' => 'Cannot create upload directory. Run: sudo chmod -R 775 /var/www/laskie/uploads && sudo chown -R www-data:www-data /var/www/laskie/uploads'];
        }
    }
    if (!is_writable($dir)) {
        return ['path' => null, 'error' => 'Upload directory not writable. Run on server: sudo chmod -R 775 /var/www/laskie/uploads && sudo chown -R www-data:www-data /var/www/laskie/uploads'];
    }
    $filename = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return ['path' => null, 'error' => 'Failed to save file. Run: sudo chown -R www-data:www-data /var/www/laskie/uploads'];
    }
    return ['path' => UPLOAD_URL_BASE . $subDir . '/' . $filename, 'error' => null];
}

// ─── Proration Helper ────────────────────────────────────────
// Returns the prorated charge for a month when contract_start falls after
// the due day, otherwise returns the full monthly rate.
// Formula: (rate / days_in_month) × (days_in_month − move_in_day + 1)
function prorateFirstMonth(float $monthlyRate, int $dueDay, ?string $contractStart, int $month, int $year): float {
    if (!$contractStart || $monthlyRate <= 0) return $monthlyRate;
    $cs = new DateTime($contractStart);
    if ((int)$cs->format('Y') !== $year || (int)$cs->format('n') !== $month) return $monthlyRate;
    $csDay = (int)$cs->format('j');
    if ($csDay <= $dueDay) return $monthlyRate;
    $daysInMonth  = (int)(new DateTime("$year-$month-01"))->format('t');
    $daysOccupied = $daysInMonth - $csDay + 1;
    return round(($monthlyRate / $daysInMonth) * $daysOccupied, 2);
}

// Returns the correct charge date: contract_start when prorating, otherwise due day.
function chargeDate(int $dueDay, ?string $contractStart, int $month, int $year): string {
    if ($contractStart) {
        $cs = new DateTime($contractStart);
        if ((int)$cs->format('Y') === $year && (int)$cs->format('n') === $month && (int)$cs->format('j') > $dueDay) {
            return $contractStart;
        }
    }
    $daysInMonth = (int)(new DateTime("$year-$month-01"))->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min($dueDay, $daysInMonth));
}

// ─── Payment Status Calculator ───────────────────────────────
function getUnitPaymentStatus(PDO $pdo, int $unitId, int $month, int $year): string {
    $unit = $pdo->prepare("SELECT ru.monthly_rate, ru.due_day, t.contract_start
                           FROM rental_units ru
                           LEFT JOIN tenants t ON t.unit_id = ru.id AND t.status = 'active'
                           WHERE ru.id = ? LIMIT 1");
    $unit->execute([$unitId]);
    $u = $unit->fetch();
    if (!$u) return 'gray';

    $paid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE unit_id=? AND payment_type='rent' AND period_month=? AND period_year=?");
    $paid->execute([$unitId, $month, $year]);
    $totalPaid = (float)$paid->fetchColumn();
    $expected  = prorateFirstMonth((float)$u['monthly_rate'], (int)$u['due_day'], $u['contract_start'] ?? null, $month, $year);

    if ($totalPaid <= 0 && $expected > 0) {
        $dueDate = mktime(0,0,0, $month, $u['due_day'], $year);
        if (time() > $dueDate) return 'red';
        return 'amber';
    }
    if ($totalPaid >= $expected) return 'green';
    if ($totalPaid > 0 && $totalPaid < $expected) return 'amber';
    return 'green';
}

// ─── JSON Response Helpers ───────────────────────────────────
function jsonOk(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ─── Sanitize ────────────────────────────────────────────────
function clean(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function nullOrStr(?string $v): ?string {
    $v = trim($v ?? '');
    return $v === '' ? null : $v;
}

// ─── Get App Setting ─────────────────────────────────────────
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $v = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $v->execute([$key]);
        $r = $v->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (Exception $e) { return $default; }
}
