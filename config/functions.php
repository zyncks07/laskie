<?php
// config/functions.php — Core Helper Functions

define('UPLOAD_BASE', __DIR__ . '/../uploads/');
define('UPLOAD_URL_BASE', '/uploads/');
define('APP_VERSION', '1.0.0');

// ─── BASE_URL Configuration ──────────────────────────────────
// Web-relative root of the app. Empty = the app is mounted at the document
// root (most common for dedicated vhosts). Set this in config/db.php — BEFORE
// requiring functions.php — if the app lives under a subdirectory, e.g.:
//     define('BASE_URL', '/laskie');
// All app URLs (asset paths, sidebar links) should be built via
// assetUrl() / pageUrl() so a future relocation is a one-constant change.
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// Build an absolute path to a static asset (CSS/JS/uploads).
//     assetUrl('assets/css/app.css')  →  '/assets/css/app.css'  (root-mounted)
//     assetUrl('assets/css/app.css')  →  '/laskie/assets/css/app.css' (subdir)
function assetUrl(string $path): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

// Build an absolute path to a page (dashboard, admin/units.php, etc).
function pageUrl(string $path): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

// ─── Currency & Number Formatting ───────────────────────────
function money($amount): string {
    global $pdo;
    static $symbol = null;
    if ($symbol === null) {
        try {
            $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='currency_symbol'")->fetchColumn();
            $symbol = $s ?: '₱';
        } catch (Exception $e) { $symbol = '₱'; }
    }
    // Accepts int|float|string (incl. canonical "0.00" strings from money_*() helpers).
    return $symbol . number_format((float)$amount, 2);
}

// ─── Money Math (cents-based — exact integer arithmetic) ─────
// Why: PHP floats accumulate rounding errors after additions/subtractions.
// DECIMAL(12,2) in DB is exact, but any PHP-side arithmetic should
// canonicalise to integer cents to keep the books consistent.
// All helpers accept int|float|string and return canonical "0.00" strings.

// Convert any scalar to integer cents (HALF_UP rounding for accounting).
function to_cents($v): int {
    if (is_int($v))   return $v * 100;
    $f = (float)$v;
    return $f >= 0 ? (int)floor($f * 100 + 0.5) : -(int)floor(-$f * 100 + 0.5);
}

// Convert integer cents back to canonical "0.00" string.
function from_cents(int $cents): string {
    $abs   = abs($cents);
    $whole = intdiv($abs, 100);
    $frac  = $abs % 100;
    return ($cents < 0 ? '-' : '') . sprintf('%d.%02d', $whole, $frac);
}

function money_add($a, $b): string { return from_cents(to_cents($a) + to_cents($b)); }
function money_sub($a, $b): string { return from_cents(to_cents($a) - to_cents($b)); }

function money_sum(array $vals): string {
    $total = 0;
    foreach ($vals as $v) $total += to_cents($v);
    return from_cents($total);
}

// Multiply money by a real factor (rate × days_occupied). HALF_UP to cents.
function money_mul($a, float $factor): string {
    $r = to_cents($a) * $factor;
    return from_cents($r >= 0 ? (int)floor($r + 0.5) : -(int)floor(-$r + 0.5));
}

// Divide money by a real divisor (rate / days_in_month). HALF_UP to cents.
function money_div($a, float $divisor): string {
    if ($divisor == 0.0) throw new DivisionByZeroError('money_div: divisor is zero');
    $r = to_cents($a) / $divisor;
    return from_cents($r >= 0 ? (int)floor($r + 0.5) : -(int)floor(-$r + 0.5));
}

function money_cmp($a, $b): int  { return to_cents($a) <=> to_cents($b); }
function money_eq($a, $b): bool  { return money_cmp($a, $b) === 0; }
function money_gt($a, $b): bool  { return money_cmp($a, $b) >  0; }
function money_gte($a, $b): bool { return money_cmp($a, $b) >= 0; }
function money_lt($a, $b): bool  { return money_cmp($a, $b) <  0; }
function money_lte($a, $b): bool { return money_cmp($a, $b) <= 0; }
function money_max($a, $b): string { return money_gt($a, $b) ? from_cents(to_cents($a)) : from_cents(to_cents($b)); }
function money_is_zero($a): bool  { return to_cents($a) === 0; }
function money_is_pos($a): bool   { return to_cents($a) >  0; }

function fmtDate(?string $date, string $format = 'M j, Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function monthName(int $m): string {
    return date('F', mktime(0,0,0,$m,1,2000));
}

// ─── Date Range Helpers (sargable WHERE clauses) ─────────────
// Use these in place of WHERE YEAR(col)=? / MONTH(col)=? so the
// optimizer can use idx_pay_date / idx_exp_date / idx_cash_user_date.
// Returns [startDate, exclusiveEndDate] in 'YYYY-MM-DD' form.

// monthRange(5, 2026)  → ['2026-05-01', '2026-06-01']
// monthRange(12, 2026) → ['2026-12-01', '2027-01-01']
function monthRange(int $month, int $year): array {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = $month === 12
        ? sprintf('%04d-01-01', $year + 1)
        : sprintf('%04d-%02d-01', $year, $month + 1);
    return [$start, $end];
}

// yearRange(2026) → ['2026-01-01', '2027-01-01']
function yearRange(int $year): array {
    return [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
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
        header('Location: ' . pageUrl('index.php') . '?err=session');
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

// ─── CSRF Protection ─────────────────────────────────────────
// Returns the per-session CSRF token, creating one on first call.
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Renders a hidden form input for traditional <form method=POST>.
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Validate CSRF on POST requests. No-op for GET so it can be called
// unconditionally at the top of dual page/API endpoints.
// Reads token from $_POST['csrf_token'] or the X-CSRF-Token header.
// On mismatch: 403 JSON if JSON_RESPONSE, else 403 text. Always exits.
function csrfRequirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $expected = $_SESSION['csrf_token'] ?? '';
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
        http_response_code(403);
        if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF token missing or invalid. Reload the page and try again.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden — CSRF token missing or invalid. Reload the page and try again.";
        }
        exit;
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

// Returns the originating client IP. We deliberately ignore X-Forwarded-For:
// Apache on this host listens directly on port 49200 with no upstream proxy
// stripping headers, so any client could spoof XFF to forge audit rows or
// bypass the IP arm of the login lockout. Trust REMOTE_ADDR only. If a real
// reverse proxy is added later, define TRUSTED_PROXY_IPS in config/db.php and
// teach this helper to honor XFF when REMOTE_ADDR is in that list.
function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// ─── System Logging ──────────────────────────────────────────
function logActivity(PDO $pdo, string $action, string $module, string $details = ''): void {
    $user = currentUser();
    $userId = $user['id'] ?? null;
    $username = $user['username'] ?? 'system';
    $ip = getClientIp();
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, username, action, module, details, ip_address) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId, $username, $action, $module, $details, $ip]);
    } catch (Exception $e) { /* silent */ }
}

// Logs a before/after diff. Stores JSON in system_logs.details.
function logChange(PDO $pdo, string $action, string $module, array $before, array $after): void {
    $details = json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logActivity($pdo, $action, $module, $details);
}

// ─── Invoice Number Generator ────────────────────────────────
function generateInvoiceNo(PDO $pdo): string {
    $prefix = 'INV';
    try {
        $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn();
        if ($row) $prefix = $row;
    } catch (Exception $e) {}
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $max  = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED)),0) FROM payments WHERE invoice_no LIKE ?");
    $max->execute([$like]);
    return $prefix . '-' . $year . '-' . str_pad((int)$max->fetchColumn() + 1, 5, '0', STR_PAD_LEFT);
}

// ─── Image Compression ───────────────────────────────────────
// Re-encodes an uploaded image in place to shrink storage. Phone-screenshot
// receipts at 1–4 MB routinely compress to ~50–200 KB without losing legibility.
//
// Returns ['compressed'=>bool, 'original_size'=>int, 'new_size'=>int,
//          'new_path'=>?string, 'reason'=>?string].
// `new_path` is the file's absolute path AFTER compression — it may differ
// from $absPath when the extension changes (e.g. .png → .jpg).
// Non-image files (PDF/DOC/ZIP) return ['compressed'=>false, 'reason'=>'not-image']
// and are left untouched.
//
// Tunables (overridable via config/db.php define()s before functions.php loads):
//   IMAGE_COMPRESSION_MAX_DIM  — long-edge cap in pixels (default 2000)
//   IMAGE_COMPRESSION_QUALITY  — JPEG/WebP quality 0–100 (default 78)
if (!defined('IMAGE_COMPRESSION_MAX_DIM')) define('IMAGE_COMPRESSION_MAX_DIM', 1600);
if (!defined('IMAGE_COMPRESSION_QUALITY')) define('IMAGE_COMPRESSION_QUALITY', 72);

function compressImage(string $absPath, array $opts = []): array {
    $maxDim    = (int)($opts['max_dimension'] ?? IMAGE_COMPRESSION_MAX_DIM);
    $quality   = (int)($opts['quality']       ?? IMAGE_COMPRESSION_QUALITY);
    $pngToJpeg = (bool)($opts['png_to_jpeg']  ?? true); // flatten alpha-less PNGs to JPEG

    $orig = ['compressed' => false, 'original_size' => 0, 'new_size' => 0, 'new_path' => null, 'reason' => null];
    if (!is_file($absPath))                  return ['reason' => 'file-missing']    + $orig;
    if (!function_exists('imagecreatetruecolor')) return ['reason' => 'gd-missing'] + $orig;

    $info = @getimagesize($absPath);
    if ($info === false) return ['reason' => 'not-image'] + $orig;

    $mime     = $info['mime'];
    $w        = (int)$info[0];
    $h        = (int)$info[1];
    $origSize = (int)(filesize($absPath) ?: 0);
    $orig['original_size'] = $origSize;

    // Skip formats we can't safely re-encode in place
    if ($mime === 'image/gif')  return ['reason' => 'gif-skipped'] + $orig;  // may be animated
    if ($mime === 'image/avif') return ['reason' => 'avif-skipped'] + $orig;
    if ($mime === 'image/bmp')  return ['reason' => 'bmp-skipped'] + $orig;

    // Decode
    try {
        $src = match ($mime) {
            'image/jpeg', 'image/pjpeg' => imagecreatefromjpeg($absPath),
            'image/png'                 => imagecreatefrompng($absPath),
            'image/webp'                => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($absPath) : false,
            default                     => false,
        };
    } catch (\Throwable $e) { $src = false; }
    if (!$src) return ['reason' => 'decode-failed'] + $orig;

    // EXIF orientation correction (JPEG only — PNG/WebP rarely carry useful EXIF)
    if (($mime === 'image/jpeg' || $mime === 'image/pjpeg') && function_exists('exif_read_data')) {
        $exif = @exif_read_data($absPath);
        $rotateBy = 0;
        if (!empty($exif['Orientation'])) {
            $rotateBy = match ((int)$exif['Orientation']) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        }
        if ($rotateBy !== 0) {
            $rotated = @imagerotate($src, $rotateBy, 0);
            if ($rotated) { imagedestroy($src); $src = $rotated; $w = imagesx($src); $h = imagesy($src); }
        }
    }

    // Detect alpha — only for PNG (WebP we always re-encode to WebP; JPEG has no alpha)
    $hasAlpha = false;
    if ($mime === 'image/png') {
        $hasAlpha = _imageHasAlpha($src, $w, $h);
    }

    // Scale down if larger than $maxDim on the long edge
    $scale = max($w, $h) > $maxDim ? ($maxDim / max($w, $h)) : 1.0;
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) { imagedestroy($src); return ['reason' => 'canvas-failed'] + $orig; }

    if ($hasAlpha && !$pngToJpeg) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    } else {
        // Flatten on white — receipts/screenshots look correct when alpha is dropped
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        if ($hasAlpha) imagealphablending($dst, true); // composite PNG-with-alpha over white
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    // Choose target format
    if ($hasAlpha && !$pngToJpeg) {
        $targetExt = 'png';
    } elseif ($mime === 'image/webp') {
        $targetExt = 'webp';
    } else {
        $targetExt = 'jpg';
    }

    $tmp = $absPath . '.compress.tmp';
    $ok  = false;
    if ($targetExt === 'png') {
        $ok = @imagepng($dst, $tmp, 8); // 0=none .. 9=max; 8 is a good speed/size tradeoff
    } elseif ($targetExt === 'webp') {
        $ok = @imagewebp($dst, $tmp, $quality);
    } else {
        $ok = @imagejpeg($dst, $tmp, $quality);
    }
    imagedestroy($dst);

    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return ['reason' => 'encode-failed'] + $orig;
    }

    $newSize = (int)(filesize($tmp) ?: PHP_INT_MAX);

    // Skip the swap when savings are negligible (<5%). Re-encoding tiny files
    // often makes them larger; bail out and keep the original.
    if ($newSize >= $origSize * 0.95) {
        @unlink($tmp);
        return ['compressed' => false, 'original_size' => $origSize, 'new_size' => $newSize, 'new_path' => null, 'reason' => 'no-gain'];
    }

    $origExt = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if ($targetExt !== $origExt) {
        $newPath = preg_replace('/\.[^.]+$/', '.' . $targetExt, $absPath);
        if (!@rename($tmp, $newPath)) { @unlink($tmp); return ['reason' => 'rename-failed'] + $orig; }
        @unlink($absPath);
        return ['compressed' => true, 'original_size' => $origSize, 'new_size' => $newSize, 'new_path' => $newPath, 'reason' => null];
    }
    if (!@rename($tmp, $absPath)) { @unlink($tmp); return ['reason' => 'rename-failed'] + $orig; }
    return ['compressed' => true, 'original_size' => $origSize, 'new_size' => $newSize, 'new_path' => $absPath, 'reason' => null];
}

// Sampled alpha probe — scans on a coarse grid. Cheap and good enough for
// distinguishing receipt screenshots (opaque) from PNGs with real transparency.
function _imageHasAlpha($im, int $w, int $h): bool {
    $step = max(1, (int)floor(min($w, $h) / 32));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $rgba  = imagecolorat($im, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F; // GD: 0=opaque, 127=transparent
            if ($alpha > 0) return true;
        }
    }
    return false;
}

// ─── File Upload Handler ─────────────────────────────────────
// $allowedExts (optional): override the default file-type whitelist
// $maxBytes (optional):    override the default 30 MB size cap
// $verifyImage (optional): when true, validates the file actually IS an image
//                          via getimagesize() — defeats renamed-extension tricks
// Images (JPEG/PNG/WebP) are auto-compressed in place via compressImage();
// non-image uploads (PDF/DOC/ZIP) are left untouched.
function handleUpload(
    string $fieldName,
    string $subDir,
    ?array $allowedExts = null,
    ?int   $maxBytes = null,
    bool   $verifyImage = false
): array {
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
    $allowed = $allowedExts ?? ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','zip'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        return ['path' => null, 'error' => 'File type not allowed: .' . $ext . ' (expected: ' . implode(', ', $allowed) . ')'];
    }
    $cap = $maxBytes ?? (30 * 1024 * 1024);
    if ($file['size'] > $cap) {
        $capMb  = round($cap / 1048576, 1);
        $fileMb = round($file['size'] / 1048576, 1);
        return ['path' => null, 'error' => "File too large (max {$capMb}MB). Your file: {$fileMb}MB"];
    }
    if ($verifyImage) {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['path' => null, 'error' => 'File is not a valid image.'];
        }
        // getimagesize returns mime type — restrict further if caller asked for JPEG only.
        if (in_array('jpg', $allowed) || in_array('jpeg', $allowed)) {
            if (!in_array($info['mime'], ['image/jpeg', 'image/pjpeg'])) {
                return ['path' => null, 'error' => 'File is not a JPEG (got: ' . $info['mime'] . ').'];
            }
        }
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
    $absPath  = $dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['path' => null, 'error' => 'Failed to save file. Run: sudo chown -R www-data:www-data /var/www/laskie/uploads'];
    }

    // Compress JPEG/PNG/WebP in place. Non-image types (PDF/DOC/ZIP) are a no-op.
    // If compression rewrites the file with a new extension (PNG → JPG), the
    // returned URL reflects the new path so callers store the correct value.
    $c = compressImage($absPath);
    if ($c['compressed'] && $c['new_path']) {
        $filename = basename($c['new_path']);
    }

    return ['path' => UPLOAD_URL_BASE . $subDir . '/' . $filename, 'error' => null];
}

// ─── Rate History Lookup ─────────────────────────────────────
// Returns the effective monthly rate for a unit in a given month/year
// by finding the most recent unit_rate_history row on or before that month.
// Falls back to $baseRate (rental_units.monthly_rate) if no history exists.
function getRateForMonth(PDO $pdo, int $unitId, float $baseRate, int $month, int $year): float {
    try {
        $lastDay = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
        $stmt = $pdo->prepare(
            "SELECT monthly_rate FROM unit_rate_history
             WHERE unit_id = ? AND effective_date <= ?
             ORDER BY effective_date DESC LIMIT 1"
        );
        $stmt->execute([$unitId, $lastDay]);
        $r = $stmt->fetchColumn();
        return $r !== false ? (float)$r : $baseRate;
    } catch (Exception $e) { return $baseRate; }
}

// ─── Proration Helper ────────────────────────────────────────
// Returns the prorated charge for a month when contract_start falls after
// the due day, otherwise returns the full monthly rate.
// Formula: (rate / days_in_month) × (days_in_month − move_in_day + 1)
// Returns canonical "0.00" string from cents math (exact, no float drift).
function prorateFirstMonth($monthlyRate, int $dueDay, ?string $contractStart, int $month, int $year): string {
    $rate = to_cents($monthlyRate);
    if (!$contractStart || $rate <= 0) return from_cents($rate);
    $cs = new DateTime($contractStart);
    if ((int)$cs->format('Y') !== $year || (int)$cs->format('n') !== $month) return from_cents($rate);
    $csDay = (int)$cs->format('j');
    if ($csDay <= $dueDay) return from_cents($rate);
    $daysInMonth  = (int)(new DateTime("$year-$month-01"))->format('t');
    $daysOccupied = $daysInMonth - $csDay + 1;
    // Single-round formula: round((rate × days_occupied) / days_in_month) at cents.
    // Computed in cents to keep both ops exact; one HALF_UP at the end.
    $numerator = $rate * $daysOccupied;
    $rounded   = $numerator >= 0
        ? (int)floor($numerator / $daysInMonth + 0.5)
        : -(int)floor(-$numerator / $daysInMonth + 0.5);
    return from_cents($rounded);
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

    $rate = getRateForMonth($pdo, $unitId, (float)$u['monthly_rate'], $month, $year);

    // Sum in SQL (exact DECIMAL aggregate); compare in cents to avoid float drift.
    $paid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE unit_id=? AND payment_type='rent' AND period_month=? AND period_year=? AND deleted_at IS NULL AND status != 'voided'");
    $paid->execute([$unitId, $month, $year]);
    $totalPaid = $paid->fetchColumn();
    $expected  = prorateFirstMonth($rate, (int)$u['due_day'], $u['contract_start'] ?? null, $month, $year);

    if (money_is_zero($totalPaid) && money_is_pos($expected)) {
        $daysInMonth = (int)date('t', mktime(0,0,0,$month,1,$year));
        $cappedDay   = min((int)$u['due_day'], $daysInMonth);
        $dueDate     = mktime(0,0,0, $month, $cappedDay, $year);
        return time() > $dueDate ? 'red' : 'amber';
    }
    if (money_gte($totalPaid, $expected)) return 'green';
    if (money_is_pos($totalPaid) && money_lt($totalPaid, $expected)) return 'amber';
    return 'green';
}

// ─── JSON Response Helpers ───────────────────────────────────
function jsonOk(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    // Roll back any open transaction so a validation failure that fires
    // after beginTransaction() doesn't leave a half-written state.
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ─── Global JSON Exception Handler ───────────────────────────
// When JSON_RESPONSE is defined by an API endpoint, uncaught exceptions
// emit a JSON 500 response and roll back any open transaction so the
// database stays consistent.
function jsonExceptionHandler(Throwable $e): void {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

if (defined('JSON_RESPONSE') && JSON_RESPONSE) {
    set_exception_handler('jsonExceptionHandler');
}

// ─── Sanitize ────────────────────────────────────────────────
function clean(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function nullOrStr(?string $v): ?string {
    $v = trim($v ?? '');
    return $v === '' ? null : $v;
}

// ─── Vendor Asset Tags with SRI ──────────────────────────────
// Returns the SRI hash table for self-hosted vendor assets. Regenerate via
// `./scripts/generate-sri.sh` whenever a file in assets/vendor/ is updated,
// then paste the lines below.
function vendorSriMap(): array {
    return [
        'bootstrap.bundle.min.js'        => 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL',
        'bootstrap.min.css'              => 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN',
        'chart.umd.min.js'               => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
        'dataTables.bootstrap5.min.css'  => 'sha384-ok3J6xA9oQqai5C9ytYveFsBeKgoGk4T+NExsr6hoIKjZdv9SJcmx2mafwUWRNf9',
        'dataTables.bootstrap5.min.js'   => 'sha384-PgPBH0hy6DTJwu7pTf6bkRqPlf/+pjUBExpr/eIfzszlGYFlF9Wi9VTAJODPhgCO',
        'fontawesome.min.css'            => 'sha384-/D34rIC1DP7P2syFqB25deF36WNtLkdJwUKzFsoukQQG7dvRpjEI3ZBnpg5COdkj',
        'google-fonts.css'               => 'sha384-iEud/DHxTLt2vCbkAyKINYcMA9CEJiyh8ELI7osYdNLZMTUUpSH6Yq/RIVxSXRxw',
        'jquery.dataTables.min.js'       => 'sha384-cjmdOgDzOE22dUheI5E6Gzd3upfmReW8N1y/4jwKQE50KYcvFKZJA9JxWgQOzqwQ',
        'jquery.min.js'                  => 'sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs',
    ];
}

// Emits a <link> tag for a self-hosted vendor stylesheet with the
// integrity= attribute populated from vendorSriMap. $prefix is the
// caller-supplied relative path prefix (e.g. '../', '').
function vendorCssTag(string $prefix, string $filename): string {
    $sri  = vendorSriMap()[$filename] ?? '';
    $href = htmlspecialchars($prefix . 'assets/vendor/' . $filename, ENT_QUOTES, 'UTF-8');
    $attr = $sri !== '' ? ' integrity="' . htmlspecialchars($sri, ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous"' : '';
    return '<link rel="stylesheet" href="' . $href . '"' . $attr . '>';
}

// Same for <script> tags.
function vendorJsTag(string $prefix, string $filename): string {
    $sri  = vendorSriMap()[$filename] ?? '';
    $src  = htmlspecialchars($prefix . 'assets/vendor/' . $filename, ENT_QUOTES, 'UTF-8');
    $attr = $sri !== '' ? ' integrity="' . htmlspecialchars($sri, ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous"' : '';
    return '<script src="' . $src . '"' . $attr . '></script>';
}

// ─── PDF Generation (Chromium headless) ─────────────────────
// Converts an HTML string to a PDF file via Chromium headless.
// The chromium path is configurable via settings.chromium_path
// (defaults to /usr/bin/chromium). Returns the resolved PDF temp path
// on success, throws RuntimeException on failure with a user-friendly
// message — callers should let the global JSON exception handler emit
// it, or catch and translate to a plain die() for non-JSON endpoints.
//
// $html  — the full HTML document (must already have file:// absolute
//          paths for any local assets it references)
// Returns the path to a temp PDF file. Caller must unlink() after streaming.
function renderHtmlToPdf(string $html): string {
    global $pdo;
    $chromium = getSetting($pdo, 'chromium_path', '/usr/bin/chromium');
    if (!is_executable($chromium)) {
        // Try a couple of common alternates before giving up.
        foreach (['/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/chrome'] as $alt) {
            if (is_executable($alt)) { $chromium = $alt; break; }
        }
    }
    if (!is_executable($chromium)) {
        throw new RuntimeException(
            "PDF generator (chromium) not found. Install with: sudo apt install chromium  " .
            "(or set settings.chromium_path to your binary)."
        );
    }

    $tmpHtml = tempnam(sys_get_temp_dir(), 'laskie_pdf_') . '.html';
    $tmpPdf  = sys_get_temp_dir() . '/laskie_pdf_' . uniqid() . '.pdf';
    if (file_put_contents($tmpHtml, $html) === false) {
        throw new RuntimeException('Could not write temporary HTML file for PDF rendering.');
    }

    $cmd = sprintf(
        '%s --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
        . ' --print-to-pdf=%s --print-to-pdf-no-header %s 2>/dev/null',
        escapeshellarg($chromium),
        escapeshellarg($tmpPdf),
        escapeshellarg('file://' . $tmpHtml)
    );
    exec($cmd, $cmdOut, $exitCode);
    @unlink($tmpHtml);

    if ($exitCode !== 0 || !file_exists($tmpPdf) || filesize($tmpPdf) === 0) {
        @unlink($tmpPdf);
        throw new RuntimeException("PDF generation failed (chromium exit code $exitCode). Check that the chromium binary is runnable by the web user.");
    }
    return $tmpPdf;
}

// Returns the absolute file:// URL of the assets/vendor/ directory.
// Used to rewrite relative asset references in PDF HTML so chromium
// can load them when rendering from a temp file://.
function pdfAssetsBaseUrl(): string {
    return 'file://' . realpath(__DIR__ . '/../assets/vendor') . '/';
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
