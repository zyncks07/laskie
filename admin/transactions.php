<?php
error_reporting(0);
ini_set("display_errors", 0);
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Transaction Manager';
$depth = '../';

// ── Filters ───────────────────────────────────────────────────
$showTrash  = isset($_GET['trash']) && $_GET['trash'] === '1';
$fromDate   = $_GET['from']    ?? date('Y-m-01');
$toDate     = $_GET['to']      ?? date('Y-m-t');
$typeFilter = $_GET['type']    ?? '';
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$userFilter = (int)($_GET['user_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-t');
if ($toDate < $fromDate) $toDate = $fromDate;

$units = $pdo->query("SELECT id, unit_name FROM rental_units ORDER BY unit_name")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll();

// ── Load Transactions ─────────────────────────────────────────
$transactions = [];

if (!$typeFilter || $typeFilter === 'payment') {
    $deletedClause = $showTrash ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
    $dateField     = $showTrash ? 'DATE(p.deleted_at)' : 'p.payment_date';
    $w = ["$dateField BETWEEN ? AND ?", $deletedClause]; $par = [$fromDate, $toDate];
    if ($unitFilter) { $w[] = 'p.unit_id=?'; $par[] = $unitFilter; }
    if ($userFilter) { $w[] = 'p.received_by=?'; $par[] = $userFilter; }
    $q = $pdo->prepare("
        SELECT p.id, p.payment_date AS tx_date, 'payment' AS tx_type,
               COALESCE(p.invoice_no, CONCAT('#', p.id)) AS reference,
               p.notes AS tx_notes,
               ru.unit_name, p.amount, p.status, p.deleted_at,
               u.full_name AS recorded_by,
               p.period_month, p.period_year, p.unit_id
        FROM payments p
        LEFT JOIN rental_units ru ON p.unit_id = ru.id
        LEFT JOIN users u         ON p.received_by = u.id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $q->execute($par);
    $transactions = array_merge($transactions, $q->fetchAll());
}

if (!$showTrash && (!$typeFilter || $typeFilter === 'expense')) {
    $w = ['e.expense_date BETWEEN ? AND ?', 'e.deleted_at IS NULL']; $par = [$fromDate, $toDate];
    if ($unitFilter) { $w[] = 'e.unit_id=?'; $par[] = $unitFilter; }
    if ($userFilter) { $w[] = 'e.recorded_by=?'; $par[] = $userFilter; }
    $q = $pdo->prepare("
        SELECT e.id, e.expense_date AS tx_date, 'expense' AS tx_type,
               e.description AS reference,
               e.notes AS tx_notes,
               COALESCE(ru.unit_name, 'General') AS unit_name, e.amount, 'active' AS status, NULL AS deleted_at,
               u.full_name AS recorded_by,
               MONTH(e.expense_date) AS period_month, YEAR(e.expense_date) AS period_year, e.unit_id
        FROM expenses e
        LEFT JOIN rental_units ru ON e.unit_id = ru.id
        LEFT JOIN users u         ON e.recorded_by = u.id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY e.expense_date DESC, e.created_at DESC
    ");
    $q->execute($par);
    $transactions = array_merge($transactions, $q->fetchAll());
} elseif ($showTrash && (!$typeFilter || $typeFilter === 'expense')) {
    $w = ['DATE(e.deleted_at) BETWEEN ? AND ?', 'e.deleted_at IS NOT NULL']; $par = [$fromDate, $toDate];
    if ($unitFilter) { $w[] = 'e.unit_id=?'; $par[] = $unitFilter; }
    if ($userFilter) { $w[] = 'e.recorded_by=?'; $par[] = $userFilter; }
    $q = $pdo->prepare("
        SELECT e.id, e.expense_date AS tx_date, 'expense' AS tx_type,
               e.description AS reference,
               e.notes AS tx_notes,
               COALESCE(ru.unit_name, 'General') AS unit_name, e.amount, 'deleted' AS status, e.deleted_at,
               u.full_name AS recorded_by,
               MONTH(e.expense_date) AS period_month, YEAR(e.expense_date) AS period_year, e.unit_id
        FROM expenses e
        LEFT JOIN rental_units ru ON e.unit_id = ru.id
        LEFT JOIN users u         ON e.recorded_by = u.id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY e.deleted_at DESC, e.created_at DESC
    ");
    $q->execute($par);
    $transactions = array_merge($transactions, $q->fetchAll());
}

if (!$showTrash && ((!$typeFilter || $typeFilter === 'remittance') && !$unitFilter)) {
    $w = [
        "ct.transaction_type = 'remitted'",
        "ct.reference_payment_id IS NULL",
        "ct.reference_expense_id IS NULL",
        "ct.transaction_date BETWEEN ? AND ?"
    ];
    $par = [$fromDate, $toDate];
    if ($userFilter) { $w[] = 'ct.user_id=?'; $par[] = $userFilter; }
    $q = $pdo->prepare("
        SELECT ct.id, ct.transaction_date AS tx_date, 'remittance' AS tx_type,
               COALESCE(ct.notes, 'Manual Remittance') AS reference,
               ct.notes AS tx_notes,
               '—' AS unit_name, ct.amount, 'active' AS status, NULL AS deleted_at,
               u.full_name AS recorded_by,
               MONTH(ct.transaction_date) AS period_month, YEAR(ct.transaction_date) AS period_year, NULL AS unit_id
        FROM cash_transactions ct
        LEFT JOIN users u ON ct.user_id = u.id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY ct.transaction_date DESC, ct.created_at DESC
    ");
    $q->execute($par);
    $transactions = array_merge($transactions, $q->fetchAll());
}

// Vault-return rows (cash issued from the vault back to a user — admin-only flow).
if (!$showTrash && ((!$typeFilter || $typeFilter === 'vault_return') && !$unitFilter)) {
    $w = [
        "ct.transaction_type = 'vault_return'",
        "ct.transaction_date BETWEEN ? AND ?"
    ];
    $par = [$fromDate, $toDate];
    if ($userFilter) { $w[] = 'ct.user_id=?'; $par[] = $userFilter; }
    $q = $pdo->prepare("
        SELECT ct.id, ct.transaction_date AS tx_date, 'vault_return' AS tx_type,
               COALESCE(ct.notes, 'Vault → User') AS reference,
               ct.notes AS tx_notes,
               '—' AS unit_name, ct.amount, 'active' AS status, NULL AS deleted_at,
               u.full_name AS recorded_by,
               MONTH(ct.transaction_date) AS period_month, YEAR(ct.transaction_date) AS period_year, NULL AS unit_id
        FROM cash_transactions ct
        LEFT JOIN users u ON ct.user_id = u.id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY ct.transaction_date DESC, ct.created_at DESC
    ");
    $q->execute($par);
    $transactions = array_merge($transactions, $q->fetchAll());
}

usort($transactions, fn($a, $b) => strcmp($b['tx_date'], $a['tx_date']));
$grandTotal = array_sum(array_column($transactions, 'amount'));

logActivity($pdo, 'VIEW_TRANSACTIONS', 'Transactions', "Viewed transaction manager ({$fromDate} to {$toDate})");
include '../includes/header.php';

// ── Helpers for badge rendering ───────────────────────────────
function typeBadge(string $type): string {
    return match($type) {
        'payment'    => '<span class="badge" style="background:var(--primary-light);color:var(--primary)">Payment</span>',
        'expense'    => '<span class="badge bg-danger">Expense</span>',
        'remittance' => '<span class="badge" style="background:#e0f2fe;color:#0369a1">Remittance</span>',
        'vault_return' => '<span class="badge badge-vault-return">Vault&rarr;User</span>',
        default      => '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>',
    };
}

function statusBadge(string $type, string $status, ?string $deletedAt = null): string {
    if ($deletedAt) return '<span class="badge bg-danger" style="font-size:10px">Deleted</span>';
    if ($type !== 'payment') return '<span class="badge bg-success" style="font-size:10px">Active</span>';
    return match($status) {
        'paid'               => '<span class="badge bg-success" style="font-size:10px">Paid</span>',
        'voided'             => '<span class="badge bg-secondary" style="font-size:10px">Voided</span>',
        'refunded'           => '<span class="badge bg-danger" style="font-size:10px">Refunded</span>',
        'partially_refunded' => '<span class="badge bg-warning text-dark" style="font-size:10px">Partial Refund</span>',
        default              => '<span class="badge bg-secondary" style="font-size:10px">' . htmlspecialchars($status) . '</span>',
    };
}
?>

<div class="page-header">
  <h1 class="page-title">
    <i class="fa-solid fa-<?= $showTrash ? 'trash-can' : 'rectangle-list' ?> me-2 text-primary-custom"></i>
    Transaction Manager<?= $showTrash ? ' — Trash' : '' ?>
  </h1>
  <?php
    $baseParams = array_diff_key($_GET, ['trash' => '']);
    $trashUrl   = 'transactions.php?' . http_build_query(array_merge($baseParams, ['trash' => '1']));
    $activeUrl  = 'transactions.php?' . http_build_query($baseParams);
  ?>
  <div class="d-flex gap-2">
    <?php if ($showTrash): ?>
    <a href="<?= $activeUrl ?>" class="btn btn-sm btn-outline-secondary">
      <i class="fa-solid fa-arrow-left me-1"></i>Back to Active
    </a>
    <?php else: ?>
    <a href="<?= $trashUrl ?>" class="btn btn-sm btn-outline-danger">
      <i class="fa-solid fa-trash-can me-1"></i>View Trash
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filter Form -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-auto">
        <label class="form-label mb-1" style="font-size:12px">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= clean($fromDate) ?>">
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label mb-1" style="font-size:12px">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= clean($toDate) ?>">
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label mb-1" style="font-size:12px">Type</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="payment"    <?= $typeFilter==='payment'    ?'selected':'' ?>>Payments</option>
          <option value="expense"    <?= $typeFilter==='expense'    ?'selected':'' ?>>Expenses</option>
          <option value="remittance"   <?= $typeFilter==='remittance'   ?'selected':'' ?>>Remittances</option>
          <option value="vault_return" <?= $typeFilter==='vault_return' ?'selected':'' ?>>Vault Returns to Users</option>
        </select>
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label mb-1" style="font-size:12px">Unit</label>
        <select name="unit_id" class="form-select form-select-sm">
          <option value="">All Units</option>
          <?php foreach ($units as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $unitFilter===$u['id']?'selected':'' ?>><?= clean($u['unit_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label mb-1" style="font-size:12px">Recorded By</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Users</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userFilter===$u['id']?'selected':'' ?>><?= clean($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Apply</button>
        <a href="transactions.php" class="btn btn-secondary btn-sm">Reset</a>
      </div>
      <?php if ($unitFilter && ($typeFilter === 'remittance' || $typeFilter === '')): ?>
      <div class="col-12"><div class="alert alert-info py-1 mb-0" style="font-size:12px"><i class="fa-solid fa-circle-info me-1"></i>Remittances are not linked to units and are hidden when a unit filter is active.</div></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Summary Strip -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <div class="card py-2 px-3" style="min-width:120px">
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Records</div>
    <div class="fw-700" style="font-size:18px"><?= count($transactions) ?></div>
  </div>
  <div class="card py-2 px-3" style="min-width:150px">
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Total Amount</div>
    <div class="fw-700" style="font-size:18px;color:var(--primary)"><?= money($grandTotal) ?></div>
  </div>
</div>

<!-- Transaction Table -->
<div class="card">
  <div class="table-responsive">
    <table class="table" id="txTable">
      <thead><tr>
        <th>Date</th>
        <th>Type</th>
        <th>Reference</th>
        <th>Unit</th>
        <th class="text-end">Amount</th>
        <th>Recorded By</th>
        <th>Status</th>
        <th class="text-center no-print">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($transactions as $tx): ?>
      <?php
        $isDeleted  = !empty($tx['deleted_at']);
        $isVoided   = $tx['tx_type'] === 'payment' && $tx['status'] === 'voided' && !$isDeleted;
        $rowStyle   = ($isVoided || $isDeleted) ? ' style="opacity:0.55"' : '';
        $amtColor   = match($tx['tx_type']) {
            'expense'      => 'var(--danger)',
            'remittance'   => 'var(--info)',
            'vault_return' => 'var(--info)',
            default        => 'var(--success)',
        };
        $refEsc     = addslashes(clean($tx['reference'] ?? ''));

        // Navigate link to source page
        $navUrl = match($tx['tx_type']) {
            'payment'      => '../payments/collection.php?month=' . (int)$tx['period_month'] . '&year=' . (int)$tx['period_year'],
            'expense'      => '../expenses.php',
            'remittance'   => '../cash.php',
            'vault_return' => '../admin/vault.php',
            default        => '#',
        };
      ?>
      <tr<?= $rowStyle ?> data-type="<?= $tx['tx_type'] ?>">
        <td style="white-space:nowrap;font-size:12.5px"><?= clean($tx['tx_date']) ?></td>
        <td><?= typeBadge($tx['tx_type']) ?></td>
        <td class="cell-trunc-lg">
          <div class="fw-600" style="font-size:12.5px"><?= clean($tx['reference'] ?? '—') ?></div>
          <?php if (!empty($tx['tx_notes']) && $tx['tx_notes'] !== $tx['reference']): ?>
          <div class="cell-trunc" style="font-size:11px;color:var(--text-muted)"><?= clean($tx['tx_notes']) ?></div>
          <?php endif; ?>
          <?php if ($isDeleted): ?>
          <div style="font-size:11px;color:var(--danger)"><i class="fa-solid fa-trash fa-xs me-1"></i>Deleted <?= date('M j, Y', strtotime($tx['deleted_at'])) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:12.5px"><?= clean($tx['unit_name'] ?? '—') ?></td>
        <td class="text-end fw-600" style="color:<?= $amtColor ?>"><?= money((float)$tx['amount']) ?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= clean($tx['recorded_by'] ?? '—') ?></td>
        <td><?= statusBadge($tx['tx_type'], $tx['status'], $tx['deleted_at'] ?? null) ?></td>
        <td class="text-center">
          <?php if ($showTrash): ?>
            <!-- Trash mode: Restore + Purge -->
            <button class="btn-icon" title="Restore"
              onclick="restoreDeleted('<?= $tx['tx_type'] ?>', <?= (int)$tx['id'] ?>)"
              style="margin-left:2px"><i class="fa-solid fa-rotate-right fa-xs" style="color:var(--success)"></i></button>
            <button class="btn-icon danger" title="Delete Permanently"
              onclick="purgeTx('<?= $tx['tx_type'] ?>', <?= (int)$tx['id'] ?>)"
              style="margin-left:2px"><i class="fa-solid fa-fire fa-xs"></i></button>
          <?php else: ?>
            <!-- Active mode: Navigate + Void/Restore + Delete -->
            <a href="<?= $navUrl ?>" target="_blank" class="btn-icon" title="Go to source page">
              <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
            <?php if ($tx['tx_type'] === 'payment'): ?>
              <?php if ($isVoided): ?>
              <button class="btn-icon" title="Restore Voided Payment"
                onclick="restoreTx(<?= (int)$tx['id'] ?>)"
                style="margin-left:2px"><i class="fa-solid fa-rotate-right fa-xs" style="color:var(--success)"></i></button>
              <?php else: ?>
              <button class="btn-icon" title="Void Payment"
                onclick="voidTx(<?= (int)$tx['id'] ?>, '<?= $refEsc ?>')"
                style="margin-left:2px"><i class="fa-solid fa-ban fa-xs" style="color:var(--warning)"></i></button>
              <?php endif; ?>
            <?php endif; ?>
            <button class="btn-icon danger" title="Delete (move to trash)"
              onclick="deleteTx('<?= $tx['tx_type'] ?>', <?= (int)$tx['id'] ?>)"
              style="margin-left:2px"><i class="fa-solid fa-trash fa-xs"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($transactions)): ?>
      <tr><td colspan="8" class="text-center py-5 text-muted">
        <i class="fa-solid fa-inbox fa-2x d-block mb-2"></i>No transactions found for this period.
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function() {
  $('#txTable').DataTable({
    pageLength: 50,
    order: [[0, 'desc']],
    columnDefs: [{orderable: false, targets: 7}],
    dom: '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rtip',
    language: {search: 'Search:', lengthMenu: 'Show _MENU_', info: '_START_–_END_ of _TOTAL_'}
  });
});

function deleteTx(txType, id) {
  var apiUrl, action, msg;
  if (txType === 'payment') {
    apiUrl = '../payments/api_payment.php';
    action = 'delete_payment';
    msg    = 'Delete this payment? The linked cash record will also be removed.';
  } else if (txType === 'expense') {
    apiUrl = '../api/expenses_api.php';
    action = 'delete_expense';
    msg    = 'Delete this expense? The linked cash record will also be removed.';
  } else if (txType === 'vault_return') {
    apiUrl = '../admin/vault.php';
    action = 'delete_user_return';
    msg    = 'Delete this vault-to-user return? The user\'s cash on hand will decrease by the amount.';
  } else {
    apiUrl = '../api/cash_api.php';
    action = 'delete_cash_tx';
    msg    = 'Delete this remittance?';
  }
  confirmDelete(msg, function() {
    apiPost(apiUrl, {action: action, id: id}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Delete failed.', 'error'); return; }
      showToast(res.msg, 'success');
      setTimeout(function() { location.reload(); }, 700);
    });
  });
}

function voidTx(id, invoiceNo) {
  confirmDelete('Void payment ' + (invoiceNo || '#' + id) + '? It will be excluded from totals but kept on record.', function() {
    apiPost('../payments/api_payment.php', {action: 'void_payment', id: id}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
      showToast(res.msg, 'success');
      setTimeout(function() { location.reload(); }, 700);
    });
  });
}

function restoreTx(id) {
  apiPost('../payments/api_payment.php', {action: 'restore_payment', id: id}, function(err, res) {
    if (!res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
    showToast(res.msg, 'success');
    setTimeout(function() { location.reload(); }, 700);
  });
}

function restoreDeleted(txType, id) {
  var apiUrl, action;
  if (txType === 'payment') {
    apiUrl = '../payments/api_payment.php'; action = 'restore_deleted_payment';
  } else {
    apiUrl = '../api/expenses_api.php'; action = 'restore_deleted_expense';
  }
  apiPost(apiUrl, {action: action, id: id}, function(err, res) {
    if (!res || !res.success) { showToast((res && res.error) || 'Restore failed.', 'error'); return; }
    showToast(res.msg, 'success');
    setTimeout(function() { location.reload(); }, 700);
  });
}

function purgeTx(txType, id) {
  var apiUrl, action, label = txType === 'payment' ? 'payment' : 'expense';
  if (txType === 'payment') {
    apiUrl = '../payments/api_payment.php'; action = 'purge_payment';
  } else {
    apiUrl = '../api/expenses_api.php'; action = 'purge_expense';
  }
  confirmDelete('Permanently delete this ' + label + '? This cannot be undone. The linked cash record will also be removed.', function() {
    apiPost(apiUrl, {action: action, id: id}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Purge failed.', 'error'); return; }
      showToast(res.msg, 'success');
      setTimeout(function() { location.reload(); }, 700);
    });
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
