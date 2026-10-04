<?php
// admin/contract_pdf_download.php — the contract as a real A4 PDF via
// headless Chromium (renderHtmlToPdf). Same pipeline as the SoA export:
// HTML is rendered in-process, vendor fonts resolved to file:// paths.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/contract_functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$q  = $pdo->prepare("SELECT * FROM contracts WHERE id=?");
$q->execute([$id]);
$row = $q->fetch();
if (!$row) { http_response_code(404); die('Contract not found.'); }

$fields = contractFields($row);
$html   = renderContractDocument($fields, contractMeta($pdo, $row), 'pdf');

try {
    $tmpPdf = renderHtmlToPdf($html);
} catch (Throwable $e) {
    error_log('contract_pdf_download: ' . $e->getMessage());
    logActivity($pdo, 'EXPORT_CONTRACT_PDF_FAILED', 'Contracts', substr($e->getMessage(), 0, 240));
    http_response_code(500);
    die('Could not generate the contract PDF. See the system audit log for details.');
}

$slug  = fn(string $v) => preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9\-_]/', '_', $v), '_'));
$ref   = $row['contract_no'] ?: ('DRAFT-' . $id);
$name  = $slug((string)($fields['lessee_name'] ?: 'Lessee'));
$filename = 'Contract-' . $slug($ref) . ($name !== '' ? '-' . $name : '') . '.pdf';

logActivity($pdo, 'EXPORT_CONTRACT_PDF', 'Contracts', "Downloaded contract #$id ($ref) as PDF");

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpPdf);
@unlink($tmpPdf);
exit;
