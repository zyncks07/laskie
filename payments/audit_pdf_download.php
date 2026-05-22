<?php
// payments/audit_pdf_download.php — Server-side PDF generation via Chromium headless

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();

if (empty($_SESSION['settings_unlocked'])) {
    http_response_code(403);
    die('Settings must be unlocked to download the audit report.');
}

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0);

$sessionId = session_id();
session_write_close();

$params = http_build_query(['year' => $year, 'month' => $month]);
$auditUrl = 'http://localhost:49200/payments/audit_pdf.php?' . $params;

$ctx = stream_context_create([
    'http' => [
        'header'  => "Cookie: PHPSESSID=$sessionId\r\n",
        'timeout' => 30,
    ]
]);
$html = @file_get_contents($auditUrl, false, $ctx);
if ($html === false || strlen($html) < 100) {
    http_response_code(500);
    die('Could not fetch audit report page. Ensure the server is running on localhost:8888.');
}

$assetsBase = 'file:///home/bulik/apps/laskie/assets/vendor/';
$html = str_replace('../assets/vendor/', $assetsBase, $html);

$tmpHtml = tempnam('/tmp', 'audit_') . '.html';
$tmpPdf  = sys_get_temp_dir() . '/audit_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

$cmd = sprintf(
    '/usr/bin/chromium --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
    . ' --print-to-pdf=%s --print-to-pdf-no-header %s 2>/dev/null',
    escapeshellarg($tmpPdf),
    escapeshellarg('file://' . $tmpHtml)
);
exec($cmd, $cmdOut, $exitCode);

@unlink($tmpHtml);

if ($exitCode !== 0 || !file_exists($tmpPdf) || filesize($tmpPdf) === 0) {
    @unlink($tmpPdf);
    http_response_code(500);
    die('PDF generation failed (Chromium exit code ' . $exitCode . ').');
}

$period = $month > 0 ? sprintf('%04d-%02d', $year, $month) : (string)$year;
$filename = 'Audit-Report-' . $period . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpPdf);
@unlink($tmpPdf);
exit;
