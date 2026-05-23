<?php
// payments/audit_pdf_download.php
// Renders the admin audit report as a real PDF via Chromium headless.
// HTML is generated in-process (no self-HTTP-call) — see soa_pdf_download.php
// for the same pattern.

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

ob_start();
include __DIR__ . '/audit_pdf.php';
$html = ob_get_clean();

if (!is_string($html) || strlen($html) < 100) {
    http_response_code(500);
    die('Audit report rendered empty — cannot generate PDF.');
}

$html = str_replace('../assets/vendor/', pdfAssetsBaseUrl(), $html);

try {
    $tmpPdf = renderHtmlToPdf($html);
} catch (Throwable $e) {
    http_response_code(500);
    die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
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
