<?php
// api/cash_api.php
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Record Remittance ────────────────────────────────────────
if ($action === 'save_remittance') {
    $userId  = (int)($_POST['user_id'] ?? $_SESSION['user']['id']);
    $amount  = (float)($_POST['amount'] ?? 0);
    $txDate  = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes   = nullOrStr($_POST['notes'] ?? '');
    $docUrl  = nullOrStr($_POST['doc_url'] ?? '');
    $docPath = null;
    if ($userId !== (int)$_SESSION['user']['id'] && !isAdmin()) jsonErr('You can only record your own remittances.');
    if ($amount <= 0) jsonErr('Amount must be greater than zero.');
    if (!empty($_FILES['doc_file']['name'])) {
        $up = handleUpload('doc_file', 'remittance');
        if ($up['error']) jsonErr($up['error']);
        $docPath = $up['path'];
    }
    $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,notes,doc_path,doc_url,transaction_date) VALUES (?,?,?,?,?,?,?)")
        ->execute([$userId,'remitted',$amount,$notes,$docPath,$docUrl,$txDate]);
    logActivity($pdo,'RECORD_REMITTANCE','Cash',"Remitted ₱{$amount} by user #{$userId}");
    jsonOk(['msg' => 'Remittance recorded successfully.']);
}

// ── Delete Manual Cash Tx ────────────────────────────────────
if ($action === 'delete_cash_tx') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    $tx = $pdo->prepare("SELECT * FROM cash_transactions WHERE id=?");
    $tx->execute([$id]);
    $t = $tx->fetch();
    if (!$t) jsonErr('Transaction not found.');
    if ($t['reference_payment_id'] || $t['reference_expense_id']) {
        jsonErr('Linked to a payment or expense — delete the source record instead.');
    }
    $pdo->prepare("DELETE FROM cash_transactions WHERE id=?")->execute([$id]);
    logActivity($pdo,'DELETE_CASH_TX','Cash',"Deleted cash tx #$id ₱{$t['amount']}");
    jsonOk(['msg' => 'Transaction deleted.']);
}

// ── List Transactions ────────────────────────────────────────
if ($action === 'list_transactions') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $month  = (int)($_POST['month']   ?? 0);
    $year   = (int)($_POST['year']    ?? date('Y'));
    $type   = trim($_POST['type']     ?? '');
    if (!isAdmin() && $userId !== (int)$_SESSION['user']['id']) $userId = (int)$_SESSION['user']['id'];
    $where = ['1=1']; $params = [];
    if ($userId)    { $where[] = 'ct.user_id=?';               $params[] = $userId; }
    if ($month > 0) { $where[] = 'MONTH(ct.transaction_date)=?'; $params[] = $month; }
    if ($year  > 0) { $where[] = 'YEAR(ct.transaction_date)=?';  $params[] = $year; }
    if ($type)      { $where[] = 'ct.transaction_type=?';        $params[] = $type; }
    $rows = $pdo->prepare("
        SELECT ct.*, u.full_name AS user_name, u.role AS user_role,
               p.invoice_no AS linked_invoice, e.description AS linked_expense
        FROM   cash_transactions ct
        LEFT JOIN users    u ON ct.user_id              = u.id
        LEFT JOIN payments p ON ct.reference_payment_id = p.id
        LEFT JOIN expenses e ON ct.reference_expense_id = e.id
        WHERE  ".implode(' AND ',$where)."
        ORDER  BY ct.transaction_date DESC, ct.created_at DESC
    ");
    $rows->execute($params);
    $txns = $rows->fetchAll();
    $totRec = $totRem = $totExp = 0;
    foreach ($txns as $t) {
        if ($t['transaction_type']==='received') $totRec += (float)$t['amount'];
        if ($t['transaction_type']==='remitted') $totRem += (float)$t['amount'];
        if ($t['transaction_type']==='expense')  $totExp += (float)$t['amount'];
    }
    jsonOk(['transactions'=>$txns,'total_received'=>$totRec,'total_remitted'=>$totRem,'total_expenses'=>$totExp,'cash_on_hand'=>$totRec-$totRem-$totExp,'count'=>count($txns)]);
}

// ── All Users Balance Summary (admin) ────────────────────────
if ($action === 'all_users_balance') {
    requireAdmin();
    $rows = $pdo->prepare("
        SELECT u.id, u.full_name, u.role,
            COALESCE(SUM(CASE WHEN ct.transaction_type='received' THEN ct.amount ELSE 0 END),0) AS total_received,
            COALESCE(SUM(CASE WHEN ct.transaction_type='remitted' THEN ct.amount ELSE 0 END),0) AS total_remitted,
            COALESCE(SUM(CASE WHEN ct.transaction_type='expense'  THEN ct.amount ELSE 0 END),0) AS total_expenses
        FROM users u
        LEFT JOIN cash_transactions ct ON ct.user_id=u.id
        WHERE u.status='active'
        GROUP BY u.id ORDER BY u.full_name
    ");
    $rows->execute();
    $data = $rows->fetchAll();
    foreach ($data as &$d) $d['cash_on_hand'] = (float)$d['total_received']-(float)$d['total_remitted']-(float)$d['total_expenses'];
    unset($d);
    jsonOk(['users' => $data]);
}

exit;
