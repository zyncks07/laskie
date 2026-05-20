<?php
// payments/soa_pdf_download.php — Server-side PDF generation via Chromium headless

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$unitId   = (int)($_GET['unit_id']   ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

if (!$unitId) {
    http_response_code(400);
    die('Unit ID required.');
}

// Fetch unit name for the filename
$s = $pdo->prepare("SELECT unit_name FROM rental_units WHERE id=?");
$s->execute([$unitId]);
$unitName = $s->fetchColumn() ?: 'Unit';

$sessionId = session_id();
session_write_close(); // Release session lock before making internal HTTP request

// Build the internal URL to fetch the rendered SOA HTML
$params = http_build_query([
    'unit_id'   => $unitId,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
]);
$soaUrl = 'http://localhost:8888/payments/soa_pdf.php?' . $params;

$ctx = stream_context_create([
    'http' => [
        'header'  => "Cookie: PHPSESSID=$sessionId\r\n",
        'timeout' => 30,
    ]
]);
$html = @file_get_contents($soaUrl, false, $ctx);
if ($html === false || strlen($html) < 100) {
    http_response_code(500);
    die('Could not fetch SOA page. Ensure the server is running on localhost:8888.');
}

// Rewrite relative asset paths to absolute file:// paths so Chromium can load them
$assetsBase = 'file:///home/patient0/apps/laskie/assets/vendor/';
$html = str_replace('../assets/vendor/', $assetsBase, $html);

// Write HTML to a temp file
$tmpHtml = tempnam('/tmp', 'soa_') . '.html';
$tmpPdf  = sys_get_temp_dir() . '/soa_' . uniqid() . '.pdf';
file_put_contents($tmpHtml, $html);

// Run Chromium headless
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
    die('PDF generation failed (Chromium exit code ' . $exitCode . '). '
      . 'Check /usr/bin/chromium exists and is executable by www-data.');
}

// Sanitize unit name for use in filename
$safeName = preg_replace('/[^A-Za-z0-9\-_]/', '_', $unitName);
$safeName = preg_replace('/_+/', '_', trim($safeName, '_'));
$filename = 'SOA-' . $safeName . '-' . $dateFrom . '-to-' . $dateTo . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpPdf);
@unlink($tmpPdf);
exit;
