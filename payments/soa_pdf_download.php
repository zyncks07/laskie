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

// Validate date params before they reach the SQL inside soa_pdf.php (where
// they would be safely bound anyway) and — more importantly — before they
// land in the Content-Disposition filename below. Without this, anything
// the user pastes into the URL leaks into the downloaded file name.
$isoDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($isoDate, $dateFrom)) $dateFrom = date('Y-01-01');
if (!preg_match($isoDate, $dateTo))   $dateTo   = date('Y-m-d');
// Keep $_GET in sync so the included soa_pdf.php sees the sanitized values.
$_GET['date_from'] = $dateFrom;
$_GET['date_to']   = $dateTo;

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
    error_log('soa_pdf_download: ' . $e->getMessage());
    logActivity($pdo, 'EXPORT_SOA_PDF_FAILED', 'SOA', substr($e->getMessage(), 0, 240));
    http_response_code(500);
    die('Could not generate the Statement of Account PDF. See the system audit log for details.');
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
