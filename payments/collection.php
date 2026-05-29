<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
$pageTitle = 'Payment Collection';
$depth = '../';

$units        = $pdo->query("SELECT ru.id, ru.unit_name, ru.monthly_rate, ru.due_day, ru.status,
                              t.full_name AS tenant_name, t.contract_start
                              FROM rental_units ru
                              LEFT JOIN tenants t ON t.unit_id = ru.id AND t.status = 'active'
                              ORDER BY ru.unit_name")->fetchAll();
$serviceTypes = $pdo->query("SELECT id, name, default_amount FROM service_types WHERE is_active = 1 ORDER BY name")->fetchAll();

$curMonth = (int)date('n');
$curYear  = (int)date('Y');

$years = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments WHERE deleted_at IS NULL AND status != 'voided' ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($curYear, $years)) array_unshift($years, $curYear);
rsort($years);

logActivity($pdo, 'VIEW_COLLECTION', 'Payments', 'Viewed payment collection dashboard');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-money-bill-wave me-2 text-primary-custom"></i>Payment Collection</h1>
  <button class="btn btn-primary btn-sm" onclick="openPaymentModal()">
    <i class="fa-solid fa-plus me-1"></i>Record Payment
  </button>
</div>

<!-- Period Selector -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="fw-600" style="font-size:13px;color:var(--text-secondary)"><i class="fa-solid fa-calendar me-1"></i>Period:</span>
      <select id="selMonth" class="form-select form-select-sm" style="width:140px">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $curMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <select id="selYear" class="form-select form-select-sm" style="width:90px">
        <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary" onclick="loadSummary()">
        <i class="fa-solid fa-rotate me-1"></i>Refresh
      </button>
      <div class="ms-auto d-none d-md-flex align-items-center gap-3">
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot green"></span> Paid</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot amber"></span> Partial / Pending</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot red"></span> Overdue</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot gray"></span> Vacant</span>
      </div>
    </div>
  </div>
</div>

<!-- Monthly Summary Table -->
<div class="card mb-3" id="summaryCard">
  <div class="card-header">
    <span class="card-header-title" id="summaryTitle">
      <i class="fa-solid fa-table me-2"></i>Monthly Collection Summary
    </span>
    <div class="d-flex gap-2">
      <span id="summaryBadgeGreen" class="badge bg-success">— Paid</span>
      <span id="summaryBadgeAmber" class="badge bg-warning text-dark">— Partial</span>
      <span id="summaryBadgeRed"   class="badge bg-danger">— Overdue</span>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table" id="summaryTable">
      <thead>
        <tr>
          <th style="width:36px">St.</th>
          <th>Unit</th>
          <th>Tenant</th>
          <th class="text-end">Monthly Rate</th>
          <th class="text-end">Rent Paid</th>
          <th class="text-end">Services</th>
          <th class="text-end">Total Paid</th>
          <th class="text-end">Balance</th>
          <th>Last Cashier</th>
          <th class="text-center no-print">Actions</th>
        </tr>
      </thead>
      <tbody id="summaryBody">
        <tr><td colspan="10" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>
      </tbody>
      <tfoot id="summaryFoot"></tfoot>
    </table>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="payModalTitle"><i class="fa-solid fa-money-bill-wave me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="payId">
        <input type="hidden" id="payIdempotencyKey">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Rental Unit *</label>
            <select class="form-select" id="payUnit" onchange="onUnitChange(this.value)">
              <option value="">— Select unit —</option>
              <?php foreach ($units as $u): ?>
              <option value="<?= $u['id'] ?>"
                data-rate="<?= $u['monthly_rate'] ?>"
                data-due="<?= (int)$u['due_day'] ?>"
                data-tenant="<?= clean($u['tenant_name'] ?? '') ?>"
                data-contract-start="<?= $u['contract_start'] ?? '' ?>"
              ><?= clean($u['unit_name']) ?><?= $u['tenant_name'] ? ' — ' . clean($u['tenant_name']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Tenant</label>
            <select class="form-select" id="payTenant">
              <option value="">— Auto from unit —</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Type *</label>
            <select class="form-select" id="payType" onchange="onPayTypeChange(this.value)">
              <option value="rent">Rental Payment</option>
              <option value="service">Service / Fee</option>
            </select>
          </div>
          <div class="col-md-8" id="serviceRow" style="display:none">
            <label class="form-label">Service Type *</label>
            <select class="form-select" id="payService" onchange="onServiceChange(this.value)">
              <option value="">— Select service —</option>
              <?php foreach ($serviceTypes as $s): ?>
              <option value="<?= $s['id'] ?>" data-amount="<?= $s['default_amount'] ?>"><?= clean($s['name']) ?><?= money_is_pos($s['default_amount']) ? ' — ' . money($s['default_amount']) : ' (variable)' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Amount (₱) *</label>
            <input type="number" step="0.01" min="0" class="form-control" id="payAmount" placeholder="0.00">
            <div id="prorateHint" class="form-text text-warning fw-600" style="display:none"></div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Date *</label>
            <input type="date" class="form-control" id="payDate" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Due Date <small class="text-muted">(optional)</small></label>
            <input type="date" class="form-control" id="payDue">
          </div>
          <div class="col-md-4">
            <label class="form-label">Period Month *</label>
            <select class="form-select" id="payPeriodMonth">
              <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $curMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Period Year *</label>
            <select class="form-select" id="payPeriodYear">
              <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <input type="text" class="form-control" id="payNotes" placeholder="e.g. Cash payment, reference number...">
          </div>
        </div>
        <div id="unitInfoBar" class="mt-3" style="display:none">
          <div class="alert alert-info py-2 mb-0" style="font-size:12.5px">
            <i class="fa-solid fa-circle-info me-1"></i>
            <span id="unitInfoText"></span>
          </div>
        </div>
        <div id="payMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-outline-primary btn-sm" id="payBtnSaveAndPrint" onclick="saveAndPrint()"><i class="fa-solid fa-print me-1"></i>Save &amp; Print Invoice</button>
        <button class="btn btn-primary btn-sm" id="payBtnSave" onclick="savePayment(false)"><i class="fa-solid fa-save me-1"></i>Save Payment</button>
      </div>
    </div>
  </div>
</div>

<!-- Unit Detail / Payment List Modal -->
<div class="modal fade" id="unitDetailModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="unitDetailTitle">Unit Payments</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="unitDetailBody">
        <div class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>
      </div>
    </div>
  </div>
</div>

<!-- Add Service Charge Modal -->
<div class="modal fade" id="chargeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-receipt me-2" style="color:var(--warning)"></i>Add Service Charge</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="chgUnitId">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Service Type *</label>
            <select class="form-select" id="chgServiceType" onchange="onChgServiceChange()">
              <option value="">— Select service —</option>
              <?php foreach ($serviceTypes as $s): ?>
              <option value="<?= $s['id'] ?>" data-amount="<?= $s['default_amount'] ?>"><?= clean($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Amount (₱) *</label>
            <input type="number" step="0.01" min="0.01" class="form-control" id="chgAmount">
          </div>
          <div class="col-md-6">
            <label class="form-label">Charge Date *</label>
            <input type="date" class="form-control" id="chgDate" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Period Month *</label>
            <select class="form-select" id="chgMonth">
              <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $curMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Period Year *</label>
            <select class="form-select" id="chgYear">
              <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $curYear ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Description <small class="text-muted">(optional override)</small></label>
            <input type="text" class="form-control" id="chgDescription" placeholder="Leave blank to use service type name">
          </div>
        </div>
        <div class="alert alert-info mt-3 py-2" style="font-size:12.5px">
          <i class="fa-solid fa-circle-info me-1"></i>
          This creates an <strong>outstanding charge</strong> on the account. Collect payment separately when received.
          If you want to bill and collect at the same time, use <strong>Record Payment</strong> instead.
        </div>
        <div id="chgMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm" style="background:var(--warning);color:#fff;border-color:var(--warning)" onclick="saveCharge()">
          <i class="fa-solid fa-receipt me-1"></i>Add Charge
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Refund Modal -->
<div class="modal fade" id="refundModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-rotate-left me-2 text-danger"></i>Process Refund</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="refPaymentId">
        <div class="alert alert-info py-2 mb-3" id="refPaymentInfo" style="font-size:13px"></div>
        <div class="mb-3">
          <label class="form-label">Refund Amount (₱) *</label>
          <input type="number" step="0.01" min="0.01" class="form-control" id="refAmount">
          <div class="form-text" id="refMaxHint"></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <textarea class="form-control" id="refReason" rows="2" placeholder="e.g. Overpayment, duplicate payment, cancellation..."></textarea>
        </div>
        <div id="refMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" onclick="processRefund()">
          <i class="fa-solid fa-rotate-left me-1"></i>Process Refund
        </button>
      </div>
    </div>
  </div>
</div>

<!-- PHP data for JS (no heredoc) -->
<script>
var SERVICE_TYPES = <?= json_encode($serviceTypes) ?>;
</script>

<script>
function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}

var payModal        = null;
var unitDetailModal = null;
var refundModal     = null;
var chargeModal     = null;
var currentUnitDetail = {};
var IS_ADMIN = <?=isAdmin()?'true':'false'?>;
var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

document.addEventListener('DOMContentLoaded', function() {
  payModal        = new bootstrap.Modal(document.getElementById('paymentModal'));
  unitDetailModal = new bootstrap.Modal(document.getElementById('unitDetailModal'));
  refundModal     = new bootstrap.Modal(document.getElementById('refundModal'));
  chargeModal     = new bootstrap.Modal(document.getElementById('chargeModal'));
  loadSummary();
  document.getElementById('selMonth').addEventListener('change', loadSummary);
  document.getElementById('selYear').addEventListener('change', loadSummary);
});

function loadSummary() {
  var month = document.getElementById('selMonth').value;
  var year  = document.getElementById('selYear').value;
  var monthName = document.getElementById('selMonth').options[month - 1].text;

  document.getElementById('summaryTitle').innerHTML =
    '<i class="fa-solid fa-table me-2"></i>Monthly Collection Summary — ' + monthName + ' ' + year;
  document.getElementById('summaryBody').innerHTML =
    '<tr><td colspan="10" class="text-center py-4">' +
    '<span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>';
  document.getElementById('summaryFoot').innerHTML = '';

  apiPost('api_payment.php', {action: 'monthly_summary', month: month, year: year}, function(err, res) {
    if (err || !res || !res.success) {
      document.getElementById('summaryBody').innerHTML =
        '<tr><td colspan="10" class="text-center text-danger py-3">Failed to load summary.</td></tr>';
      return;
    }

    var html = '';
    var totRate = 0, totRentPaid = 0, totSvc = 0, totTotal = 0, totBal = 0;
    var countG = 0, countA = 0, countR = 0;

    res.summary.forEach(function(r) {
      var rate   = parseFloat(r.monthly_rate) || 0;
      var rentPd = parseFloat(r.rent_paid)    || 0;
      var svcPd  = parseFloat(r.service_paid) || 0;
      var totPd  = parseFloat(r.total_paid)   || 0;
      var bal    = parseFloat(r.balance)       || 0;
      totRate += rate; totRentPaid += rentPd; totSvc += svcPd; totTotal += totPd; totBal += bal;

      var dot = '<span class="status-dot ' + r.pay_status + '" title="' + r.pay_status + '"></span>';
      if      (r.pay_status === 'green') countG++;
      else if (r.pay_status === 'amber') countA++;
      else if (r.pay_status === 'red')   countR++;

      var balCell = bal > 0
        ? '<span style="color:var(--danger);font-weight:600">' + fmt(bal) + '</span>'
        : '<span style="color:var(--success)">—</span>';
      var statusBadge = r.status === 'occupied'
        ? '<span class="badge badge-occupied">Occupied</span>'
        : '<span class="badge badge-vacant">Vacant</span>';
      var tenantCell  = r.tenant_name ? esc(r.tenant_name) : '<span class="text-muted">—</span>';
      var rentCell    = rentPd > 0 ? '<span style="color:var(--success)">' + fmt(rentPd) + '</span>' : '<span class="text-muted">—</span>';
      var svcCell     = svcPd  > 0 ? fmt(svcPd)  : '<span class="text-muted">—</span>';
      var totCell     = totPd  > 0 ? fmt(totPd)  : '<span class="text-muted">—</span>';
      var cashierCell = r.last_cashier ? esc(r.last_cashier) : '<span class="text-muted">—</span>';

      html +=
        '<tr>' +
          '<td>' + dot + '</td>' +
          '<td><div class="fw-600 cell-trunc-sm">' + esc(r.unit_name) + '</div>' + statusBadge + '</td>' +
          '<td class="cell-trunc">' + tenantCell + '</td>' +
          '<td class="text-end">' + fmt(rate) + '</td>' +
          '<td class="text-end">' + rentCell + '</td>' +
          '<td class="text-end">' + svcCell  + '</td>' +
          '<td class="text-end fw-600">' + totCell + '</td>' +
          '<td class="text-end">' + balCell + '</td>' +
          '<td style="font-size:12px">' + cashierCell + '</td>' +
          '<td class="text-center no-print">' +
            '<button class="btn-icon" title="View payments" ' +
              'data-uid="' + r.id + '" data-uname="' + esc(r.unit_name) + '" ' +
              'data-month="' + month + '" data-year="' + year + '" ' +
              'onclick="viewUnitPayments(+this.dataset.uid, this.dataset.uname, +this.dataset.month, +this.dataset.year)">' +
              '<i class="fa-solid fa-eye fa-xs"></i></button> ' +
            '<button class="btn-icon" title="Record payment" onclick="openPaymentForUnit(' + r.id + ')">' +
              '<i class="fa-solid fa-plus fa-xs"></i></button>' +
          '</td>' +
        '</tr>';
    });

    document.getElementById('summaryBody').innerHTML =
      html || '<tr><td colspan="10" class="text-center py-4 text-muted">No units found.</td></tr>';

    var balColor = totBal > 0 ? 'var(--danger)' : 'var(--success)';
    var balText  = totBal > 0 ? fmt(totBal) : 'Fully Paid';
    document.getElementById('summaryFoot').innerHTML =
      '<tr style="background:#f0f4ff;font-weight:700;">' +
        '<td></td><td colspan="2">TOTAL</td>' +
        '<td class="text-end">' + fmt(totRate)     + '</td>' +
        '<td class="text-end">' + fmt(totRentPaid) + '</td>' +
        '<td class="text-end">' + fmt(totSvc)      + '</td>' +
        '<td class="text-end">' + fmt(totTotal)    + '</td>' +
        '<td class="text-end" style="color:' + balColor + '">' + balText + '</td>' +
        '<td colspan="2"></td>' +
      '</tr>';

    document.getElementById('summaryBadgeGreen').textContent = countG + ' Paid';
    document.getElementById('summaryBadgeAmber').textContent = countA + ' Partial';
    document.getElementById('summaryBadgeRed').textContent   = countR + ' Overdue';
  });
}

// Fresh UUID per modal-open so a double-click / network retry on the same
// submission dedupes server-side. Falls back to a random hex when crypto.randomUUID
// is unavailable (older browsers / non-secure contexts).
function newIdempotencyKey() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }
  var b = new Uint8Array(16);
  (window.crypto || {}).getRandomValues ? window.crypto.getRandomValues(b) : b.forEach((_,i) => b[i] = Math.floor(Math.random()*256));
  return Array.from(b, x => x.toString(16).padStart(2,'0')).join('');
}

function openPaymentModal() {
  document.getElementById('payModalTitle').innerHTML = '<i class="fa-solid fa-money-bill-wave me-2"></i>Record Payment';
  document.getElementById('payId').value          = '';
  document.getElementById('payIdempotencyKey').value = newIdempotencyKey();
  document.getElementById('payUnit').value        = '';
  document.getElementById('payTenant').innerHTML  = '<option value="">— Auto from unit —</option>';
  document.getElementById('payType').value        = 'rent';
  document.getElementById('payService').value     = '';
  document.getElementById('payAmount').value      = '';
  document.getElementById('payDate').value        = new Date().toISOString().split('T')[0];
  document.getElementById('payDue').value         = '';
  document.getElementById('payNotes').value       = '';
  document.getElementById('payMsg').style.display       = 'none';
  document.getElementById('unitInfoBar').style.display  = 'none';
  document.getElementById('serviceRow').style.display   = 'none';
  document.getElementById('prorateHint').style.display  = 'none';
  document.getElementById('payPeriodMonth').value = document.getElementById('selMonth').value;
  document.getElementById('payPeriodYear').value  = document.getElementById('selYear').value;
  payModal.show();
}

function openPaymentForUnit(unitId) {
  openPaymentModal();
  setTimeout(function() {
    document.getElementById('payUnit').value = unitId;
    onUnitChange(unitId);
  }, 100);
}

function calcProrated(rate, dueDay, contractStart, month, year) {
  if (!contractStart || rate <= 0) return rate;
  var cs    = new Date(contractStart);
  var csDay = cs.getDate(), csMonth = cs.getMonth() + 1, csYear = cs.getFullYear();
  if (csYear !== year || csMonth !== month || csDay <= dueDay) return rate;
  var daysInMonth  = new Date(year, month, 0).getDate();
  var daysOccupied = daysInMonth - csDay + 1;
  return Math.round((rate / daysInMonth) * daysOccupied * 100) / 100;
}

function applyRentAmount() {
  var sel = document.getElementById('payUnit');
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.dataset.rate) return;
  var rate          = parseFloat(opt.dataset.rate) || 0;
  var dueDay        = parseInt(opt.dataset.due)    || 5;
  var contractStart = opt.dataset.contractStart    || '';
  var month         = parseInt(document.getElementById('payPeriodMonth').value);
  var year          = parseInt(document.getElementById('payPeriodYear').value);
  var prorated      = calcProrated(rate, dueDay, contractStart, month, year);

  document.getElementById('payAmount').value = prorated.toFixed(2);

  var hint = document.getElementById('prorateHint');
  if (prorated < rate) {
    var cs          = new Date(contractStart);
    var daysInMonth = new Date(year, month, 0).getDate();
    var daysOcc     = daysInMonth - cs.getDate() + 1;
    hint.textContent = 'Prorated: ' + daysOcc + ' of ' + daysInMonth + ' days (move-in ' + contractStart + ')';
    hint.style.display = '';
  } else {
    hint.style.display = 'none';
  }
}

function onUnitChange(unitId) {
  var sel = document.getElementById('payUnit');
  var opt = sel.options[sel.selectedIndex];
  if (!unitId) {
    document.getElementById('unitInfoBar').style.display = 'none';
    document.getElementById('prorateHint').style.display = 'none';
    return;
  }
  var rate   = parseFloat(opt.dataset.rate) || 0;
  var dueDay = opt.dataset.due || '5';
  var tenant = opt.dataset.tenant || '';

  var infoText = 'Monthly Rate: ' + fmt(rate) + ' · Due: ' + dueDay + 'th of each month';
  if (tenant) infoText += ' · Tenant: ' + tenant;
  document.getElementById('unitInfoBar').style.display = '';
  document.getElementById('unitInfoText').textContent  = infoText;

  if (document.getElementById('payType').value === 'rent' && rate > 0) {
    applyRentAmount();
  }

  apiPost('api_payment.php', {action: 'get_unit_tenants', unit_id: unitId}, function(err, res) {
    var sel2 = document.getElementById('payTenant');
    sel2.innerHTML = '<option value="">— Select tenant —</option>';
    if (res && res.success && res.tenants.length) {
      res.tenants.forEach(function(t) {
        var o = document.createElement('option');
        o.value = t.id;
        o.textContent = t.full_name;
        sel2.appendChild(o);
      });
      if (res.tenants.length === 1) sel2.value = res.tenants[0].id;
    }
  });
}

function onPayTypeChange(type) {
  document.getElementById('serviceRow').style.display = type === 'service' ? '' : 'none';
  if (type === 'rent') {
    applyRentAmount();
    document.getElementById('payService').value = '';
  } else {
    document.getElementById('payAmount').value = '';
    document.getElementById('prorateHint').style.display = 'none';
  }
}

function onServiceChange() {
  var sel = document.getElementById('payService');
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.amount && parseFloat(opt.dataset.amount) > 0) {
    document.getElementById('payAmount').value = parseFloat(opt.dataset.amount).toFixed(2);
  }
}

function savePayment(andPrint) {
  var saveBtn   = document.getElementById('payBtnSave');
  var printBtn  = document.getElementById('payBtnSaveAndPrint');
  // Client-side belt: hard-lock both submit buttons until the response returns,
  // so a double-click can't fire a second request even before the network round-trip.
  // The idempotency_key is the server-side suspenders that catch retries from beyond JS.
  if (saveBtn && saveBtn.disabled)  return;
  if (saveBtn)  saveBtn.disabled  = true;
  if (printBtn) printBtn.disabled = true;

  var data = {
    action:          'save_payment',
    id:              document.getElementById('payId').value,
    idempotency_key: document.getElementById('payIdempotencyKey').value,
    unit_id:         document.getElementById('payUnit').value,
    tenant_id:       document.getElementById('payTenant').value,
    payment_type:    document.getElementById('payType').value,
    service_type_id: document.getElementById('payService').value,
    amount:          document.getElementById('payAmount').value,
    payment_date:    document.getElementById('payDate').value,
    due_date:        document.getElementById('payDue').value,
    period_month:    document.getElementById('payPeriodMonth').value,
    period_year:     document.getElementById('payPeriodYear').value,
    notes:           document.getElementById('payNotes').value
  };

  apiPost('api_payment.php', data, function(err, res) {
    if (saveBtn)  saveBtn.disabled  = false;
    if (printBtn) printBtn.disabled = false;
    if (err || !res || !res.success) {
      var el = document.getElementById('payMsg');
      el.style.display = '';
      el.className = 'alert alert-danger';
      el.textContent = (res && res.error) ? res.error : (err || 'Save failed.');
      return;
    }
    showToast(res.msg, 'success');
    payModal.hide();
    loadSummary();
    // Refresh unit detail if open so edited payment shows updated data
    var udm = document.getElementById('unitDetailModal');
    if (udm && udm.classList.contains('show') && currentUnitDetail.id) {
      viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
    }
    if (andPrint && res.id) {
      window.open('../payments/invoice_print.php?id=' + res.id, '_blank');
    }
  });
}

function saveAndPrint() { savePayment(true); }

function viewUnitPayments(unitId, unitName, month, year) {
  currentUnitDetail = {id: unitId, name: unitName, month: month, year: year};
  document.getElementById('unitDetailTitle').textContent = 'Payments — ' + unitName;
  document.getElementById('unitDetailBody').innerHTML =
    '<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>';
  unitDetailModal.show();

  apiPost('api_payment.php', {action: 'get_unit_payments', unit_id: unitId, month: month, year: year}, function(err, res) {
    if (err || !res || !res.success) {
      document.getElementById('unitDetailBody').innerHTML = '<p class="text-danger">Failed to load.</p>';
      return;
    }
    var pays    = res.payments;
    var unit    = res.unit;
    var charges = res.charges || [];
    var totPaid = res.total_paid || 0;
    var html = '';

    if (unit) {
      html +=
        '<div class="alert alert-info py-2 mb-3" style="font-size:12.5px">' +
          '<strong>' + esc(unit.unit_name) + '</strong> &nbsp;&middot;&nbsp; Tenant: <strong>' +
          esc(unit.tenant_name || 'Vacant') + '</strong>' +
          ' &nbsp;&middot;&nbsp; Monthly Rate: <strong>' + fmt(unit.monthly_rate) + '</strong>' +
          ' &nbsp;&middot;&nbsp; Due: <strong>' + parseInt(unit.due_day || 0) + 'th</strong>' +
        '</div>';
    }

    // ── Outstanding service charges ──────────────────────────────
    var outstanding = charges.filter(function(c) { return !c.payment_id; });
    if (outstanding.length) {
      html += '<div class="alert alert-warning py-2 mb-3" style="font-size:12.5px">' +
        '<div class="fw-600 mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>Outstanding Service Charges</div>' +
        '<table class="table table-sm mb-0" style="font-size:12.5px"><thead><tr>' +
        '<th>Service</th><th>Period</th><th class="text-end">Amount</th><th class="text-center" style="width:90px">Actions</th>' +
        '</tr></thead><tbody>';
      outstanding.forEach(function(c) {
        var period = MONTHS[(parseInt(c.period_month)||1) - 1] + ' ' + parseInt(c.period_year);
        html += '<tr>' +
          '<td>' + esc(c.service_name || c.description) + '</td>' +
          '<td>' + esc(period) + '</td>' +
          '<td class="text-end fw-600">' + fmt(c.amount) + '</td>' +
          '<td class="text-center">' +
            '<button class="btn btn-primary btn-sm py-0 px-2 me-1" style="font-size:11px" title="Collect payment" ' +
              'onclick="collectCharge(' + parseInt(c.id) + ',' + parseInt(unitId) + ',' + parseInt(c.service_type_id||0) + ',' + (parseFloat(c.amount)||0) + ',' + parseInt(c.period_month) + ',' + parseInt(c.period_year) + ')">' +
              '<i class="fa-solid fa-money-bill-wave fa-xs me-1"></i>Collect</button>' +
            '<button class="btn-icon danger" title="Delete charge" onclick="deleteCharge(' + parseInt(c.id) + ')">' +
              '<i class="fa-solid fa-trash fa-xs"></i></button>' +
          '</td>' +
        '</tr>';
      });
      html += '</tbody></table></div>';
    }

    // ── Payments table ──────────────────────────────────────────
    if (!pays.length) {
      html += '<div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>No payments recorded for this period.</p></div>';
    } else {
      // Date range filter bar
      html += '<div class="d-flex align-items-end gap-2 mb-2" style="flex-wrap:wrap">' +
        '<div><label style="font-size:11px;display:block;margin-bottom:2px">From</label>' +
        '<input type="date" id="payDateFrom" class="form-control form-control-sm" style="width:140px" oninput="filterPaymentDates()"></div>' +
        '<div><label style="font-size:11px;display:block;margin-bottom:2px">To</label>' +
        '<input type="date" id="payDateTo" class="form-control form-control-sm" style="width:140px" oninput="filterPaymentDates()"></div>' +
        '<button class="btn btn-outline-secondary btn-sm" onclick="clearPayDateFilter()" style="align-self:flex-end">' +
        '<i class="fa-solid fa-rotate fa-xs me-1"></i>Clear</button>' +
        '</div>';

      // Bulk bar for admin
      if (IS_ADMIN) {
        html += '<div id="payBulkBar" style="display:none;margin-bottom:8px" class="d-flex align-items-center gap-2 bg-light border rounded px-3 py-2">' +
          '<span class="fw-600" id="payBulkCount"></span>' +
          '<button class="btn btn-danger btn-sm" onclick="bulkDeletePayments()"><i class="fa-solid fa-trash me-1"></i>Delete Selected</button>' +
          '<button class="btn btn-secondary btn-sm" onclick="clearPaySelection()">Cancel</button>' +
          '</div>';
      }

      html +=
        '<div class="table-responsive"><table class="table" id="payDetailTable"><thead><tr>' +
        (IS_ADMIN ? '<th class="no-print" style="width:32px"><input type="checkbox" id="paySelectAll" onclick="toggleAllPayments(this)"></th>' : '') +
        '<th>Date</th><th>Invoice</th><th>Type</th><th>Description</th>' +
        '<th class="text-end">Amount</th><th>Cashier</th><th class="text-center">Actions</th>' +
        '</tr></thead><tbody>';

      pays.forEach(function(p) {
        var isVoided = p.status === 'voided';
        var typeLabel = p.payment_type === 'rent'
          ? '<span class="badge badge-rent">Rent</span>'
          : '<span class="badge badge-service">' + esc(p.service_name || 'Service') + '</span>';
        var statusBadge = '';
        if (p.status === 'voided') {
          statusBadge = ' <span class="badge bg-secondary" style="font-size:10px">Voided</span>';
        } else if (p.status === 'refunded') {
          statusBadge = ' <span class="badge bg-danger" style="font-size:10px">Refunded</span>';
        } else if (p.status === 'partially_refunded') {
          statusBadge = ' <span class="badge bg-warning text-dark" style="font-size:10px">Partial Refund</span>';
        }
        var alreadyRefunded = parseFloat(p.refunded_total) || 0;
        var pid    = parseInt(p.id) || 0;
        var invEsc = esc(p.invoice_no || '');
        // data-* attributes carry user-controlled strings (invoice_no) out of
        // the JS literal — handlers read them via dataset, never via inline
        // interpolation that could break out of the attribute.
        var refBtn = (IS_ADMIN && !isVoided && p.status !== 'refunded')
          ? '<button class="btn-icon" title="Process Refund" data-id="' + pid + '" data-inv="' + invEsc + '" data-amt="' + (parseFloat(p.amount)||0) + '" data-already="' + alreadyRefunded + '" onclick="openRefundModal(+this.dataset.id, this.dataset.inv, +this.dataset.amt, +this.dataset.already)">' +
              '<i class="fa-solid fa-rotate-left fa-xs" style="color:var(--danger)"></i></button> '
          : '';
        var editBtn = (IS_ADMIN && !isVoided)
          ? '<button class="btn-icon" title="Edit" data-id="' + pid + '" onclick="editPayment(+this.dataset.id)"><i class="fa-solid fa-pen fa-xs"></i></button> '
          : '';
        var voidRestoreBtn = '';
        if (IS_ADMIN) {
          voidRestoreBtn = isVoided
            ? '<button class="btn-icon" title="Restore Payment" data-id="' + pid + '" onclick="restorePayment(+this.dataset.id)"><i class="fa-solid fa-rotate-right fa-xs" style="color:var(--success)"></i></button> '
            : '<button class="btn-icon" title="Void Payment" data-id="' + pid + '" data-inv="' + invEsc + '" onclick="voidPayment(+this.dataset.id, this.dataset.inv)"><i class="fa-solid fa-ban fa-xs" style="color:var(--warning)"></i></button> ';
        }
        html +=
          '<tr' + (isVoided ? ' style="opacity:0.55"' : '') + '>' +
            (IS_ADMIN ? '<td class="no-print"><input type="checkbox" class="pay-chk" value="' + pid + '" onclick="updatePayBulkBar()"></td>' : '') +
            '<td style="white-space:nowrap">' + esc(p.payment_date) + '</td>' +
            '<td class="mono" style="font-size:12px">' + (p.invoice_no ? esc(p.invoice_no) : '—') + '</td>' +
            '<td>' + typeLabel + '</td>' +
            '<td style="font-size:12.5px">' + (p.notes ? esc(p.notes) : '—') + '</td>' +
            '<td class="text-end fw-600">' + fmt(p.amount) + statusBadge + '</td>' +
            '<td style="font-size:12px">' + (p.cashier_name ? esc(p.cashier_name) : '—') + '</td>' +
            '<td class="text-center">' +
              '<a href="../payments/invoice_print.php?id=' + pid + '" target="_blank" rel="noopener noreferrer" class="btn-icon" title="Print Invoice">' +
                '<i class="fa-solid fa-print fa-xs"></i></a> ' +
              refBtn +
              editBtn +
              voidRestoreBtn +
              '<button class="btn-icon danger" title="Delete" data-id="' + pid + '" onclick="deletePayment(+this.dataset.id)">' +
                '<i class="fa-solid fa-trash fa-xs"></i></button>' +
            '</td>' +
          '</tr>';
      });

      html +=
        '</tbody><tfoot><tr style="background:#f9fafb;font-weight:700">' +
        '<td colspan="' + (IS_ADMIN ? 5 : 4) + '">Total Paid</td><td class="text-end">' + fmt(totPaid) + '</td><td colspan="2"></td>' +
        '</tr></tfoot></table></div>';
    }

    html +=
      '<div class="mt-3 d-flex justify-content-between align-items-center">' +
        '<button class="btn btn-sm" style="background:var(--warning);color:#fff;border-color:var(--warning)" onclick="openChargeModal(' + unitId + ')">' +
          '<i class="fa-solid fa-receipt me-1"></i>Add Service Charge</button>' +
        '<button class="btn btn-primary btn-sm" onclick="openPaymentForUnit(' + unitId + '); unitDetailModal.hide()">' +
          '<i class="fa-solid fa-plus me-1"></i>Record Payment</button>' +
      '</div>';

    document.getElementById('unitDetailBody').innerHTML = html;
  });
}

function deletePayment(id) {
  confirmDelete('Delete this payment? The cash record will also be removed. Admins only.', function() {
    apiPost('api_payment.php', {action: 'delete_payment', id: id}, function(err, res) {
      if (err || !res || !res.success) {
        showToast((res && res.error) || 'Failed.', 'error');
        return;
      }
      showToast(res.msg, 'success');
      loadSummary();
      var detailModal = document.getElementById('unitDetailModal');
      if (detailModal.classList.contains('show')) unitDetailModal.hide();
    });
  });
}

function voidPayment(id, invoiceNo) {
  confirmDelete('Void payment ' + (invoiceNo || '#' + id) + '? It will be excluded from totals but kept on record. Admins only.', function() {
    apiPost('api_payment.php', {action: 'void_payment', id: id}, function(err, res) {
      if (err || !res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
      showToast(res.msg, 'success');
      loadSummary();
      var udm = document.getElementById('unitDetailModal');
      if (udm && udm.classList.contains('show') && currentUnitDetail.id) {
        viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
      }
    });
  });
}

function restorePayment(id) {
  apiPost('api_payment.php', {action: 'restore_payment', id: id}, function(err, res) {
    if (err || !res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
    showToast(res.msg, 'success');
    loadSummary();
    var udm = document.getElementById('unitDetailModal');
    if (udm && udm.classList.contains('show') && currentUnitDetail.id) {
      viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
    }
  });
}

function editPayment(id) {
  apiPost('api_payment.php', {action: 'get_payment', id: id}, function(err, res) {
    if (!res || !res.success) return showToast('Failed to load payment.', 'error');
    var p = res.payment;

    document.getElementById('payModalTitle').innerHTML = '<i class="fa-solid fa-pen me-2"></i>Edit Payment';
    document.getElementById('payId').value          = p.id;
    // Edits are naturally idempotent (UPDATE by id); no key needed.
    document.getElementById('payIdempotencyKey').value = '';
    document.getElementById('payUnit').value        = p.unit_id;
    document.getElementById('payType').value        = p.payment_type;
    document.getElementById('payAmount').value      = p.amount;
    document.getElementById('payDate').value        = p.payment_date;
    document.getElementById('payDue').value         = p.due_date || '';
    document.getElementById('payPeriodMonth').value = p.period_month;
    document.getElementById('payPeriodYear').value  = p.period_year;
    document.getElementById('payNotes').value       = p.notes || '';
    document.getElementById('payMsg').style.display = 'none';
    document.getElementById('prorateHint').style.display = 'none';

    onPayTypeChange(p.payment_type);
    if (p.payment_type === 'service') {
      document.getElementById('payService').value = p.service_type_id || '';
    }

    // Show unit info bar from existing option data
    var sel = document.getElementById('payUnit');
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.rate) {
      var rate   = parseFloat(opt.dataset.rate) || 0;
      var dueDay = opt.dataset.due || '5';
      var tenant = opt.dataset.tenant || '';
      var infoText = 'Monthly Rate: ' + fmt(rate) + ' \xb7 Due: ' + dueDay + 'th of each month';
      if (tenant) infoText += ' \xb7 Tenant: ' + tenant;
      document.getElementById('unitInfoBar').style.display = '';
      document.getElementById('unitInfoText').textContent  = infoText;
    }

    // Load tenant dropdown then select the payment's tenant
    apiPost('api_payment.php', {action: 'get_unit_tenants', unit_id: p.unit_id}, function(err2, res2) {
      var sel2 = document.getElementById('payTenant');
      sel2.innerHTML = '<option value="">— Select tenant —</option>';
      if (res2 && res2.success && res2.tenants.length) {
        res2.tenants.forEach(function(t) {
          var o = document.createElement('option');
          o.value = t.id; o.textContent = t.full_name;
          sel2.appendChild(o);
        });
      }
      if (p.tenant_id) sel2.value = p.tenant_id;
    });

    payModal.show();
  });
}

// ── Bulk select / delete (admin only) ────────────────────────
function toggleAllPayments(el) {
  document.querySelectorAll('.pay-chk').forEach(function(cb) { cb.checked = el.checked; });
  updatePayBulkBar();
}

function updatePayBulkBar() {
  var checked = document.querySelectorAll('.pay-chk:checked');
  var bar = document.getElementById('payBulkBar');
  if (!bar) return;
  if (checked.length > 0) {
    document.getElementById('payBulkCount').textContent = checked.length + ' selected';
    bar.style.display = 'flex';
  } else {
    bar.style.display = 'none';
  }
}

function clearPaySelection() {
  document.querySelectorAll('.pay-chk').forEach(function(cb) { cb.checked = false; });
  var sa = document.getElementById('paySelectAll');
  if (sa) sa.checked = false;
  updatePayBulkBar();
}

function filterPaymentDates() {
  var from = (document.getElementById('payDateFrom') || {}).value || '';
  var to   = (document.getElementById('payDateTo')   || {}).value || '';
  var table = document.getElementById('payDetailTable');
  if (!table) return;
  var rows = table.querySelectorAll('tbody tr');
  rows.forEach(function(row) {
    if (!from && !to) { row.style.display = ''; return; }
    var dateCell = row.cells[IS_ADMIN ? 1 : 0];
    if (!dateCell) { row.style.display = ''; return; }
    var d = dateCell.textContent.trim();
    var show = (!from || d >= from) && (!to || d <= to);
    row.style.display = show ? '' : 'none';
  });
}

function clearPayDateFilter() {
  var f = document.getElementById('payDateFrom');
  var t = document.getElementById('payDateTo');
  if (f) f.value = '';
  if (t) t.value = '';
  filterPaymentDates();
}

function bulkDeletePayments() {
  var ids = Array.from(document.querySelectorAll('.pay-chk:checked')).map(function(cb) { return parseInt(cb.value); });
  if (!ids.length) return;
  confirmDelete('Delete ' + ids.length + ' payment(s)? Their cash records will also be removed. This cannot be undone.', function() {
    apiPost('api_payment.php', {action: 'bulk_delete_payments', ids: JSON.stringify(ids)}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Bulk delete failed.', 'error'); return; }
      showToast(res.msg, 'success');
      loadSummary();
      if (currentUnitDetail.id) {
        viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
      }
    });
  });
}

function openChargeModal(unitId) {
  document.getElementById('chgUnitId').value      = unitId;
  document.getElementById('chgServiceType').value = '';
  document.getElementById('chgAmount').value      = '';
  document.getElementById('chgDate').value        = new Date().toISOString().split('T')[0];
  document.getElementById('chgMonth').value       = document.getElementById('selMonth').value;
  document.getElementById('chgYear').value        = document.getElementById('selYear').value;
  document.getElementById('chgDescription').value = '';
  document.getElementById('chgMsg').style.display = 'none';
  chargeModal.show();
}

function onChgServiceChange() {
  var sel = document.getElementById('chgServiceType');
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.amount && parseFloat(opt.dataset.amount) > 0) {
    document.getElementById('chgAmount').value = parseFloat(opt.dataset.amount).toFixed(2);
  }
}

function saveCharge() {
  var data = {
    action: 'save_charge',
    unit_id: document.getElementById('chgUnitId').value,
    service_type_id: document.getElementById('chgServiceType').value,
    amount: document.getElementById('chgAmount').value,
    charge_date: document.getElementById('chgDate').value,
    period_month: document.getElementById('chgMonth').value,
    period_year: document.getElementById('chgYear').value,
    description: document.getElementById('chgDescription').value
  };
  var msgEl = document.getElementById('chgMsg');
  apiPost('api_payment.php', data, function(err, res) {
    if (err || !res || !res.success) {
      msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = (res&&res.error)||'Failed.'; msgEl.style.display = '';
      return;
    }
    showToast(res.msg, 'success');
    chargeModal.hide();
    loadSummary();
    if (currentUnitDetail.id) {
      viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
      unitDetailModal.show();
    }
  });
}

function collectCharge(chargeId, unitId, serviceTypeId, amount, month, year) {
  unitDetailModal.hide();
  setTimeout(function() {
    openPaymentModal();
    setTimeout(function() {
      document.getElementById('payUnit').value = unitId;
      onUnitChange(unitId);
      setTimeout(function() {
        document.getElementById('payType').value = 'service';
        onPayTypeChange('service');
        if (serviceTypeId) document.getElementById('payService').value = serviceTypeId;
        document.getElementById('payAmount').value       = parseFloat(amount).toFixed(2);
        document.getElementById('payPeriodMonth').value  = month;
        document.getElementById('payPeriodYear').value   = year;
      }, 250);
    }, 100);
  }, 300);
}

function deleteCharge(chargeId) {
  confirmDelete('Delete this outstanding service charge?', function() {
    apiPost('api_payment.php', {action: 'delete_charge', id: chargeId}, function(err, res) {
      if (err || !res || !res.success) { showToast((res&&res.error)||'Failed.','error'); return; }
      showToast(res.msg, 'success');
      loadSummary();
      if (currentUnitDetail.id) {
        viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
        unitDetailModal.show();
      }
    });
  });
}

function openRefundModal(paymentId, invoiceNo, amount, alreadyRefunded) {
  alreadyRefunded = alreadyRefunded || 0;
  var maxRefund = amount - alreadyRefunded;
  document.getElementById('refPaymentId').value = paymentId;
  // invoice_no is server-generated (INV-YYYY-NNNNN) but defence-in-depth:
  // esc() any value we pull from a data-* attribute back into innerHTML.
  document.getElementById('refPaymentInfo').innerHTML =
    '<strong>' + esc(invoiceNo) + '</strong> &nbsp;·&nbsp; Original: <strong>' + fmt(amount) + '</strong>' +
    (alreadyRefunded > 0 ? ' &nbsp;·&nbsp; Already refunded: <strong>' + fmt(alreadyRefunded) + '</strong>' : '');
  document.getElementById('refAmount').value = maxRefund.toFixed(2);
  document.getElementById('refAmount').max   = maxRefund.toFixed(2);
  document.getElementById('refMaxHint').textContent = 'Max refundable: ₱' + fmt(maxRefund);
  document.getElementById('refReason').value = '';
  document.getElementById('refMsg').style.display = 'none';
  refundModal.show();
}

function processRefund() {
  var paymentId = document.getElementById('refPaymentId').value;
  var amount    = parseFloat(document.getElementById('refAmount').value);
  var reason    = document.getElementById('refReason').value.trim();
  var msgEl     = document.getElementById('refMsg');

  if (!amount || amount <= 0) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Enter a valid amount.'; msgEl.style.display = ''; return;
  }
  if (!reason) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Reason is required.'; msgEl.style.display = ''; return;
  }

  apiPost('api_payment.php', {action: 'process_refund', payment_id: paymentId, amount: amount, reason: reason}, function(err, res) {
    if (err || !res || !res.success) {
      msgEl.className = 'alert alert-danger mt-2';
      msgEl.textContent = (res && res.error) ? res.error : (err || 'Failed.');
      msgEl.style.display = '';
      return;
    }
    showToast(res.msg, 'success');
    refundModal.hide();
    loadSummary();
    if (currentUnitDetail.id) {
      viewUnitPayments(currentUnitDetail.id, currentUnitDetail.name, currentUnitDetail.month, currentUnitDetail.year);
      unitDetailModal.show();
    }
  });
}
</script>

<?php include '../includes/footer.php'; ?>
