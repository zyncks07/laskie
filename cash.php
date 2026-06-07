<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
requireLogin();
$pageTitle = 'Cash on Hand';
$depth = '';

$allUsers = $pdo->query("SELECT id, full_name, role FROM users WHERE status='active' ORDER BY full_name")->fetchAll();
$curMonth = (int)date('n');
$curYear  = (int)date('Y');
$years    = $pdo->query("SELECT DISTINCT YEAR(transaction_date) y FROM cash_transactions ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($curYear, $years)) array_unshift($years, $curYear);
rsort($years);

$myBal = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN transaction_type='received'     THEN amount ELSE 0 END),0) AS total_received,
        COALESCE(SUM(CASE WHEN transaction_type='remitted'     THEN amount ELSE 0 END),0) AS total_remitted,
        COALESCE(SUM(CASE WHEN transaction_type='expense'      THEN amount ELSE 0 END),0) AS total_expenses,
        COALESCE(SUM(CASE WHEN transaction_type='vault_return' THEN amount ELSE 0 END),0) AS total_vault_returns,
        COALESCE(SUM(CASE WHEN transaction_type='refunded'     THEN amount ELSE 0 END),0) AS total_refunded
    FROM cash_transactions WHERE user_id=?
");
$myBal->execute([$_SESSION['user']['id']]);
$myBalance = $myBal->fetch();
// cash_on_hand = received + vault_return - remitted - expenses - refunded
$myCash = money_sub(
    money_sub(money_sub(money_add($myBalance['total_received'], $myBalance['total_vault_returns']), $myBalance['total_remitted']),
    $myBalance['total_expenses']),
    $myBalance['total_refunded']
);

logActivity($pdo, 'VIEW_CASH', 'Cash', 'Viewed cash on hand page');
include 'includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-hand-holding-dollar me-2 text-primary-custom"></i>Cash on Hand</h1>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-primary" onclick="openVaultRequestModal()">
      <i class="fa-solid fa-vault me-1"></i>Request Cash from Vault
    </button>
    <button class="btn btn-sm btn-primary" onclick="openRemitModal()">
      <i class="fa-solid fa-paper-plane me-1"></i>Record Remittance
    </button>
  </div>
</div>

<!-- My Cash Hero -->
<div class="cash-hero mb-3">
  <div class="row g-3 align-items-center">
    <div class="col-md-4">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;opacity:.75;margin-bottom:4px">My Current Cash on Hand</div>
      <div class="cash-hero-amount" style="font-size:36px;font-weight:800;letter-spacing:-1px"><?=money($myCash)?></div>
      <div style="font-size:12px;opacity:.8;margin-top:4px">
        <?=clean($_SESSION['user']['full_name'])?> &nbsp;&middot;&nbsp; <?=ucfirst($_SESSION['user']['role'])?>
      </div>
    </div>
    <div class="col-md-8">
      <div class="row g-2">
        <div class="col-4">
          <div class="cash-stat-inner" style="background:rgba(127,127,127,.15);border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.75;margin-bottom:4px">Total Received</div>
            <div class="cash-stat-val" style="font-size:18px;font-weight:700"><?=money((float)$myBalance['total_received'])?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="cash-stat-inner" style="background:rgba(127,127,127,.15);border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.75;margin-bottom:4px">Total Remitted</div>
            <div class="cash-stat-val" style="font-size:18px;font-weight:700"><?=money((float)$myBalance['total_remitted'])?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="cash-stat-inner" style="background:rgba(127,127,127,.15);border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.75;margin-bottom:4px">Total Expenses</div>
            <div class="cash-stat-val" style="font-size:18px;font-weight:700"><?=money((float)$myBalance['total_expenses'])?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if(isAdmin() || isAccountant()): ?>
<!-- All Staff Balances (admin + accountant — both roles can view; writes
     and per-row edits stay admin-only via the IS_ADMIN JS gate below) -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-users me-2"></i>All Staff Cash Balances</span>
    <button class="btn btn-xs btn-outline-secondary" onclick="loadAllBalances()"><i class="fa-solid fa-rotate me-1"></i>Refresh</button>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>Staff Member</th><th>Role</th>
        <th class="text-end">Total Received</th><th class="text-end">Total Remitted</th>
        <th class="text-end">Total Expenses</th><th class="text-end">Cash on Hand</th>
        <th class="text-center">Actions</th>
      </tr></thead>
      <tbody id="allBalBody">
        <tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Transaction Log -->
<div class="card">
  <div class="card-header">
    <span class="card-header-title"><i class="fa-solid fa-list me-2"></i>Transaction Log</span>
    <span class="badge bg-secondary" id="txBadge">0</span>
  </div>
  <div class="card-body py-2 border-bottom">
    <div class="row g-2 align-items-end">
      <?php if(isAdmin() || isAccountant()): ?>
      <div class="col-6 col-md-3">
        <label class="form-label">Staff Member</label>
        <select id="fUser" class="form-select form-select-sm">
          <option value="0">All Staff</option>
          <?php foreach($allUsers as $u): ?>
          <option value="<?=$u['id']?>" <?=$u['id']==$_SESSION['user']['id']?'selected':''?>><?=clean($u['full_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
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
      <div class="col-6 col-md-2">
        <label class="form-label">Type</label>
        <select id="fType" class="form-select form-select-sm">
          <option value="">All Types</option>
          <option value="received">Received</option>
          <option value="remitted">Remitted</option>
          <option value="expense">Expense</option>
          <option value="vault_return">Vault→You</option>
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
        <button class="btn btn-primary btn-sm" onclick="loadTransactions()"><i class="fa-solid fa-search me-1"></i>Filter</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="resetTxFilters()"><i class="fa-solid fa-rotate me-1"></i>Reset</button>
      </div>
    </div>
  </div>

  <!-- Period summary strip -->
  <div class="d-flex gap-3 flex-wrap px-3 py-2 border-bottom" style="background:var(--gray-100);font-size:12.5px">
    <span>Received: <strong id="pRec">&#8212;</strong></span>
    <span class="text-muted">|</span>
    <span>Remitted: <strong id="pRem">&#8212;</strong></span>
    <span class="text-muted">|</span>
    <span>Expenses: <strong id="pExp">&#8212;</strong></span>
    <span class="text-muted">|</span>
    <span>Vault&rarr;You: <strong id="pVret">&#8212;</strong></span>
    <span class="text-muted">|</span>
    <span>Net Cash on Hand: <strong id="pNet">&#8212;</strong></span>
  </div>

  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>Date</th><th>Encode Date</th><th>Type</th><th>Staff Member</th><th>Reference</th>
        <th class="text-end">Amount</th><th>Notes</th>
        <th class="text-center">Proof</th><th class="text-center no-print">Actions</th>
      </tr></thead>
      <tbody id="txBody">
        <tr><td colspan="8" class="text-center py-4">
          <span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...
        </td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Remittance Modal -->
<div class="modal fade" id="remitModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="remitModalTitle"><i class="fa-solid fa-paper-plane me-2"></i>Record Remittance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="remitId">
        <?php if(isAdmin()): ?>
        <div class="mb-3">
          <label class="form-label">Staff Member</label>
          <select class="form-select" id="remitUser">
            <?php foreach($allUsers as $u): ?>
            <option value="<?=$u['id']?>" <?=$u['id']==$_SESSION['user']['id']?'selected':''?>>
              <?=clean($u['full_name'])?> (<?=ucfirst($u['role'])?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="remitUser" value="<?=$_SESSION['user']['id']?>">
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label">Amount Remitted (&#8369;) *</label>
          <input type="number" step="0.01" min="0" class="form-control" id="remitAmount" placeholder="0.00">
        </div>
        <div class="mb-3">
          <label class="form-label">Date *</label>
          <input type="date" class="form-control" id="remitDate" value="<?=date('Y-m-d')?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <input type="text" class="form-control" id="remitNotes" placeholder="e.g. Remitted to accountant">
        </div>
        <div class="mb-3">
          <label class="form-label">Proof of Remittance</label>
          <div class="form-text mb-1">Upload file (photo, receipt, etc.)</div>
          <input type="file" class="form-control form-control-sm" id="remitFile" accept=".jpg,.jpeg,.png,.pdf">
          <div class="form-text mt-1">Or external URL</div>
          <input type="url" class="form-control form-control-sm mt-1" id="remitUrl" placeholder="https://drive.google.com/...">
        </div>
        <div id="remitMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveRemittance()">
          <i class="fa-solid fa-paper-plane me-1"></i>Record Remittance
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Request Cash from Vault Modal -->
<div class="modal fade" id="vaultRequestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-vault me-2"></i>Request Cash from Vault</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2" style="font-size:12.5px">Ask an admin to return cash to you from the Vault — for a tenant deposit refund or an unexpected expense after you've remitted. You'll be notified when it's approved.</div>
        <div class="mb-3">
          <label class="form-label">Amount (&#8369;) *</label>
          <input type="number" step="0.01" min="0.01" class="form-control" id="vrAmount" placeholder="0.00">
        </div>
        <div class="mb-3">
          <label class="form-label">For *</label>
          <select class="form-select" id="vrType">
            <option value="refund_fund">Tenant refund (e.g. deposit return)</option>
            <option value="expense_fund">Unexpected expense</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Purpose / details *</label>
          <textarea class="form-control" id="vrPurpose" rows="2" placeholder="e.g. 2-month deposit refund for tenant at 359-A, lease ended"></textarea>
        </div>
        <div id="vrMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="submitVaultRequest()"><i class="fa-solid fa-paper-plane me-1"></i>Submit Request</button>
      </div>
    </div>
  </div>
</div>

<script>
var vaultRequestModal = null;
function openVaultRequestModal() {
  if (!vaultRequestModal) vaultRequestModal = new bootstrap.Modal(document.getElementById('vaultRequestModal'));
  document.getElementById('vrAmount').value = '';
  document.getElementById('vrPurpose').value = '';
  document.getElementById('vrType').value = 'refund_fund';
  document.getElementById('vrMsg').style.display = 'none';
  vaultRequestModal.show();
}
function submitVaultRequest() {
  var msgEl = document.getElementById('vrMsg');
  var amount = parseFloat(document.getElementById('vrAmount').value);
  var purpose = document.getElementById('vrPurpose').value.trim();
  if (!amount || amount <= 0) { msgEl.className='alert alert-danger'; msgEl.textContent='Enter a valid amount.'; msgEl.style.display=''; return; }
  if (!purpose) { msgEl.className='alert alert-danger'; msgEl.textContent='Purpose is required.'; msgEl.style.display=''; return; }
  apiPost('api/requests_api.php', {
    action: 'create_request', amount: amount, purpose: purpose,
    request_type: document.getElementById('vrType').value
  }, function (err, res) {
    if (!res || !res.success) { msgEl.className='alert alert-danger'; msgEl.textContent=(res&&res.error)||'Failed.'; msgEl.style.display=''; return; }
    vaultRequestModal.hide();
    showToast(res.msg, 'success');
  });
}

function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}
// Reject javascript:/data:/vbscript: URLs before they reach an href. Allows
// /uploads/... paths, absolute http(s), and protocol-relative URLs.
function safeUrl(u) {
  if (!u) return '';
  var s = String(u).trim();
  if (/^\s*(javascript|data|vbscript|file):/i.test(s)) return '';
  // After scheme check it's safe to attribute-escape via esc().
  return esc(s);
}

var remitModal    = null;
// IS_ADMIN gates writes (Edit / Delete cash rows, choose-other-user when
// recording a remittance). CAN_VIEW_ALL gates view-only affordances —
// the All Staff Balances card and the Staff Member filter dropdown.
// Accountants share view permission with admins; only admins can mutate.
var IS_ADMIN      = <?=isAdmin()?'true':'false'?>;
var CAN_VIEW_ALL  = <?=(isAdmin() || isAccountant())?'true':'false'?>;
var MY_ID         = <?=(int)$_SESSION['user']['id']?>;

document.addEventListener('DOMContentLoaded', function() {
  remitModal = new bootstrap.Modal(document.getElementById('remitModal'));
  loadTransactions();
  if (CAN_VIEW_ALL) loadAllBalances();
});

function loadAllBalances() {
  if (!CAN_VIEW_ALL) return;
  document.getElementById('allBalBody').innerHTML = '<tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>';
  apiPost('api/cash_api.php', {action:'all_users_balance'}, function(err, res) {
    if (!res || !res.success) {
      document.getElementById('allBalBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Failed to load.</td></tr>';
      return;
    }
    var html = '';
    for (var i = 0; i < res.users.length; i++) {
      var u = res.users[i];
      var r = parseFloat(u.total_received) || 0;
      var m = parseFloat(u.total_remitted) || 0;
      var e = parseFloat(u.total_expenses) || 0;
      var c = parseFloat(u.cash_on_hand)   || 0;
      var roleBadge = '<span class="badge badge-' + esc(u.role) + '">' + esc(u.role.charAt(0).toUpperCase() + u.role.slice(1)) + '</span>';
      html += '<tr>';
      html += '<td class="fw-600">' + esc(u.full_name) + '</td>';
      html += '<td>' + roleBadge + '</td>';
      html += '<td class="text-end">' + fmt(r) + '</td>';
      html += '<td class="text-end">' + fmt(m) + '</td>';
      html += '<td class="text-end">' + fmt(e) + '</td>';
      html += '<td class="text-end fw-600 num">' + fmt(c) + '</td>';
      html += '<td class="text-center"><button class="btn-icon" title="View" onclick="filterByUser(' + parseInt(u.id) + ')"><i class="fa-solid fa-eye fa-xs"></i></button></td>';
      html += '</tr>';
    }
    var t = res.totals || {};
    var totR   = parseFloat(t.total_received) || 0;
    var totM   = parseFloat(t.total_remitted) || 0;
    var totE   = parseFloat(t.total_expenses) || 0;
    var totNet = parseFloat(t.net_on_hand)    || 0;
    html += '<tr style="background:var(--gray-100);font-weight:700;border-top:2px solid var(--gray-200)">';
    html += '<td colspan="2">TOTAL</td>';
    html += '<td class="text-end">' + fmt(totR) + '</td>';
    html += '<td class="text-end">' + fmt(totM) + '</td>';
    html += '<td class="text-end">' + fmt(totE) + '</td>';
    html += '<td class="text-end fw-600 num">' + fmt(totNet) + '</td>';
    html += '<td></td></tr>';
    document.getElementById('allBalBody').innerHTML = html || '<tr><td colspan="7" class="text-center text-muted py-3">No active users.</td></tr>';
  });
}

function filterByUser(userId) {
  var sel = document.getElementById('fUser');
  if (sel) sel.value = userId;
  loadTransactions();
  document.getElementById('txBody').scrollIntoView({behavior:'smooth'});
}

function loadTransactions() {
  var userId   = document.getElementById('fUser') ? document.getElementById('fUser').value : MY_ID;
  var month    = document.getElementById('fMonth').value;
  var year     = document.getElementById('fYear').value;
  var type     = document.getElementById('fType').value;
  var dateFrom = document.getElementById('fDateFrom').value;
  var dateTo   = document.getElementById('fDateTo').value;

  document.getElementById('txBody').innerHTML = '<tr><td colspan="9" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>';

  apiPost('api/cash_api.php', {action:'list_transactions', user_id:userId, month:month, year:year, type:type, date_from:dateFrom, date_to:dateTo}, function(err, res) {
    if (!res || !res.success) {
      document.getElementById('txBody').innerHTML = '<tr><td colspan="9" class="text-center text-danger py-3">Failed to load.</td></tr>';
      return;
    }
    document.getElementById('txBadge').textContent = res.count;
    document.getElementById('pRec').textContent  = fmt(res.total_received);
    document.getElementById('pRem').textContent  = fmt(res.total_remitted);
    document.getElementById('pExp').textContent  = fmt(res.total_expenses);
    document.getElementById('pVret').textContent = fmt(res.total_vault_returns || 0);

    // "Net Cash on Hand" shows the TRUE lifetime balance (unaffected by the
    // period/type filter), not the period net — so it never reads negative just
    // because this month's remittances exceed this month's collections.
    var netEl = document.getElementById('pNet');
    netEl.textContent = fmt(res.true_cash_on_hand != null ? res.true_cash_on_hand : res.cash_on_hand);

    var typeBadge = {received:'badge-received', remitted:'badge-remitted', expense:'badge-expense', vault_return:'badge-vault-return'};
    var typeIcon  = {received:'fa-arrow-down',  remitted:'fa-arrow-up',    expense:'fa-minus-circle', vault_return:'fa-hand-holding-dollar'};
    var typeLabel = {received:'Collection',      remitted:'Remitted',       expense:'Expense',         vault_return:'Vault→You'};

    var html = '';
    for (var i = 0; i < res.transactions.length; i++) {
      var t = res.transactions[i];
      var tt = t.transaction_type;
      var refHtml = '&#8212;';
      if (t.linked_invoice) refHtml = '<span class="mono" style="font-size:11.5px">' + esc(t.linked_invoice) + '</span>';
      else if (t.linked_expense) refHtml = '<span class="text-muted" style="font-size:11.5px">' + esc(t.linked_expense) + '</span>';

      var proofHtml = '<span class="text-muted">&#8212;</span>';
      if (t.doc_path) {
        var p = safeUrl(t.doc_path);
        if (p) proofHtml = '<a href="' + p + '" target="_blank" rel="noopener noreferrer" class="btn-icon" title="View file"><i class="fa-solid fa-paperclip fa-xs"></i></a>';
      } else if (t.doc_url) {
        var u = safeUrl(t.doc_url);
        if (u) proofHtml = '<a href="' + u + '" target="_blank" rel="noopener noreferrer" class="btn-icon" title="Open URL"><i class="fa-solid fa-link fa-xs"></i></a>';
      }

      var isManual = !t.reference_payment_id && !t.reference_expense_id;
      var isRemittance = isManual && tt === 'remitted';
      var editBtn = (isRemittance && IS_ADMIN)
        ? '<button class="btn-icon" title="Edit" onclick="editRemittance(' + parseInt(t.id) + ')"><i class="fa-solid fa-pen fa-xs"></i></button> '
        : '';
      var delBtn = (isManual && IS_ADMIN)
        ? '<button class="btn-icon danger" title="Delete" onclick="deleteTx(' + parseInt(t.id) + ')"><i class="fa-solid fa-trash fa-xs"></i></button>'
        : (!editBtn ? '<span class="text-muted">&#8212;</span>' : '');

      html += '<tr>';
      html += '<td style="white-space:nowrap;font-size:12.5px">' + t.transaction_date + '</td>';
      html += '<td style="white-space:nowrap;font-size:12px;color:var(--text-muted)">' + fmtDateTime(t.created_at) + '</td>';
      html += '<td><span class="badge ' + (typeBadge[tt] || 'badge-staff') + '"><i class="fa-solid ' + (typeIcon[tt] || 'fa-circle') + ' me-1 fa-xs"></i>' + (typeLabel[tt] || tt) + '</span></td>';
      html += '<td style="font-size:12.5px">' + (t.user_name ? esc(t.user_name) : '&#8212;') + '</td>';
      html += '<td>' + refHtml + '</td>';
      html += '<td class="text-end fw-600 num">' + fmt(t.amount) + '</td>';
      html += '<td style="font-size:12px;color:var(--text-muted);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + (t.notes ? esc(t.notes) : '&#8212;') + '</td>';
      html += '<td class="text-center">' + proofHtml + '</td>';
      html += '<td class="text-center">' + editBtn + delBtn + '</td>';
      html += '</tr>';
    }

    if (!html) html = '<tr><td colspan="9" class="text-center py-5 text-muted"><i class="fa-solid fa-inbox fa-2x d-block mb-2 mt-2"></i>No transactions found.</td></tr>';
    document.getElementById('txBody').innerHTML = html;
  });
}

function resetTxFilters() {
  if (document.getElementById('fUser')) document.getElementById('fUser').value = MY_ID;
  document.getElementById('fMonth').value    = new Date().getMonth() + 1;
  document.getElementById('fYear').value     = new Date().getFullYear();
  document.getElementById('fType').value     = '';
  document.getElementById('fDateFrom').value = '';
  document.getElementById('fDateTo').value   = '';
  loadTransactions();
}

function openRemitModal() {
  document.getElementById('remitId').value     = '';
  document.getElementById('remitAmount').value = '';
  document.getElementById('remitDate').value   = new Date().toISOString().split('T')[0];
  document.getElementById('remitNotes').value  = '';
  document.getElementById('remitFile').value   = '';
  document.getElementById('remitUrl').value    = '';
  document.getElementById('remitMsg').style.display = 'none';
  document.getElementById('remitModalTitle').innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>Record Remittance';
  if (IS_ADMIN && document.getElementById('remitUser')) {
    document.getElementById('remitUser').value = MY_ID;
  }
  remitModal.show();
}

function saveRemittance() {
  var fd = new FormData();
  fd.append('action',           'save_remittance');
  fd.append('id',               document.getElementById('remitId').value || '');
  fd.append('user_id',          document.getElementById('remitUser') ? document.getElementById('remitUser').value : MY_ID);
  fd.append('amount',           document.getElementById('remitAmount').value);
  fd.append('transaction_date', document.getElementById('remitDate').value);
  fd.append('notes',            document.getElementById('remitNotes').value);
  fd.append('doc_url',          document.getElementById('remitUrl').value);
  var file = document.getElementById('remitFile').files[0];
  if (file) fd.append('doc_file', file);

  fetch('api/cash_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      var el = document.getElementById('remitMsg');
      el.style.display = '';
      if (!res.success) {
        el.className = 'alert alert-danger'; el.textContent = res.error;
        return;
      }
      el.className = 'alert alert-success'; el.textContent = res.msg;
      setTimeout(function() { remitModal.hide(); location.reload(); }, 1000);
    })
    .catch(function() { showToast('Network error.', 'error'); });
}

function deleteTx(id) {
  confirmDelete('Delete this manual transaction?', function() {
    apiPost('api/cash_api.php', {action:'delete_cash_tx', id:id}, function(err, res) {
      if (!res || !res.success) return showToast((res && res.error) || 'Failed.', 'error');
      showToast(res.msg, 'success');
      loadTransactions();
      if (CAN_VIEW_ALL) loadAllBalances();
    });
  });
}

function editRemittance(id) {
  apiPost('api/cash_api.php', {action:'get_remittance', id:id}, function(err, res) {
    if (!res || !res.success) return showToast('Failed to load remittance.', 'error');
    var r = res.remittance;
    document.getElementById('remitModalTitle').innerHTML = '<i class="fa-solid fa-pen me-2"></i>Edit Remittance';
    document.getElementById('remitId').value     = r.id;
    document.getElementById('remitAmount').value = r.amount;
    document.getElementById('remitDate').value   = r.transaction_date;
    document.getElementById('remitNotes').value  = r.notes || '';
    document.getElementById('remitUrl').value    = r.doc_url || '';
    document.getElementById('remitFile').value   = '';
    document.getElementById('remitMsg').style.display = 'none';
    if (IS_ADMIN && document.getElementById('remitUser')) {
      document.getElementById('remitUser').value = r.user_id;
    }
    remitModal.show();
  });
}
</script>
<?php include 'includes/footer.php'; ?>
