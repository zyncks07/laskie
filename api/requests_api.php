<?php
// api/requests_api.php — Vault cash-request workflow + in-app notifications.
//
// Staff/accountant request cash to be returned to them from the Vault (to fund a
// tenant deposit refund or an unexpected expense after they've remitted all cash).
// An admin approves — which AUTO-ISSUES the existing vault_return cash row,
// crediting the requester's cash-on-hand — or rejects. Each event drops a
// notification for the topbar bell. See migration 009 + admin/requests.php.
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
csrfRequirePost();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$me     = currentUser();
$myId   = (int)($me['id'] ?? 0);

// ── Create a request (staff / accountant / admin, for themselves) ─────────────
if ($action === 'create_request') {
    $amount  = trim((string)($_POST['amount'] ?? '0'));
    $purpose = trim($_POST['purpose'] ?? '');
    $type    = $_POST['request_type'] ?? 'other';
    $refPay  = (int)($_POST['reference_payment_id'] ?? 0) ?: null;

    if (!in_array($type, ['refund_fund', 'expense_fund', 'other'], true)) $type = 'other';
    if (!money_is_pos($amount))          jsonErr('Amount must be greater than zero.');
    if (money_gt($amount, '9999999.99')) jsonErr('Amount exceeds the maximum allowed (₱9,999,999.99).');
    if ($purpose === '')                 jsonErr('Purpose is required.');
    if (mb_strlen($purpose) > 255) $purpose = mb_substr($purpose, 0, 255);

    $pdo->prepare("INSERT INTO vault_requests (requested_by, request_type, amount, purpose, reference_payment_id) VALUES (?,?,?,?,?)")
        ->execute([$myId, $type, $amount, $purpose, $refPay]);
    $reqId = (int)$pdo->lastInsertId();

    // Store the message as raw text — every render path escapes (bell uses
    // textContent; admin table uses clean()). Pre-encoding here would double-escape.
    notifyAdmins($pdo, 'request_created',
        ($me['full_name'] ?? 'A user') . " requested " . money($amount) . " from the Vault: " . $purpose,
        'admin/requests.php', $reqId);
    logActivity($pdo, 'CREATE_VAULT_REQUEST', 'VaultRequest', "Requested " . money($amount) . " ($type): $purpose");
    jsonOk(['msg' => 'Request submitted. An admin will review it.', 'id' => $reqId]);
}

// ── List requests (own for non-admins; all for admins) ────────────────────────
if ($action === 'list_requests') {
    $where  = [];
    $params = [];
    if (!isAdmin()) { $where[] = 'vr.requested_by = ?'; $params[] = $myId; }
    $status = $_POST['status'] ?? $_GET['status'] ?? '';
    if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        $where[] = 'vr.status = ?'; $params[] = $status;
    }
    $sql = "SELECT vr.*, ru.full_name AS requester_name, rv.full_name AS reviewer_name,
                   p.invoice_no AS ref_invoice
            FROM vault_requests vr
            LEFT JOIN users ru    ON vr.requested_by = ru.id
            LEFT JOIN users rv    ON vr.reviewed_by  = rv.id
            LEFT JOIN payments p  ON vr.reference_payment_id = p.id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY vr.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonOk(['requests' => $stmt->fetchAll()]);
}

// ── Approve a request → auto-issue the vault_return (admin) ────────────────────
if ($action === 'approve_request') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Request ID required.');

    $pdo->beginTransaction();
    try {
        $rq = $pdo->prepare("SELECT * FROM vault_requests WHERE id=? FOR UPDATE");
        $rq->execute([$id]);
        $req = $rq->fetch();
        if (!$req)                      jsonErr('Request not found.');
        if ($req['status'] !== 'pending') jsonErr('This request is already ' . $req['status'] . '.');

        // Auto-issue the vault return: credits the requester's cash-on-hand and
        // (by the vault balance formula) debits the Vault. Mirrors the manual
        // add_user_return flow in admin/vault.php.
        $note = "Vault return — approved request #{$id}: " . $req['purpose'];
        $pdo->prepare("INSERT INTO cash_transactions (user_id, transaction_type, amount, transaction_date, notes) VALUES (?,'vault_return',?,?,?)")
            ->execute([$req['requested_by'], $req['amount'], date('Y-m-d'), $note]);
        $cashTxId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE vault_requests SET status='approved', reviewed_by=?, reviewed_at=NOW(), cash_tx_id=? WHERE id=?")
            ->execute([$myId, $cashTxId, $id]);

        notifyUser($pdo, (int)$req['requested_by'], 'request_approved',
            "Your vault request of " . money($req['amount']) . " was approved — cash added to your on-hand.",
            'cash.php', $id);
        logActivity($pdo, 'APPROVE_VAULT_REQUEST', 'VaultRequest',
            "Approved request #{$id} (" . money($req['amount']) . ") → vault_return cash tx #{$cashTxId} for user #{$req['requested_by']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Request approved and cash returned to the user.']);
}

// ── Reject a request (admin) ──────────────────────────────────────────────────
if ($action === 'reject_request') {
    requireAdmin();
    $id   = (int)($_POST['id'] ?? 0);
    $note = trim($_POST['decision_note'] ?? '');
    if (!$id) jsonErr('Request ID required.');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    // Lock + recheck inside a transaction, same as approve_request, so a
    // concurrent approval can't leave a request rejected after its cash was issued.
    $pdo->beginTransaction();
    try {
        $rq = $pdo->prepare("SELECT * FROM vault_requests WHERE id=? FOR UPDATE");
        $rq->execute([$id]);
        $req = $rq->fetch();
        if (!$req)                        jsonErr('Request not found.');
        if ($req['status'] !== 'pending') jsonErr('This request is already ' . $req['status'] . '.');

        $pdo->prepare("UPDATE vault_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), decision_note=? WHERE id=?")
            ->execute([$myId, ($note !== '' ? $note : null), $id]);
        notifyUser($pdo, (int)$req['requested_by'], 'request_rejected',
            "Your vault request of " . money($req['amount']) . " was rejected." . ($note !== '' ? " Reason: " . $note : ''),
            'cash.php', $id);
        logActivity($pdo, 'REJECT_VAULT_REQUEST', 'VaultRequest', "Rejected request #{$id}" . ($note !== '' ? ": $note" : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Request rejected.']);
}

// ── Cancel a pending request (own, or admin) ──────────────────────────────────
if ($action === 'cancel_request') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Request ID required.');
    $pdo->beginTransaction();
    try {
        $rq = $pdo->prepare("SELECT * FROM vault_requests WHERE id=? FOR UPDATE");
        $rq->execute([$id]);
        $req = $rq->fetch();
        if (!$req)                                          jsonErr('Request not found.');
        if (!isAdmin() && (int)$req['requested_by'] !== $myId) jsonErr('You can only cancel your own requests.', 403);
        if ($req['status'] !== 'pending')                   jsonErr('Only pending requests can be cancelled.');

        $pdo->prepare("UPDATE vault_requests SET status='cancelled' WHERE id=?")->execute([$id]);
        logActivity($pdo, 'CANCEL_VAULT_REQUEST', 'VaultRequest', "Cancelled request #{$id}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Request cancelled.']);
}

// ── Notifications (current user only) ─────────────────────────────────────────
if ($action === 'unread_count') {
    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $c->execute([$myId]);
    jsonOk(['count' => (int)$c->fetchColumn()]);
}

if ($action === 'list_notifications') {
    $n = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $n->execute([$myId]);
    $rows = $n->fetchAll();
    $unread = 0;
    foreach ($rows as $r) { if (!$r['is_read']) $unread++; }
    jsonOk(['notifications' => $rows, 'unread' => $unread]);
}

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Notification ID required.');
    // Scoped to the current user — no cross-user mark-read.
    $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?")->execute([$id, $myId]);
    jsonOk();
}

if ($action === 'mark_all_read') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jsonErr('POST required.', 405);
    $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0")->execute([$myId]);
    jsonOk();
}

jsonErr('Unknown action.');
