<?php
// admin/contract_print.php — on-screen paper view of one contract, with
// Print and Download PDF. The same document (minus the toolbar) is what
// contract_pdf_download.php pipes through chromium.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/contract_functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$q  = $pdo->prepare("SELECT * FROM contracts WHERE id=?");
$q->execute([$id]);
$row = $q->fetch();
if (!$row) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem">Contract not found. <a href="contracts.php">Back to contracts</a></p>');
}

logActivity($pdo, 'VIEW_CONTRACT', 'Contracts', 'Viewed contract #' . $id . ($row['contract_no'] ? ' (' . $row['contract_no'] . ')' : ' (draft)'));
echo renderContractDocument(contractFields($row), contractMeta($pdo, $row), 'screen');
