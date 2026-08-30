<?php
// api/cash_api.php
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
csrfRequirePost();
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Record / Update Remittance ───────────────────────────────
if ($action === 'save_remittance') {
    $id      = (int)($_POST['id'] ?? 0);
    $userId  = (int)($_POST['user_id'] ?? $_SESSION['user']['id']);
    $amount  = trim((string)($_POST['amount'] ?? '0'));
    $txDate  = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes   = nullOrStr($_POST['notes'] ?? '');
    $docUrl  = nullOrStr($_POST['doc_url'] ?? '');
    $docPath = null;
    if ($userId !== (int)$_SESSION['user']['id'] && !isAdmin()) jsonErr('You can only record your own remittances.');
    if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
    if (!empty($_FILES['doc_file']['name'])) {
        $up = handleUpload('doc_file', 'remittance');
        if ($up['error']) jsonErr($up['error']);
        $docPath = $up['path'];
    }
    if ($id) {
        requireAdmin();
        $tx = $pdo->prepare("SELECT * FROM cash_transactions WHERE id=? AND transaction_type='remitted' AND reference_payment_id IS NULL AND reference_expense_id IS NULL");
        $tx->execute([$id]);
        $before = $tx->fetch();
        if (!$before) jsonErr('Remittance not found or cannot be edited.');
        $pdo->beginTransaction();
        try {
            if ($docPath) {
                $pdo->prepare("UPDATE cash_transactions SET user_id=?,amount=?,notes=?,doc_path=?,doc_url=?,transaction_date=? WHERE id=?")
                    ->execute([$userId,$amount,$notes,$docPath,$docUrl,$txDate,$id]);
            } else {
                $pdo->prepare("UPDATE cash_transactions SET user_id=?,amount=?,notes=?,doc_url=?,transaction_date=? WHERE id=?")
                    ->execute([$userId,$amount,$notes,$docUrl,$txDate,$id]);
            }
            $after = ['user_id'=>$userId,'amount'=>$amount,'notes'=>$notes,'transaction_date'=>$txDate];
            logChange($pdo,'UPDATE_REMITTANCE','Cash',array_intersect_key($before,array_flip(['user_id','amount','notes','transaction_date'])),$after);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg' => 'Remittance updated successfully.']);
    } else {
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,notes,doc_path,doc_url,transaction_date) VALUES (?,?,?,?,?,?,?)")
            ->execute([$userId,'remitted',$amount,$notes,$docPath,$docUrl,$txDate]);
        logActivity($pdo,'RECORD_REMITTANCE','Cash',"Remitted ₱{$amount} by user #{$userId}");
        jsonOk(['msg' => 'Remittance recorded successfully.']);
    }
}

// ── Get Single Remittance (for edit modal) ───────────────────
if ($action === 'get_remittance') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    $tx = $pdo->prepare("SELECT * FROM cash_transactions WHERE id=? AND transaction_type='remitted' AND reference_payment_id IS NULL AND reference_expense_id IS NULL");
    $tx->execute([$id]);
    $data = $tx->fetch();
    if (!$data) jsonErr('Remittance not found.');
    jsonOk(['remittance' => $data]);
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
    $userId   = (int)($_POST['user_id']   ?? 0);
    $month    = (int)($_POST['month']     ?? 0);
    $year     = (int)($_POST['year']      ?? date('Y'));
    $type     = trim($_POST['type']       ?? '');
    $dateFrom = nullOrStr($_POST['date_from'] ?? '');
    $dateTo   = nullOrStr($_POST['date_to']   ?? '');
    // Admins and accountants can list any user's cash transactions; everyone
    // else is silently scoped to their own data (staff role).
    $canViewAll = isAdmin() || isAccountant();
    if (!$canViewAll && $userId !== (int)$_SESSION['user']['id']) $userId = (int)$_SESSION['user']['id'];
    $where = ['1=1']; $params = [];
    if ($userId) { $where[] = 'ct.user_id=?'; $params[] = $userId; }
    if ($dateFrom && $dateTo) {
        $where[] = 'ct.transaction_date BETWEEN ? AND ?'; $params[] = $dateFrom; $params[] = $dateTo;
    } elseif ($dateFrom) {
        $where[] = 'ct.transaction_date >= ?'; $params[] = $dateFrom;
    } elseif ($dateTo) {
        $where[] = 'ct.transaction_date <= ?'; $params[] = $dateTo;
    } else {
        if ($month > 0 && $year > 0) {
            [$ms, $me] = monthRange($month, $year);
            $where[] = 'ct.transaction_date >= ? AND ct.transaction_date < ?';
            $params[] = $ms; $params[] = $me;
        } elseif ($year > 0) {
            [$ys, $ye] = yearRange($year);
            $where[] = 'ct.transaction_date >= ? AND ct.transaction_date < ?';
            $params[] = $ys; $params[] = $ye;
        }
    }
    if ($type) { $where[] = 'ct.transaction_type=?'; $params[] = $type; }
    $rows = $pdo->prepare("
        SELECT ct.*, u.full_name AS user_name, u.role AS user_role,
               p.invoice_no AS linked_invoice, e.description AS linked_expense,
               -- Proof of the SOURCE record. Only manual remittances and vault
               -- returns carry a document on the cash row itself (doc_path/
               -- doc_url); a collection/refund row's proof lives on the payment
               -- and an expense row's on the expense. Without these columns the
               -- Proof column rendered blank for every linked transaction even
               -- when a receipt had been uploaded.
               p.receipt_path AS payment_receipt_path, p.receipt_url AS payment_receipt_url,
               e.receipt_path AS expense_receipt_path, e.receipt_url AS expense_receipt_url
        FROM   cash_transactions ct
        LEFT JOIN users    u ON ct.user_id              = u.id
        LEFT JOIN payments p ON ct.reference_payment_id = p.id
        LEFT JOIN expenses e ON ct.reference_expense_id = e.id
        WHERE  ".implode(' AND ',$where)."
        ORDER  BY ct.transaction_date DESC, ct.created_at DESC
    ");
    $rows->execute($params);
    $txns = $rows->fetchAll();
    $rec = $rem = $exp = $vretFromVault = $ref = [];
    foreach ($txns as $t) {
        if ($t['transaction_type']==='received')     $rec[]           = $t['amount'];
        if ($t['transaction_type']==='remitted')     $rem[]           = $t['amount'];
        if ($t['transaction_type']==='expense')      $exp[]           = $t['amount'];
        if ($t['transaction_type']==='vault_return') $vretFromVault[] = $t['amount'];
        if ($t['transaction_type']==='refunded')     $ref[]           = $t['amount'];
    }
    $totRec     = money_sum($rec);
    $totRem     = money_sum($rem);
    $totExp     = money_sum($exp);
    $totVaultIn = money_sum($vretFromVault);
    $totRef     = money_sum($ref);
    // Period net = received + vault_return - remitted - expenses - refunded,
    // scoped to the active date/type filter. This is the net CHANGE during the
    // shown period, NOT cash on hand — within one month you can remit cash that
    // was collected in earlier months, so this can legitimately go negative.
    $periodNet = money_sub(money_sub(money_sub(money_add($totRec, $totVaultIn), $totRem), $totExp), $totRef);
    // True cash on hand = the user's full lifetime running balance, ignoring the
    // date/type filter. This is what the summary strip's "Net Cash on Hand" cell
    // displays so it always reflects the real balance regardless of the filter.
    if ($userId) {
        $trueCashOnHand = getUserCashOnHand($pdo, $userId);
    } else {
        // "All Staff" selected — sum every user's lifetime balance (mirrors the
        // unscoped period totals shown beside it).
        $allBal = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type='received'     THEN amount ELSE 0 END),0) AS received,
                COALESCE(SUM(CASE WHEN transaction_type='vault_return' THEN amount ELSE 0 END),0) AS vault_return,
                COALESCE(SUM(CASE WHEN transaction_type='remitted'     THEN amount ELSE 0 END),0) AS remitted,
                COALESCE(SUM(CASE WHEN transaction_type='expense'      THEN amount ELSE 0 END),0) AS expenses,
                COALESCE(SUM(CASE WHEN transaction_type='refunded'     THEN amount ELSE 0 END),0) AS refunded
            FROM cash_transactions
        ")->fetch();
        $trueCashOnHand = money_sub(
            money_sub(money_sub(money_add($allBal['received'], $allBal['vault_return']), $allBal['remitted']), $allBal['expenses']),
            $allBal['refunded']
        );
    }
    jsonOk([
        'transactions'        => $txns,
        'total_received'      => $totRec,
        'total_remitted'      => $totRem,
        'total_expenses'      => $totExp,
        'total_vault_returns' => $totVaultIn,
        'total_refunded'      => $totRef,
        'cash_on_hand'        => $periodNet,       // period net (legacy field)
        'true_cash_on_hand'   => $trueCashOnHand,  // lifetime running balance
        'count'               => count($txns),
    ]);
}

// ── All Users Balance Summary (admin + accountant — both view-only roles
// for cash reconciliation). Writes against another user's cash still
// require admin (see save_remittance / get_remittance / delete_cash_tx).
if ($action === 'all_users_balance') {
    requireRole(['admin', 'accountant']);
    $rows = $pdo->prepare("
        SELECT u.id, u.full_name, u.role,
            COALESCE(SUM(CASE WHEN ct.transaction_type='received'     THEN ct.amount ELSE 0 END),0) AS total_received,
            COALESCE(SUM(CASE WHEN ct.transaction_type='remitted'     THEN ct.amount ELSE 0 END),0) AS total_remitted,
            COALESCE(SUM(CASE WHEN ct.transaction_type='expense'      THEN ct.amount ELSE 0 END),0) AS total_expenses,
            COALESCE(SUM(CASE WHEN ct.transaction_type='vault_return' THEN ct.amount ELSE 0 END),0) AS total_vault_returns,
            COALESCE(SUM(CASE WHEN ct.transaction_type='refunded'     THEN ct.amount ELSE 0 END),0) AS total_refunded
        FROM users u
        LEFT JOIN cash_transactions ct ON ct.user_id=u.id
        WHERE u.status='active'
        GROUP BY u.id ORDER BY u.full_name
    ");
    $rows->execute();
    $data = $rows->fetchAll();
    foreach ($data as &$d) {
        // cash_on_hand = received + vault_return - remitted - expenses - refunded
        $d['cash_on_hand'] = money_sub(
            money_sub(money_sub(money_add($d['total_received'], $d['total_vault_returns']), $d['total_remitted']),
            $d['total_expenses']),
            $d['total_refunded']
        );
    }
    unset($d);
    $totals = [
        'total_received' => money_sum(array_column($data, 'total_received')),
        'total_remitted' => money_sum(array_column($data, 'total_remitted')),
        'total_expenses' => money_sum(array_column($data, 'total_expenses')),
        'net_on_hand'    => money_sum(array_column($data, 'cash_on_hand')),
    ];
    jsonOk(['users' => $data, 'totals' => $totals]);
}

exit;
