<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
// The Vault is accessible to both admins and accountants. Within-page
// destructive operations (recipient management, etc.) still gate on isAdmin().
requireRole(['admin', 'accountant']);

$pageTitle = 'The Vault';
$depth = '../';

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
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $rChk = $pdo->prepare("SELECT id FROM dividend_recipients WHERE id=? AND is_active=1");
        $rChk->execute([$recipientId]);
        if (!$rChk->fetch()) jsonErr('Recipient not found or inactive.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO dividend_distributions (recipient_id,amount,distribution_date,notes,created_by) VALUES (?,?,?,?,?)")
                ->execute([$recipientId,$amount,$date,$notes,$_SESSION['user']['id']]);
            logActivity($pdo,'DIVIDEND_DISTRIBUTION','Vault',"Distributed " . money($amount) . " to recipient #$recipientId");
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonOk(['msg'=>'Dividend distribution recorded.']);
    }

    if ($action === 'delete_distribution') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('Distribution ID required.');
        $chk = $pdo->prepare("SELECT id FROM dividend_distributions WHERE id=?");
        $chk->execute([$id]);
        if (!$chk->fetch()) jsonErr('Distribution not found.');
        $pdo->prepare("DELETE FROM dividend_distributions WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_DIVIDEND_DIST','Vault',"Deleted distribution #$id");
        jsonOk(['msg'=>'Distribution deleted.']);
    }

    if ($action === 'edit_distribution') {
        $id          = (int)($_POST['id'] ?? 0);
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = trim((string)($_POST['amount'] ?? '0'));
        $date        = trim($_POST['distribution_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$id) jsonErr('Distribution ID required.');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $chk = $pdo->prepare("SELECT recipient_id, amount, distribution_date, notes FROM dividend_distributions WHERE id=?");
        $chk->execute([$id]);
        $before = $chk->fetch();
        if (!$before) jsonErr('Distribution not found.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE dividend_distributions SET recipient_id=?,amount=?,distribution_date=?,notes=? WHERE id=?")
                ->execute([$recipientId,$amount,$date,$notes,$id]);
            logChange($pdo,'EDIT_DIVIDEND_DIST','Vault',$before,['recipient_id'=>$recipientId,'amount'=>$amount,'distribution_date'=>$date,'notes'=>$notes]);
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
    // Admin-only flow: cash issued from the vault back to a user (admin,
    // accountant, or staff) — fixes excessive remittances or funds a
    // planned expense when the user has zero cash on hand by mistake.
    // Stored as cash_transactions.transaction_type='vault_return': it
    // INCREASES the user's cash_on_hand and DECREASES the vault balance.
    if ($action === 'add_user_return') {
        if (!isAdmin()) jsonErr('Admin access required.', 403);
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
        if (!isAdmin()) jsonErr('Admin access required.', 403);
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
        if (!isAdmin()) jsonErr('Admin access required.', 403);
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
        if (!isAdmin()) jsonErr('Admin access required.', 403);
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
                   CAST(0 AS UNSIGNED) AS recipient_id
            FROM cash_transactions ct
            LEFT JOIN users u ON u.id = ct.user_id
            WHERE ct.transaction_type='remitted'
              AND ct.transaction_date >= ? AND ct.transaction_date < ?
            UNION ALL
            SELECT 'distribution', dd.id, dd.distribution_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dd.amount, dd.notes,
                   dd.recipient_id
            FROM dividend_distributions dd
            LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
            LEFT JOIN users u ON u.id = dd.created_by
            WHERE dd.distribution_date >= ? AND dd.distribution_date < ?
            UNION ALL
            SELECT 'return', dret.id, dret.return_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dret.amount, dret.notes,
                   dret.recipient_id
            FROM dividend_returns dret
            LEFT JOIN dividend_recipients dr ON dr.id = dret.recipient_id
            LEFT JOIN users u ON u.id = dret.created_by
            WHERE dret.return_date >= ? AND dret.return_date < ?
            UNION ALL
            SELECT 'user_return', ct2.id, ct2.transaction_date,
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci,
                   CONVERT(u2.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   ct2.amount, ct2.notes,
                   CAST(0 AS UNSIGNED)
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

// Chart: monthly remittances per user for selected year. MONTH() in the
// SELECT / GROUP BY is just a projection — the WHERE uses a sargable range
// so idx_cash_user_date can be used.
[$yrStart, $yrEnd] = yearRange($selectedYear);
$cr = $pdo->prepare("
    SELECT MONTH(ct.transaction_date) AS mo, ct.user_id AS uid,
           u.full_name, SUM(ct.amount) AS total
    FROM cash_transactions ct LEFT JOIN users u ON u.id=ct.user_id
    WHERE ct.transaction_type='remitted'
      AND ct.transaction_date >= ? AND ct.transaction_date < ?
    GROUP BY mo, uid, u.full_name ORDER BY uid, mo
");
$cr->execute([$yrStart, $yrEnd]);
$chartByUser = [];
foreach ($cr->fetchAll() as $row) {
    $uid = $row['uid'];
    if (!isset($chartByUser[$uid])) {
        $chartByUser[$uid] = ['name' => $row['full_name'], 'data' => array_fill(0,12,0)];
    }
    $chartByUser[$uid]['data'][(int)$row['mo'] - 1] = (float)$row['total'];
}
$chartByUser = array_values($chartByUser);

// All active users for remittance dropdown
$allUsers = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll();

// Vault → User return records (cash issued from vault back to a user)
$userReturns = $pdo->query("
    SELECT ct.id, ct.user_id, ct.amount, ct.transaction_date AS return_date, ct.notes, u.full_name, u.role
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
           dr.name AS recipient_name, u.full_name AS recorded_by
    FROM dividend_distributions dd
    LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
    LEFT JOIN users u ON u.id = dd.created_by
    ORDER BY dd.distribution_date DESC, dd.id DESC
")->fetchAll();

// All individual return records for the management table
$returnRecords = $pdo->query("
    SELECT dret.id, dret.recipient_id, dret.amount, dret.return_date, dret.notes,
           dr.name AS recipient_name, u.full_name AS recorded_by
    FROM dividend_returns dret
    LEFT JOIN dividend_recipients dr ON dr.id = dret.recipient_id
    LEFT JOIN users u ON u.id = dret.created_by
    ORDER BY dret.return_date DESC, dret.id DESC
")->fetchAll();

// Chart: dividend distributions per recipient for selected div_year
$divYear = (int)($_GET['div_year'] ?? date('Y'));
[$divYrStart, $divYrEnd] = yearRange($divYear);
$divChartStmt = $pdo->prepare("
    SELECT dr.id, dr.name, COALESCE(SUM(dd.amount),0) AS total
    FROM dividend_recipients dr
    LEFT JOIN dividend_distributions dd
      ON dd.recipient_id = dr.id
     AND dd.distribution_date >= ? AND dd.distribution_date < ?
    GROUP BY dr.id, dr.name
    ORDER BY dr.name
");
$divChartStmt->execute([$divYrStart, $divYrEnd]);
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
    <button class="btn btn-success btn-sm" onclick="openDistributionModal()"><i class="fa-solid fa-money-bill-transfer me-1"></i>Distribute Dividend</button>
    <button class="btn btn-warning btn-sm" onclick="openReturnModal()"><i class="fa-solid fa-rotate-left me-1"></i>Return to Vault</button>
    <?php if (isAdmin()): ?>
    <button class="btn btn-info btn-sm" onclick="openUserReturnModal()"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Return to User</button>
    <?php endif; ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="openRecipientsModal()"><i class="fa-solid fa-users me-1"></i>Recipients</button>
  </div>
</div>

<!-- Vault Balance Hero -->
<div class="card mb-3 dark-card" style="border:none;">
  <div class="card-body py-4 text-center text-white">
    <div style="font-size:11px;letter-spacing:3px;text-transform:uppercase;opacity:.7;margin-bottom:6px;">Current Vault Balance</div>
    <div id="vaultBalanceDisplay" style="font-size:46px;font-weight:800;letter-spacing:-1px;line-height:1.1"><?= money($vaultBalance) ?></div>
    <div style="opacity:.55;font-size:12px;margin-top:6px;"><?= date('F j, Y') ?></div>
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
      <button class="btn btn-sm btn-success" onclick="openDistributionModal()"><i class="fa-solid fa-plus me-1"></i>Add Distribution</button>
    </div>
    <?php if (empty($distributions)): ?>
    <div class="text-center text-muted py-4"><i class="fa-solid fa-inbox fa-2x mb-2 d-block" style="opacity:.2"></i>No distributions recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="distTable">
        <thead>
          <tr>
            <th>Date</th><th>Recipient</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distributions as $d): ?>
          <tr data-dist-id="<?= $d['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($d['distribution_date']) ?></td>
            <td class="fw-600"><?= clean($d['recipient_name'] ?? '—') ?></td>
            <td class="text-end fw-600 text-success"><?= money((float)$d['amount']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= $d['notes'] ? clean($d['notes']) : '—' ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn-icon" title="Edit" onclick="openEditDist(<?= $d['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" onclick="deleteDistribution(<?= $d['id'] ?>)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="2" class="fw-700">Total</td>
            <td class="text-end fw-700" id="distTotal"><?= money($totalDistrib) ?></td>
            <td colspan="2"></td>
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
      <button class="btn btn-sm btn-warning" onclick="openReturnModal()"><i class="fa-solid fa-plus me-1"></i>Add Return</button>
    </div>
    <?php if (empty($returnRecords)): ?>
    <div class="text-center text-muted py-3" style="font-size:13px">No returns recorded yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="retTable">
        <thead>
          <tr>
            <th>Date</th><th>Returned By</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($returnRecords as $ret): ?>
          <tr data-ret-id="<?= $ret['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($ret['return_date']) ?></td>
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
            <td colspan="2" class="fw-700">Total Returned</td>
            <td class="text-end fw-700" id="retTotal" style="color:var(--warning)"><?= money($totalReturned) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (isAdmin()): ?>
<!-- Vault Returns to Users (admin-only section — accountants don't see it
     since both the UI and the backend gate the flow on isAdmin()) -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h6 class="mb-0 fw-600"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:var(--info)"></i>Returns to Users <span class="badge bg-info" style="font-size:10px;font-weight:600">admin only</span></h6>
        <div class="text-muted" style="font-size:11.5px;margin-top:2px">Cash issued back from the vault to a user — corrects excess remittances or funds a planned expense.</div>
      </div>
      <button class="btn btn-sm btn-info text-white" onclick="openUserReturnModal()"><i class="fa-solid fa-plus me-1"></i>Issue Cash to User</button>
    </div>
    <?php if (empty($userReturns)): ?>
    <div class="text-center text-muted py-3" style="font-size:13px">No vault returns to users yet.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="userRetTable">
        <thead>
          <tr>
            <th>Date</th><th>Issued To</th><th>Role</th>
            <th class="text-end">Amount</th><th>Notes</th>
            <th class="text-center" style="width:70px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userReturns as $ur): ?>
          <tr data-user-ret-id="<?= $ur['id'] ?>">
            <td style="white-space:nowrap;color:var(--text-secondary)"><?= fmtDate($ur['return_date']) ?></td>
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
            <td colspan="3" class="fw-700">Total Issued to Users</td>
            <td class="text-end fw-700" style="color:var(--info)"><?= money($totalUserReturned) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; /* isAdmin section close — Vault Returns to Users */ ?>

<!-- Chart -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-chart-bar me-2 text-primary-custom"></i>Monthly Remittances — <?= $selectedYear ?></h6>
      <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="div_year" value="<?= $divYear ?>">
        <span class="text-muted small">Year:</span>
        <select name="year" class="form-select form-select-sm" style="width:90px" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $y==$selectedYear?'selected':'' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (empty($chartByUser)): ?>
    <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-bar fa-2x mb-2 d-block" style="opacity:.2"></i>No remittances recorded for <?= $selectedYear ?>.</div>
    <?php else: ?>
    <canvas id="remittanceChart" style="max-height:320px"></canvas>
    <?php endif; ?>
  </div>
</div>

<!-- Dividend Distribution Chart -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h6 class="mb-0 fw-600"><i class="fa-solid fa-chart-column me-2" style="color:var(--success)"></i>Dividends per Recipient — <?= $divYear ?></h6>
      <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="year" value="<?= $selectedYear ?>">
        <span class="text-muted small">Year:</span>
        <select name="div_year" class="form-select form-select-sm" style="width:90px" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $y==$divYear?'selected':'' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (empty($divChart)): ?>
    <div class="text-center text-muted py-5"><i class="fa-solid fa-chart-column fa-2x mb-2 d-block" style="opacity:.2"></i>No recipients added yet.</div>
    <?php else: ?>
    <canvas id="divChart" style="max-height:280px"></canvas>
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
              <span class="badge bg-secondary ms-1" style="font-size:10px">Inactive</span>
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
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="distNotes" class="form-control" rows="2" placeholder="e.g. Q2 dividend share"></textarea>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if (!empty($activeRecipients)): ?>
        <button class="btn btn-success" onclick="saveDistribution()"><i class="fa-solid fa-check me-1"></i>Record Distribution</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Return Cash from Vault to User (admin only) -->
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
        <button class="btn btn-info text-white" id="userReturnSaveBtn" onclick="saveUserReturn()"><i class="fa-solid fa-check me-1"></i>Issue Cash</button>
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
        <div class="p-3 mb-3 rounded" style="background:var(--bg)">
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
                  <span class="badge bg-secondary">Inactive</span>
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
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="editDistNotes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveEditDistribution()"><i class="fa-solid fa-check me-1"></i>Save Changes</button>
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
        <button class="btn btn-warning" onclick="saveReturn()"><i class="fa-solid fa-check me-1"></i>Record Return</button>
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
        <button class="btn btn-warning" onclick="saveEditReturn()"><i class="fa-solid fa-check me-1"></i>Save Changes</button>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
<?php $chartJson = json_encode($chartByUser, JSON_UNESCAPED_UNICODE); ?>
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

// Role flag for client-side UI gating (admin-only buttons in the logs table).
// Backend still enforces every admin-only action via isAdmin() checks.
const IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;

// ── Chart ────────────────────────────────────────────────────
const CHART_COLORS = ['#EF9F27','#1D9E75','#D85A30','#5754A8','#FAC775','#9FE1CB','#F5C4B3','#26215C'];
const chartByUser  = <?= $chartJson ?>;
const months       = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

if (chartByUser.length > 0) {
  const ctx = document.getElementById('remittanceChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: months,
      datasets: chartByUser.map((u, i) => ({
        label: u.name,
        data: u.data,
        backgroundColor: CHART_COLORS[i % CHART_COLORS.length],
        borderRadius: 4,
        stack: 'vault'
      }))
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'rect', padding: 16 } },
        tooltip: {
          callbacks: {
            label: c => ` ${c.dataset.label}: ₱${parseFloat(c.raw||0).toLocaleString('en-PH',{minimumFractionDigits:2})}`
          }
        }
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false } },
        y: {
          stacked: true,
          grid: { color: '#f0ede5' },
          border: { display: false },
          ticks: { callback: v => '₱' + (v/1000>=1 ? (v/1000).toFixed(0)+'k' : v) }
        }
      }
    }
  });
}

// ── Dividend chart ───────────────────────────────────────────
<?php $divChartJson = json_encode($divChart, JSON_UNESCAPED_UNICODE); ?>
const divChartData = <?= $divChartJson ?>;
if (divChartData.length > 0) {
  const ctx2 = document.getElementById('divChart');
  if (ctx2) {
    new Chart(ctx2.getContext('2d'), {
      type: 'bar',
      data: {
        labels: divChartData.map(r => r.name),
        datasets: [{
          label: 'Total Distributed',
          data: divChartData.map(r => parseFloat(r.total)),
          backgroundColor: divChartData.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: c => ` ₱${parseFloat(c.raw||0).toLocaleString('en-PH',{minimumFractionDigits:2})}`
            }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false } },
          y: {
            beginAtZero: true,
            grid: { color: '#f0ede5' },
            border: { display: false },
            ticks: { callback: v => '₱' + (v/1000>=1 ? (v/1000).toFixed(0)+'k' : v) }
          }
        }
      }
    });
  }
}

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
        <th class="text-end">Amount</th><th>Notes</th><th class="text-center" style="width:70px"></th>
      </tr></thead><tbody>`;
    res.logs.forEach(r => {
      const isRem      = r.log_type === 'remittance';
      const isDist     = r.log_type === 'distribution';
      const isRet      = r.log_type === 'return';
      const isUserRet  = r.log_type === 'user_return';
      let badge, person, amtColor, actions;
      if (isRem) {
        badge    = `<span class="badge" style="background:var(--laskie-amber-bg);color:var(--laskie-amber-ink);font-weight:600">Remittance</span>`;
        person   = esc(r.person_name||'—');
        amtColor = 'var(--primary)';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteRemittance(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else if (isDist) {
        badge    = `<span class="badge" style="background:var(--laskie-teal-bg);color:var(--laskie-teal-ink);font-weight:600">Dividend</span>`;
        person   = `<span style="color:var(--laskie-teal)">→ ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = 'var(--success)';
        actions  = `<button class="btn-icon" title="Edit" onclick="openEditDist(${r.id})"><i class="fa-solid fa-pen fa-xs"></i></button>`
                 + `<button class="btn-icon danger" title="Delete" onclick="deleteDistribution(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else if (isRet) {
        badge    = `<span class="badge" style="background:var(--laskie-coral-bg);color:var(--laskie-coral-ink);font-weight:600">Return</span>`;
        person   = `<span style="color:var(--laskie-coral)">← ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = 'var(--laskie-coral)';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteReturn(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else {  // user_return
        badge    = `<span class="badge" style="background:var(--laskie-indigo-bg);color:var(--laskie-indigo);font-weight:600">Vault→User</span>`;
        person   = `<span style="color:var(--laskie-indigo)">→ ${esc(r.recipient_name||'—')}</span>`;
        amtColor = 'var(--info)';
        // user_return Edit/Delete are admin-only — accountants see the row but
        // not the action buttons (backend would 403 them anyway).
        actions  = IS_ADMIN
          ? `<button class="btn-icon" title="Edit" onclick="openEditUserReturn(${r.id})"><i class="fa-solid fa-pen fa-xs"></i></button>`
          + `<button class="btn-icon danger" title="Delete" onclick="deleteUserReturn(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`
          : '';
      }
      html += `<tr>
        <td style="white-space:nowrap;color:var(--text-secondary)">${esc(r.log_date)}</td>
        <td>${badge}</td>
        <td>${person}</td>
        <td class="text-end fw-600" style="color:${amtColor}">₱${parseFloat(r.amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        <td class="text-muted" style="font-size:12px">${esc(r.notes)||'—'}</td>
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
function openDistributionModal() {
  const el = document.getElementById('distRecipient');
  if (el) el.value = '';
  const am = document.getElementById('distAmount');
  if (am) am.value = '';
  document.getElementById('distDate') && (document.getElementById('distDate').value = new Date().toISOString().slice(0,10));
  const nt = document.getElementById('distNotes');
  if (nt) nt.value = '';
  document.getElementById('distMsg').style.display = 'none';
  distributionModal.show();
}

function saveDistribution() {
  const el = document.getElementById('distMsg');
  apiPost('../admin/vault.php', {
    action:'add_distribution',
    recipient_id: document.getElementById('distRecipient').value,
    amount: document.getElementById('distAmount').value,
    distribution_date: document.getElementById('distDate').value,
    notes: document.getElementById('distNotes').value
  }, (err, res) => {
    el.style.display='';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res && res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ distributionModal.hide(); location.reload(); }, 700);
  });
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
  document.getElementById('editDistMsg').style.display = 'none';
  editDistModal.show();
}

function saveEditDistribution() {
  const el = document.getElementById('editDistMsg');
  apiPost('../admin/vault.php', {
    action: 'edit_distribution',
    id: document.getElementById('editDistId').value,
    recipient_id: document.getElementById('editDistRecipient').value,
    amount: document.getElementById('editDistAmount').value,
    distribution_date: document.getElementById('editDistDate').value,
    notes: document.getElementById('editDistNotes').value
  }, (err, res) => {
    el.style.display = '';
    if (!res || !res.success){ el.className='alert alert-danger'; el.textContent=(res&&res.error)||'Save failed.'; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ editDistModal.hide(); location.reload(); }, 700);
  });
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

// ── Vault Returns to Users (admin issues cash from vault back to a user) ──
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
