<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
requireLogin();
$pageTitle = 'My Summary';
$depth = '';

$me       = currentUser();
$myId     = (int)$me['id'];
$curMonth = (int)date('n');
$curYear  = (int)date('Y');
$selMonth = (int)($_GET['month'] ?? $curMonth);
$selYear  = (int)($_GET['year']  ?? $curYear);

// ── Available periods ─────────────────────────────────────────
$periods = $pdo->prepare("SELECT DISTINCT MONTH(transaction_date) m, YEAR(transaction_date) y FROM cash_transactions WHERE user_id=? ORDER BY y DESC, m DESC");
$periods->execute([$myId]);
$periodRows = $periods->fetchAll();
$years = array_unique(array_column($periodRows,'y'));
if (!in_array($curYear,$years)) array_unshift($years,$curYear);
rsort($years);

[$selStart, $selEnd] = monthRange($selMonth, $selYear);

// ── Period totals ─────────────────────────────────────────────
$totals = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN transaction_type='received'     THEN amount ELSE 0 END),0) AS total_received,
        COALESCE(SUM(CASE WHEN transaction_type='remitted'     THEN amount ELSE 0 END),0) AS total_remitted,
        COALESCE(SUM(CASE WHEN transaction_type='expense'      THEN amount ELSE 0 END),0) AS total_expenses,
        COALESCE(SUM(CASE WHEN transaction_type='vault_return' THEN amount ELSE 0 END),0) AS total_vault_returns
    FROM cash_transactions
    WHERE user_id=? AND transaction_date >= ? AND transaction_date < ?
");
$totals->execute([$myId,$selStart,$selEnd]);
$tot = $totals->fetch();
// ── Expenses breakdown ────────────────────────────────────────
$expBreak = $pdo->prepare("
    SELECT ec.name AS category, COALESCE(SUM(e.amount),0) AS total, COUNT(*) AS count
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id=ec.id
    WHERE e.recorded_by=? AND e.expense_date >= ? AND e.expense_date < ? AND e.deleted_at IS NULL
    GROUP BY ec.id ORDER BY total DESC
");
$expBreak->execute([$myId,$selStart,$selEnd]);
$expBreakdown = $expBreak->fetchAll();

// ── All expense rows ──────────────────────────────────────────
$expRows = $pdo->prepare("
    SELECT e.*, ec.name AS category_name, ru.unit_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id=ec.id
    LEFT JOIN rental_units ru       ON e.unit_id=ru.id
    WHERE e.recorded_by=? AND e.expense_date >= ? AND e.expense_date < ? AND e.deleted_at IS NULL
    ORDER BY e.expense_date DESC
");
$expRows->execute([$myId,$selStart,$selEnd]);
$myExpenses = $expRows->fetchAll();

// ── Payments received ─────────────────────────────────────────
$payRows = $pdo->prepare("
    SELECT p.*, ru.unit_name, t.full_name AS tenant_name, st.name AS service_name
    FROM payments p
    LEFT JOIN rental_units ru ON p.unit_id=ru.id
    LEFT JOIN tenants t       ON p.tenant_id=t.id
    LEFT JOIN service_types st ON p.service_type_id=st.id
    WHERE p.received_by=? AND p.payment_date >= ? AND p.payment_date < ? AND p.status != 'voided' AND p.deleted_at IS NULL
    ORDER BY p.payment_date DESC
");
$payRows->execute([$myId,$selStart,$selEnd]);
$myPayments = $payRows->fetchAll();

// ── Remittances ───────────────────────────────────────────────
$remitRows = $pdo->prepare("
    SELECT * FROM cash_transactions
    WHERE user_id=? AND transaction_type='remitted' AND transaction_date >= ? AND transaction_date < ?
    ORDER BY transaction_date DESC
");
$remitRows->execute([$myId,$selStart,$selEnd]);
$myRemits = $remitRows->fetchAll();

// ── Raw logs ──────────────────────────────────────────────────
$logRows = $pdo->prepare("
    SELECT * FROM system_logs
    WHERE user_id=? AND created_at >= ? AND created_at < ?
    ORDER BY created_at DESC LIMIT 500
");
$logRows->execute([$myId,$selStart,$selEnd]);
$myLogs = $logRows->fetchAll();

logActivity($pdo,'VIEW_MY_SUMMARY','MySummary',"Viewed own summary for $selMonth/$selYear");
include 'includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-user-tie me-2 text-primary-custom"></i>My Summary</h1>
  <!-- Period selector -->
  <form method="GET" class="d-flex gap-2 align-items-center">
    <select name="month" class="form-select form-select-sm" style="width:140px" onchange="this.form.submit()">
      <?php for($m=1;$m<=12;$m++): ?>
      <option value="<?=$m?>" <?=$m===$selMonth?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
      <?php endfor; ?>
    </select>
    <select name="year" class="form-select form-select-sm" style="width:90px" onchange="this.form.submit()">
      <?php foreach($years as $y): ?>
      <option value="<?=$y?>" <?=$y===$selYear?'selected':''?>><?=$y?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- Identity Card -->
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3">
      <div style="width:48px;height:48px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;flex-shrink:0">
        <?= strtoupper(substr($me['full_name'],0,1)) ?>
      </div>
      <div>
        <div style="font-size:16px;font-weight:700"><?= clean($me['full_name']) ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= ucfirst($me['role']) ?> &nbsp;·&nbsp; <?= clean($me['username']) ?></div>
      </div>
      <div class="ms-auto text-end">
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em">Period</div>
        <div style="font-size:15px;font-weight:700"><?= date('F Y',mktime(0,0,0,$selMonth,1,$selYear)) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Period Summary Stats -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa-solid fa-arrow-down"></i></div>
      <div class="stat-body">
        <div class="stat-label">Cash Received</div>
        <div class="stat-value" style="font-size:17px"><?= money((float)$tot['total_received']) ?></div>
        <div class="stat-sub"><?= count($myPayments) ?> collection<?= count($myPayments)!=1?'s':'' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-arrow-up"></i></div>
      <div class="stat-body">
        <div class="stat-label">Remitted</div>
        <div class="stat-value" style="font-size:17px"><?= money((float)$tot['total_remitted']) ?></div>
        <div class="stat-sub"><?= count($myRemits) ?> remittance<?= count($myRemits)!=1?'s':'' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-receipt"></i></div>
      <div class="stat-body">
        <div class="stat-label">Expenses</div>
        <div class="stat-value" style="font-size:17px"><?= money((float)$tot['total_expenses']) ?></div>
        <div class="stat-sub"><?= count($myExpenses) ?> record<?= count($myExpenses)!=1?'s':'' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Collections Table -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-money-bill-wave me-2"></i>Collections Recorded</span>
    <span class="badge bg-success"><?= count($myPayments) ?></span>
  </div>
  <?php if(empty($myPayments)): ?>
  <div class="card-body text-center text-muted py-4" style="font-size:13px">No collections recorded for this period.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>Date</th><th>Invoice</th><th>Unit</th><th>Tenant</th>
        <th>Type</th><th class="text-end">Amount</th><th>Notes</th>
      </tr></thead>
      <tbody>
      <?php foreach($myPayments as $p): ?>
      <tr>
        <td style="font-size:12.5px;white-space:nowrap"><?= $p['payment_date'] ?></td>
        <td><a href="payments/invoice_print.php?id=<?=$p['id']?>" target="_blank" class="mono text-primary" style="font-size:11.5px"><?= clean($p['invoice_no']??'—') ?></a></td>
        <td style="font-size:12.5px"><?= clean($p['unit_name']??'—') ?></td>
        <td style="font-size:12.5px"><?= clean($p['tenant_name']??'—') ?></td>
        <td><?= $p['payment_type']==='rent'?'<span class="badge badge-rent">Rent</span>':'<span class="badge badge-service">'.clean($p['service_name']??'Service').'</span>' ?></td>
        <td class="text-end fw-600" style="color:var(--success)"><?= money((float)$p['amount']) ?></td>
        <td class="cell-trunc-lg" style="font-size:12px;color:var(--text-muted)"><?= clean($p['notes']??'—') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f0fdf4;font-weight:700">
          <td colspan="5">Total Collected</td>
          <td class="text-end" style="color:var(--success)"><?= money(array_sum(array_column($myPayments,'amount'))) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Remittances Table -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-paper-plane me-2"></i>Remittances</span>
    <span class="badge bg-primary"><?= count($myRemits) ?></span>
  </div>
  <?php if(empty($myRemits)): ?>
  <div class="card-body text-center text-muted py-4" style="font-size:13px">No remittances recorded for this period.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Date</th><th class="text-end">Amount</th><th>Notes</th><th class="text-center">Proof</th></tr></thead>
      <tbody>
      <?php foreach($myRemits as $r): ?>
      <tr>
        <td style="font-size:12.5px"><?= clean($r['transaction_date']) ?></td>
        <td class="text-end fw-600" style="color:var(--info)"><?= money((float)$r['amount']) ?></td>
        <td class="cell-trunc-lg" style="font-size:12px;color:var(--text-muted)"><?= clean($r['notes']??'—') ?></td>
        <td class="text-center">
          <?php if($r['doc_path']): ?>
            <a href="<?= clean($r['doc_path']) ?>" target="_blank" class="btn-icon" title="View file"><i class="fa-solid fa-paperclip fa-xs"></i></a>
          <?php elseif($r['doc_url']): ?>
            <a href="<?= clean($r['doc_url']) ?>" target="_blank" class="btn-icon" title="Open URL"><i class="fa-solid fa-link fa-xs"></i></a>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f0f8ff;font-weight:700">
          <td>Total Remitted</td>
          <td class="text-end" style="color:var(--info)"><?= money(array_sum(array_column($myRemits,'amount'))) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Expense Breakdown -->
<div class="row g-3 mb-3">
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-tags me-2"></i>Expense Breakdown by Category</span></div>
      <?php if(empty($expBreakdown)): ?>
      <div class="card-body text-center text-muted py-4" style="font-size:13px">No expenses this period.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Category</th><th class="text-end">Total</th><th class="text-end">Count</th></tr></thead>
          <tbody>
          <?php foreach($expBreakdown as $eb): ?>
          <tr>
            <td><span class="badge" style="background:var(--primary-light);color:var(--primary)"><?= clean($eb['category']??'Uncategorized') ?></span></td>
            <td class="text-end fw-600" style="color:var(--danger)"><?= money((float)$eb['total']) ?></td>
            <td class="text-end text-muted"><?= $eb['count'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:700;background:#f9fafb">
              <td>Total</td>
              <td class="text-end" style="color:var(--danger)"><?= money((float)$tot['total_expenses']) ?></td>
              <td class="text-end"><?= count($myExpenses) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-receipt me-2"></i>Expense Detail</span></div>
      <?php if(empty($myExpenses)): ?>
      <div class="card-body text-center text-muted py-4" style="font-size:13px">No expenses this period.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table" style="font-size:12.5px">
          <thead><tr><th>Date</th><th>Description</th><th>Unit</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach($myExpenses as $e): ?>
          <tr>
            <td style="white-space:nowrap"><?= clean($e['expense_date']) ?></td>
            <td class="cell-trunc-lg"><?= clean($e['description']) ?>
              <?php if($e['notes']): ?><br><small class="text-muted cell-trunc"><?= clean(substr($e['notes'],0,60)) ?></small><?php endif; ?>
            </td>
            <td><?= clean($e['unit_name']??'General') ?></td>
            <td class="text-end fw-600" style="color:var(--danger)"><?= money((float)$e['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Raw Activity Logs -->
<div class="card">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-scroll me-2"></i>My Activity Log — <?= date('F Y',mktime(0,0,0,$selMonth,1,$selYear)) ?></span>
    <span class="badge bg-secondary"><?= count($myLogs) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table" id="myLogsTable">
      <thead><tr>
        <th>Timestamp</th><th>Action</th><th>Module</th><th>Details</th><th>IP Address</th>
      </tr></thead>
      <tbody>
      <?php if(empty($myLogs)): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No activity logs for this period.</td></tr>
      <?php endif; ?>
      <?php foreach($myLogs as $log): ?>
      <tr>
        <td data-order="<?= $log['created_at'] ?>" class="mono" style="font-size:11.5px;white-space:nowrap"><?= date('M j, Y H:i:s',strtotime($log['created_at'])) ?></td>
        <td><span class="badge log-action badge-staff" style="font-size:10.5px"><?= clean($log['action']) ?></span></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= clean($log['module']??'—') ?></td>
        <td class="log-details"><?= clean($log['details']??'—') ?></td>
        <td><span class="ip-badge"><?= clean($log['ip_address']??'—') ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
  if (document.getElementById('myLogsTable')) {
    $('#myLogsTable').DataTable({
      pageLength: 25,
      order: [[0,'desc']],
      dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rtip',
      language: { search:'Filter:', lengthMenu:'Show _MENU_' }
    });
  }
});
</script>
JS;
include 'includes/footer.php'; ?>
