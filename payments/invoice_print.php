<?php
// payments/invoice_print.php — Printable Payment Invoice
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('<p style="font-family:sans-serif;padding:2rem;color:red;">Invalid invoice ID.</p>');

$stmt = $pdo->prepare("
    SELECT p.*,
           ru.unit_name, ru.due_day,
           t.full_name  AS tenant_name, t.phone AS tenant_phone, t.address AS tenant_address,
           st.name      AS service_name,
           u.full_name  AS cashier_name, u.role AS cashier_role,
           COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id = p.id), 0) AS refunded_total
    FROM payments p
    LEFT JOIN rental_units ru ON p.unit_id    = ru.id
    LEFT JOIN tenants t       ON p.tenant_id  = t.id
    LEFT JOIN service_types st ON p.service_type_id = st.id
    LEFT JOIN users u          ON p.received_by = u.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$pay = $stmt->fetch();
if (!$pay) die('<p style="font-family:sans-serif;padding:2rem;color:red;">Payment not found.</p>');

// Compute the receipt status banner — never claim a payment is paid when it is
// voided, refunded, or moved to trash. Admins can still open the page for
// reference, but the document must reflect reality so it can't be passed off
// as a valid receipt.
$isDeleted = !empty($pay['deleted_at']);
$payStatus = $pay['status'] ?? 'paid';
$netPaid   = money_sub((string)$pay['amount'], (string)$pay['refunded_total']);
if ($isDeleted) {
    $bannerLabel = 'DELETED — NOT A VALID RECEIPT';
    $bannerKind  = 'danger';
} elseif ($payStatus === 'voided') {
    $bannerLabel = 'VOIDED — NOT A VALID RECEIPT';
    $bannerKind  = 'danger';
} elseif ($payStatus === 'refunded') {
    $bannerLabel = 'FULLY REFUNDED — NOT A VALID RECEIPT';
    $bannerKind  = 'danger';
} elseif ($payStatus === 'partially_refunded') {
    $bannerLabel = 'PARTIALLY REFUNDED — NET ' . money($netPaid);
    $bannerKind  = 'warning';
} else {
    $bannerLabel = 'PAYMENT RECEIVED — PAID IN FULL';
    $bannerKind  = 'success';
}
// Restrict casual access to invalid receipts to admin only; staff/accountant
// must not be able to print a void/deleted/refunded invoice.
if ($bannerKind === 'danger' && !isAdmin()) {
    die('<p style="font-family:sans-serif;padding:2rem;color:#0a0a0a;">This payment is no longer valid and the receipt cannot be displayed.</p>');
}

// Company settings
$companyName    = getSetting($pdo, 'company_name',    'Laskie Rental Properties');
$companyAddress = getSetting($pdo, 'company_address', '');
$companyPhone   = getSetting($pdo, 'company_phone',   '');
$companyEmail   = getSetting($pdo, 'company_email',   '');
$appName        = getSetting($pdo, 'app_name',        'Laskie Rental Property Management System');

// Payment description
$description = $pay['payment_type'] === 'rent'
    ? 'Rental Payment — ' . date('F Y', mktime(0,0,0,$pay['period_month'],1,$pay['period_year']))
    : ($pay['service_name'] ?? 'Service Fee') . ' — ' . date('F Y', mktime(0,0,0,$pay['period_month'],1,$pay['period_year']));

logActivity($pdo, 'PRINT_INVOICE', 'Payments', "Printed invoice {$pay['invoice_no']} (payment #{$id})");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= clean($pay['invoice_no']) ?> — <?= clean($companyName) ?></title>
<link href="../assets/vendor/google-fonts.css" rel="stylesheet">
<style>
  :root {
    --primary: #0a0a0a;
    --primary-light: #f4f4f4;
    --text: #0a0a0a;
    --muted: #737373;
    --border: #e4e4e4;
    --bg: #fafafa;
    --success: #0a0a0a;
    --success-bg: #f4f4f4;
  }
  * { box-sizing: border-box; margin: 0; }
  body {
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    background: var(--bg);
    color: var(--text);
    padding: 24px;
    -webkit-font-smoothing: antialiased;
  }
  .page-actions {
    max-width: 620px; margin: 0 auto 16px;
    display: flex; gap: 10px; justify-content: flex-end;
  }
  .btn { padding: 7px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid; font-family: inherit; }
  .btn-primary   { background: var(--primary); color: #ffffff; border-color: var(--primary); }
  .btn-secondary { background: #ffffff; color: var(--text); border-color: var(--border); }
  .btn-secondary:hover { background: var(--bg); }

  /* ── Invoice ───────────────────────────────────────────── */
  .invoice {
    max-width: 620px; margin: 0 auto;
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
  }

  /* Header band */
  .inv-header {
    background: var(--primary);
    color: #ffffff;
    padding: 28px 32px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
  }
  .inv-company-name { font-size: 20px; font-weight: 800; letter-spacing: -.3px; }
  .inv-company-sub  { font-size: 11.5px; opacity: .75; margin-top: 3px; line-height: 1.5; }
  .inv-title-block  { text-align: right; }
  .inv-label        { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; opacity: .7; }
  .inv-number       { font-size: 26px; font-weight: 800; font-family: 'DM Mono', monospace; letter-spacing: -.5px; }
  .inv-date         { font-size: 12px; opacity: .85; margin-top: 4px; }

  /* Status badge */
  .inv-status-row {
    padding: 10px 32px;
    display: flex; align-items: center; gap: 10px;
  }
  .inv-status-row.kind-success { background: var(--success-bg); }
  .inv-status-row.kind-success .inv-status-dot  { background: var(--success); }
  .inv-status-row.kind-success .inv-status-text { color: var(--success); }
  .inv-status-row.kind-warning { background: #e4e4e4; }
  .inv-status-row.kind-warning .inv-status-dot  { background: #737373; }
  .inv-status-row.kind-warning .inv-status-text { color: #3f3f3f; }
  .inv-status-row.kind-danger  { background: #e4e4e4; }
  .inv-status-row.kind-danger  .inv-status-dot  { background: #0a0a0a; }
  .inv-status-row.kind-danger  .inv-status-text { color: #0a0a0a; }
  .inv-status-dot { width: 10px; height: 10px; border-radius: 50%; }
  .inv-status-text { font-size: 12.5px; font-weight: 700; }
  /* Big translucent watermark for non-valid receipts so a printed copy can't be mistaken. */
  .inv-void-mark {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
    font-size: 90px; font-weight: 900;
    color: rgba(185, 28, 28, .14);
    transform: rotate(-22deg);
    letter-spacing: .12em;
    z-index: 5;
  }

  /* Body */
  .inv-body { padding: 28px 32px; }

  /* From / To grid */
  .inv-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
  .party-label { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 700; margin-bottom: 6px; }
  .party-name  { font-size: 15px; font-weight: 700; color: var(--text); }
  .party-sub   { font-size: 12px; color: var(--muted); margin-top: 3px; line-height: 1.5; }

  /* Line items */
  .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  .inv-table thead th {
    background: #fafafa;
    padding: 9px 12px;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    text-align: left;
  }
  .inv-table thead th:last-child { text-align: right; }
  .inv-table tbody td { padding: 12px; border-bottom: 1px solid #e4e4e4; font-size: 13px; vertical-align: top; }
  .inv-table tbody td:last-child { text-align: right; font-weight: 600; }
  .inv-table tfoot td { padding: 10px 12px; }

  /* Total box */
  .inv-total-wrap { display: flex; justify-content: flex-end; margin-bottom: 24px; }
  .inv-total-box {
    background: var(--primary);
    color: #ffffff;
    border-radius: 10px;
    padding: 16px 24px;
    text-align: right;
    min-width: 200px;
  }
  .inv-total-label  { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .8; margin-bottom: 4px; }
  .inv-total-amount { font-size: 28px; font-weight: 800; letter-spacing: -.5px; font-family: 'DM Mono', monospace; }

  /* Details row */
  .inv-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; background: #fafafa; border-radius: 8px; padding: 16px; margin-bottom: 24px; }
  .inv-detail-label { font-size: 10.5px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
  .inv-detail-value { font-size: 13px; font-weight: 600; color: var(--text); }

  /* Notes */
  .inv-notes { background: #f4f4f4; border: 1px solid #e4e4e4; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
  .inv-notes-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #3f3f3f; margin-bottom: 4px; }
  .inv-notes-text  { font-size: 12.5px; color: #555555; }

  /* Cashier */
  .inv-cashier {
    border-top: 1px solid var(--border);
    padding-top: 16px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .inv-cashier-label { font-size: 11px; color: var(--muted); }
  .inv-cashier-name  { font-size: 13.5px; font-weight: 700; }
  .inv-cashier-role  { font-size: 11px; color: var(--muted); margin-top: 2px; }

  /* Footer */
  .inv-footer {
    background: #fafafa; border-top: 1px solid var(--border);
    padding: 14px 32px;
    text-align: center;
    font-size: 11px; color: var(--muted);
    line-height: 1.6;
  }

  /* Print */
  @media print {
    body { background: #fff; padding: 0; }
    .page-actions { display: none; }
    .invoice { box-shadow: none; border: none; max-width: 100%; border-radius: 0; }
    .inv-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-status-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-total-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<div class="page-actions no-print">
  <button class="btn btn-secondary" onclick="window.close()"><i>←</i> Close</button>
  <button class="btn btn-primary" onclick="window.print()">🖨 Print Invoice</button>
</div>

<div class="invoice" style="position:relative">

  <?php if ($bannerKind === 'danger'): ?>
  <div class="inv-void-mark"><?= $isDeleted ? 'DELETED' : ($payStatus === 'voided' ? 'VOID' : 'REFUNDED') ?></div>
  <?php endif; ?>

  <!-- Header -->
  <div class="inv-header">
    <div>
      <div class="inv-company-name"><?= clean($companyName) ?></div>
      <div class="inv-company-sub">
        <?php if($companyAddress): ?><?= clean($companyAddress) ?><br><?php endif; ?>
        <?php if($companyPhone): ?><?= clean($companyPhone) ?><?php endif; ?>
        <?php if($companyEmail): ?> &nbsp;·&nbsp; <?= clean($companyEmail) ?><?php endif; ?>
      </div>
    </div>
    <div class="inv-title-block">
      <div class="inv-label">Official Receipt</div>
      <div class="inv-number"><?= clean($pay['invoice_no']) ?></div>
      <div class="inv-date"><?= fmtDate($pay['payment_date'], 'F j, Y') ?></div>
    </div>
  </div>

  <!-- Status banner -->
  <div class="inv-status-row kind-<?= clean($bannerKind) ?>">
    <div class="inv-status-dot"></div>
    <div class="inv-status-text"><?= clean($bannerLabel) ?></div>
  </div>

  <div class="inv-body">

    <!-- From / To -->
    <div class="inv-parties">
      <div>
        <div class="party-label">Received by</div>
        <div class="party-name"><?= clean($pay['cashier_name'] ?? 'N/A') ?></div>
        <div class="party-sub"><?= ucfirst($pay['cashier_role'] ?? '') ?><br><?= clean($companyName) ?></div>
      </div>
      <div>
        <div class="party-label">Received from</div>
        <div class="party-name"><?= clean($pay['tenant_name'] ?? 'Tenant') ?></div>
        <?php if($pay['tenant_phone']): ?><div class="party-sub"><?= clean($pay['tenant_phone']) ?></div><?php endif; ?>
        <?php if($pay['tenant_address']): ?><div class="party-sub"><?= clean($pay['tenant_address']) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Payment Details Strip -->
    <div class="inv-details">
      <div>
        <div class="inv-detail-label">Unit</div>
        <div class="inv-detail-value"><?= clean($pay['unit_name']) ?></div>
      </div>
      <div>
        <div class="inv-detail-label">Period</div>
        <div class="inv-detail-value"><?= date('F Y', mktime(0,0,0,$pay['period_month'],1,$pay['period_year'])) ?></div>
      </div>
      <div>
        <div class="inv-detail-label">Payment Date</div>
        <div class="inv-detail-value"><?= fmtDate($pay['payment_date'], 'M j, Y') ?></div>
      </div>
    </div>

    <!-- Line Items -->
    <table class="inv-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Description</th>
          <th>Type</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>1</td>
          <td>
            <strong><?= clean($description) ?></strong>
            <?php if($pay['notes']): ?><br><small style="color:var(--muted)"><?= clean($pay['notes']) ?></small><?php endif; ?>
          </td>
          <td>
            <?php if($pay['payment_type']==='rent'): ?>
            <span style="font-size:11px;background:#e4e4e4;color:#3f3f3f;padding:2px 8px;border-radius:4px;font-weight:600;">RENT</span>
            <?php else: ?>
            <span style="font-size:11px;background:#e4e4e4;color:#3f3f3f;padding:2px 8px;border-radius:4px;font-weight:600;">SERVICE</span>
            <?php endif; ?>
          </td>
          <td><?= money((float)$pay['amount']) ?></td>
        </tr>
      </tbody>
    </table>

    <!-- Total -->
    <div class="inv-total-wrap">
      <div class="inv-total-box">
        <div class="inv-total-label">Total Amount Paid</div>
        <div class="inv-total-amount"><?= money((float)$pay['amount']) ?></div>
      </div>
    </div>

    <!-- Notes -->
    <?php if($pay['notes']): ?>
    <div class="inv-notes">
      <div class="inv-notes-label">Notes</div>
      <div class="inv-notes-text"><?= clean($pay['notes']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Cashier sign-off -->
    <div class="inv-cashier">
      <div>
        <div class="inv-cashier-label">Processed &amp; received by</div>
        <div class="inv-cashier-name"><?= clean($pay['cashier_name'] ?? '—') ?></div>
        <div class="inv-cashier-role"><?= ucfirst($pay['cashier_role'] ?? '') ?> &nbsp;·&nbsp; <?= clean($companyName) ?></div>
      </div>
      <div style="text-align:right">
        <div style="width:160px;border-top:2px solid var(--border);padding-top:6px;font-size:11px;color:var(--muted)">Authorized Signature</div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="inv-footer">
    This is a computer-generated official receipt. &nbsp;·&nbsp; <?= clean($appName) ?><br>
    Generated: <?= date('F j, Y \a\t h:i A') ?> &nbsp;·&nbsp; Invoice No: <strong><?= clean($pay['invoice_no']) ?></strong>
  </div>

</div>

<script>
// Auto-print if ?print=1
if (new URLSearchParams(window.location.search).get('print') === '1') {
  window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
