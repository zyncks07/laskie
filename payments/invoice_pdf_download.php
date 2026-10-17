<?php
// payments/invoice_pdf_download.php — one payment's invoice as an A4 PDF, for
// emailing. Renders invoice_print.php in-process (same document, same access
// rules: staff can't fetch a voided / refunded / deleted receipt) and pipes it
// through headless chromium like the SoA and contract exports.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$q  = $pdo->prepare("SELECT p.invoice_no, t.full_name FROM payments p LEFT JOIN tenants t ON t.id = p.tenant_id WHERE p.id=?");
$q->execute([$id]);
$pay = $q->fetch();
if (!$pay) { http_response_code(404); die('Payment not found.'); }

// invoice_print.php die()s with a message for an invalid / no-longer-valid
// receipt; that text reaches the browser as a non-PDF reply, which the
// download dialog shows as the error. Mark it as a failure, not a 200.
http_response_code(403);
$invoicePdf = true;
ob_start();
include __DIR__ . '/invoice_print.php';
$html = (string)ob_get_clean();
http_response_code(200);

$html = str_replace('../assets/vendor/', pdfAssetsBaseUrl(), $html);

try {
    $tmpPdf = renderHtmlToPdf($html);
} catch (Throwable $e) {
    error_log('invoice_pdf_download: ' . $e->getMessage());
    logActivity($pdo, 'EXPORT_INVOICE_PDF_FAILED', 'Payments', substr($e->getMessage(), 0, 240));
    http_response_code(500);
    die('Could not generate the invoice PDF. See the system audit log for details.');
}

$slug     = fn(string $v) => preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9\-_]/', '_', $v), '_'));
$name     = $slug((string)($pay['full_name'] ?? ''));
$filename = 'Invoice-' . ($slug((string)$pay['invoice_no']) ?: $id) . ($name !== '' ? '-' . $name : '') . '.pdf';

logActivity($pdo, 'EXPORT_INVOICE_PDF', 'Payments', "Downloaded invoice {$pay['invoice_no']} (payment #$id) as PDF");

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpPdf);
@unlink($tmpPdf);
exit;
