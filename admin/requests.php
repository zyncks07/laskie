<?php
// admin/requests.php — Admin review of vault cash requests.
// Approve (auto-issues the vault_return, crediting the requester's cash-on-hand)
// or reject. See api/requests_api.php for the actions.
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Cash Requests';
$depth = '../';

// Guarded so the page renders an actionable hint instead of a 500 if migration
// 009 hasn't been applied to this database yet.
$requests   = [];
$tableReady = true;
try {
    $requests = $pdo->query("
        SELECT vr.*, ru.full_name AS requester_name, rv.full_name AS reviewer_name, p.invoice_no AS ref_invoice
        FROM vault_requests vr
        LEFT JOIN users ru   ON vr.requested_by = ru.id
        LEFT JOIN users rv   ON vr.reviewed_by  = rv.id
        LEFT JOIN payments p ON vr.reference_payment_id = p.id
        ORDER BY vr.created_at DESC
    ")->fetchAll();
} catch (Throwable $e) {
    $tableReady = false;
}

$typeLabel = ['refund_fund' => 'Refund', 'expense_fund' => 'Expense', 'other' => 'Other'];
$statusPill = [
    'pending'   => 'attn-pill',
    'approved'  => 'ok-pill',
    'rejected'  => 'muted-pill',
    'cancelled' => 'muted-pill',
];

logActivity($pdo, 'VIEW_REQUESTS', 'VaultRequest', 'Viewed cash requests');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-hand-holding-dollar me-2 text-primary-custom"></i>Cash Requests</h1>
  <div class="text-muted" style="font-size:12.5px">Vault → user cash requests. Approving issues the return and tops up the requester's cash on hand.</div>
</div>

<?php if (!$tableReady): ?>
  <div class="empty-state">
    <i class="fa-solid fa-database"></i>
    <p>The <code>vault_requests</code> table doesn't exist yet. Apply <code>migrations/009_add_vault_requests_notifications.sql</code> to enable this feature.</p>
  </div>
<?php else: ?>
<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table id="reqTable" class="table table-hover" style="width:100%">
        <thead>
          <tr>
            <th>Date</th><th>Requester</th><th>Type</th><th class="text-end">Amount</th>
            <th>Purpose</th><th>Status</th><th>Reviewed by</th><th class="no-print text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
          <tr>
            <td data-order="<?= clean($r['created_at']) ?>"><?= fmtDate($r['created_at']) ?></td>
            <td><?= clean($r['requester_name'] ?? '—') ?></td>
            <td><?= clean($typeLabel[$r['request_type']] ?? ucfirst($r['request_type'])) ?></td>
            <td class="text-end num" data-order="<?= (float)$r['amount'] ?>"><?= money($r['amount']) ?></td>
            <td><span class="cell-trunc-lg"><?= clean($r['purpose']) ?><?= $r['ref_invoice'] ? ' · ' . clean($r['ref_invoice']) : '' ?></span></td>
            <td><span class="<?= $statusPill[$r['status']] ?? 'muted-pill' ?>"><?= ucfirst(clean($r['status'])) ?></span>
                <?php if ($r['status'] === 'rejected' && $r['decision_note']): ?><div class="text-muted" style="font-size:10.5px"><?= clean($r['decision_note']) ?></div><?php endif; ?></td>
            <td class="text-muted" style="font-size:12px"><?= clean($r['reviewer_name'] ?? '—') ?></td>
            <td class="no-print text-center">
              <?php if ($r['status'] === 'pending'): ?>
                <button class="btn btn-sm btn-success" data-id="<?= (int)$r['id'] ?>" onclick="approveReq(+this.dataset.id)"><i class="fa-solid fa-check"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-id="<?= (int)$r['id'] ?>" onclick="openReject(+this.dataset.id)"><i class="fa-solid fa-xmark"></i></button>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-xmark me-2 text-danger"></i>Reject Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rejectId">
        <label class="form-label">Reason (optional)</label>
        <textarea class="form-control" id="rejectNote" rows="2" placeholder="Why is this being rejected?"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" onclick="submitReject()"><i class="fa-solid fa-xmark me-1"></i>Reject</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $extraJs = <<<'JS'
<script>
var rejectModal = null;
$(document).ready(function () {
  if ($('#reqTable').length) {
    $('#reqTable').DataTable({
      pageLength: 25, order: [[0, 'desc']],
      columnDefs: [{orderable: false, targets: 7}],
      dom: '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rtip',
      language: {search: 'Search:', lengthMenu: 'Show _MENU_', info: '_START_–_END_ of _TOTAL_'}
    });
  }
  var el = document.getElementById('rejectModal');
  if (el) rejectModal = new bootstrap.Modal(el);
});

function approveReq(id) {
  confirmDelete('Approve this request? This issues a vault return and adds the cash to the requester’s on-hand balance.', function () {
    apiPost('../api/requests_api.php', {action: 'approve_request', id: id}, function (err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
      showToast(res.msg, 'success');
      setTimeout(function () { location.reload(); }, 700);
    });
  });
}

function openReject(id) {
  document.getElementById('rejectId').value = id;
  document.getElementById('rejectNote').value = '';
  rejectModal.show();
}

function submitReject() {
  var id = document.getElementById('rejectId').value;
  apiPost('../api/requests_api.php', {action: 'reject_request', id: id, decision_note: document.getElementById('rejectNote').value}, function (err, res) {
    if (!res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
    rejectModal.hide();
    showToast(res.msg, 'success');
    setTimeout(function () { location.reload(); }, 700);
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
