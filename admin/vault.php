<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();

$pageTitle = 'The Vault';
$depth = '../';

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    $action = $_POST['action'] ?? '';

    if ($action === 'add_remittance') {
        $remittedBy = (int)($_POST['remitted_by'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $date       = trim($_POST['remittance_date'] ?? '');
        $notes      = nullOrStr($_POST['notes'] ?? '');
        if (!$remittedBy) jsonErr('Please select who is remitting.');
        if ($amount <= 0) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,transaction_date,notes) VALUES (?,?,?,?,?)")
            ->execute([$remittedBy,'remitted',$amount,$date,$notes]);
        logActivity($pdo,'VAULT_REMITTANCE','Vault',"Remittance ₱$amount from user #$remittedBy");
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
        $amount      = (float)($_POST['amount'] ?? 0);
        $date        = trim($_POST['distribution_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if ($amount <= 0) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $pdo->prepare("INSERT INTO dividend_distributions (recipient_id,amount,distribution_date,notes,created_by) VALUES (?,?,?,?,?)")
            ->execute([$recipientId,$amount,$date,$notes,$_SESSION['user']['id']]);
        logActivity($pdo,'DIVIDEND_DISTRIBUTION','Vault',"Distributed ₱$amount to recipient #$recipientId");
        jsonOk(['msg'=>'Dividend distribution recorded.']);
    }

    if ($action === 'delete_distribution') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM dividend_distributions WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_DIVIDEND_DIST','Vault',"Deleted distribution #$id");
        jsonOk(['msg'=>'Distribution deleted.']);
    }

    if ($action === 'edit_distribution') {
        $id          = (int)($_POST['id'] ?? 0);
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $date        = trim($_POST['distribution_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$id) jsonErr('Distribution ID required.');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if ($amount <= 0) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $chk = $pdo->prepare("SELECT id FROM dividend_distributions WHERE id=?");
        $chk->execute([$id]);
        if (!$chk->fetch()) jsonErr('Distribution not found.');
        $pdo->prepare("UPDATE dividend_distributions SET recipient_id=?,amount=?,distribution_date=?,notes=? WHERE id=?")
            ->execute([$recipientId,$amount,$date,$notes,$id]);
        logActivity($pdo,'EDIT_DIVIDEND_DIST','Vault',"Edited distribution #$id (₱$amount to recipient #$recipientId)");
        jsonOk(['msg'=>'Distribution updated.']);
    }

    if ($action === 'add_return') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $date        = trim($_POST['return_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if ($amount <= 0) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $pdo->prepare("INSERT INTO dividend_returns (recipient_id,amount,return_date,notes,created_by) VALUES (?,?,?,?,?)")
            ->execute([$recipientId,$amount,$date,$notes,$_SESSION['user']['id']]);
        logActivity($pdo,'DIVIDEND_RETURN','Vault',"Return ₱$amount from recipient #$recipientId");
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
        $amount      = (float)($_POST['amount'] ?? 0);
        $date        = trim($_POST['return_date'] ?? '');
        $notes       = nullOrStr($_POST['notes'] ?? '');
        if (!$id) jsonErr('Return ID required.');
        if (!$recipientId) jsonErr('Please select a recipient.');
        if ($amount <= 0) jsonErr('Amount must be greater than zero.');
        if (!$date || !strtotime($date)) jsonErr('Valid date required.');
        $chk = $pdo->prepare("SELECT id FROM dividend_returns WHERE id=?");
        $chk->execute([$id]);
        if (!$chk->fetch()) jsonErr('Return not found.');
        $pdo->prepare("UPDATE dividend_returns SET recipient_id=?,amount=?,return_date=?,notes=? WHERE id=?")
            ->execute([$recipientId,$amount,$date,$notes,$id]);
        logActivity($pdo,'EDIT_DIVIDEND_RETURN','Vault',"Edited return #$id (₱$amount from recipient #$recipientId)");
        jsonOk(['msg'=>'Return updated.']);
    }

    if ($action === 'save_recipient') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $notes = nullOrStr($_POST['notes'] ?? '');
        if (!$name) jsonErr('Name is required.');
        if ($id) {
            $pdo->prepare("UPDATE dividend_recipients SET name=?,notes=? WHERE id=?")->execute([$name,$notes,$id]);
            logActivity($pdo,'UPDATE_RECIPIENT','Vault',"Updated recipient #$id ($name)");
        } else {
            $pdo->prepare("INSERT INTO dividend_recipients (name,notes) VALUES (?,?)")->execute([$name,$notes]);
            logActivity($pdo,'ADD_RECIPIENT','Vault',"Added recipient: $name");
        }
        jsonOk(['msg'=>'Recipient saved.']);
    }

    if ($action === 'delete_recipient') {
        $id = (int)($_POST['id'] ?? 0);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM dividend_distributions WHERE recipient_id=?");
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) jsonErr('Cannot delete: recipient has distribution history. Deactivate instead.');
        $pdo->prepare("DELETE FROM dividend_recipients WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_RECIPIENT','Vault',"Deleted recipient #$id");
        jsonOk(['msg'=>'Recipient deleted.']);
    }

    if ($action === 'toggle_recipient') {
        $id     = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 1);
        $pdo->prepare("UPDATE dividend_recipients SET is_active=? WHERE id=?")->execute([$active,$id]);
        jsonOk(['msg'=>$active ? 'Recipient activated.' : 'Recipient deactivated.']);
    }

    if ($action === 'get_logs') {
        $mo = (int)($_POST['month'] ?? 0);
        $yr = (int)($_POST['year']  ?? date('Y'));
        $rows = $pdo->prepare("
            SELECT 'remittance' AS log_type, ct.id, ct.transaction_date AS log_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS person_name,
                   CAST(NULL AS CHAR) COLLATE utf8mb4_general_ci AS recipient_name,
                   ct.amount, ct.notes,
                   CAST(0 AS UNSIGNED) AS recipient_id
            FROM cash_transactions ct
            LEFT JOIN users u ON u.id = ct.user_id
            WHERE ct.transaction_type='remitted'
              AND YEAR(ct.transaction_date)=? AND (?=0 OR MONTH(ct.transaction_date)=?)
            UNION ALL
            SELECT 'distribution', dd.id, dd.distribution_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dd.amount, dd.notes,
                   dd.recipient_id
            FROM dividend_distributions dd
            LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
            LEFT JOIN users u ON u.id = dd.created_by
            WHERE YEAR(dd.distribution_date)=? AND (?=0 OR MONTH(dd.distribution_date)=?)
            UNION ALL
            SELECT 'return', dret.id, dret.return_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dret.amount, dret.notes,
                   dret.recipient_id
            FROM dividend_returns dret
            LEFT JOIN dividend_recipients dr ON dr.id = dret.recipient_id
            LEFT JOIN users u ON u.id = dret.created_by
            WHERE YEAR(dret.return_date)=? AND (?=0 OR MONTH(dret.return_date)=?)
            ORDER BY log_date DESC, log_type
        ");
        $rows->execute([$yr,$mo,$mo,$yr,$mo,$mo,$yr,$mo,$mo]);
        jsonOk(['logs'=>$rows->fetchAll()]);
    }

    exit;
}

// ── Page data ─────────────────────────────────────────────────
$selectedYear  = (int)($_GET['year'] ?? date('Y'));
$totalRemitted = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='remitted'")->fetchColumn();
$totalDistrib  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_distributions")->fetchColumn();
$totalReturned = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_returns")->fetchColumn();
$vaultBalance  = $totalRemitted - $totalDistrib + $totalReturned;

// Chart: monthly remittances per user for selected year
$cr = $pdo->prepare("
    SELECT MONTH(ct.transaction_date) AS mo, ct.user_id AS uid,
           u.full_name, SUM(ct.amount) AS total
    FROM cash_transactions ct LEFT JOIN users u ON u.id=ct.user_id
    WHERE ct.transaction_type='remitted' AND YEAR(ct.transaction_date)=?
    GROUP BY mo, uid, u.full_name ORDER BY uid, mo
");
$cr->execute([$selectedYear]);
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
$divChartStmt = $pdo->prepare("
    SELECT dr.id, dr.name, COALESCE(SUM(dd.amount),0) AS total
    FROM dividend_recipients dr
    LEFT JOIN dividend_distributions dd ON dd.recipient_id=dr.id AND YEAR(dd.distribution_date)=?
    GROUP BY dr.id, dr.name
    ORDER BY dr.name
");
$divChartStmt->execute([$divYear]);
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
    <button class="btn btn-outline-secondary btn-sm" onclick="openRecipientsModal()"><i class="fa-solid fa-users me-1"></i>Recipients</button>
  </div>
</div>

<!-- Vault Balance Hero -->
<div class="card mb-3" style="background:linear-gradient(135deg,#1a3a8f 0%,#3b5bdb 100%);border:none;border-radius:var(--radius-lg);">
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
          <?php $net = (float)$r['total_received'] - (float)$r['total_returned']; ?>
          <tr>
            <td>
              <?= clean($r['name']) ?>
              <?php if (!$r['is_active']): ?>
              <span class="badge bg-secondary ms-1" style="font-size:10px">Inactive</span>
              <?php endif; ?>
              <?php if ($r['notes']): ?><div class="text-muted" style="font-size:11px"><?= clean($r['notes']) ?></div><?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= (int)$r['dist_count'] ?>×</td>
            <td class="text-end text-success"><?= money((float)$r['total_received']) ?></td>
            <td class="text-end" style="color:var(--warning)"><?= $r['total_returned'] > 0 ? money((float)$r['total_returned']) : '—' ?></td>
            <td class="text-end fw-600 text-success"><?= money($net) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td class="fw-700">Total</td>
            <td></td>
            <td class="text-end fw-700"><?= money($totalDistrib) ?></td>
            <td class="text-end fw-700" style="color:var(--warning)"><?= $totalReturned > 0 ? money($totalReturned) : '—' ?></td>
            <td class="text-end fw-700"><?= money($totalDistrib - $totalReturned) ?></td>
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
        <div class="mb-0">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <textarea id="remNotes" class="form-control" rows="2" placeholder="e.g. Weekly cash deposit"></textarea>
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

<!-- Manage Recipients -->
<div class="modal fade" id="recipientsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
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
                <td class="text-end text-success fw-600"><?= money((float)$r['total_received']) ?></td>
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

// ── Embedded page data (no AJAX needed to read these) ────────
<?php
$distMap  = [];
foreach ($distributions  as $d)   { $distMap[$d['id']]  = $d; }
$retMap   = [];
foreach ($returnRecords  as $ret)  { $retMap[$ret['id']] = $ret; }
?>
const DIST_DATA      = <?= json_encode($distMap,  JSON_UNESCAPED_UNICODE) ?>;
const RET_DATA       = <?= json_encode($retMap,   JSON_UNESCAPED_UNICODE) ?>;
const TOTAL_REMITTED = <?= $totalRemitted ?>;

function recalcVaultBalance() {
  const distTotal = Object.values(DIST_DATA).reduce((s,d)=>s+parseFloat(d.amount||0),0);
  const retTotal  = Object.values(RET_DATA).reduce((s,r)=>s+parseFloat(r.amount||0),0);
  const balance   = TOTAL_REMITTED - distTotal + retTotal;
  const fmt = v => '₱'+v.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('vaultBalanceDisplay').textContent = fmt(balance);
  document.getElementById('statDistTotal').textContent       = fmt(distTotal);
  document.getElementById('statRetTotal').textContent        = fmt(retTotal);
  const dt = document.getElementById('distTotal'); if (dt) dt.textContent = fmt(distTotal);
  const rt = document.getElementById('retTotal');  if (rt) rt.textContent = fmt(retTotal);
}

// ── Chart ────────────────────────────────────────────────────
const CHART_COLORS = ['#1a3a8f','#0ea5e9','#15803d','#d97706','#7c3aed','#dc2626','#0891b2','#be185d'];
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
          grid: { color: '#f3f4f6' },
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
            grid: { color: '#f3f4f6' },
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
      const isRem  = r.log_type === 'remittance';
      const isDist = r.log_type === 'distribution';
      const isRet  = r.log_type === 'return';
      let badge, person, amtColor, actions;
      if (isRem) {
        badge    = `<span class="badge" style="background:#dbeafe;color:#1a3a8f;font-weight:600">Remittance</span>`;
        person   = esc(r.person_name||'—');
        amtColor = 'var(--primary)';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteRemittance(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else if (isDist) {
        badge    = `<span class="badge" style="background:#dcfce7;color:#15803d;font-weight:600">Dividend</span>`;
        person   = `<span style="color:#15803d">→ ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = 'var(--success)';
        actions  = `<button class="btn-icon" title="Edit" onclick="openEditDist(${r.id})"><i class="fa-solid fa-pen fa-xs"></i></button>`
                 + `<button class="btn-icon danger" title="Delete" onclick="deleteDistribution(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      } else {
        badge    = `<span class="badge" style="background:#fef9c3;color:#92400e;font-weight:600">Return</span>`;
        person   = `<span style="color:#92400e">← ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
        amtColor = '#d97706';
        actions  = `<button class="btn-icon danger" title="Delete" onclick="deleteReturn(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
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
  document.getElementById('remMsg').style.display = 'none';
  remittanceModal.show();
}

function saveRemittance() {
  const el = document.getElementById('remMsg');
  apiPost('../admin/vault.php', {
    action:'add_remittance',
    remitted_by: document.getElementById('remBy').value,
    amount: document.getElementById('remAmount').value,
    remittance_date: document.getElementById('remDate').value,
    notes: document.getElementById('remNotes').value
  }, (err, res) => {
    el.style.display='';
    if (!res.success){ el.className='alert alert-danger'; el.textContent=res.error; return; }
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
    if (!res.success){ el.className='alert alert-danger'; el.textContent=res.error; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ distributionModal.hide(); location.reload(); }, 700);
  });
}

// ── Delete handlers ──────────────────────────────────────────
function deleteRemittance(id) {
  confirmDelete('Delete this remittance? This cannot be undone.', () => {
    apiPost('../admin/vault.php', {action:'delete_remittance', id}, (err, res) => {
      if (!res.success){ showToast(res.error,'error'); return; }
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
      const row = document.querySelector(`tr[data-dist-id="${id}"]`);
      if (row) row.remove();
      delete DIST_DATA[id];
      recalcVaultBalance();
      loadLogs();
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
    if (!res.success){ el.className='alert alert-danger'; el.textContent=res.error; return; }
    el.className='alert alert-success'; el.textContent=res.msg;
    setTimeout(()=>{ recipientsModal.hide(); location.reload(); }, 700);
  });
}

function toggleRecipient(id, active) {
  apiPost('../admin/vault.php', {action:'toggle_recipient', id, is_active:active}, (err, res) => {
    if (!res.success){ showToast(res.error,'error'); return; }
    showToast(res.msg,'success');
    setTimeout(()=>location.reload(), 700);
  });
}

function deleteRecipient(id) {
  confirmDelete('Delete this recipient?', () => {
    apiPost('../admin/vault.php', {action:'delete_recipient', id}, (err, res) => {
      if (!res.success){ showToast(res.error,'error'); return; }
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
      const row = document.querySelector(`tr[data-ret-id="${id}"]`);
      if (row) row.remove();
      delete RET_DATA[id];
      recalcVaultBalance();
      loadLogs();
    });
  });
}
</script>
<?php $extraJs = ob_get_clean(); include '../includes/footer.php'; ?>
