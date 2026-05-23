<?php
// payments/soa_pdf_download.php
// Renders the Statement of Account as a real PDF via Chromium headless.
// The HTML is generated in-process by including soa_pdf.php under output
// buffering — no self-HTTP-call, no session forwarding, no port assumption.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$unitId   = (int)($_GET['unit_id']   ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

if (!$unitId) { http_response_code(400); die('Unit ID required.'); }

$s = $pdo->prepare("SELECT unit_name FROM rental_units WHERE id=?");
$s->execute([$unitId]);
$unitName = $s->fetchColumn() ?: 'Unit';

// Render the SoA HTML in-process. soa_pdf.php reads from $_GET (already set
// above) and echoes a full HTML document. Output-buffer it instead of
// streaming to the browser.
ob_start();
include __DIR__ . '/soa_pdf.php';
$html = ob_get_clean();

if (!is_string($html) || strlen($html) < 100) {
    http_response_code(500);
    die('Statement of Account rendered empty — cannot generate PDF.');
}

// Rewrite relative asset references so chromium can load them from disk
// when rendering the temp HTML file. pdfAssetsBaseUrl() resolves to the
// real path of assets/vendor/ — no hardcoded paths.
$html = str_replace('../assets/vendor/', pdfAssetsBaseUrl(), $html);

try {
    $tmpPdf = renderHtmlToPdf($html);
} catch (Throwable $e) {
    http_response_code(500);
    die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$safeName = preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9\-_]/', '_', $unitName), '_'));
$filename = "SOA-{$safeName}-{$dateFrom}-to-{$dateTo}.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpPdf);
@unlink($tmpPdf);
exit;
