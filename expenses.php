<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
requireLogin();
$pageTitle = 'Expenses';
$depth = '';

$units      = $pdo->query("SELECT id, unit_name FROM rental_units ORDER BY unit_name")->fetchAll();
$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();
$users      = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll();
$curMonth   = (int)date('n');
$curYear    = (int)date('Y');
$years      = $pdo->query("SELECT DISTINCT YEAR(expense_date) y FROM expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($curYear, $years)) array_unshift($years, $curYear);
rsort($years);

logActivity($pdo, 'VIEW_EXPENSES', 'Expenses', 'Viewed expenses page');
include 'includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-receipt me-2 text-primary-custom"></i>Expenses</h1>
  <div class="d-flex gap-2">
    <?php if(isAdmin()): ?>
    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#catModal">
      <i class="fa-solid fa-tags me-1"></i>Categories
    </button>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" onclick="openExpenseModal()">
      <i class="fa-solid fa-plus me-1"></i>Record Expense
    </button>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label">Month</label>
        <select id="fMonth" class="form-select form-select-sm">
          <option value="0">All Months</option>
          <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?=$m?>" <?=$m===$curMonth?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Year</label>
        <select id="fYear" class="form-select form-select-sm">
          <?php foreach($years as $y): ?>
          <option value="<?=$y?>" <?=$y===$curYear?'selected':''?>><?=$y?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Unit</label>
        <select id="fUnit" class="form-select form-select-sm">
          <option value="0">All Units</option>
          <?php foreach($units as $u): ?>
          <option value="<?=$u['id']?>"><?=clean($u['unit_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Category</label>
        <select id="fCategory" class="form-select form-select-sm">
          <option value="0">All Categories</option>
          <?php foreach($categories as $c): ?>
          <option value="<?=$c['id']?>"><?=clean($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Recorded By</label>
        <select id="fRecorder" class="form-select form-select-sm">
          <option value="0">All Staff</option>
          <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>"><?=clean($u['full_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">From Date</label>
        <input type="date" id="fDateFrom" class="form-control form-control-sm" title="Overrides Month/Year when set">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">To Date</label>
        <input type="date" id="fDateTo" class="form-control form-control-sm" title="Overrides Month/Year when set">
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="loadExpenses()"><i class="fa-solid fa-search me-1"></i>Filter</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()"><i class="fa-solid fa-rotate me-1"></i>Reset</button>
      </div>
    </div>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-receipt"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Expenses</div>
        <div class="stat-value" style="font-size:17px" id="statTotal">&#8369;0.00</div>
        <div class="stat-sub" id="statCount">0 records</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="fa-solid fa-tags"></i></div>
      <div class="stat-body">
        <div class="stat-label">Top Category</div>
        <div class="stat-value" style="font-size:14px;line-height:1.2" id="statTopCat">&#8212;</div>
        <div class="stat-sub" id="statTopCatAmt"></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon purple"><i class="fa-solid fa-building"></i></div>
      <div class="stat-body">
        <div class="stat-label">Top Unit</div>
        <div class="stat-value" style="font-size:14px;line-height:1.2" id="statTopUnit">&#8212;</div>
        <div class="stat-sub" id="statTopUnitAmt"></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon teal"><i class="fa-solid fa-user-tie"></i></div>
      <div class="stat-body">
        <div class="stat-label">Top Recorder</div>
        <div class="stat-value" style="font-size:14px;line-height:1.2" id="statTopRecorder">&#8212;</div>
        <div class="stat-sub" id="statTopRecorderAmt"></div>
      </div>
    </div>
  </div>
</div>

<!-- Expenses Table -->
<div class="card">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-list me-2"></i>Expense Records</span>
    <span class="badge bg-secondary" id="expBadge">0</span>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <?php if(isAdmin()): ?><th class="no-print" style="width:32px"><input type="checkbox" id="expSelectAll" title="Select all"></th><?php endif; ?>
          <th>Date</th><th>Description</th><th>Category</th><th>Unit</th>
          <th class="text-end">Amount</th><th>Recorded By</th><th>Notes</th>
          <th class="text-center">Receipt</th><th class="text-center no-print">Actions</th>
        </tr>
      </thead>
      <tbody id="expBody">
        <tr><td colspan="<?=isAdmin()?10:9?>" class="text-center py-4">
          <span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...
        </td></tr>
      </tbody>
      <tfoot id="expFoot"></tfoot>
    </table>
  </div>
</div>

<?php if(isAdmin()): ?>
<!-- Bulk action bar (shown when expenses are selected) -->
<div id="expBulkBar" class="d-none" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:1050;background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px 20px;box-shadow:0 4px 20px rgba(0,0,0,.18)">
  <div class="d-flex align-items-center gap-3">
    <span class="fw-600" id="expBulkCount"></span>
    <button class="btn btn-danger btn-sm" onclick="bulkDeleteExpenses()"><i class="fa-solid fa-trash me-1"></i>Delete Selected</button>
    <button class="btn btn-secondary btn-sm" onclick="clearExpSelection()">Cancel</button>
  </div>
</div>
<?php endif; ?>

<!-- Record Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="expModalTitle"><i class="fa-solid fa-receipt me-2"></i>Record Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="expId">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Description *</label>
            <input type="text" class="form-control" id="expDesc" placeholder="e.g. Repair of water pipe — Room 3">
          </div>
          <div class="col-md-4">
            <label class="form-label">Amount (&#8369;) *</label>
            <input type="number" step="0.01" min="0" class="form-control" id="expAmount" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <select class="form-select" id="expCategory">
              <option value="">&#8212; Uncategorized &#8212;</option>
              <?php foreach($categories as $c): ?>
              <option value="<?=$c['id']?>"><?=clean($c['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Rental Unit</label>
            <select class="form-select" id="expUnit">
              <option value="">&#8212; General / not unit-specific &#8212;</option>
              <?php foreach($units as $u): ?>
              <option value="<?=$u['id']?>"><?=clean($u['unit_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Expense Date *</label>
            <input type="date" class="form-control" id="expDate" value="<?=date('Y-m-d')?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" id="expNotes" rows="2" placeholder="Vendor, location, reference numbers..."></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">Upload Receipt <small class="text-muted">(JPG, PNG, PDF — max 10MB)</small></label>
            <input type="file" class="form-control form-control-sm" id="expReceiptFile" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
          </div>
          <div class="col-md-6">
            <label class="form-label">Or External URL</label>
            <input type="url" class="form-control form-control-sm" id="expReceiptUrl" placeholder="https://drive.google.com/...">
          </div>
          <div class="col-12" id="existingReceiptRow" style="display:none">
            <div class="alert alert-info py-2 mb-0" style="font-size:12.5px">
              <i class="fa-solid fa-paperclip me-1"></i>
              Current receipt: <a id="existingReceiptLink" href="#" target="_blank" class="fw-600">View</a>
              <span class="text-muted ms-2">(Upload new file to replace)</span>
            </div>
          </div>
        </div>
        <div id="expMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveExpense()">
          <i class="fa-solid fa-save me-1"></i>Save Expense
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-tags me-2"></i>Expense Categories</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3">
          <div class="card-header"><span class="card-header-title" id="catFormTitle">Add Category</span></div>
          <div class="card-body">
            <div class="row g-2 align-items-end">
              <input type="hidden" id="catId">
              <div class="col-md-4">
                <label class="form-label">Name *</label>
                <input type="text" class="form-control form-control-sm" id="catName" placeholder="e.g. Utilities">
              </div>
              <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" class="form-control form-control-sm" id="catDesc" placeholder="Short description">
              </div>
              <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100" onclick="saveCategory()">
                  <i class="fa-solid fa-save me-1"></i>Save
                </button>
              </div>
            </div>
            <div id="catMsg" class="mt-2" style="display:none"></div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Name</th><th>Description</th><th class="text-center">Actions</th></tr></thead>
            <tbody>
              <?php foreach($categories as $c): ?>
              <tr id="cat-row-<?=$c['id']?>">
                <td class="fw-600"><?=clean($c['name'])?></td>
                <td style="font-size:12.5px;color:var(--text-muted)"><?=clean($c['description']??'&#8212;')?></td>
                <td class="text-center">
                  <button class="btn-icon" onclick="editCategory(<?=$c['id']?>,'<?=clean(addslashes($c['name']))?>','<?=clean(addslashes($c['description']??''))?>')"><i class="fa-solid fa-pen fa-xs"></i></button>
                  <button class="btn-icon danger" onclick="deleteCategory(<?=$c['id']?>,'<?=clean(addslashes($c['name']))?>')"><i class="fa-solid fa-trash fa-xs"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var IS_ADMIN  = <?=isAdmin()?'true':'false'?>;
var expModal = null;
document.addEventListener('DOMContentLoaded', function() {
  expModal = new bootstrap.Modal(document.getElementById('expenseModal'));
  loadExpenses();
});

function loadExpenses() {
  var month    = document.getElementById('fMonth').value;
  var year     = document.getElementById('fYear').value;
  var unit     = document.getElementById('fUnit').value;
  var category = document.getElementById('fCategory').value;
  var recorder = document.getElementById('fRecorder').value;
  var dateFrom = document.getElementById('fDateFrom').value;
  var dateTo   = document.getElementById('fDateTo').value;

  document.getElementById('expBody').innerHTML = '<tr><td colspan="9" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>';
  document.getElementById('expFoot').innerHTML = '';

  apiPost('api/expenses_api.php', {action:'list_expenses', month:month, year:year, unit_id:unit, category_id:category, recorded_by:recorder, date_from:dateFrom, date_to:dateTo}, function(err, res) {
    if (!res || !res.success) {
      document.getElementById('expBody').innerHTML = '<tr><td colspan="9" class="text-center text-danger py-3">Failed to load expenses.</td></tr>';
      return;
    }
    var exps = res.expenses;
    document.getElementById('expBadge').textContent = exps.length;
    document.getElementById('statTotal').textContent = fmt(res.total);
    document.getElementById('statCount').textContent = exps.length + ' record' + (exps.length !== 1 ? 's' : '');

    // Top stats
    var bycat = {}, byunit = {}, byrec = {};
    for (var i = 0; i < exps.length; i++) {
      var e = exps[i];
      var cat = e.category_name || 'Uncategorized';
      var unt = e.unit_name     || 'General';
      var rec = e.recorder_name || 'Unknown';
      bycat[cat] = (bycat[cat] || 0) + parseFloat(e.amount);
      byunit[unt] = (byunit[unt] || 0) + parseFloat(e.amount);
      byrec[rec]  = (byrec[rec]  || 0) + parseFloat(e.amount);
    }
    function topOf(obj) {
      var best = null, bestVal = 0;
      for (var k in obj) { if (obj[k] > bestVal) { bestVal = obj[k]; best = k; } }
      return best ? [best, bestVal] : null;
    }
    var tC = topOf(bycat), tU = topOf(byunit), tR = topOf(byrec);
    if (tC) { document.getElementById('statTopCat').textContent      = tC[0]; document.getElementById('statTopCatAmt').textContent      = fmt(tC[1]); }
    if (tU) { document.getElementById('statTopUnit').textContent     = tU[0]; document.getElementById('statTopUnitAmt').textContent     = fmt(tU[1]); }
    if (tR) { document.getElementById('statTopRecorder').textContent = tR[0]; document.getElementById('statTopRecorderAmt').textContent = fmt(tR[1]); }

    var html = '';
    for (var j = 0; j < exps.length; j++) {
      var ex = exps[j];
      var receipt = '<span class="text-muted">&#8212;</span>';
      if (ex.receipt_path) receipt = '<a href="' + ex.receipt_path + '" target="_blank" class="btn-icon" title="View file"><i class="fa-solid fa-paperclip fa-xs"></i></a>';
      else if (ex.receipt_url) receipt = '<a href="' + ex.receipt_url + '" target="_blank" class="btn-icon" title="Open URL"><i class="fa-solid fa-link fa-xs"></i></a>';

      var catBadge = ex.category_name
        ? '<span class="badge" style="background:var(--primary-light);color:var(--primary);font-size:11px">' + ex.category_name + '</span>'
        : '<span class="text-muted" style="font-size:11.5px">&#8212;</span>';
      var unitLabel = ex.unit_name
        ? '<span style="font-size:12.5px">' + ex.unit_name + '</span>'
        : '<span class="text-muted" style="font-size:11.5px">General</span>';
      var notesHtml = ex.notes
        ? '<span style="font-size:11.5px;color:var(--text-muted);display:inline-block;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + ex.notes + '</span>'
        : '<span class="text-muted">&#8212;</span>';

      html += '<tr>';
      if (IS_ADMIN) html += '<td class="no-print"><input type="checkbox" class="exp-chk" value="' + parseInt(ex.id) + '" onclick="updateExpBulkBar()"></td>';
      html += '<td style="white-space:nowrap;font-size:12.5px">' + ex.expense_date + '</td>';
      html += '<td class="fw-600 cell-trunc-lg" style="font-size:12.5px">' + ex.description + '</td>';
      html += '<td>' + catBadge + '</td>';
      html += '<td>' + unitLabel + '</td>';
      html += '<td class="text-end fw-600" style="color:var(--danger)">' + fmt(ex.amount) + '</td>';
      html += '<td style="font-size:12px;color:var(--text-muted)">' + (ex.recorder_name || '&#8212;') + '</td>';
      html += '<td>' + notesHtml + '</td>';
      html += '<td class="text-center">' + receipt + '</td>';
      html += '<td class="text-center no-print">';
      if (IS_ADMIN) {
        html += '<button class="btn-icon" title="Edit" onclick="editExpense(' + parseInt(ex.id) + ')"><i class="fa-solid fa-pen fa-xs"></i></button> ';
        html += '<button class="btn-icon danger" title="Delete" onclick="deleteExpense(' + parseInt(ex.id) + ',\'' + ex.description.replace(/\'/g, "\\'") + '\')"><i class="fa-solid fa-trash fa-xs"></i></button>';
      } else {
        html += '<span class="text-muted">&#8212;</span>';
      }
      html += '</td></tr>';
    }

    // Reset select-all and bulk bar when table reloads
    var saEl = document.getElementById('expSelectAll');
    if (saEl) saEl.checked = false;
    updateExpBulkBar();

    if (!html) html = '<tr><td colspan="' + (IS_ADMIN ? 10 : 9) + '" class="text-center py-5 text-muted"><i class="fa-solid fa-inbox fa-2x d-block mb-2 mt-2"></i>No expense records found.</td></tr>';
    document.getElementById('expBody').innerHTML = html;

    if (exps.length > 0) {
      document.getElementById('expFoot').innerHTML =
        '<tr style="background:#f9fafb;font-weight:700;border-top:2px solid var(--border)">' +
        '<td colspan="' + (IS_ADMIN ? 5 : 4) + '">TOTAL (' + exps.length + ' records)</td>' +
        '<td class="text-end" style="color:var(--danger)">' + fmt(res.total) + '</td>' +
        '<td colspan="4"></td></tr>';
    }
  });
}

function openExpenseModal() {
  document.getElementById('expModalTitle').innerHTML = '<i class="fa-solid fa-receipt me-2"></i>Record Expense';
  document.getElementById('expId').value        = '';
  document.getElementById('expDesc').value      = '';
  document.getElementById('expAmount').value    = '';
  document.getElementById('expCategory').value  = '';
  document.getElementById('expUnit').value      = '';
  document.getElementById('expDate').value      = new Date().toISOString().split('T')[0];
  document.getElementById('expNotes').value     = '';
  document.getElementById('expReceiptFile').value = '';
  document.getElementById('expReceiptUrl').value  = '';
  document.getElementById('existingReceiptRow').style.display = 'none';
  document.getElementById('expMsg').style.display = 'none';
  expModal.show();
}

function editExpense(id) {
  apiPost('api/expenses_api.php', {action:'get_expense', id:id}, function(err, res) {
    if (!res || !res.success) return showToast('Failed to load expense.', 'error');
    var e = res.expense;
    document.getElementById('expModalTitle').innerHTML = '<i class="fa-solid fa-pen me-2"></i>Edit Expense';
    document.getElementById('expId').value       = e.id;
    document.getElementById('expDesc').value     = e.description || '';
    document.getElementById('expAmount').value   = e.amount || '';
    document.getElementById('expCategory').value = e.category_id || '';
    document.getElementById('expUnit').value     = e.unit_id || '';
    document.getElementById('expDate').value     = e.expense_date || '';
    document.getElementById('expNotes').value    = e.notes || '';
    document.getElementById('expReceiptUrl').value  = e.receipt_url || '';
    document.getElementById('expReceiptFile').value = '';
    var er = document.getElementById('existingReceiptRow');
    var el = document.getElementById('existingReceiptLink');
    if (e.receipt_path || e.receipt_url) {
      er.style.display = '';
      el.href = e.receipt_path || e.receipt_url;
      el.textContent = e.receipt_path ? 'Uploaded file' : 'External URL';
    } else {
      er.style.display = 'none';
    }
    document.getElementById('expMsg').style.display = 'none';
    expModal.show();
  });
}

function saveExpense() {
  var fd = new FormData();
  fd.append('action',       'save_expense');
  fd.append('id',           document.getElementById('expId').value);
  fd.append('description',  document.getElementById('expDesc').value);
  fd.append('amount',       document.getElementById('expAmount').value);
  fd.append('category_id',  document.getElementById('expCategory').value);
  fd.append('unit_id',      document.getElementById('expUnit').value);
  fd.append('expense_date', document.getElementById('expDate').value);
  fd.append('notes',        document.getElementById('expNotes').value);
  fd.append('receipt_url',  document.getElementById('expReceiptUrl').value);
  var file = document.getElementById('expReceiptFile').files[0];
  if (file) fd.append('receipt_file', file);

  fetch('api/expenses_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (!res.success) {
        var el = document.getElementById('expMsg');
        el.style.display = ''; el.className = 'alert alert-danger'; el.textContent = res.error;
        return;
      }
      showToast(res.msg, 'success');
      expModal.hide();
      loadExpenses();
    })
    .catch(function() { showToast('Network error.', 'error'); });
}

function deleteExpense(id, desc) {
  confirmDelete('Delete expense "' + desc + '"? Its cash record will also be removed.', function() {
    apiPost('api/expenses_api.php', {action:'delete_expense', id:id}, function(err, res) {
      if (!res || !res.success) return showToast((res && res.error) || 'Failed.', 'error');
      showToast(res.msg, 'success');
      loadExpenses();
    });
  });
}

// ── Bulk select / delete (admin only) ────────────────────────
function updateExpBulkBar() {
  if (!IS_ADMIN) return;
  var checked = document.querySelectorAll('.exp-chk:checked');
  var bar = document.getElementById('expBulkBar');
  if (!bar) return;
  if (checked.length > 0) {
    document.getElementById('expBulkCount').textContent = checked.length + ' selected';
    bar.classList.remove('d-none');
  } else {
    bar.classList.add('d-none');
  }
}

function clearExpSelection() {
  document.querySelectorAll('.exp-chk').forEach(function(cb) { cb.checked = false; });
  var sa = document.getElementById('expSelectAll');
  if (sa) sa.checked = false;
  updateExpBulkBar();
}

function bulkDeleteExpenses() {
  var ids = Array.from(document.querySelectorAll('.exp-chk:checked')).map(function(cb) { return parseInt(cb.value); });
  if (!ids.length) return;
  confirmDelete('Delete ' + ids.length + ' expense(s)? Their cash records will also be removed. This cannot be undone.', function() {
    apiPost('api/expenses_api.php', {action: 'bulk_delete_expenses', ids: JSON.stringify(ids)}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Bulk delete failed.', 'error'); return; }
      showToast(res.msg, 'success');
      loadExpenses();
    });
  });
}

document.addEventListener('DOMContentLoaded', function() {
  var sa = document.getElementById('expSelectAll');
  if (sa) {
    sa.addEventListener('change', function() {
      document.querySelectorAll('.exp-chk').forEach(function(cb) { cb.checked = sa.checked; });
      updateExpBulkBar();
    });
  }
});

function resetFilters() {
  document.getElementById('fMonth').value    = new Date().getMonth() + 1;
  document.getElementById('fYear').value     = new Date().getFullYear();
  document.getElementById('fUnit').value     = 0;
  document.getElementById('fCategory').value = 0;
  document.getElementById('fRecorder').value = 0;
  document.getElementById('fDateFrom').value = '';
  document.getElementById('fDateTo').value   = '';
  loadExpenses();
}

function editCategory(id, name, desc) {
  document.getElementById('catId').value   = id;
  document.getElementById('catName').value = name;
  document.getElementById('catDesc').value = desc;
  document.getElementById('catFormTitle').textContent = 'Edit Category';
  document.getElementById('catMsg').style.display = 'none';
}

function saveCategory() {
  apiPost('api/expenses_api.php', {
    action:      'save_category',
    id:          document.getElementById('catId').value,
    name:        document.getElementById('catName').value,
    description: document.getElementById('catDesc').value
  }, function(err, res) {
    var el = document.getElementById('catMsg');
    el.style.display = '';
    if (!res || !res.success) {
      el.className = 'alert alert-danger'; el.textContent = (res && res.error) || 'Failed.';
      return;
    }
    el.className = 'alert alert-success'; el.textContent = res.msg;
    setTimeout(function() { location.reload(); }, 900);
  });
}

function deleteCategory(id, name) {
  confirmDelete('Delete category "' + name + '"? Only works if no expenses are tagged to it.', function() {
    apiPost('api/expenses_api.php', {action:'delete_category', id:id}, function(err, res) {
      if (!res || !res.success) return showToast((res && res.error) || 'Failed.', 'error');
      showToast(res.msg, 'success');
      var row = document.getElementById('cat-row-' + id);
      if (row) row.remove();
    });
  });
}
</script>
<?php include 'includes/footer.php'; ?>
