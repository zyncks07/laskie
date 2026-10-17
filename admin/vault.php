<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
// The Vault is accessible to both admins and accountants. Every write action
// on this page (remittances, distributions, dividend returns, recipient
// management, and vault→user returns) is open to both roles — the page-level
// requireRole below is the access boundary; no action adds an extra isAdmin().
requireRole(['admin', 'accountant']);

$pageTitle = 'The Vault';
$depth = '../';
$needsChartJs = true;   // load Chart.js (footer is gated on this) for the vault charts

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    csrfRequirePost();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_remittance') {
        $remittedBy = (int)($_POST['remitted_by'] ?? 0);
        $amount     = trim((string)($_POST['amount'] ?? '0'));
        $date       = trim($_POST['remittance_date'] ?? '');
        $notes      = nullOrStr($_POST['notes'] ?? '');
        $docUrl     = nullOrStr($_POST['doc_url'] ?? '');
        $docPath    = null;
        if (!$remittedBy) jsonErr('Please select who is remitting.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        // Confirm the target user actually exists + is active (mirrors add_user_return).
        $uChk = $pdo->prepare("SELECT full_name FROM users WHERE id=? AND status='active'");
        $uChk->execute([$remittedBy]);
        if (!$uChk->fetchColumn()) jsonErr('Selected user is not active or does not exist.');
        // Optional proof attachment — same shape as cash_api.php::save_remittance.
        // handleUpload stores under uploads/remittance/ with the project's
        // standard whitelist + image auto-compression.
        if (!empty($_FILES['doc_file']['name'])) {
            $up = handleUpload('doc_file', 'remittance');
            if ($up['error']) jsonErr($up['error']);
            $docPath = $up['path'];
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,transaction_date,notes,doc_path,doc_url) VALUES (?,?,?,?,?,?,?)")
                ->execute([$remittedBy,'remitted',$amount,$date,$notes,$docPath,$docUrl]);
            logActivity($pdo,'VAULT_REMITTANCE','Vault',"Remittance " . money($amount) . " from user #$remittedBy" . ($docPath || $docUrl ? ' (with proof)' : ''));
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Remittance recorded.']);
    }

    if ($action === 'delete_remittance') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM cash_transactions WHERE id=? AND transaction_type='remitted'");
        $chk->execute([$id]);
        if (!$chk->fetch()) jsonErr('Remittance not found.');
        $pdo->prepare("DELETE FROM cash_transactions WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_VAULT_REMITTANCE','Vault',"Deleted remittance #$id");
        jsonOk(['msg'=>'Remittance deleted.']);
    }

    if ($action === 'add_distribution') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = trim((string)($_POST['amount'] ?? '0'));
        $date        = trim($_POST['distribution_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        $receiptUrl  = nullOrStr($_POST['receipt_url'] ?? '');
        $receiptPath = null;
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        // Reject javascript:/data:/file: on the way IN — the Distribution Records
        // table is server-rendered, so the stored value must already be safe.
        if ($receiptUrl && !preg_match('#^https?://#i', $receiptUrl)) {
            jsonErr('External links must start with http:// or https://');
        }
        $rChk = $pdo->prepare("SELECT id FROM dividend_recipients WHERE id=? AND is_active=1");
        $rChk->execute([$recipientId]);
        if (!$rChk->fetch()) jsonErr('Recipient not found or inactive.');
        // Optional proof attachment. handleUpload() applies the project whitelist,
        // the 30 MB cap and in-place JPEG/PNG/WebP compression (PDFs untouched) —
        // same pipeline as expense and payment receipts. Validated AFTER the cheap
        // checks so a rejected distribution never leaves an orphan file on disk.
        if (!empty($_FILES['receipt_file']['name'])) {
            $up = handleUpload('receipt_file', 'dividends');
            if ($up['error']) jsonErr($up['error']);
            $receiptPath = $up['path'];
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO dividend_distributions (recipient_id,amount,distribution_date,notes,receipt_path,receipt_url,created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$recipientId,$amount,$date,$notes,$receiptPath,$receiptUrl,$_SESSION['user']['id']]);
            logActivity($pdo,'DIVIDEND_DISTRIBUTION','Vault',"Distributed " . money($amount) . " to recipient #$recipientId" . ($receiptPath || $receiptUrl ? ' (with proof)' : ''));
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Dividend distribution recorded.']);
    }

    if ($action === 'delete_distribution') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('Distribution ID required.');
        $chk = $pdo->prepare("SELECT receipt_path FROM dividend_distributions WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) jsonErr('Distribution not found.');
        $pdo->prepare("DELETE FROM dividend_distributions WHERE id=?")->execute([$id]);
        // Row first, file second: a failed DELETE must not destroy the proof,
        // whereas a failed unlink only leaves a harmless orphan. The
        // '/uploads/' prefix check keeps a tampered column from reaching
        // anything outside the upload tree (same guard as tenants.php
        // delete_doc). This is a hard delete by design — a distribution is not
        // a ledger row — so the attachment goes with it.
        $fp = (string)($row['receipt_path'] ?? '');
        if ($fp !== '' && str_starts_with($fp, '/uploads/')) {
            @unlink(__DIR__ . '/..' . $fp);
        }
        logActivity($pdo,'DELETE_DIVIDEND_DIST','Vault',"Deleted distribution #$id" . ($fp !== '' ? ' (proof file removed)' : ''));
        jsonOk(['msg'=>'Distribution deleted.']);
    }

    if ($action === 'edit_distribution') {
        $id          = (int)($_POST['id'] ?? 0);
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = trim((string)($_POST['amount'] ?? '0'));
        $date        = trim($_POST['distribution_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        $receiptUrl  = nullOrStr($_POST['receipt_url'] ?? '');
        $receiptPath = null;
        if (!$id) jsonErr('Distribution ID required.');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        if ($receiptUrl && !preg_match('#^https?://#i', $receiptUrl)) {
            jsonErr('External links must start with http:// or https://');
        }
        $chk = $pdo->prepare("SELECT recipient_id, amount, distribution_date, notes, receipt_path, receipt_url FROM dividend_distributions WHERE id=?");
        $chk->execute([$id]);
        $before = $chk->fetch();
        if (!$before) jsonErr('Distribution not found.');
        // Uploaded only after the row is known to exist, so a bad id can't leave
        // an orphan file behind (same ordering as expenses_api.php::save_expense).
        if (!empty($_FILES['receipt_file']['name'])) {
            $up = handleUpload('receipt_file', 'dividends');
            if ($up['error']) jsonErr($up['error']);
            $receiptPath = $up['path'];
        }
        $pdo->beginTransaction();
        try {
            // A new upload REPLACES the stored path; no upload leaves the existing
            // file attached (the URL field is always overwritten by the form).
            if ($receiptPath) {
                $pdo->prepare("UPDATE dividend_distributions SET recipient_id=?,amount=?,distribution_date=?,notes=?,receipt_path=?,receipt_url=? WHERE id=?")
                    ->execute([$recipientId,$amount,$date,$notes,$receiptPath,$receiptUrl,$id]);
            } else {
                $pdo->prepare("UPDATE dividend_distributions SET recipient_id=?,amount=?,distribution_date=?,notes=?,receipt_url=? WHERE id=?")
                    ->execute([$recipientId,$amount,$date,$notes,$receiptUrl,$id]);
            }
            logChange($pdo,'EDIT_DIVIDEND_DIST','Vault',$before,[
                'recipient_id'=>$recipientId,'amount'=>$amount,'distribution_date'=>$date,'notes'=>$notes,
                'receipt_path'=>$receiptPath ?? $before['receipt_path'], 'receipt_url'=>$receiptUrl,
            ]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Distribution updated.']);
    }

    if ($action === 'add_return') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = trim((string)($_POST['amount'] ?? '0'));
        $date        = trim($_POST['return_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $rChk = $pdo->prepare("SELECT id FROM dividend_recipients WHERE id=? AND is_active=1");
        $rChk->execute([$recipientId]);
        if (!$rChk->fetch()) jsonErr('Recipient not found or inactive.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO dividend_returns (recipient_id,amount,return_date,notes,created_by) VALUES (?,?,?,?,?)")
                ->execute([$recipientId,$amount,$date,$notes,$_SESSION['user']['id']]);
            logActivity($pdo,'DIVIDEND_RETURN','Vault',"Return " . money($amount) . " from recipient #$recipientId");
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Return to vault recorded.']);
    }

    if ($action === 'delete_return') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM dividend_returns WHERE id=?");
        $chk->execute([$id]);
        if (!$chk->fetch()) jsonErr('Return record not found.');
        $pdo->prepare("DELETE FROM dividend_returns WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_DIVIDEND_RETURN','Vault',"Deleted return #$id");
        jsonOk(['msg'=>'Return deleted.']);
    }

    if ($action === 'edit_return') {
        $id          = (int)($_POST['id'] ?? 0);
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = trim((string)($_POST['amount'] ?? '0'));
        $date        = trim($_POST['return_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$id) jsonErr('Return ID required.');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $chk = $pdo->prepare("SELECT recipient_id, amount, return_date, notes FROM dividend_returns WHERE id=?");
        $chk->execute([$id]);
        $before = $chk->fetch();
        if (!$before) jsonErr('Return not found.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE dividend_returns SET recipient_id=?,amount=?,return_date=?,notes=? WHERE id=?")
                ->execute([$recipientId,$amount,$date,$notes,$id]);
            logChange($pdo,'EDIT_DIVIDEND_RETURN','Vault',$before,['recipient_id'=>$recipientId,'amount'=>$amount,'return_date'=>$date,'notes'=>$notes]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Return updated.']);
    }

    // ── Vault Returns to Users ───────────────────────────────────
    // Available to admins AND accountants (the whole Vault page is gated to
    // those two roles at the top via requireRole; this flow carries no extra
    // isAdmin() check, matching every other write action on this page).
    // Cash issued from the vault back to a user (admin, accountant, or staff)
    // — fixes excessive remittances or funds a planned expense when the user
    // has zero cash on hand by mistake. Stored as
    // cash_transactions.transaction_type='vault_return': it INCREASES the
    // user's cash_on_hand and DECREASES the vault balance.
    if ($action === 'add_user_return') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $amount = trim((string)($_POST['amount'] ?? '0'));
        $date   = trim($_POST['return_date'] ?? '');
        $notes  = nullOrStr($_POST['notes'] ?? '');
        if (!$userId)                    jsonErr('Please select a user.');
        if (!money_is_pos($amount))      jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        // Confirm the target user actually exists + is active
        $u = $pdo->prepare("SELECT full_name FROM users WHERE id=? AND status='active'");
        $u->execute([$userId]);
        $name = $u->fetchColumn();
        if (!$name) jsonErr('Selected user is not active or does not exist.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO cash_transactions (user_id,transaction_type,amount,transaction_date,notes)
                 VALUES (?,'vault_return',?,?,?)"
            )->execute([$userId, $amount, $date, $notes]);
            logActivity($pdo,'VAULT_USER_RETURN','Vault',"Issued " . money($amount) . " from vault to $name (user #$userId)");
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg' => "Issued " . money($amount) . " from vault to $name."]);
    }

    if ($action === 'edit_user_return') {
        $id     = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $amount = trim((string)($_POST['amount'] ?? '0'));
        $date   = trim($_POST['return_date'] ?? '');
        $notes  = nullOrStr($_POST['notes'] ?? '');
        if (!$id)                        jsonErr('Return ID required.');
        if (!$userId)                    jsonErr('Please select a user.');
        if (!money_is_pos($amount))      jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $chk = $pdo->prepare("SELECT user_id, amount, transaction_date, notes FROM cash_transactions WHERE id=? AND transaction_type='vault_return'");
        $chk->execute([$id]);
        $before = $chk->fetch();
        if (!$before) jsonErr('Vault return not found.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE cash_transactions SET user_id=?, amount=?, transaction_date=?, notes=?
                 WHERE id=? AND transaction_type='vault_return'"
            )->execute([$userId, $amount, $date, $notes, $id]);
            logChange($pdo,'EDIT_VAULT_USER_RETURN','Vault',$before,['user_id'=>$userId,'amount'=>$amount,'transaction_date'=>$date,'notes'=>$notes]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg' => 'Vault return updated.']);
    }

    if ($action === 'delete_user_return') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('Return ID required.');
        $chk = $pdo->prepare("SELECT amount FROM cash_transactions WHERE id=? AND transaction_type='vault_return'");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) jsonErr('Vault return not found.');
        $pdo->prepare("DELETE FROM cash_transactions WHERE id=? AND transaction_type='vault_return'")->execute([$id]);
        logActivity($pdo,'DELETE_VAULT_USER_RETURN','Vault',"Deleted vault return #$id (" . money($row['amount']) . ")");
        jsonOk(['msg' => 'Vault return deleted.']);
    }

    if ($action === 'get_user_return') {
        $id = (int)($_POST['id'] ?? 0);
        $r  = $pdo->prepare("SELECT * FROM cash_transactions WHERE id=? AND transaction_type='vault_return'");
        $r->execute([$id]);
        $data = $r->fetch();
        if (!$data) jsonErr('Vault return not found.');
        jsonOk(['record' => $data]);
    }

    if ($action === 'save_recipient') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $notes = nullOrStr($_POST['notes'] ?? '');
        if (!$name) jsonErr('Name is required.');
        if ($id) {
            $prev = $pdo->prepare("SELECT name, notes FROM dividend_recipients WHERE id=?");
            $prev->execute([$id]);
            $before = $prev->fetch();
            if (!$before) jsonErr('Recipient not found.');
            $pdo->prepare("UPDATE dividend_recipients SET name=?,notes=? WHERE id=?")->execute([$name,$notes,$id]);
            logChange($pdo,'UPDATE_RECIPIENT','Vault',(array)$before,['name'=>$name,'notes'=>$notes]);
        } else {
            $pdo->prepare("INSERT INTO dividend_recipients (name,notes) VALUES (?,?)")->execute([$name,$notes]);
            logActivity($pdo,'ADD_RECIPIENT','Vault',"Added recipient: $name");
        }
        jsonOk(['msg'=>'Recipient saved.']);
    }

    if ($action === 'delete_recipient') {
        $id = (int)($_POST['id'] ?? 0);
        // Check BOTH distribution and return history. The dividend_returns FK
        // is RESTRICT (no ON DELETE clause in install.sql) so MySQL would bounce
        // the DELETE anyway, but with a generic FK error rather than the
        // user-friendly message — fail clean here.
        $cntDist = $pdo->prepare("SELECT COUNT(*) FROM dividend_distributions WHERE recipient_id=?");
        $cntDist->execute([$id]);
        if ((int)$cntDist->fetchColumn() > 0) jsonErr('Cannot delete: recipient has distribution history. Deactivate instead.');
        $cntRet = $pdo->prepare("SELECT COUNT(*) FROM dividend_returns WHERE recipient_id=?");
        $cntRet->execute([$id]);
        if ((int)$cntRet->fetchColumn() > 0) jsonErr('Cannot delete: recipient has return history. Deactivate instead.');
        $pdo->prepare("DELETE FROM dividend_recipients WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_RECIPIENT','Vault',"Deleted recipient #$id");
        jsonOk(['msg'=>'Recipient deleted.']);
    }

    if ($action === 'toggle_recipient') {
        $id     = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 1);
        $pdo->prepare("UPDATE dividend_recipients SET is_active=? WHERE id=?")->execute([$active,$id]);
        // Every other recipient operation (save, delete) is audit-logged; keep
        // this one consistent so admins have a record of who flipped a recipient
        // active/inactive and when.
        logActivity($pdo, 'TOGGLE_RECIPIENT', 'Vault', ($active ? 'Activated' : 'Deactivated') . " recipient #$id");
        jsonOk(['msg'=>$active ? 'Recipient activated.' : 'Recipient deactivated.']);
    }

    if ($action === 'get_logs') {
        $mo = (int)($_POST['month'] ?? 0);
        $yr = (int)($_POST['year']  ?? date('Y'));
        // Sargable half-open range so idx_cash_user_date / date-column indexes
        // can be used instead of full-scan-then-MONTH()/YEAR() filtering.
        [$periodStart, $periodEnd] = $mo > 0 ? monthRange($mo, $yr) : yearRange($yr);
        $rows = $pdo->prepare("
            SELECT 'remittance' AS log_type, ct.id, ct.transaction_date AS log_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS person_name,
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci AS recipient_name,
                   ct.amount, ct.notes,
                   CAST(0 AS UNSIGNED) AS recipient_id,
                   CONVERT(ct.doc_path USING utf8mb4) COLLATE utf8mb4_general_ci AS proof_path,
                   CONVERT(ct.doc_url  USING utf8mb4) COLLATE utf8mb4_general_ci AS proof_url
            FROM cash_transactions ct
            LEFT JOIN users u ON u.id = ct.user_id
            WHERE ct.transaction_type='remitted'
              AND ct.transaction_date >= ? AND ct.transaction_date < ?
            UNION ALL
            SELECT 'distribution', dd.id, dd.distribution_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dd.amount, dd.notes,
                   dd.recipient_id,
                   CONVERT(dd.receipt_path USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dd.receipt_url  USING utf8mb4) COLLATE utf8mb4_general_ci
            FROM dividend_distributions dd
            LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
            LEFT JOIN users u ON u.id = dd.created_by
            WHERE dd.distribution_date >= ? AND dd.distribution_date < ?
            UNION ALL
            SELECT 'return', dret.id, dret.return_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dret.amount, dret.notes,
                   dret.recipient_id,
                   -- dividend_returns carries no attachment columns; the Proof
                   -- cell renders an em dash for these rows.
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci,
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci
            FROM dividend_returns dret
            LEFT JOIN dividend_recipients dr ON dr.id = dret.recipient_id
            LEFT JOIN users u ON u.id = dret.created_by
            WHERE dret.return_date >= ? AND dret.return_date < ?
            UNION ALL
            SELECT 'user_return', ct2.id, ct2.transaction_date,
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci,
                   CONVERT(u2.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   ct2.amount, ct2.notes,
                   CAST(0 AS UNSIGNED),
                   CONVERT(ct2.doc_path USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(ct2.doc_url  USING utf8mb4) COLLATE utf8mb4_general_ci
            FROM cash_transactions ct2
            LEFT JOIN users u2 ON u2.id = ct2.user_id
            WHERE ct2.transaction_type='vault_return'
              AND ct2.transaction_date >= ? AND ct2.transaction_date < ?
            ORDER BY log_date DESC, log_type
        ");
        $rows->execute([$periodStart, $periodEnd, $periodStart, $periodEnd, $periodStart, $periodEnd, $periodStart, $periodEnd]);
        jsonOk(['logs'=>$rows->fetchAll()]);
    }

    exit;
}

// ── Page data ─────────────────────────────────────────────────
$selectedYear  = (int)($_GET['year'] ?? date('Y'));
// Sums come straight from SQL on DECIMAL(12,2) columns — exact. Combine them
// via cents-based money helpers so the displayed vault balance can't drift.
$totalRemitted     = (string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='remitted'")->fetchColumn();
$totalDistrib      = (string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_distributions")->fetchColumn();
$totalReturned     = (string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_returns")->fetchColumn();
$totalUserReturned = (string)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='vault_return'")->fetchColumn();
// vault_return ⇒ cash leaves the vault back to a user, so it subtracts from the balance.
$vaultBalance      = money_sub(money_add(money_sub($totalRemitted, $totalDistrib), $totalReturned), $totalUserReturned);

// Charts — every vault movement for the selected year, split by direction:
//   in  = remittances from staff + dividends returned by recipients
//   out = dividends paid + cash returned to users (vault_return)
// MONTH() is only a projection; each WHERE is a sargable half-open range.
// Sums stay DECIMAL strings until json — the chart only draws them.
[$yrStart, $yrEnd] = yearRange($selectedYear);
$flowKeys = ['remitted', 'div_returned', 'dividends', 'user_returns'];
$flow = array_fill_keys($flowKeys, array_fill(0, 12, '0.00'));
$fq = $pdo->prepare("
    SELECT 'remitted' AS k, MONTH(transaction_date) AS mo, SUM(amount) AS s FROM cash_transactions
     WHERE transaction_type='remitted' AND transaction_date >= ? AND transaction_date < ? GROUP BY mo
    UNION ALL
    SELECT 'user_returns', MONTH(transaction_date), SUM(amount) FROM cash_transactions
     WHERE transaction_type='vault_return' AND transaction_date >= ? AND transaction_date < ? GROUP BY MONTH(transaction_date)
    UNION ALL
    SELECT 'dividends', MONTH(distribution_date), SUM(amount) FROM dividend_distributions
     WHERE distribution_date >= ? AND distribution_date < ? GROUP BY MONTH(distribution_date)
    UNION ALL
    SELECT 'div_returned', MONTH(return_date), SUM(amount) FROM dividend_returns
     WHERE return_date >= ? AND return_date < ? GROUP BY MONTH(return_date)
");
$fq->execute([$yrStart, $yrEnd, $yrStart, $yrEnd, $yrStart, $yrEnd, $yrStart, $yrEnd]);
foreach ($fq->fetchAll() as $r) $flow[$r['k']][(int)$r['mo'] - 1] = (string)$r['s'];

// Balance on Jan 1 of the selected year, so the tooltip can show the vault
// balance at each month end (same formula as $vaultBalance, dated before).
$oq = $pdo->prepare("
    SELECT (SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='remitted'     AND transaction_date < ?)
         - (SELECT COALESCE(SUM(amount),0) FROM dividend_distributions WHERE distribution_date < ?)
         + (SELECT COALESCE(SUM(amount),0) FROM dividend_returns       WHERE return_date < ?)
         - (SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='vault_return' AND transaction_date < ?)
");
$oq->execute([$yrStart, $yrStart, $yrStart, $yrStart]);
$flowOpening = (string)$oq->fetchColumn();

// Per staff member for the year: cash they remitted vs cash the vault handed
// back to them. A user with only returns still gets a row.
$sq = $pdo->prepare("
    SELECT ct.user_id, u.full_name,
           SUM(CASE WHEN ct.transaction_type='remitted'     THEN ct.amount ELSE 0 END) AS remitted,
           SUM(CASE WHEN ct.transaction_type='vault_return' THEN ct.amount ELSE 0 END) AS returned
    FROM cash_transactions ct LEFT JOIN users u ON u.id = ct.user_id
    WHERE ct.transaction_type IN ('remitted','vault_return')
      AND ct.transaction_date >= ? AND ct.transaction_date < ?
    GROUP BY ct.user_id, u.full_name
");
$sq->execute([$yrStart, $yrEnd]);
$staffFlow = $sq->fetchAll();

// Months of the selected year that have happened (current year stops at this
// month; a future year shows none) — later months are left empty, not zero.
$flowMonthsElapsed = $selectedYear < (int)date('Y') ? 12 : ($selectedYear > (int)date('Y') ? 0 : (int)date('n'));

// All active users for remittance dropdown
$allUsers = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll();

// Vault → User return records (cash issued from vault back to a user)
$userReturns = $pdo->query("
    SELECT ct.id, ct.user_id, ct.amount, ct.transaction_date AS return_date, ct.notes, ct.created_at, u.full_name, u.role
    FROM cash_transactions ct
    LEFT JOIN users u ON u.id = ct.user_id
    WHERE ct.transaction_type='vault_return'
    ORDER BY ct.transaction_date DESC, ct.id DESC
")->fetchAll();

// Recipient stats
$recipientStats = $pdo->query("
    SELECT dr.id, dr.name, dr.notes, dr.is_active,
           COALESCE(d.total_received, 0) AS total_received,
           COALESCE(d.dist_count, 0)     AS dist_count,
           COALESCE(r.total_returned, 0) AS total_returned
    FROM dividend_recipients dr
    LEFT JOIN (
        SELECT recipient_id, SUM(amount) AS total_received, COUNT(id) AS dist_count
        FROM dividend_distributions GROUP BY recipient_id
    ) d ON d.recipient_id = dr.id
    LEFT JOIN (
        SELECT recipient_id, SUM(amount) AS total_returned
        FROM dividend_returns GROUP BY recipient_id
    ) r ON r.recipient_id = dr.id
    ORDER BY dr.name
")->fetchAll();
$activeRecipients = array_values(array_filter($recipientStats, fn($r) => $r['is_active']));

// All recipients (including inactive) for edit distribution modal
$allRecipients = $pdo->query("SELECT id, name, is_active FROM dividend_recipients ORDER BY name")->fetchAll();

// All individual distribution records for the management table
$distributions = $pdo->query("
    SELECT dd.id, dd.recipient_id, dd.amount, dd.distribution_date, dd.notes,
           dd.receipt_path, dd.receipt_url, dd.created_at,
           dr.name AS recipient_name, u.full_name AS recorded_by
    FROM dividend_distributions dd
    LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
    LEFT JOIN users u ON u.id = dd.created_by
    ORDER BY dd.distribution_date DESC, dd.id DESC
")->fetchAll();

// All individual return records for the management table
$returnRecords = $pdo->query("
    SELECT dret.id, dret.recipient_id, dret.amount, dret.return_date, dret.notes, dret.created_at,
           dr.name AS recipient_name, u.full_name AS recorded_by
    FROM dividend_returns dret
    LEFT JOIN dividend_recipients dr ON dr.id = dret.recipient_id
    LEFT JOIN users u ON u.id = dret.created_by
    ORDER BY dret.return_date DESC, dret.id DESC
")->fetchAll();

// Chart: dividends per recipient for the selected div_year — paid, and any
// returned by the recipient that year. Inactive recipients appear only if
// money moved for them that year.
$divYear = (int)($_GET['div_year'] ?? date('Y'));
[$divYrStart, $divYrEnd] = yearRange($divYear);
$divChartStmt = $pdo->prepare("
    SELECT dr.id, dr.name, dr.is_active,
           COALESCE(d.paid, 0) AS paid, COALESCE(d.n, 0) AS n, COALESCE(r.returned, 0) AS returned
    FROM dividend_recipients dr
    LEFT JOIN (SELECT recipient_id, SUM(amount) AS paid, COUNT(*) AS n FROM dividend_distributions
               WHERE distribution_date >= ? AND distribution_date < ? GROUP BY recipient_id) d ON d.recipient_id = dr.id
    LEFT JOIN (SELECT recipient_id, SUM(amount) AS returned FROM dividend_returns
               WHERE return_date >= ? AND return_date < ? GROUP BY recipient_id) r ON r.recipient_id = dr.id
    WHERE dr.is_active = 1 OR d.paid IS NOT NULL OR r.returned IS NOT NULL
    ORDER BY dr.name
");
$divChartStmt->execute([$divYrStart, $divYrEnd, $divYrStart, $divYrEnd]);
$divChart = $divChartStmt->fetchAll();

// Available years for selectors
$dbYears = $pdo->query("
    SELECT DISTINCT yr FROM (
        SELECT YEAR(transaction_date) AS yr FROM cash_transactions WHERE transaction_type='remitted'
        UNION SELECT YEAR(distribution_date) FROM dividend_distributions
        UNION SELECT YEAR(return_date) FROM dividend_returns
    ) t ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
$years = array_unique(array_merge([date('Y'), $selectedYear, $divYear], (array)$dbYears));
rsort($years);

logActivity($pdo,'VIEW_VAULT','Vault','Viewed The Vault');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-vault me-2 text-primary-custom"></i>The Vault</h1>
  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-primary btn-sm" onclick="openRemittanceModal()"><i class="fa-solid fa-arrow-down-to-line me-1"></i>Record Remittance</button>
    <button class="btn btn-primary btn-sm" onclick="openDistributionModal()"><i class="fa-solid fa-money-bill-transfer me-1"></i>Distribute Dividend</button>
    <button class="btn btn-primary btn-sm" onclick="openReturnModal()"><i class="fa-solid fa-rotate-left me-1"></i>Return to Vault</button>
    <button class="btn btn-primary btn-sm" onclick="openUserReturnModal()"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Return to User</button>
    <button class="btn btn-outline-secondary btn-sm" onclick="openRecipientsModal()"><i class="fa-solid fa-users me-1"></i>Recipients</button>
  </div>
</div>

<!-- Vault Balance Hero (inverted panel — flips to a light panel in dark mode) -->
<div class="card mb-3 dark-card">
  <div class="card-body py-4 text-center">
    <div style="font-size:11px;letter-spacing:3px;text-transform:uppercase;opacity:.62;margin-bottom:6px;">Current Vault Balance</div>
    <div id="vaultBalanceDisplay" class="num" style="font-size:46px;font-weight:800;letter-spacing:-1px;line-height:1.1"><?= money($vaultBalance) ?></div>
    <div style="opacity:.5;font-size:12px;margin-top:6px;"><?= date('F j, Y') ?></div>
  </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-arrow-down-to-line"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Remitted</div>
        <div class="stat-value"><?= money($totalRemitted) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--success-bg)"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--success)"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Distributed</div>
        <div id="statDistTotal" class="stat-value"><?= money($totalDistrib) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--warning-bg)"><i class="fa-solid fa-rotate-left" style="color:var(--warning)"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Returned</div>
        <div id="statRetTotal" class="stat-value"><?= money($totalReturned) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--warning-bg)"><i class="fa-solid fa-users" style="color:var(--warning)"></i></div>
      <div class="stat-body">
        <div class="stat-label">Recipients</div>
        <div class="stat-value"><?= count($recipientStats) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Distribution Records -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-money-bill-transfer me-2" style="color:var(--success)"></i>Distribution Records</h6>
      <button class="btn btn-sm btn-primary" onclick="openDistributionModal()"><i class="fa-solid fa-plus me-1"></i>Add Distribution</button>
    </div>
    <?php if (empty($distributions)): ?>
    <div class="text-center text-muted py-4"><i class="fa-solid fa-inbox fa-2x mb-2 d-block" style="opacity:.2"></i>No distributions recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="distTable">
        <thead>
          <tr>
            <th>Date</th><th>Encode Date</th><th>Recipient</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:56px">Proof</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distributions as $d): ?>
          <tr data-dist-id="<?= $d['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($d['distribution_date']) ?></td>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-muted)"><?= fmtDateTime($d['created_at'] ?? null) ?></td>
            <td class="fw-600"><?= clean($d['recipient_name'] ?? '—') ?></td>
            <td class="text-end fw-600 text-success"><?= money((float)$d['amount']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= $d['notes'] ? clean($d['notes']) : '—' ?></td>
            <td class="text-center">
              <?php $dProof = $d['receipt_path'] ?: $d['receipt_url']; ?>
              <?php if ($dProof): ?>
              <a href="<?= clean($dProof) ?>" target="_blank" rel="noopener noreferrer" class="btn-icon" title="View proof of distribution"><i class="fa-solid fa-paperclip fa-xs"></i></a>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn-icon" title="Edit" onclick="openEditDist(<?= $d['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" onclick="deleteDistribution(<?= $d['id'] ?>)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="3" class="fw-700">Total</td>
            <td class="text-end fw-700" id="distTotal"><?= money($totalDistrib) ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Return Records -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-rotate-left me-2" style="color:var(--warning)"></i>Return Records</h6>
      <button class="btn btn-sm btn-primary" onclick="openReturnModal()"><i class="fa-solid fa-plus me-1"></i>Add Return</button>
    </div>
    <?php if (empty($returnRecords)): ?>
    <div class="text-center text-muted py-3" style="font-size:13px">No returns recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="retTable">
        <thead>
          <tr>
            <th>Date</th><th>Encode Date</th><th>Returned By</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($returnRecords as $ret): ?>
          <tr data-ret-id="<?= $ret['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($ret['return_date']) ?></td>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-muted)"><?= fmtDateTime($ret['created_at'] ?? null) ?></td>
            <td class="fw-600"><?= clean($ret['recipient_name'] ?? '—') ?></td>
            <td class="text-end fw-600" style="color:var(--warning)"><?= money((float)$ret['amount']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= $ret['notes'] ? clean($ret['notes']) : '—' ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn-icon" title="Edit" onclick="openEditReturn(<?= $ret['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" onclick="deleteReturn(<?= $ret['id'] ?>)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="3" class="fw-700">Total Returned</td>
            <td class="text-end fw-700" id="retTotal" style="color:var(--warning)"><?= money($totalReturned) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Vault Returns to Users (admins + accountants — same as every other write
     action on this page) -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h6 class="mb-0 fw-600"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:var(--info)"></i>Returns to Users</h6>
        <div class="text-muted" style="font-size:11.5px;margin-top:2px">Cash issued back from the vault to a user — corrects excess remittances or funds a planned expense.</div>
      </div>
      <button class="btn btn-sm btn-primary" onclick="openUserReturnModal()"><i class="fa-solid fa-plus me-1"></i>Issue Cash to User</button>
    </div>
    <?php if (empty($userReturns)): ?>
    <div class="text-center text-muted py-3" style="font-size:13px">No vault returns to users yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="userRetTable">
        <thead>
          <tr>
            <th>Date</th><th>Encode Date</th><th>Issued To</th><th>Role</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userReturns as $ur): ?>
          <tr data-user-ret-id="<?= $ur['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($ur['return_date']) ?></td>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-muted)"><?= fmtDateTime($ur['created_at'] ?? null) ?></td>
            <td class="fw-600"><?= clean($ur['full_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= clean($ur['role'] ?? 'staff') ?>"><?= ucfirst(clean($ur['role'] ?? '—')) ?></span></td>
            <td class="text-end fw-600" style="color:var(--info)"><?= money((float)$ur['amount']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= $ur['notes'] ? clean($ur['notes']) : '—' ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn-icon" title="Edit" onclick="openEditUserReturn(<?= $ur['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" onclick="deleteUserReturn(<?= $ur['id'] ?>)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="4" class="fw-700">Total Issued to Users</td>
            <td class="text-end fw-700" style="color:var(--info)"><?= money($totalUserReturned) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
// Year totals for the chart summary strips (exact — cents-based helpers).
$flowIn  = money_add(money_sum($flow['remitted']), money_sum($flow['div_returned']));
$flowOut = money_add(money_sum($flow['dividends']), money_sum($flow['user_returns']));
$flowNet = money_sub($flowIn, $flowOut);
$flowEnd = money_add($flowOpening, $flowNet);
$flowAny = money_is_pos($flowIn) || money_is_pos($flowOut);
$divPaid = money_sum(array_column($divChart, 'paid') ?: ['0.00']);
$divRet  = money_sum(array_column($divChart, 'returned') ?: ['0.00']);
$divPaidTo = count(array_filter($divChart, fn($r) => money_is_pos($r['paid'])));
$yearSelect = function (string $name, int $current, string $otherName, int $otherVal) use ($years) {
    ob_start(); ?>
    <form method="GET" class="d-flex align-items-center gap-2 ms-auto">
      <input type="hidden" name="<?= $otherName ?>" value="<?= $otherVal ?>">
      <select name="<?= $name ?>" class="form-select form-select-sm" style="width:auto;font-size:11.5px" onchange="this.form.submit()" aria-label="Year">
        <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $y == $current ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php return ob_get_clean();
};
?>
<style>
/* Chart summary strip under a card title — same look as the dashboard's unit-chart stats. */
.vault-chart-stats { display: flex; gap: 14px; flex-wrap: wrap; font-size: 11px; color: var(--gray-500);
                     margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--gray-200); }
.vault-chart-stats strong { color: var(--ink); }
/* Horizontal bar charts: height is set per row count by _vSizeRows(). */
.vault-rows { position: relative; min-height: 180px; }
.vault-mini-tbl td, .vault-mini-tbl th { font-size: 11.5px; padding: 5px 6px !important; }
.vault-mini-tbl thead th { background: transparent; border-bottom: 1px solid var(--gray-200); }
.vault-mini-tbl td.num   { font-family: 'DM Mono', monospace; font-variant-numeric: tabular-nums; white-space: nowrap; }
@media (max-width: 576px) { .vault-mini-tbl td, .vault-mini-tbl th { font-size: 10.5px; padding: 4px 4px !important; } }
</style>
<!-- Charts: vault cash flow + remittances by staff (shared year) -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header" style="display:block">
        <div class="d-flex align-items-center gap-2">
          <span class="card-header-title"><i class="fa-solid fa-chart-column me-1"></i>Vault Cash Flow — <?= $selectedYear ?></span>
          <?= $yearSelect('year', $selectedYear, 'div_year', $divYear) ?>
        </div>
        <div class="vault-chart-stats">
          <span>In: <strong class="num"><?= money($flowIn) ?></strong></span>
          <span>Out: <strong class="num"><?= money($flowOut) ?></strong></span>
          <span>Net: <strong class="num" style="font-weight:<?= money_is_neg($flowNet) ? 800 : 600 ?>"><?= money_is_neg($flowNet) ? '▼ ' : '▲ ' ?><?= money(money_abs($flowNet)) ?></strong></span>
          <span class="ms-auto">Balance <?= $selectedYear == date('Y') ? 'today' : 'at year end' ?>: <strong class="num"><?= money($flowEnd) ?></strong></span>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$flowAny): ?>
        <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-column fa-2x mb-2 d-block" style="opacity:.2"></i>No money moved through the vault in <?= $selectedYear ?>.</div>
        <?php else: ?>
        <div class="chart-wrap"><canvas id="flowChart" role="img" aria-label="Money into and out of the vault per month, <?= $selectedYear ?>"></canvas></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header" style="display:block">
        <span class="card-header-title"><i class="fa-solid fa-chart-bar me-1"></i>Remittances by Staff — <?= $selectedYear ?></span>
        <div class="vault-chart-stats"><span>Remitted, less cash the vault returned to them</span></div>
      </div>
      <div class="card-body">
        <?php if (empty($staffFlow)): ?>
        <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-bar fa-2x mb-2 d-block" style="opacity:.2"></i>No remittances recorded for <?= $selectedYear ?>.</div>
        <?php else: ?>
        <div class="vault-rows"><canvas id="staffChart" role="img" aria-label="Cash remitted per staff member, <?= $selectedYear ?>"></canvas></div>
        <?php
          $staffRows = array_map(fn($r) => $r + ['net' => money_sub($r['remitted'], $r['returned'])], $staffFlow);
          usort($staffRows, fn($a, $b) => to_cents($b['net']) <=> to_cents($a['net']));
        ?>
        <div class="table-responsive mt-2">
        <table class="table table-sm vault-mini-tbl mb-0">
          <thead><tr><th>Staff</th><th class="text-end">Remitted</th><th class="text-end d-none d-sm-table-cell">Returned</th><th class="text-end">Net</th></tr></thead>
          <tbody>
          <?php foreach ($staffRows as $r): ?>
            <tr>
              <td class="cell-trunc"><?= clean(trim(preg_replace('/\s+/', ' ', (string)($r['full_name'] ?? 'Unknown user')))) ?></td>
              <td class="text-end num"><?= money($r['remitted']) ?></td>
              <td class="text-end num d-none d-sm-table-cell"><?= money_is_pos($r['returned']) ? money($r['returned']) : '—' ?></td>
              <td class="text-end num fw-700"><?= money($r['net']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Dividends per recipient -->
<div class="card mb-4">
  <div class="card-header" style="display:block">
    <div class="d-flex align-items-center gap-2">
      <span class="card-header-title"><i class="fa-solid fa-chart-bar me-1"></i>Dividends per Recipient — <?= $divYear ?></span>
      <?= $yearSelect('div_year', $divYear, 'year', $selectedYear) ?>
    </div>
    <div class="vault-chart-stats">
      <span>Paid: <strong class="num"><?= money($divPaid) ?></strong> to <?= $divPaidTo ?> of <?= count($divChart) ?> recipient<?= count($divChart) === 1 ? '' : 's' ?></span>
      <?php if (money_is_pos($divRet)): ?>
      <span>Returned: <strong class="num"><?= money($divRet) ?></strong></span>
      <span>Net: <strong class="num"><?= money(money_sub($divPaid, $divRet)) ?></strong></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <?php if (empty($divChart)): ?>
    <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-bar fa-2x mb-2 d-block" style="opacity:.2"></i>No recipients added yet.</div>
    <?php elseif (!money_is_pos($divPaid) && !money_is_pos($divRet)): ?>
    <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-bar fa-2x mb-2 d-block" style="opacity:.2"></i>No dividends paid in <?= $divYear ?>.</div>
    <?php else: ?>
    <div class="vault-rows"><canvas id="divChart" role="img" aria-label="Dividends paid per recipient, <?= $divYear ?>"></canvas></div>
    <?php endif; ?>
  </div>
</div>

<!-- Dividend Recipients Summary -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-people-group me-2 text-primary-custom"></i>Dividend Recipients</h6>
      <button class="btn btn-sm btn-outline-secondary" onclick="openRecipientsModal()">Manage</button>
    </div>
    <?php if (empty($recipientStats)): ?>
    <div class="text-center text-muted py-4"><i class="fa-solid fa-users fa-2x mb-2 d-block" style="opacity:.2"></i>No recipients added yet. Click <strong>Recipients</strong> to add one.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Recipient</th>
            <th class="text-center">Distributions</th>
            <th class="text-end">Distributed</th>
            <th class="text-end">Returned</th>
            <th class="text-end">Net Received</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recipientStats as $r): ?>
          <?php $net = money_sub($r['total_received'], $r['total_returned']); ?>
          <tr>
            <td>
              <?= clean($r['name']) ?>
              <?php if (!$r['is_active']): ?>
              <span class="attn-pill ms-1" style="font-size:10px">Inactive</span>
              <?php endif; ?>
              <?php if ($r['notes']): ?><div class="text-muted" style="font-size:11px"><?= clean($r['notes']) ?></div><?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= (int)$r['dist_count'] ?>×</td>
            <td class="text-end text-success"><?= money($r['total_received']) ?></td>
            <td class="text-end" style="color:var(--warning)"><?= money_is_pos($r['total_returned']) ? money($r['total_returned']) : '—' ?></td>
            <td class="text-end fw-600 text-success"><?= money($net) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td class="fw-700">Total</td>
            <td></td>
            <td class="text-end fw-700"><?= money($totalDistrib) ?></td>
            <td class="text-end fw-700" style="color:var(--warning)"><?= money_is_pos($totalReturned) ? money($totalReturned) : '—' ?></td>
            <td class="text-end fw-700"><?= money(money_sub($totalDistrib, $totalReturned)) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Transaction Logs -->
<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-clock-rotate-left me-2 text-primary-custom"></i>Transaction Log</h6>
      <div class="d-flex gap-2 align-items-center">
        <select id="logMonth" class="form-select form-select-sm" style="width:130px">
          <option value="0">All Months</option>
          <?php for ($m=1; $m<=12; $m++): ?>
          <option value="<?=$m?>" <?=$m==(int)date('n')?'selected':''?>><?= date('F',mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select>
        <select id="logYear" class="form-select form-select-sm" style="width:90px">
          <?php foreach ($years as $y): ?>
          <option value="<?=$y?>" <?=$y==(int)date('Y')?'selected':''?>><?=$y?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div id="logsContainer"><div class="text-center text-muted py-4">Loading…</div></div>
  </div>
</div>

<!-- ═══ MODALS ══════════════════════════════════════════════════ -->

<!-- Record Remittance -->
<div class="modal fade" id="remittanceModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-arrow-down-to-line me-2 text-primary-custom"></i>Record Remittance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="remMsg" class="alert" style="display:none"></div>
        <div class="mb-3">
          <label class="form-label">Remitted By</label>
          <select id="remBy" class="form-select">
            <option value="">— Select person —</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>"><?= clean($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="remAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="remDate" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="remNotes" class="form-control" rows="2" placeholder="e.g. Weekly cash deposit"></textarea>
        </div>
        <div class="mb-0">
          <label class="form-label">Proof of Remittance <span class="text-muted">(optional)</span></label>
          <div class="form-text mb-1">Upload file (photo, receipt, etc.)</div>
          <input type="file" class="form-control form-control-sm" id="remFile" accept=".jpg,.jpeg,.png,.pdf">
          <div class="form-text mt-1">Or external URL</div>
          <input type="url" class="form-control form-control-sm mt-1" id="remUrl" placeholder="https://drive.google.com/...">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveRemittance()"><i class="fa-solid fa-check me-1"></i>Record</button>
      </div>
    </div>
  </div>
</div>

<!-- Distribute Dividend -->
<div class="modal fade" id="distributionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-money-bill-transfer me-2" style="color:var(--success)"></i>Distribute Dividend</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="distMsg" class="alert" style="display:none"></div>
        <?php if (empty($activeRecipients)): ?>
        <div class="alert alert-warning mb-0">No active recipients. Add one via <strong>Recipients</strong> first.</div>
        <?php else: ?>
        <div class="mb-3">
          <label class="form-label">Recipient</label>
          <select id="distRecipient" class="form-select">
            <option value="">— Select recipient —</option>
            <?php foreach ($activeRecipients as $r): ?>
            <option value="<?= $r['id'] ?>"><?= clean($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="distAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="distDate" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="distNotes" class="form-control" rows="2" placeholder="e.g. Q2 dividend share"></textarea>
        </div>
        <div class="mb-0">
          <label class="form-label">Proof of Distribution <span class="text-muted">(optional)</span></label>
          <input type="file" class="form-control form-control-sm" id="distFile" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
          <div class="form-text">Signed acknowledgement, bank-transfer screenshot or PDF slip — or paste an external link below. Images are compressed on upload; max 30MB.</div>
          <input type="url" class="form-control form-control-sm mt-1" id="distUrl" placeholder="https://drive.google.com/...">
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <div id="distUploadProgress" style="display:none;width:100%;margin-bottom:8px">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <small style="color:var(--text-muted)"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Uploading proof…</small>
            <small id="distUploadPct" style="color:var(--text-muted);font-variant-numeric:tabular-nums">0%</small>
          </div>
          <div class="progress" style="height:5px;border-radius:3px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="distUploadBar" style="width:0%;background:var(--primary)"></div>
          </div>
        </div>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if (!empty($activeRecipients)): ?>
        <button class="btn btn-primary" id="distSaveBtn" onclick="saveDistribution()"><i class="fa-solid fa-check me-1"></i>Record Distribution</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Return Cash from Vault to User (admins + accountants) -->
<div class="modal fade" id="userReturnModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:var(--info)"></i><span id="userReturnTitle">Issue Cash from Vault to User</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="userReturnId">
        <div id="userReturnMsg" class="alert" style="display:none"></div>
        <div class="alert alert-info py-2 mb-3" style="font-size:12.5px">
          <i class="fa-solid fa-circle-info me-1"></i>
          Use this to correct an over-remittance, or to advance cash for a planned expense when the user has none.
          This INCREASES the user's cash on hand and DECREASES the vault balance.
        </div>
        <div class="mb-3">
          <label class="form-label">Issue To</label>
          <select id="userReturnUser" class="form-select">
            <option value="">— Select user —</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>"><?= clean($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="userReturnAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="userReturnDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional but recommended)</span></label>
          <textarea id="userReturnNotes" class="form-control" rows="2" placeholder="e.g. Over-remitted by ₱500 on Apr 28; refund for upcoming repair"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="userReturnSaveBtn" onclick="saveUserReturn()"><i class="fa-solid fa-check me-1"></i>Issue Cash</button>
      </div>
    </div>
  </div>
</div>

<!-- Manage Recipients -->
<div class="modal fade" id="recipientsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-users me-2 text-primary-custom"></i>Manage Dividend Recipients</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Add / Edit form -->
        <div class="p-3 mb-3 rounded" style="background:var(--gray-100)">
          <div id="recipFormTitle" class="fw-600 mb-2" style="font-size:13px">Add New Recipient</div>
          <div id="recipEditBanner" style="display:none" class="alert alert-warning py-2 px-3 mb-2 d-flex justify-content-between align-items-center">
            <span style="font-size:12px"><i class="fa-solid fa-pen me-1"></i>Editing: <strong id="recipEditName"></strong></span>
            <button class="btn btn-xs btn-outline-secondary" onclick="cancelEditRecipient()">Cancel</button>
          </div>
          <input type="hidden" id="recipId">
          <div class="row g-2 align-items-end">
            <div class="col-sm-5">
              <label class="form-label mb-1" style="font-size:12px">Full Name</label>
              <input type="text" id="recipName" class="form-control form-control-sm" placeholder="e.g. Maria Santos">
            </div>
            <div class="col-sm-5">
              <label class="form-label mb-1" style="font-size:12px">Notes <span class="text-muted">(optional)</span></label>
              <input type="text" id="recipNotes" class="form-control form-control-sm" placeholder="e.g. 25% share">
            </div>
            <div class="col-sm-2">
              <button class="btn btn-primary btn-sm w-100" onclick="saveRecipient()">Save</button>
            </div>
          </div>
          <div id="recipMsg" class="alert mt-2 mb-0" style="display:none"></div>
        </div>

        <!-- Recipients list -->
        <?php if (empty($recipientStats)): ?>
        <div class="text-center text-muted py-4">No recipients yet.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Name</th>
                <th>Notes</th>
                <th class="text-end">Total Received</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recipientStats as $r): ?>
              <tr>
                <td class="fw-600"><?= clean($r['name']) ?></td>
                <td class="text-muted" style="font-size:12px"><?= clean($r['notes']??'') ?: '—' ?></td>
                <td class="text-end text-success fw-600"><?= money($r['total_received']) ?></td>
                <td class="text-center">
                  <?php if ($r['is_active']): ?>
                  <span class="badge" style="background:var(--success-bg);color:var(--success)">Active</span>
                  <?php else: ?>
                  <span class="attn-pill">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="text-center" style="white-space:nowrap">
                  <button class="btn-icon" title="Edit" onclick="editRecipient(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['notes']??''), ENT_QUOTES) ?>)">
                    <i class="fa-solid fa-pen fa-xs"></i>
                  </button>
                  <button class="btn-icon <?= $r['is_active'] ? 'warning' : '' ?>" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"
                    onclick="toggleRecipient(<?= $r['id'] ?>, <?= $r['is_active'] ? 0 : 1 ?>)">
                    <i class="fa-solid <?= $r['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?> fa-xs"></i>
                  </button>
                  <?php if ($r['dist_count'] == 0): ?>
                  <button class="btn-icon danger" title="Delete" onclick="deleteRecipient(<?= $r['id'] ?>)">
                    <i class="fa-solid fa-trash fa-xs"></i>
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Edit Distribution -->
<div class="modal fade" id="editDistModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-pen me-2 text-primary-custom"></i>Edit Distribution</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="editDistMsg" class="alert" style="display:none"></div>
        <input type="hidden" id="editDistId">
        <div class="mb-3">
          <label class="form-label">Recipient</label>
          <select id="editDistRecipient" class="form-select">
            <option value="">— Select recipient —</option>
            <?php foreach ($allRecipients as $r): ?>
            <option value="<?= $r['id'] ?>"><?= clean($r['name']) ?><?= !$r['is_active'] ? ' (Inactive)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="editDistAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="editDistDate" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="editDistNotes" class="form-control" rows="2"></textarea>
        </div>
        <div class="mb-0">
          <label class="form-label">Proof of Distribution <span class="text-muted">(optional)</span></label>
          <input type="file" class="form-control form-control-sm" id="editDistFile" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
          <div class="form-text">Upload a new file to replace the current one. Images are compressed on upload; max 30MB.</div>
          <input type="url" class="form-control form-control-sm mt-1" id="editDistUrl" placeholder="https://drive.google.com/...">
          <div id="editDistProofCurrent" class="form-text mt-1" style="display:none">
            Current proof: <a href="#" target="_blank" rel="noopener noreferrer" id="editDistProofLink">view</a>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <div id="editDistUploadProgress" style="display:none;width:100%;margin-bottom:8px">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <small style="color:var(--text-muted)"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Uploading proof…</small>
            <small id="editDistUploadPct" style="color:var(--text-muted);font-variant-numeric:tabular-nums">0%</small>
          </div>
          <div class="progress" style="height:5px;border-radius:3px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="editDistUploadBar" style="width:0%;background:var(--primary)"></div>
          </div>
        </div>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="editDistSaveBtn" onclick="saveEditDistribution()"><i class="fa-solid fa-check me-1"></i>Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Return to Vault -->
<div class="modal fade" id="returnModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-rotate-left me-2" style="color:var(--warning)"></i>Return to Vault</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="retMsg" class="alert" style="display:none"></div>
        <p class="text-muted small mb-3">Record a dividend that was returned by a recipient back into the vault.</p>
        <?php if (empty($activeRecipients)): ?>
        <div class="alert alert-warning mb-0">No active recipients. Add one via <strong>Recipients</strong> first.</div>
        <?php else: ?>
        <div class="mb-3">
          <label class="form-label">Returned By</label>
          <select id="retRecipient" class="form-select">
            <option value="">— Select recipient —</option>
            <?php foreach ($activeRecipients as $r): ?>
            <option value="<?= $r['id'] ?>"><?= clean($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="retAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="retDate" class="form-control">
        </div>
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="retNotes" class="form-control" rows="2" placeholder="e.g. Partial return of Q2 dividend"></textarea>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if (!empty($activeRecipients)): ?>
        <button class="btn btn-primary" onclick="saveReturn()"><i class="fa-solid fa-check me-1"></i>Record Return</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Edit Return -->
<div class="modal fade" id="editReturnModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-pen me-2" style="color:var(--warning)"></i>Edit Return Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="editRetMsg" class="alert" style="display:none"></div>
        <input type="hidden" id="editRetId">
        <div class="mb-3">
          <label class="form-label">Returned By</label>
          <select id="editRetRecipient" class="form-select">
            <option value="">— Select recipient —</option>
            <?php foreach ($allRecipients as $r): ?>
            <option value="<?= $r['id'] ?>"><?= clean($r['name']) ?><?= !$r['is_active'] ? ' (Inactive)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount (₱)</label>
          <input type="number" id="editRetAmount" class="form-control" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" id="editRetDate" class="form-control">
        </div>
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="editRetNotes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveEditReturn()"><i class="fa-solid fa-check me-1"></i>Save Changes</button>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
// ── Modals ───────────────────────────────────────────────────
const remittanceModal   = new bootstrap.Modal(document.getElementById('remittanceModal'));
const distributionModal = new bootstrap.Modal(document.getElementById('distributionModal'));
const recipientsModal   = new bootstrap.Modal(document.getElementById('recipientsModal'));
const editDistModal     = new bootstrap.Modal(document.getElementById('editDistModal'));
const returnModal       = new bootstrap.Modal(document.getElementById('returnModal'));
const editReturnModal   = new bootstrap.Modal(document.getElementById('editReturnModal'));
const userReturnModal   = new bootstrap.Modal(document.getElementById('userReturnModal'));

// ── Embedded page data (no AJAX needed to read these) ────────
<?php
$distMap  = [];
foreach ($distributions  as $d)   { $distMap[$d['id']]  = $d; }
$retMap   = [];
foreach ($returnRecords  as $ret)  { $retMap[$ret['id']] = $ret; }
?>
// Embedded edit-source for the per-row Edit buttons in Distribution Records
// and Return Records tables. Saves an AJAX round-trip on every pencil click.
const DIST_DATA = <?= json_encode($distMap,  JSON_UNESCAPED_UNICODE) ?>;
const RET_DATA  = <?= json_encode($retMap,   JSON_UNESCAPED_UNICODE) ?>;

// ── Charts (monochrome, theme-aware) ─────────────────────────
// Same grammar as the dashboard: one meaning per shade, position says the
// rest. Black = money that came IN, grey = money that went OUT, the dashed
// line is the result. Every colour comes from window.chartTheme().
<?php
$flowMonthly = [];
foreach ($flowKeys as $k) $flowMonthly[$k] = array_map('floatval', $flow[$k]);
?>
const FLOW = <?= json_encode(['year' => $selectedYear, 'opening' => (float)$flowOpening, 'elapsed' => $flowMonthsElapsed] + $flowMonthly, JSON_UNESCAPED_UNICODE) ?>;
const STAFF_FLOW = <?= json_encode(array_map(fn($r) => ['name' => trim(preg_replace('/\s+/', ' ', (string)($r['full_name'] ?? 'Unknown user'))),
                                                        'remitted' => (float)$r['remitted'], 'returned' => (float)$r['returned']], $staffFlow), JSON_UNESCAPED_UNICODE) ?>;
const DIV_FLOW = <?= json_encode(array_map(fn($r) => ['name' => $r['name'], 'active' => (bool)$r['is_active'], 'n' => (int)$r['n'],
                                                      'paid' => (float)$r['paid'], 'returned' => (float)$r['returned']], $divChart), JSON_UNESCAPED_UNICODE) ?>;
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const MONTHS_LONG = ['January','February','March','April','May','June','July','August','September','October','November','December'];

var _flowChartInst = null, _staffChartInst = null, _divChartInst = null;

function _vPeso(v) { return (v < 0 ? '−' : '') + fmt(Math.abs(v || 0)); }
// Compact peso label for axes and bar ends: ₱950 · ₱12.5k · ₱1.25M.
function _vCompact(v) {
  var a = Math.abs(v), sign = v < 0 ? '−' : '';
  if (a >= 1e6) return sign + '₱' + (a / 1e6).toFixed(a >= 1e7 ? 1 : 2).replace(/\.?0+$/, '') + 'M';
  if (a >= 1e3) return sign + '₱' + (a / 1e3).toFixed(a >= 1e5 ? 0 : 1).replace(/\.0$/, '') + 'k';
  return sign + '₱' + Math.round(a);
}
function _vTip(T, extra) {
  return Object.assign({
    backgroundColor: T.paper, titleColor: T.tick, bodyColor: T.ink, footerColor: T.ink,
    borderColor: T.grid, borderWidth: 1, cornerRadius: 8, caretSize: 6, caretPadding: 8,
    padding: { x: 12, y: 8 }, displayColors: true, boxPadding: 4,
    titleFont: { size: 10, family: 'DM Sans' }, bodyFont: { size: 12, family: 'DM Sans', weight: '600' },
    footerFont: { size: 12, family: 'DM Sans', weight: '700' }
  }, extra || {});
}
function _vLegend(T) {
  return { font: { size: 11, family: 'DM Sans' }, color: T.tick, padding: 16, usePointStyle: true, pointStyle: 'rect' };
}
// Diagonal hatch: a second "out" component told apart by texture as well as
// lightness, so the two grey segments never rely on shade alone.
function _vHatch(T) {
  var c = document.createElement('canvas'); c.width = c.height = 8;
  var x = c.getContext('2d');
  x.fillStyle = T.paper; x.fillRect(0, 0, 8, 8);
  x.strokeStyle = T.series[1]; x.lineWidth = 2;
  x.beginPath(); x.moveTo(-2, 10); x.lineTo(10, -2); x.moveTo(-2, 2); x.lineTo(2, -2); x.moveTo(6, 10); x.lineTo(10, 6); x.stroke();
  return x.createPattern(c, 'repeat');
}
// Writes a label just past the end of each row of a horizontal bar chart
// (the vendored Chart.js build has no data-label plugin).
var _vRowLabels = {
  id: 'vaultRowLabels',
  afterDatasetsDraw: function (chart, args, opts) {
    if (!opts || typeof opts.text !== 'function') return;
    var T = window.chartTheme(), ctx = chart.ctx, metas = chart.getSortedVisibleDatasetMetas();
    ctx.save(); ctx.textBaseline = 'middle';
    for (var i = 0; i < chart.data.labels.length; i++) {
      var x = chart.chartArea.left, y = null;
      metas.forEach(function (m) { var el = m.data[i]; if (el) { x = Math.max(x, el.x); if (y === null) y = el.y; } });
      var t = opts.text(i);
      if (!t || y === null) continue;
      ctx.font = (t.bold ? '700 ' : '600 ') + '10.5px "DM Sans", sans-serif';
      ctx.fillStyle = t.muted ? T.muted : (t.bold ? T.ink : T.tick);
      ctx.fillText(t.text, x + 6, y);
    }
    ctx.restore();
  }
};
function _vSizeRows(canvas, rows) {
  canvas.parentNode.style.height = Math.max(180, rows * 34 + 64) + 'px';
}
function _vRowScales(T) {
  return {
    x: { beginAtZero: true, grid: { color: T.grid }, border: { display: false },
         ticks: { callback: function (v) { return _vCompact(v); }, color: T.tick, font: { size: 10, family: 'DM Sans' }, maxTicksLimit: 6 } },
    y: { grid: { display: false }, border: { display: false },
         ticks: { color: T.ink, font: { size: 11, family: 'DM Sans', weight: '600' } } }
  };
}

// ── Vault cash flow: one column per month ───────────────────
// Money IN rises above the zero line (black), money OUT hangs below it (grey;
// cash handed back to users is hatched), and the ◆ marks the month's net. A
// lumpy month (a quarterly dividend run) is a long bar down, not a line that
// drags every other month flat. Months not reached yet stay empty.
function buildFlowChart(T) {
  var el = document.getElementById('flowChart');
  if (!el) return;
  var f = FLOW, live = function (i) { return i < f.elapsed; };
  var pick = function (arr, sign) { return arr.map(function (v, i) { return live(i) ? sign * v : null; }); };
  var sum = function (arr) { return arr.reduce(function (s, v) { return s + (v || 0); }, 0); };
  var inn = MONTHS.map(function (_, i) { return f.remitted[i] + f.div_returned[i]; });
  var out = MONTHS.map(function (_, i) { return f.dividends[i] + f.user_returns[i]; });
  var bal = [], run = f.opening;
  MONTHS.forEach(function (_, i) { run += inn[i] - out[i]; bal.push(run); });
  var cur = (f.year === new Date().getFullYear()) ? f.elapsed - 1 : -1;
  // 2px paper seam between stacked segments; data ends rounded, baseline square.
  var bar = function (o) { return Object.assign({ type: 'bar', stack: 'flow', borderRadius: 3, borderSkipped: 'start',
                                                  barPercentage: 0.62, categoryPercentage: 0.9, borderColor: T.paper, borderWidth: 0 }, o); };

  var ds = [
    { type: 'line', label: 'Net for the month', data: pick(MONTHS.map(function (_, i) { return inn[i] - out[i]; }), 1), order: 0,
      stack: 'net', showLine: false, pointStyle: 'rectRot', pointRadius: 5, pointHoverRadius: 7,
      pointBackgroundColor: T.paper, pointBorderColor: T.ink, pointBorderWidth: 2, borderColor: T.ink, backgroundColor: T.paper },
    bar({ label: 'Remitted by staff', dir: 'in', data: pick(f.remitted, 1), order: 2, backgroundColor: T.ink })
  ];
  // Optional legs appear only in a year they actually happened.
  if (sum(f.div_returned) > 0)
    ds.push(bar({ label: 'Returned by recipients', dir: 'in', data: pick(f.div_returned, 1), order: 2, backgroundColor: T.series[2], borderWidth: { top: 0, bottom: 2 } }));
  ds.push(bar({ label: 'Dividends paid', dir: 'out', data: pick(f.dividends, -1), order: 1, backgroundColor: T.series[1] }));
  if (sum(f.user_returns) > 0)
    ds.push(bar({ label: 'Returned to users', dir: 'out', data: pick(f.user_returns, -1), order: 1,
                  backgroundColor: _vHatch(T), borderColor: T.series[1], borderWidth: 1 }));

  _flowChartInst = new Chart(el, {
    data: { labels: MONTHS, datasets: ds },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 8 } },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'start', labels: Object.assign(_vLegend(T), {
          sort: function (a, b) { return (a.datasetIndex === 0) - (b.datasetIndex === 0) || a.datasetIndex - b.datasetIndex; },
          generateLabels: function (chart) {
            return Chart.defaults.plugins.legend.labels.generateLabels(chart).map(function (l) {
              var d = chart.data.datasets[l.datasetIndex];
              if (l.datasetIndex === 0) { l.pointStyle = 'rectRot'; l.fillStyle = T.paper; l.strokeStyle = T.ink; l.lineWidth = 2; }
              else { l.text = (d.dir === 'in' ? 'In · ' : 'Out · ') + l.text; l.strokeStyle = T.series[1]; l.lineWidth = d.label === 'Returned to users' ? 1 : 0; }
              return l;
            });
          }
        }) },
        tooltip: _vTip(T, {
          itemSort: function (a, b) { return (a.datasetIndex === 0) - (b.datasetIndex === 0) || a.datasetIndex - b.datasetIndex; },
          filter: function (c) { return c.parsed.y !== null && (c.datasetIndex === 0 || c.parsed.y !== 0); },
          callbacks: {
            // A month not reached yet has every row filtered out, so items is
            // empty — reading items[0] there threw inside Chart.js's event
            // handler and froze the tooltip.
            title: function (items) { return items.length ? MONTHS_LONG[items[0].dataIndex] + ' ' + f.year : ''; },
            label: function (c) {
              if (c.datasetIndex === 0) return ' Net: ' + (c.parsed.y < 0 ? '▼ ' : '▲ ') + fmt(Math.abs(c.parsed.y));
              return ' ' + (c.dataset.dir === 'in' ? 'In · ' : 'Out · ') + c.dataset.label + ': ' + fmt(Math.abs(c.parsed.y));
            },
            footer: function (items) { return items.length ? 'Vault balance at month end: ' + _vPeso(bal[items[0].dataIndex]) : ''; }
          }
        })
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false },
             ticks: { font: function (c) { return { size: 11, family: 'DM Sans', weight: c.index === cur ? '800' : '500' }; },
                      color: function (c) { return live(c.index) ? (c.index === cur ? T.ink : T.tick) : T.muted; }, padding: 6 } },
        y: { stacked: true, beginAtZero: true, border: { display: false },
             title: { display: true, text: 'Out ←     ·     → In', color: T.tick, font: { size: 10, family: 'DM Sans', weight: '600' } },
             grid: { color: function (c) { return c.tick.value === 0 ? T.tick : T.grid; }, lineWidth: function (c) { return c.tick.value === 0 ? 1.5 : 1; } },
             ticks: { callback: function (v) { return _vCompact(Math.abs(v)); }, color: T.tick, font: { size: 10, family: 'DM Sans' } } }
      }
    }
  });
}

// ── By staff: remitted (black) with cash handed back (grey) on top ──
// Ranked by net. A person who was handed back more than they remitted shows a
// grey bar longer than the black one and a bold minus net label.
function buildStaffChart(T) {
  var el = document.getElementById('staffChart');
  if (!el) return;
  var rows = STAFF_FLOW.map(function (r) { return Object.assign({ net: r.remitted - r.returned }, r); })
                       .sort(function (a, b) { return b.net - a.net; });
  _vSizeRows(el, rows.length);
  _staffChartInst = new Chart(el, {
    type: 'bar',
    data: {
      labels: rows.map(function (r) { return r.name; }),
      datasets: [
        { label: 'Remitted to vault', data: rows.map(function (r) { return r.remitted; }), backgroundColor: T.ink,
          borderRadius: 3, barPercentage: 0.9, categoryPercentage: 0.8, grouped: false, order: 2 },
        { label: 'Returned to them', data: rows.map(function (r) { return r.returned; }), backgroundColor: T.series[1],
          borderColor: T.paper, borderWidth: 2, borderSkipped: false,
          borderRadius: 3, barPercentage: 0.45, categoryPercentage: 0.8, grouped: false, order: 1 }
      ]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', axis: 'y', intersect: false },
      layout: { padding: { right: 70, top: 4 } },
      plugins: {
        legend: { position: 'top', align: 'start', labels: Object.assign(_vLegend(T), { sort: function (a, b) { return a.datasetIndex - b.datasetIndex; } }) },
        vaultRowLabels: { text: function (i) { var n = rows[i].net; return { text: _vCompact(n) + ' net', bold: n < 0 }; } },
        tooltip: _vTip(T, { mode: 'index', axis: 'y', intersect: false, callbacks: {
          label: function (c) { return ' ' + c.dataset.label + ': ' + _vPeso(c.parsed.x); },
          footer: function (items) { return items.length ? 'Net remitted: ' + _vPeso(rows[items[0].dataIndex].net) : ''; }
        } })
      },
      scales: _vRowScales(T)
    },
    plugins: [_vRowLabels]
  });
}

// ── Dividends per recipient, largest first ───────────────────
// One shade for everyone — a recipient is identified by its row label, not a
// colour. Share of the year's payout is printed at each bar end.
function buildDivChart(T) {
  var el = document.getElementById('divChart');
  if (!el) return;
  var anyRet = DIV_FLOW.some(function (r) { return r.returned > 0; });
  var rows = DIV_FLOW.map(function (r) { return Object.assign({ net: r.paid - r.returned }, r); })
                     .sort(function (a, b) { return b.paid - a.paid || b.net - a.net || a.name.localeCompare(b.name); });
  var total = rows.reduce(function (s, r) { return s + r.paid; }, 0);
  _vSizeRows(el, rows.length);
  var ds = [{ label: 'Dividends paid', data: rows.map(function (r) { return r.paid; }), backgroundColor: T.ink,
              borderRadius: 3, barPercentage: 0.9, categoryPercentage: 0.8, grouped: false, order: 2 }];
  if (anyRet) ds.push({ label: 'Returned to vault', data: rows.map(function (r) { return r.returned; }), backgroundColor: T.series[1],
                        borderColor: T.paper, borderWidth: 2, borderSkipped: false,
                        borderRadius: 3, barPercentage: 0.45, categoryPercentage: 0.8, grouped: false, order: 1 });
  _divChartInst = new Chart(el, {
    type: 'bar',
    data: { labels: rows.map(function (r) { return r.active ? r.name : r.name + ' (inactive)'; }), datasets: ds },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', axis: 'y', intersect: false },
      layout: { padding: { right: 96, top: 4 } },
      plugins: {
        legend: { display: anyRet, position: 'top', align: 'start', labels: _vLegend(T) },
        vaultRowLabels: { text: function (i) {
          var r = rows[i];
          if (!r.paid && !r.returned) return { text: 'none this year', muted: true };
          var pct = total ? Math.round(r.paid / total * 100) : 0;
          return { text: _vCompact(anyRet ? r.net : r.paid) + (anyRet ? ' net' : '') + ' · ' + pct + '%' };
        } },
        tooltip: _vTip(T, { mode: 'index', axis: 'y', intersect: false, callbacks: {
          label: function (c) {
            var r = rows[c.dataIndex];
            if (c.datasetIndex === 0) return ' Paid: ' + _vPeso(r.paid) + (r.n ? ' (' + r.n + ' payout' + (r.n === 1 ? '' : 's') + ')' : '');
            return ' Returned: ' + _vPeso(r.returned);
          },
          footer: function (items) {
            if (!items.length) return '';
            var r = rows[items[0].dataIndex], pct = total ? (r.paid / total * 100).toFixed(1) : '0.0';
            return (anyRet ? 'Net received: ' + _vPeso(r.net) + ' · ' : '') + pct + '% of ' + _vPeso(total) + ' paid';
          }
        } })
      },
      scales: _vRowScales(T)
    },
    plugins: [_vRowLabels]
  });
}

function buildVaultCharts() {
  var T = window.chartTheme();
  [_flowChartInst, _staffChartInst, _divChartInst].forEach(function (c) { if (c) c.destroy(); });
  _flowChartInst = _staffChartInst = _divChartInst = null;
  buildFlowChart(T); buildStaffChart(T); buildDivChart(T);
}
buildVaultCharts();
window.addEventListener('laskie:themechange', buildVaultCharts);

// ── Logs ─────────────────────────────────────────────────────
function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function loadLogs() {
  const month = document.getElementById('logMonth').value;
  const year  = document.getElementById('logYear').value;
  const box   = document.getElementById('logsContainer');
  box.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
  apiPost('../admin/vault.php', {action:'get_logs', month, year}, (err, res) => {
    if (!res || !res.success) { box.innerHTML='<div class="alert alert-danger">Failed to load logs.</div>'; return; }
    if (!res.logs.length) { box.innerHTML='<div class="text-center text-muted py-5">No records for this period.</div>'; return; }
    let html = `<div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr>
        <th>Date</th><th>Type</th><th>Person / Recipient</th>
        <th class="text-end">Amount</th><th>Notes</th>
        <th class="text-center" style="width:56px">Proof</th>
        <th class="text-center" style="width:70px"></th>
      </tr></thead><tbody>`;
    res.logs.forEach(r => {
      const isRem      = r.log_type === 'remittance';
      const isDist     = r.log_type === 'distribution';
      const isRet      = r.log_type === 'return';
      const isUserRet  = r.log_type === 'user_return';
      let badge, person, amtColor, actions;
      if (isRem) {
        // Direction is carried by the ←/→ glyph, not hue (§3.3).
        badge    = `<span class="muted-pill" style="font-weight:600">Remittance</span>`;
        person   = esc(r.person_name||'—');
        amtColor = 'var(--ink)';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteRemittance(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else if (isDist) {
        badge    = `<span class="muted-pill" style="font-weight:600">Dividend</span>`;
        person   = `<span>→ ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = 'var(--ink)';
        actions  = `<button class="btn-icon" title="Edit" onclick="openEditDist(${r.id})"><i class="fa-solid fa-pen fa-xs"></i></button>`
                 + `<button class="btn-icon danger" title="Delete" onclick="deleteDistribution(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else if (isRet) {
        badge    = `<span class="muted-pill" style="font-weight:600">Return</span>`;
        person   = `<span>← ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = 'var(--ink)';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteReturn(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else {  // user_return
        badge    = `<span class="muted-pill" style="font-weight:600">Vault→User</span>`;
        person   = `<span>→ ${esc(r.recipient_name||'—')}</span>`;
        amtColor = 'var(--ink)';
        // user_return Edit/Delete are available to admins + accountants (this
        // whole page is gated to those two roles), same as the other log rows.
        actions  = `<button class="btn-icon" title="Edit" onclick="openEditUserReturn(${r.id})"><i class="fa-solid fa-pen fa-xs"></i></button>`
                 + `<button class="btn-icon danger" title="Delete" onclick="deleteUserReturn(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      }
      // Attachment, where the source table has one: remittances and
      // vault→user returns carry cash_transactions.doc_path/doc_url,
      // distributions carry dividend_distributions.receipt_path/receipt_url,
      // and dividend returns have none (the query sends NULL for those).
      const proofHref = _vaultSafeUrl(r.proof_path || r.proof_url);
      const proof = proofHref
        ? `<a href="${esc(proofHref)}" target="_blank" rel="noopener noreferrer" class="btn-icon" title="View proof"><i class="fa-solid fa-paperclip fa-xs"></i></a>`
        : '<span class="text-muted">—</span>';
      html += `<tr>
        <td style="white-space:nowrap;color:var(--text-secondary)">${esc(r.log_date)}</td>
        <td>${badge}</td>
        <td>${person}</td>
        <td class="text-end fw-600" style="color:${amtColor}">₱${parseFloat(r.amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        <td class="text-muted" style="font-size:12px">${esc(r.notes)||'—'}</td>
        <td class="text-center">${proof}</td>
        <td class="text-center" style="white-space:nowrap">${actions}</td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
  });
}
document.getElementById('logMonth').addEventListener('change', loadLogs);
document.getElementById('logYear').addEventListener('change', loadLogs);
loadLogs();

// ── Remittance modal ─────────────────────────────────────────
function openRemittanceModal() {
  document.getElementById('remBy').value     = '';
  document.getElementById('remAmount').value = '';
  document.getElementById('remDate').value   = new Date().toISOString().slice(0,10);
  document.getElementById('remNotes').value  = '';
  document.getElementById('remFile').value   = '';
  document.getElementById('remUrl').value    = '';
  document.getElementById('remMsg').style.display = 'none';
  remittanceModal.show();
}

function saveRemittance() {
  const el = document.getElementById('remMsg');
  // FormData (not a plain object) so the optional file upload rides along.
  // apiPost passes FormData through unchanged.
  const fd = new FormData();
  fd.append('action',          'add_remittance');
  fd.append('remitted_by',     document.getElementById('remBy').value);
  fd.append('amount',          document.getElementById('remAmount').value);
  fd.append('remittance_date', document.getElementById('remDate').value);
  fd.append('notes',           document.getElementById('remNotes').value);
  fd.append('doc_url',         document.getElementById('remUrl').value);
  const file = document.getElementById('remFile').files[0];
  if (file) fd.append('doc_file', file);
  apiPost('../admin/vault.php', fd, (err, res) => {
    el.style.display='';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res && res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ remittanceModal.hide(); location.reload(); }, 700);
  });
}

// ── Distribution modal ───────────────────────────────────────
// ── Proof upload plumbing (shared by both distribution modals) ───
// Reject javascript:/data:/vbscript: before a stored value reaches an href.
// Server-side validation rejects those on the way in; this is the render-side
// belt, matching safeUrl() in collection.php / cash.php.
function _vaultSafeUrl(u) {
  if (!u) return '';
  const v = String(u).trim();
  return /^\s*(javascript|data|vbscript|file):/i.test(v) ? '' : v;
}

// Lock a modal's save button and show a spinner — the same shape as
// expenses.php::_setExpSaveBusy / collection.php::_setPaySaveBusy, so a
// double-tap cannot fire a second POST before the first round-trip returns.
function _setDistBusy(btnId, busy, label, idleHtml) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  btn.disabled = busy;
  btn.innerHTML = busy
    ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (label || 'Saving…')
    : idleHtml;
}

function _resetDistProgress(prefix) {
  const wrap = document.getElementById(prefix + 'UploadProgress');
  if (!wrap) return;
  wrap.style.display = 'none';
  document.getElementById(prefix + 'UploadBar').style.width = '0%';
  document.getElementById(prefix + 'UploadPct').textContent = '0%';
}

// Submit a distribution FormData. With a file attached it goes over XHR so
// upload.onprogress can drive the bar (fetch/apiPost expose no progress);
// with no file it takes the ordinary apiPost path.
function _submitDist(o) {
  const el = document.getElementById(o.msgId);

  const done = (res) => {
    el.style.display = '';
    if (!res || !res.success) {
      _setDistBusy(o.btnId, false, null, o.idleHtml);
      _resetDistProgress(o.prefix);
      el.className = 'alert alert-danger';
      el.textContent = (res && res.error) || 'Save failed.';
      return;
    }
    el.className = 'alert alert-success';
    el.textContent = res.msg;
    // Full reload — stat cards, recipient stats and charts are server-rendered
    // and stay stale otherwise (same as every other write handler on this page).
    setTimeout(() => { o.modal.hide(); location.reload(); }, 700);
  };

  const fail = () => {
    _setDistBusy(o.btnId, false, null, o.idleHtml);
    _resetDistProgress(o.prefix);
    el.style.display = '';
    el.className = 'alert alert-danger';
    el.textContent = 'Network error. Please try again.';
  };

  _setDistBusy(o.btnId, true, o.file ? 'Uploading…' : 'Saving…', o.idleHtml);
  el.style.display = 'none';

  if (!o.file) {
    apiPost('../admin/vault.php', o.fd, (err, res) => err ? fail() : done(res));
    return;
  }

  const bar = document.getElementById(o.prefix + 'UploadBar');
  const pct = document.getElementById(o.prefix + 'UploadPct');
  document.getElementById(o.prefix + 'UploadProgress').style.display = '';

  const xhr = new XMLHttpRequest();
  xhr.open('POST', '../admin/vault.php', true);
  xhr.withCredentials = true;
  const hdr = window.csrfHeaders();
  Object.keys(hdr).forEach(k => xhr.setRequestHeader(k, hdr[k]));

  xhr.upload.onprogress = (e) => {
    if (!e.lengthComputable) return;
    const p = Math.round(e.loaded / e.total * 100);
    bar.style.width = p + '%';
    pct.textContent = p + '%';
    // 100% means the bytes are up; the server is still compressing the image.
    if (p >= 100) { pct.textContent = 'Processing…'; _setDistBusy(o.btnId, true, 'Processing…', o.idleHtml); }
  };
  xhr.onload  = () => { _resetDistProgress(o.prefix); try { done(JSON.parse(xhr.responseText)); } catch (e) { fail(); } };
  xhr.onerror = fail;
  xhr.send(o.fd);
}

const DIST_SAVE_HTML      = '<i class="fa-solid fa-check me-1"></i>Record Distribution';
const EDIT_DIST_SAVE_HTML = '<i class="fa-solid fa-check me-1"></i>Save Changes';

function openDistributionModal() {
  const el = document.getElementById('distRecipient');
  if (el) el.value = '';
  const am = document.getElementById('distAmount');
  if (am) am.value = '';
  document.getElementById('distDate') && (document.getElementById('distDate').value = new Date().toISOString().slice(0,10));
  const nt = document.getElementById('distNotes');
  if (nt) nt.value = '';
  // The proof fields only exist when there is at least one active recipient.
  const df = document.getElementById('distFile');
  if (df) df.value = '';
  const du = document.getElementById('distUrl');
  if (du) du.value = '';
  _setDistBusy('distSaveBtn', false, null, DIST_SAVE_HTML);
  _resetDistProgress('dist');
  document.getElementById('distMsg').style.display = 'none';
  distributionModal.show();
}

function saveDistribution() {
  const btn = document.getElementById('distSaveBtn');
  if (btn && btn.disabled) return;   // double-tap belt

  // Client-side size guard (30 MB — the same cap handleUpload enforces) so an
  // oversized file fails instantly instead of after a long upload.
  const fileInput = document.getElementById('distFile');
  if (fileInput && fileInput.files[0] && !validateFileSize(fileInput)) return;
  const file = fileInput ? fileInput.files[0] : null;

  const fd = new FormData();
  fd.append('action',            'add_distribution');
  fd.append('recipient_id',      document.getElementById('distRecipient').value);
  fd.append('amount',            document.getElementById('distAmount').value);
  fd.append('distribution_date', document.getElementById('distDate').value);
  fd.append('notes',             document.getElementById('distNotes').value);
  fd.append('receipt_url',       document.getElementById('distUrl').value);
  if (file) fd.append('receipt_file', file);

  _submitDist({ fd: fd, file: file, prefix: 'dist', btnId: 'distSaveBtn',
                msgId: 'distMsg', idleHtml: DIST_SAVE_HTML, modal: distributionModal });
}

// ── Delete handlers ──────────────────────────────────────────
function deleteRemittance(id) {
  confirmDelete('Delete this remittance? This cannot be undone.', () => {
    apiPost('../admin/vault.php', {action:'delete_remittance', id}, (err, res) => {
      if (!res || !res.success){ showToast((res && res.error)||'Delete failed.','error'); return; }
      showToast(res.msg,'success'); loadLogs();
      setTimeout(()=>location.reload(), 900);
    });
  });
}

function deleteDistribution(id) {
  confirmDelete('Delete this distribution? This cannot be undone.', () => {
    apiPost('../admin/vault.php', {action:'delete_distribution', id}, (err, res) => {
      if (!res || !res.success){ showToast((res&&res.error)||'Delete failed.','error'); return; }
      showToast(res.msg,'success');
      // Full reload — the Recipient Stats card, dividend chart, and table
      // tfoots are server-rendered and stay stale otherwise. All other delete
      // handlers on this page do the same.
      setTimeout(() => location.reload(), 400);
    });
  });
}

// ── Recipients modal ─────────────────────────────────────────
function openRecipientsModal() {
  cancelEditRecipient();
  recipientsModal.show();
}

function cancelEditRecipient() {
  document.getElementById('recipId').value    = '';
  document.getElementById('recipName').value  = '';
  document.getElementById('recipNotes').value = '';
  document.getElementById('recipFormTitle').textContent    = 'Add New Recipient';
  document.getElementById('recipEditBanner').style.display = 'none';
  document.getElementById('recipMsg').style.display        = 'none';
}

function editRecipient(id, name, notes) {
  document.getElementById('recipId').value    = id;
  document.getElementById('recipName').value  = name;
  document.getElementById('recipNotes').value = notes||'';
  document.getElementById('recipFormTitle').textContent    = 'Edit Recipient';
  document.getElementById('recipEditName').textContent     = name;
  document.getElementById('recipEditBanner').style.display = '';
  document.getElementById('recipMsg').style.display        = 'none';
  const modalBody = document.querySelector('#recipientsModal .modal-body');
  if (modalBody) modalBody.scrollTop = 0;
  document.getElementById('recipName').focus();
}

function saveRecipient() {
  const el = document.getElementById('recipMsg');
  apiPost('../admin/vault.php', {
    action:'save_recipient',
    id: document.getElementById('recipId').value,
    name: document.getElementById('recipName').value,
    notes: document.getElementById('recipNotes').value
  }, (err, res) => {
    el.style.display='';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res && res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ recipientsModal.hide(); location.reload(); }, 700);
  });
}

function toggleRecipient(id, active) {
  apiPost('../admin/vault.php', {action:'toggle_recipient', id, is_active:active}, (err, res) => {
    if (!res || !res.success){ showToast((res && res.error)||'Toggle failed.','error'); return; }
    showToast(res.msg,'success');
    setTimeout(()=>location.reload(), 700);
  });
}

function deleteRecipient(id) {
  confirmDelete('Delete this recipient?', () => {
    apiPost('../admin/vault.php', {action:'delete_recipient', id}, (err, res) => {
      if (!res || !res.success){ showToast((res && res.error)||'Delete failed.','error'); return; }
      showToast(res.msg,'success');
      setTimeout(()=>location.reload(), 700);
    });
  });
}

// ── Edit Distribution (reads embedded DIST_DATA — no AJAX needed) ──
function openEditDist(id) {
  const d = DIST_DATA[id];
  if (!d) { showToast('Distribution not found.','error'); return; }
  document.getElementById('editDistId').value        = d.id;
  document.getElementById('editDistRecipient').value = d.recipient_id;
  document.getElementById('editDistAmount').value    = parseFloat(d.amount);
  document.getElementById('editDistDate').value      = d.distribution_date;
  document.getElementById('editDistNotes').value     = d.notes || '';
  document.getElementById('editDistFile').value      = '';
  document.getElementById('editDistUrl').value       = d.receipt_url || '';
  // Show what is already attached; uploading a new file replaces it.
  const pc = document.getElementById('editDistProofCurrent');
  const pl = document.getElementById('editDistProofLink');
  const proof = _vaultSafeUrl(d.receipt_path || d.receipt_url);
  if (proof) {
    pl.href = proof;
    pl.textContent = d.receipt_path ? 'Uploaded file' : 'External link';
    pc.style.display = '';
  } else {
    pc.style.display = 'none';
  }
  _setDistBusy('editDistSaveBtn', false, null, EDIT_DIST_SAVE_HTML);
  _resetDistProgress('editDist');
  document.getElementById('editDistMsg').style.display = 'none';
  editDistModal.show();
}

function saveEditDistribution() {
  const btn = document.getElementById('editDistSaveBtn');
  if (btn && btn.disabled) return;   // double-tap belt

  const fileInput = document.getElementById('editDistFile');
  if (fileInput && fileInput.files[0] && !validateFileSize(fileInput)) return;
  const file = fileInput ? fileInput.files[0] : null;

  const fd = new FormData();
  fd.append('action',            'edit_distribution');
  fd.append('id',                document.getElementById('editDistId').value);
  fd.append('recipient_id',      document.getElementById('editDistRecipient').value);
  fd.append('amount',            document.getElementById('editDistAmount').value);
  fd.append('distribution_date', document.getElementById('editDistDate').value);
  fd.append('notes',             document.getElementById('editDistNotes').value);
  fd.append('receipt_url',       document.getElementById('editDistUrl').value);
  if (file) fd.append('receipt_file', file);

  _submitDist({ fd: fd, file: file, prefix: 'editDist', btnId: 'editDistSaveBtn',
                msgId: 'editDistMsg', idleHtml: EDIT_DIST_SAVE_HTML, modal: editDistModal });
}

// ── Edit Return ───────────────────────────────────────────────
function openEditReturn(id) {
  const r = RET_DATA[id];
  if (!r) { showToast('Return record not found.','error'); return; }
  document.getElementById('editRetId').value         = r.id;
  document.getElementById('editRetRecipient').value  = r.recipient_id;
  document.getElementById('editRetAmount').value     = parseFloat(r.amount);
  document.getElementById('editRetDate').value       = r.return_date;
  document.getElementById('editRetNotes').value      = r.notes || '';
  document.getElementById('editRetMsg').style.display = 'none';
  editReturnModal.show();
}

function saveEditReturn() {
  const el = document.getElementById('editRetMsg');
  apiPost('../admin/vault.php', {
    action: 'edit_return',
    id: document.getElementById('editRetId').value,
    recipient_id: document.getElementById('editRetRecipient').value,
    amount: document.getElementById('editRetAmount').value,
    return_date: document.getElementById('editRetDate').value,
    notes: document.getElementById('editRetNotes').value
  }, (err, res) => {
    el.style.display = '';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res&&res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ editReturnModal.hide(); location.reload(); }, 700);
  });
}

// ── Return to Vault ──────────────────────────────────────────
function openReturnModal() {
  const el = document.getElementById('retRecipient');
  if (el) el.value = '';
  const am = document.getElementById('retAmount');
  if (am) am.value = '';
  const dt = document.getElementById('retDate');
  if (dt) dt.value = new Date().toISOString().slice(0,10);
  const nt = document.getElementById('retNotes');
  if (nt) nt.value = '';
  document.getElementById('retMsg').style.display = 'none';
  returnModal.show();
}

function saveReturn() {
  const el = document.getElementById('retMsg');
  apiPost('../admin/vault.php', {
    action: 'add_return',
    recipient_id: document.getElementById('retRecipient').value,
    amount: document.getElementById('retAmount').value,
    return_date: document.getElementById('retDate').value,
    notes: document.getElementById('retNotes').value
  }, (err, res) => {
    el.style.display = '';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res&&res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ returnModal.hide(); location.reload(); }, 700);
  });
}

function deleteReturn(id) {
  confirmDelete('Delete this return record? This cannot be undone.', () => {
    apiPost('../admin/vault.php', {action:'delete_return', id}, (err, res) => {
      if (!res || !res.success){ showToast((res&&res.error)||'Delete failed.','error'); return; }
      showToast(res.msg,'success');
      // Full reload — see deleteDistribution comment.
      setTimeout(() => location.reload(), 400);
    });
  });
}

// ── Vault Returns to Users (admin/accountant issues cash from vault to a user) ──
function openUserReturnModal() {
  document.getElementById('userReturnTitle').textContent = 'Issue Cash from Vault to User';
  document.getElementById('userReturnId').value     = '';
  document.getElementById('userReturnUser').value   = '';
  document.getElementById('userReturnAmount').value = '';
  document.getElementById('userReturnDate').value   = new Date().toISOString().split('T')[0];
  document.getElementById('userReturnNotes').value  = '';
  document.getElementById('userReturnMsg').style.display = 'none';
  document.getElementById('userReturnSaveBtn').disabled = false;
  document.getElementById('userReturnSaveBtn').innerHTML = '<i class="fa-solid fa-check me-1"></i>Issue Cash';
  userReturnModal.show();
}

function openEditUserReturn(id) {
  apiPost('../admin/vault.php', {action:'get_user_return', id}, (err, res) => {
    if (!res || !res.success) { showToast((res&&res.error)||'Failed to load.','error'); return; }
    const r = res.record;
    document.getElementById('userReturnTitle').textContent = 'Edit Vault Return to User';
    document.getElementById('userReturnId').value     = r.id;
    document.getElementById('userReturnUser').value   = r.user_id;
    document.getElementById('userReturnAmount').value = r.amount;
    document.getElementById('userReturnDate').value   = r.transaction_date;
    document.getElementById('userReturnNotes').value  = r.notes || '';
    document.getElementById('userReturnMsg').style.display = 'none';
    document.getElementById('userReturnSaveBtn').disabled = false;
    document.getElementById('userReturnSaveBtn').innerHTML = '<i class="fa-solid fa-check me-1"></i>Save Changes';
    userReturnModal.show();
  });
}

function saveUserReturn() {
  const btn = document.getElementById('userReturnSaveBtn');
  if (btn.disabled) return;
  btn.disabled = true;
  const id = document.getElementById('userReturnId').value;
  const data = {
    action:      id ? 'edit_user_return' : 'add_user_return',
    id,
    user_id:     document.getElementById('userReturnUser').value,
    amount:      document.getElementById('userReturnAmount').value,
    return_date: document.getElementById('userReturnDate').value,
    notes:       document.getElementById('userReturnNotes').value
  };
  apiPost('../admin/vault.php', data, (err, res) => {
    btn.disabled = false;
    if (!res || !res.success) {
      const m = document.getElementById('userReturnMsg');
      m.style.display = '';
      m.className = 'alert alert-danger';
      m.textContent = (res && res.error) || 'Save failed.';
      return;
    }
    showToast(res.msg, 'success');
    userReturnModal.hide();
    // Cheap refresh: reload the page so the row + balance + chart all re-render correctly.
    setTimeout(() => location.reload(), 400);
  });
}

function deleteUserReturn(id) {
  confirmDelete('Delete this vault-return record? The user\'s cash on hand will decrease by the amount.', () => {
    apiPost('../admin/vault.php', {action:'delete_user_return', id}, (err, res) => {
      if (!res || !res.success) { showToast((res&&res.error)||'Delete failed.','error'); return; }
      showToast(res.msg, 'success');
      setTimeout(() => location.reload(), 400);
    });
  });
}
</script>
<?php $extraJs = ob_get_clean(); include '../includes/footer.php'; ?>
