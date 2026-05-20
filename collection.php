<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
$pageTitle = 'Payment Collection';
$depth = '../';

$units        = $pdo->query("SELECT ru.id, ru.unit_name, ru.monthly_rate, ru.due_day, ru.status, t.full_name as tenant_name FROM rental_units ru LEFT JOIN tenants t ON t.unit_id=ru.id AND t.status='active' ORDER BY ru.unit_name")->fetchAll();
$serviceTypes = $pdo->query("SELECT id, name, default_amount FROM service_types WHERE is_active=1 ORDER BY name")->fetchAll();
$curMonth     = (int)date('n');
$curYear      = (int)date('Y');
$years        = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
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

<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="fw-600" style="font-size:13px;color:var(--text-secondary)"><i class="fa-solid fa-calendar me-1"></i>Period:</span>
      <select id="selMonth" class="form-select form-select-sm" style="width:140px">
        <?php for($m=1;$m<=12;$m++): ?>
        <option value="<?=$m?>" <?=$m===$curMonth?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
        <?php endfor; ?>
      </select>
      <select id="selYear" class="form-select form-select-sm" style="width:90px">
        <?php foreach($years as $y): ?>
        <option value="<?=$y?>" <?=$y===$curYear?'selected':''?>><?=$y?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary" onclick="loadSummary()"><i class="fa-solid fa-rotate me-1"></i>Refresh</button>
      <div class="ms-auto d-flex align-items-center gap-3">
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot green"></span> Paid</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot amber"></span> Partial</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot red"></span> Overdue</span>
        <span class="d-flex align-items-center gap-1" style="font-size:12px"><span class="status-dot gray"></span> Vacant</span>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <span class="card-header-title" id="summaryTitle"><i class="fa-solid fa-table me-2"></i>Monthly Collection Summary</span>
    <div class="d-flex gap-2">
      <span id="summaryBadgeGreen" class="badge bg-success">— Paid</span>
      <span id="summaryBadgeAmber" class="badge bg-warning text-dark">— Partial</span>
      <span id="summaryBadgeRed"   class="badge bg-danger">— Overdue</span>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th style="width:36px">St.</th><th>Unit</th><th>Tenant</th>
        <th class="text-end">Monthly Rate</th><th class="text-end">Rent Paid</th>
        <th class="text-end">Services</th><th class="text-end">Total Paid</th>
        <th class="text-end">Balance</th><th>Last Cashier</th>
        <th class="text-center no-print">Actions</th>
      </tr></thead>
      <tbody id="summaryBody">
        <tr><td colspan="10" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>
      </tbody>
      <tfoot id="summaryFoot"></tfoot>
    </table>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-money-bill-wave me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="payId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Rental Unit *</label>
            <select class="form-select" id="payUnit" onchange="onUnitChange(this.value)">
              <option value="">— Select unit —</option>
              <?php foreach($units as $u): ?>
              <option value="<?=$u['id']?>" data-rate="<?=$u['monthly_rate']?>" data-due="<?=$u['due_day']?>" data-tenant="<?=clean($u['tenant_name']??'')?>">
                <?=clean($u['unit_name'])?><?=$u['tenant_name']?' — '.clean($u['tenant_name']):''?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Tenant</label>
            <select class="form-select" id="payTenant"><option value="">— Auto from unit —</option></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Type *</label>
            <select class="form-select" id="payType" onchange="onPayTypeChange(this.value)">
              <option value="rent">Rental Payment</option>
              <option value="service">Service / Fee</option>
            </select>
          </div>
          <div class="col-md-8" id="serviceRow" style="display:none">
            <label class="form-label">Service Type</label>
            <select class="form-select" id="payService" onchange="onServiceChange(this.value)">
              <option value="">— Select service —</option>
              <?php foreach($serviceTypes as $s): ?>
              <option value="<?=$s['id']?>" data-amount="<?=$s['default_amount']?>"><?=clean($s['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Amount (&#8369;) *</label>
            <input type="number" step="0.01" min="0" class="form-control" id="payAmount" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Date *</label>
            <input type="date" class="form-control" id="payDate" value="<?=date('Y-m-d')?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Due Date</label>
            <input type="date" class="form-control" id="payDue">
          </div>
          <div class="col-md-4">
            <label class="form-label">Period Month *</label>
            <select class="form-select" id="payPeriodMonth">
              <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?=$m?>" <?=$m===$curMonth?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Period Year *</label>
            <select class="form-select" id="payPeriodYear">
              <?php foreach($years as $y): ?><option value="<?=$y?>" <?=$y===$curYear?'selected':''?>><?=$y?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Notes</label>
            <input type="text" class="form-control" id="payNotes" placeholder="Reference, remarks...">
          </div>
        </div>
        <div id="unitInfoBar" class="mt-3" style="display:none">
          <div class="alert alert-info py-2 mb-0" style="font-size:12.5px">
            <i class="fa-solid fa-circle-info me-1"></i><span id="unitInfoText"></span>
          </div>
        </div>
        <div id="payMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-outline-primary btn-sm" onclick="saveAndPrint()"><i class="fa-solid fa-print me-1"></i>Save &amp; Print</button>
        <button class="btn btn-primary btn-sm" onclick="savePayment(false)"><i class="fa-solid fa-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Unit Detail Modal -->
<div class="modal fade" id="unitDetailModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
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

<script>
var payModal = null;
var unitDetailModal = null;

document.addEventListener('DOMContentLoaded', function() {
  payModal        = new bootstrap.Modal(document.getElementById('paymentModal'));
  unitDetailModal = new bootstrap.Modal(document.getElementById('unitDetailModal'));
  loadSummary();
  document.getElementById('selMonth').addEventListener('change', loadSummary);
  document.getElementById('selYear').addEventListener('change', loadSummary);
});

function loadSummary() {
  var month     = document.getElementById('selMonth').value;
  var year      = document.getElementById('selYear').value;
  var monthName = document.getElementById('selMonth').options[parseInt(month)-1].text;
  document.getElementById('summaryTitle').innerHTML = '<i class="fa-solid fa-table me-2"></i>Monthly Collection Summary &mdash; ' + monthName + ' ' + year;
  document.getElementById('summaryBody').innerHTML  = '<tr><td colspan="10" class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Loading...</td></tr>';
  document.getElementById('summaryFoot').innerHTML  = '';

  apiPost('api_payment.php', {action:'monthly_summary', month:month, year:year}, function(err, res) {
    if (!res || !res.success) {
      document.getElementById('summaryBody').innerHTML = '<tr><td colspan="10" class="text-center text-danger py-3">Failed to load summary.</td></tr>';
      return;
    }
    var html = '';
    var totRate=0, totRentPaid=0, totSvc=0, totTotal=0, totBal=0, countG=0, countA=0, countR=0;

    for (var i = 0; i < res.summary.length; i++) {
      var r      = res.summary[i];
      var rate   = parseFloat(r.monthly_rate)||0;
      var rentPd = parseFloat(r.rent_paid)||0;
      var svcPd  = parseFloat(r.service_paid)||0;
      var totPd  = parseFloat(r.total_paid)||0;
      var bal    = parseFloat(r.balance)||0;
      totRate += rate; totRentPaid += rentPd; totSvc += svcPd; totTotal += totPd; totBal += bal;

      var dotClass = r.pay_status || 'gray';
      if (dotClass === 'green') countG++;
      else if (dotClass === 'amber') countA++;
      else if (dotClass === 'red') countR++;

      var dot         = '<span class="status-dot ' + dotClass + '"></span>';
      var statusBadge = r.status === 'occupied' ? '<span class="badge badge-occupied">Occupied</span>' : '<span class="badge badge-vacant">Vacant</span>';
      var balCell     = bal > 0 ? '<span style="color:var(--danger);font-weight:600">' + fmt(bal) + '</span>' : '<span style="color:var(--success)">&#8212;</span>';
      var uid         = parseInt(r.id);

      html += '<tr>';
      html += '<td>' + dot + '</td>';
      html += '<td><div class="fw-600">' + r.unit_name + '</div>' + statusBadge + '</td>';
      html += '<td>' + (r.tenant_name || '<span class="text-muted">&#8212;</span>') + '</td>';
      html += '<td class="text-end">' + fmt(rate) + '</td>';
      html += '<td class="text-end">' + (rentPd > 0 ? '<span style="color:var(--success)">' + fmt(rentPd) + '</span>' : '<span class="text-muted">&#8212;</span>') + '</td>';
      html += '<td class="text-end">' + (svcPd  > 0 ? fmt(svcPd) : '<span class="text-muted">&#8212;</span>') + '</td>';
      html += '<td class="text-end fw-600">' + (totPd > 0 ? fmt(totPd) : '<span class="text-muted">&#8212;</span>') + '</td>';
      html += '<td class="text-end">' + balCell + '</td>';
      html += '<td style="font-size:12px">' + (r.last_cashier || '<span class="text-muted">&#8212;</span>') + '</td>';
      html += '<td class="text-center no-print">';
      html += '<button class="btn-icon" title="View" onclick="viewUnitPayments(' + uid + ',\'' + r.unit_name.replace(/\'/g,"\\'") + '\',' + month + ',' + year + ')"><i class="fa-solid fa-eye fa-xs"></i></button> ';
      html += '<button class="btn-icon" title="Add" onclick="openPaymentForUnit(' + uid + ')"><i class="fa-solid fa-plus fa-xs"></i></button>';
      html += '</td></tr>';
    }

    if (!html) html = '<tr><td colspan="10" class="text-center py-4 text-muted">No units found.</td></tr>';
    document.getElementById('summaryBody').innerHTML = html;
    document.getElementById('summaryFoot').innerHTML =
      '<tr style="background:#f0f4ff;font-weight:700;">' +
      '<td></td><td colspan="2">TOTAL</td>' +
      '<td class="text-end">' + fmt(totRate) + '</td>' +
      '<td class="text-end">' + fmt(totRentPaid) + '</td>' +
      '<td class="text-end">' + fmt(totSvc) + '</td>' +
      '<td class="text-end">' + fmt(totTotal) + '</td>' +
      '<td class="text-end" style="color:' + (totBal>0?'var(--danger)':'var(--success)') + '">' + (totBal>0?fmt(totBal):'Fully Paid') + '</td>' +
      '<td colspan="2"></td></tr>';

    document.getElementById('summaryBadgeGreen').textContent = countG + ' Paid';
    document.getElementById('summaryBadgeAmber').textContent = countA + ' Partial';
    document.getElementById('summaryBadgeRed').textContent   = countR + ' Overdue';
  });
}

function openPaymentModal() {
  if (!payModal) return;
  document.getElementById('payId').value       = '';
  document.getElementById('payUnit').value     = '';
  document.getElementById('payTenant').innerHTML = '<option value="">&#8212; Auto from unit &#8212;</option>';
  document.getElementById('payType').value     = 'rent';
  document.getElementById('payService').value  = '';
  document.getElementById('payAmount').value   = '';
  document.getElementById('payDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('payDue').value      = '';
  document.getElementById('payNotes').value    = '';
  document.getElementById('payMsg').style.display      = 'none';
  document.getElementById('unitInfoBar').style.display = 'none';
  document.getElementById('serviceRow').style.display  = 'none';
  document.getElementById('payPeriodMonth').value = document.getElementById('selMonth').value;
  document.getElementById('payPeriodYear').value  = document.getElementById('selYear').value;
  payModal.show();
}

function openPaymentForUnit(unitId) {
  openPaymentModal();
  setTimeout(function() {
    document.getElementById('payUnit').value = unitId;
    onUnitChange(unitId);
  }, 200);
}

function onUnitChange(unitId) {
  var sel = document.getElementById('payUnit');
  var opt = sel.options[sel.selectedIndex];
  if (!unitId) { document.getElementById('unitInfoBar').style.display = 'none'; return; }
  var rate   = parseFloat(opt.dataset.rate) || 0;
  var dueDay = opt.dataset.due || 5;
  var tenant = opt.dataset.tenant || '';
  document.getElementById('unitInfoBar').style.display = '';
  document.getElementById('unitInfoText').textContent  = 'Monthly Rate: ' + fmt(rate) + '  Due: ' + dueDay + 'th' + (tenant ? '  Tenant: ' + tenant : '');
  if (document.getElementById('payType').value === 'rent' && rate > 0) {
    document.getElementById('payAmount').value = rate.toFixed(2);
  }
  apiPost('api_payment.php', {action:'get_unit_tenants', unit_id:unitId}, function(err, res) {
    var sel2 = document.getElementById('payTenant');
    sel2.innerHTML = '<option value="">&#8212; Select tenant &#8212;</option>';
    if (res && res.success && res.tenants.length) {
      res.tenants.forEach(function(t) {
        var o = document.createElement('option');
        o.value = t.id; o.textContent = t.full_name;
        sel2.appendChild(o);
      });
      if (res.tenants.length === 1) sel2.value = res.tenants[0].id;
    }
  });
}

function onPayTypeChange(type) {
  document.getElementById('serviceRow').style.display = (type === 'service') ? '' : 'none';
  if (type === 'rent') {
    var opt = document.getElementById('payUnit').options[document.getElementById('payUnit').selectedIndex];
    if (opt && opt.dataset.rate) document.getElementById('payAmount').value = parseFloat(opt.dataset.rate).toFixed(2);
    document.getElementById('payService').value = '';
  } else {
    document.getElementById('payAmount').value = '';
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
  var data = {
    action:          'save_payment',
    id:              document.getElementById('payId').value,
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
    if (!res || !res.success) {
      var el = document.getElementById('payMsg');
      el.style.display = ''; el.className = 'alert alert-danger';
      el.textContent = (res && res.error) ? res.error : 'Failed to save payment.';
      return;
    }
    showToast(res.msg, 'success');
    payModal.hide();
    loadSummary();
    if (andPrint && res.id) window.open('invoice_print.php?id=' + res.id, '_blank');
  });
}

function saveAndPrint() { savePayment(true); }

function viewUnitPayments(unitId, unitName, month, year) {
  document.getElementById('unitDetailTitle').textContent = 'Payments for ' + unitName;
  document.getElementById('unitDetailBody').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>';
  unitDetailModal.show();
  apiPost('api_payment.php', {action:'get_unit_payments', unit_id:unitId, month:month, year:year}, function(err, res) {
    if (!res || !res.success) {
      document.getElementById('unitDetailBody').innerHTML = '<p class="text-danger p-3">Failed to load.</p>';
      return;
    }
    var pays = res.payments || [];
    var unit = res.unit;
    var html = '';
    if (unit) {
      html += '<div class="alert alert-info py-2 mb-3" style="font-size:12.5px"><strong>' + unit.unit_name + '</strong>';
      html += ' &nbsp;&middot;&nbsp; Tenant: <strong>' + (unit.tenant_name || 'Vacant') + '</strong>';
      html += ' &nbsp;&middot;&nbsp; Rate: <strong>' + fmt(unit.monthly_rate) + '</strong></div>';
    }
    if (!pays.length) {
      html += '<div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>No payments recorded for this period.</p></div>';
    } else {
      var totPaid = 0;
      html += '<div class="table-responsive"><table class="table"><thead><tr>';
      html += '<th>Date</th><th>Invoice</th><th>Type</th><th>Notes</th>';
      html += '<th class="text-end">Amount</th><th>Cashier</th><th class="text-center">Actions</th>';
      html += '</tr></thead><tbody>';
      for (var i = 0; i < pays.length; i++) {
        var p = pays[i];
        totPaid += parseFloat(p.amount) || 0;
        var badge = p.payment_type === 'rent'
          ? '<span class="badge badge-rent">Rent</span>'
          : '<span class="badge badge-service">' + (p.service_name || 'Service') + '</span>';
        html += '<tr>';
        html += '<td style="font-size:12.5px;white-space:nowrap">' + p.payment_date + '</td>';
        html += '<td class="mono" style="font-size:12px">' + (p.invoice_no || '&#8212;') + '</td>';
        html += '<td>' + badge + '</td>';
        html += '<td style="font-size:12.5px">' + (p.notes || '&#8212;') + '</td>';
        html += '<td class="text-end fw-600">' + fmt(p.amount) + '</td>';
        html += '<td style="font-size:12px">' + (p.cashier_name || '&#8212;') + '</td>';
        html += '<td class="text-center">';
        html += '<a href="invoice_print.php?id=' + p.id + '" target="_blank" class="btn-icon" title="Print"><i class="fa-solid fa-print fa-xs"></i></a> ';
        html += '<button class="btn-icon danger" onclick="deletePayment(' + parseInt(p.id) + ')"><i class="fa-solid fa-trash fa-xs"></i></button>';
        html += '</td></tr>';
      }
      html += '</tbody><tfoot><tr style="background:#f9fafb;font-weight:700">';
      html += '<td colspan="4">Total Paid</td><td class="text-end">' + fmt(totPaid) + '</td><td colspan="2"></td>';
      html += '</tr></tfoot></table></div>';
    }
    html += '<div class="mt-3 text-end"><button class="btn btn-primary btn-sm" onclick="openPaymentForUnit(' + parseInt(unitId) + ');unitDetailModal.hide()"><i class="fa-solid fa-plus me-1"></i>Record Payment</button></div>';
    document.getElementById('unitDetailBody').innerHTML = html;
  });
}

function deletePayment(id) {
  confirmDelete('Delete this payment? The cash record will also be removed. Admins only.', function() {
    apiPost('api_payment.php', {action:'delete_payment', id:id}, function(err, res) {
      if (!res || !res.success) return showToast((res && res.error) || 'Failed.', 'error');
      showToast(res.msg, 'success');
      loadSummary();
      unitDetailModal.hide();
    });
  });
}
</script>
<?php include '../includes/footer.php'; ?>
