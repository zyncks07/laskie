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
                   ct.amount, ct.notes
            FROM cash_transactions ct
            LEFT JOIN users u ON u.id = ct.user_id
            WHERE ct.transaction_type='remitted'
              AND YEAR(ct.transaction_date)=? AND (?=0 OR MONTH(ct.transaction_date)=?)
            UNION ALL
            SELECT 'distribution', dd.id, dd.distribution_date,
                   CONVERT(u.full_name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   CONVERT(dr.name USING utf8mb4) COLLATE utf8mb4_general_ci,
                   dd.amount, dd.notes
            FROM dividend_distributions dd
            LEFT JOIN dividend_recipients dr ON dr.id = dd.recipient_id
            LEFT JOIN users u ON u.id = dd.created_by
            WHERE YEAR(dd.distribution_date)=? AND (?=0 OR MONTH(dd.distribution_date)=?)
            ORDER BY log_date DESC, log_type
        ");
        $rows->execute([$yr,$mo,$mo,$yr,$mo,$mo]);
        jsonOk(['logs'=>$rows->fetchAll()]);
    }

    exit;
}

// ── Page data ─────────────────────────────────────────────────
$selectedYear  = (int)($_GET['year'] ?? date('Y'));
$totalRemitted = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type='remitted'")->fetchColumn();
$totalDistrib  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dividend_distributions")->fetchColumn();
$vaultBalance  = $totalRemitted - $totalDistrib;

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
           COALESCE(SUM(dd.amount),0) AS total_received,
           COUNT(dd.id) AS dist_count
    FROM dividend_recipients dr
    LEFT JOIN dividend_distributions dd ON dd.recipient_id=dr.id
    GROUP BY dr.id, dr.name, dr.notes, dr.is_active
    ORDER BY dr.name
")->fetchAll();
$activeRecipients = array_values(array_filter($recipientStats, fn($r) => $r['is_active']));

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
    <button class="btn btn-outline-secondary btn-sm" onclick="openRecipientsModal()"><i class="fa-solid fa-users me-1"></i>Recipients</button>
  </div>
</div>

<!-- Vault Balance Hero -->
<div class="card mb-3" style="background:linear-gradient(135deg,#1a3a8f 0%,#3b5bdb 100%);border:none;border-radius:var(--radius-lg);">
  <div class="card-body py-4 text-center text-white">
    <div style="font-size:11px;letter-spacing:3px;text-transform:uppercase;opacity:.7;margin-bottom:6px;">Current Vault Balance</div>
    <div style="font-size:46px;font-weight:800;letter-spacing:-1px;line-height:1.1"><?= money($vaultBalance) ?></div>
    <div style="opacity:.55;font-size:12px;margin-top:6px;"><?= date('F j, Y') ?></div>
  </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-arrow-down-to-line"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Remitted</div>
        <div class="stat-value"><?= money($totalRemitted) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--success-bg)"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--success)"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Distributed</div>
        <div class="stat-value"><?= money($totalDistrib) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--warning-bg)"><i class="fa-solid fa-users" style="color:var(--warning)"></i></div>
      <div class="stat-body">
        <div class="stat-label">Recipients</div>
        <div class="stat-value"><?= count($recipientStats) ?></div>
      </div>
    </div>
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
            <th class="text-end">Total Received</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recipientStats as $r): ?>
          <tr>
            <td>
              <?= clean($r['name']) ?>
              <?php if (!$r['is_active']): ?>
              <span class="badge bg-secondary ms-1" style="font-size:10px">Inactive</span>
              <?php endif; ?>
              <?php if ($r['notes']): ?><div class="text-muted" style="font-size:11px"><?= clean($r['notes']) ?></div><?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= (int)$r['dist_count'] ?>×</td>
            <td class="text-end fw-600 text-success"><?= money((float)$r['total_received']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border)">
            <td class="fw-700">Total</td>
            <td></td>
            <td class="text-end fw-700"><?= money($totalDistrib) ?></td>
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
                  <button class="btn-icon" title="Edit" onclick="editRecipient(<?= $r['id'] ?>, <?= json_encode($r['name']) ?>, <?= json_encode($r['notes']??'') ?>)">
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

<?php ob_start(); ?>
<script>
<?php $chartJson = json_encode($chartByUser, JSON_UNESCAPED_UNICODE); ?>
// ── Modals ───────────────────────────────────────────────────
const remittanceModal   = new bootstrap.Modal(document.getElementById('remittanceModal'));
const distributionModal = new bootstrap.Modal(document.getElementById('distributionModal'));
const recipientsModal   = new bootstrap.Modal(document.getElementById('recipientsModal'));

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
        <th class="text-end">Amount</th><th>Notes</th><th class="text-center" style="width:50px"></th>
      </tr></thead><tbody>`;
    res.logs.forEach(r => {
      const isRem = r.log_type === 'remittance';
      const badge = isRem
        ? `<span class="badge" style="background:#dbeafe;color:#1a3a8f;font-weight:600">Remittance</span>`
        : `<span class="badge" style="background:#dcfce7;color:#15803d;font-weight:600">Dividend</span>`;
      const person = isRem
        ? esc(r.person_name||'—')
        : `<span style="color:#15803d">→ ${esc(r.recipient_name||'—')}</span> <span class="text-muted" style="font-size:11px">via ${esc(r.person_name||'—')}</span>`;
      const delBtn = isRem
        ? `<button class="btn-icon danger" title="Delete" onclick="deleteRemittance(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`
        : `<button class="btn-icon danger" title="Delete" onclick="deleteDistribution(${r.id})"><i class="fa-solid fa-trash fa-xs"></i></button>`;
      html += `<tr>
        <td style="white-space:nowrap;color:var(--text-secondary)">${esc(r.log_date)}</td>
        <td>${badge}</td>
        <td>${person}</td>
        <td class="text-end fw-600" style="color:${isRem?'var(--primary)':'var(--success)'}">₱${parseFloat(r.amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        <td class="text-muted" style="font-size:12px">${esc(r.notes)||'—'}</td>
        <td class="text-center">${delBtn}</td>
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
      if (!res.success){ showToast(res.error,'error'); return; }
      showToast(res.msg,'success'); loadLogs();
      setTimeout(()=>location.reload(), 900);
    });
  });
}

// ── Recipients modal ─────────────────────────────────────────
function openRecipientsModal() {
  document.getElementById('recipId').value    = '';
  document.getElementById('recipName').value  = '';
  document.getElementById('recipNotes').value = '';
  document.getElementById('recipFormTitle').textContent = 'Add New Recipient';
  document.getElementById('recipMsg').style.display = 'none';
  recipientsModal.show();
}

function editRecipient(id, name, notes) {
  document.getElementById('recipId').value    = id;
  document.getElementById('recipName').value  = name;
  document.getElementById('recipNotes').value = notes||'';
  document.getElementById('recipFormTitle').textContent = 'Edit Recipient';
  document.getElementById('recipMsg').style.display = 'none';
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
</script>
<?php $extraJs = ob_get_clean(); include '../includes/footer.php'; ?>
