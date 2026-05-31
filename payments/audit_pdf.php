<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
if (empty($_SESSION['settings_unlocked'])) {
    die('<p style="font-family:sans-serif;padding:2rem;color:#0a0a0a">Settings must be unlocked to access the audit report. <a href="../admin/settings.php">Go to Settings</a></p>');
}

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0); // 0 = all months

$companyName = getSetting($pdo, 'company_name', 'Laskie Rental Properties');
$currSymbol  = getSetting($pdo, 'currency_symbol', '₱');
$generatedAt = date('F j, Y \a\t g:i A');

function fmt_money(float $n, string $sym): string {
    return htmlspecialchars($sym, ENT_QUOTES, 'UTF-8') . number_format($n, 2);
}

// Resolve the date window once (sargable — hits idx_pay_date / idx_exp_date / idx_cash_user_date)
[$rangeStart, $rangeEnd] = $month > 0 ? monthRange($month, $year) : yearRange($year);

// ── Payments ─────────────────────────────────────────────────
$payStmt = $pdo->prepare(
    "SELECT p.*, ru.unit_name, t.full_name AS tenant_name, u.full_name AS cashier_name,
            st.name AS service_name
     FROM payments p
     LEFT JOIN rental_units ru ON ru.id = p.unit_id
     LEFT JOIN tenants t       ON t.id  = p.tenant_id
     LEFT JOIN users u         ON u.id  = p.received_by
     LEFT JOIN service_types st ON st.id = p.service_type_id
     WHERE p.payment_date >= ? AND p.payment_date < ?
     ORDER BY p.payment_date, p.id"
);
$payStmt->execute([$rangeStart, $rangeEnd]);
$payments = $payStmt->fetchAll();

// Voided / soft-deleted payments are kept in $payments and rendered in the
// detail tables (an audit report should list everything that happened) but
// must not inflate the Net Income summary. Same for soft-deleted expenses.
$isValidPayment = fn(array $p): bool => empty($p['deleted_at']) && ($p['status'] ?? 'paid') !== 'voided';
$validPayments    = array_values(array_filter($payments, $isValidPayment));
$rentPayments     = array_filter($payments, fn($p) => $p['payment_type'] === 'rent');
$servicePayments  = array_filter($payments, fn($p) => $p['payment_type'] === 'service');
$validRent        = array_filter($rentPayments,    $isValidPayment);
$validService     = array_filter($servicePayments, $isValidPayment);
$voidedOrDeletedPayments = array_values(array_filter($payments, fn($p) => !$isValidPayment($p)));

// ── Expenses ─────────────────────────────────────────────────
$expStmt = $pdo->prepare(
    "SELECT e.*, ru.unit_name, ec.name AS category_name, u.full_name AS recorded_by_name
     FROM expenses e
     LEFT JOIN rental_units ru     ON ru.id = e.unit_id
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     LEFT JOIN users u               ON u.id  = e.recorded_by
     WHERE e.expense_date >= ? AND e.expense_date < ?
     ORDER BY e.expense_date, e.id"
);
$expStmt->execute([$rangeStart, $rangeEnd]);
$expenses = $expStmt->fetchAll();
$validExpenses          = array_values(array_filter($expenses, fn($e) => empty($e['deleted_at'])));
$deletedExpenses        = array_values(array_filter($expenses, fn($e) => !empty($e['deleted_at'])));

// ── Cash transactions ─────────────────────────────────────────
$cashStmt = $pdo->prepare(
    "SELECT ct.*, u.full_name AS user_name
     FROM cash_transactions ct
     LEFT JOIN users u ON u.id = ct.user_id
     WHERE ct.transaction_date >= ? AND ct.transaction_date < ?
     ORDER BY ct.transaction_date, ct.id"
);
$cashStmt->execute([$rangeStart, $rangeEnd]);
$cashTxns = $cashStmt->fetchAll();

// ── Summaries ─────────────────────────────────────────────────
// Cents-based sums to avoid float drift across a year's worth of rows.
$totalRentPaid    = money_sum(array_column($validRent,     'amount'));
$totalServicePaid = money_sum(array_column($validService,  'amount'));
$totalRevenue     = money_add($totalRentPaid, $totalServicePaid);
$totalExpenses    = money_sum(array_column($validExpenses, 'amount'));
$netIncome        = money_sub($totalRevenue, $totalExpenses);
$voidedExcluded   = money_sum(array_column($voidedOrDeletedPayments, 'amount'));
$deletedExpExcluded = money_sum(array_column($deletedExpenses, 'amount'));

// Expenses by category (valid expenses only — same reasoning as totals)
$expByCategory = [];
foreach ($validExpenses as $e) {
    $cat = $e['category_name'] ?: 'Uncategorized';
    $expByCategory[$cat] = money_add($expByCategory[$cat] ?? '0.00', $e['amount']);
}

$periodLabel = $month > 0
    ? date('F', mktime(0,0,0,$month,1)) . ' ' . $year
    : 'Full Year ' . $year;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Audit Report — <?= clean($periodLabel) ?> — <?= clean($companyName) ?></title>
<link href="../assets/vendor/google-fonts.css" rel="stylesheet">
<style>
:root {
  --primary: #0a0a0a;
  --danger:  #0a0a0a;
  --success: #0a0a0a;
  --border:  #e4e4e4;
  --bg:      #fafafa;
  --muted:   #737373;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; font-size: 11px; color: #0a0a0a; background: #ffffff; }

/* Print toolbar (hidden when printing) */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 999;
  background: var(--primary); color: #ffffff; padding: 10px 20px;
  display: flex; align-items: center; gap: 12px; font-size: 13px;
}
.toolbar h2 { font-size: 15px; font-weight: 700; flex: 1; }
.btn-print {
  background: #ffffff; color: var(--primary); border: none; padding: 6px 16px;
  border-radius: 4px; font-weight: 700; cursor: pointer; font-size: 13px;
}
.btn-print:hover { background: #f4f4f4; }

/* Page body */
.report { padding: 80px 32px 40px; max-width: 1100px; margin: 0 auto; }
.report-header { border-bottom: 3px solid var(--primary); padding-bottom: 12px; margin-bottom: 20px; }
.report-header h1 { font-size: 20px; font-weight: 800; color: var(--primary); }
.report-header .sub { font-size: 12px; color: var(--muted); margin-top: 4px; }
.report-meta { display: flex; gap: 32px; margin-top: 8px; font-size: 12px; }
.report-meta span { color: var(--muted); }
.report-meta strong { color: #0a0a0a; }

/* Summary cards */
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.sum-card { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 12px 14px; }
.sum-card .label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.sum-card .value { font-size: 16px; font-weight: 700; margin-top: 2px; }
.sum-card.revenue .value { color: var(--primary); }
.sum-card.expense .value { color: var(--danger); }
.sum-card.net.positive .value { color: var(--success); }
.sum-card.net.negative .value { color: var(--danger); }

/* Section headers */
.section { margin-bottom: 28px; }
.section-title {
  font-size: 13px; font-weight: 700; color: var(--primary);
  background: #f4f4f4; padding: 7px 12px; border-left: 4px solid var(--primary);
  margin-bottom: 0; display: flex; justify-content: space-between; align-items: center;
}
.section-title .section-total { font-size: 13px; font-weight: 700; }

/* Tables */
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead tr { background: #fafafa; }
th { padding: 6px 8px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); color: #555555; white-space: nowrap; }
th.r, td.r { text-align: right; }
td { padding: 5px 8px; border-bottom: 1px solid #e4e4e4; vertical-align: top; }
tr:hover { background: #f4f4f4; }
tfoot td { background: #fafafa; font-weight: 700; border-top: 2px solid var(--border); }
.badge { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 10px; font-weight: 600; }
.badge-rent    { background: #e4e4e4; color: #3f3f3f; }
.badge-service { background: #e4e4e4; color: #3f3f3f; }
.badge-recv    { background: #e4e4e4; color: #3f3f3f; }
.badge-remit   { background: #e4e4e4; color: #3f3f3f; }
.badge-exp     { background: #e4e4e4; color: #3f3f3f; }
.badge-voided  { background: #e4e4e4; color: #3f3f3f; }
.badge-deleted { background: #f4f4f4; color: #737373; }
tr.row-excluded td { color: #9b9b9b; text-decoration: line-through; }
tr.row-excluded td .badge { text-decoration: none; }
.excluded-note { padding: 6px 12px; font-size: 10.5px; color: var(--muted); background: #f4f4f4; border-left: 3px solid #e4e4e4; margin-top: 4px; }
.mono { font-family: 'DM Mono', monospace; font-size: 10px; }
.text-muted { color: var(--muted); }

/* Category sub-header */
.cat-row td { background: #fafafa; font-weight: 600; color: var(--primary); font-size: 11px; padding: 5px 8px; border-top: 1px solid var(--border); }

/* Page break hints */
.section { page-break-inside: avoid; }

/* Print styles */
@media print {
  .toolbar { display: none !important; }
  .report  { padding: 20px; }
  body { font-size: 10px; }
  .summary-grid { grid-template-columns: repeat(4,1fr); }
  a { text-decoration: none; color: inherit; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
  <h2><i style="margin-right:6px">📋</i><?= clean($companyName) ?> — Audit Report (<?= clean($periodLabel) ?>)</h2>
  <a class="btn-print" href="audit_pdf_download.php?year=<?= $year ?>&month=<?= $month ?>">⬇ Download PDF</a>
  <button class="btn-print" onclick="window.print()">🖨 Print</button>
  <button class="btn-print" onclick="window.close()">✕ Close</button>
</div>

<div class="report">

  <!-- Header -->
  <div class="report-header">
    <h1><?= clean($companyName) ?></h1>
    <div class="sub">Transaction Audit Report</div>
    <div class="report-meta">
      <div><span>Period: </span><strong><?= clean($periodLabel) ?></strong></div>
      <div><span>Generated: </span><strong><?= $generatedAt ?></strong></div>
      <div><span>Payments: </span><strong><?= count($payments) ?></strong></div>
      <div><span>Expenses: </span><strong><?= count($expenses) ?></strong></div>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary-grid">
    <div class="sum-card revenue">
      <div class="label">Total Revenue</div>
      <div class="value"><?= fmt_money((float)$totalRevenue, $currSymbol) ?></div>
    </div>
    <div class="sum-card expense">
      <div class="label">Total Expenses</div>
      <div class="value"><?= fmt_money((float)$totalExpenses, $currSymbol) ?></div>
    </div>
    <div class="sum-card net <?= money_gte($netIncome, '0.00') ? 'positive' : 'negative' ?>">
      <div class="label">Net Income</div>
      <div class="value"><?= fmt_money((float)$netIncome, $currSymbol) ?></div>
    </div>
    <div class="sum-card">
      <div class="label">Cash Transactions</div>
      <div class="value"><?= count($cashTxns) ?></div>
    </div>
  </div>
  <?php if (money_is_pos($voidedExcluded) || money_is_pos($deletedExpExcluded)): ?>
  <div style="background:#f4f4f4;border:1px solid #e4e4e4;border-radius:6px;padding:8px 12px;margin-bottom:18px;font-size:11px;color:#555555">
    <strong>Note:</strong> Totals exclude
    <?php if (money_is_pos($voidedExcluded)): ?>
      <?= count($voidedOrDeletedPayments) ?> voided/deleted payment(s) totalling <strong><?= fmt_money((float)$voidedExcluded, $currSymbol) ?></strong>
    <?php endif; ?>
    <?php if (money_is_pos($voidedExcluded) && money_is_pos($deletedExpExcluded)): ?> and<?php endif; ?>
    <?php if (money_is_pos($deletedExpExcluded)): ?>
      <?= count($deletedExpenses) ?> deleted expense(s) totalling <strong><?= fmt_money((float)$deletedExpExcluded, $currSymbol) ?></strong>
    <?php endif; ?>.
    These records are still listed in the detail sections below for audit-trail completeness.
  </div>
  <?php endif; ?>

  <!-- ── SECTION 1: Rental Payments ─────────────────────────── -->
  <?php $rentExcluded = count($rentPayments) - count($validRent); ?>
  <div class="section">
    <div class="section-title">
      <span>1 — Rental Payments (<?= count($validRent) ?> counted<?= $rentExcluded > 0 ? " + $rentExcluded voided/deleted" : '' ?>)</span>
      <span class="section-total"><?= fmt_money((float)$totalRentPaid, $currSymbol) ?></span>
    </div>
    <?php if (empty($rentPayments)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No rental payments recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Invoice</th><th>Unit</th><th>Tenant</th>
          <th>Period</th><th>Status</th><th class="r">Amount</th><th>Cashier</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php $rSeq = 0; foreach ($rentPayments as $p):
          $rSeq++;
          $period   = date('M Y', mktime(0,0,0,$p['period_month'],1,$p['period_year']));
          $excluded = !$isValidPayment($p);
          $statusBadge = !empty($p['deleted_at'])
              ? '<span class="badge badge-deleted">Deleted</span>'
              : (($p['status'] ?? 'paid') === 'voided'
                  ? '<span class="badge badge-voided">Voided</span>'
                  : '');
        ?>
        <tr<?= $excluded ? ' class="row-excluded"' : '' ?>>
          <td class="text-muted"><?= $rSeq ?></td>
          <td><?= $p['payment_date'] ?></td>
          <td class="mono"><?= clean($p['invoice_no'] ?: '—') ?></td>
          <td><?= clean($p['unit_name'] ?: '—') ?></td>
          <td><?= clean($p['tenant_name'] ?: '—') ?></td>
          <td><?= $period ?></td>
          <td><?= $statusBadge ?></td>
          <td class="r"><strong><?= fmt_money((float)$p['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($p['cashier_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($p['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7">TOTAL RENTAL PAYMENTS (counted)</td>
          <td class="r"><?= fmt_money((float)$totalRentPaid, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
    <?php if ($rentExcluded > 0): ?>
      <div class="excluded-note">Excluded from total: <?= $rentExcluded ?> voided / deleted record(s). Listed above for audit-trail completeness but not summed.</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 2: Service Payments ───────────────────────────── -->
  <?php $svcExcluded = count($servicePayments) - count($validService); ?>
  <div class="section">
    <div class="section-title">
      <span>2 — Service / Fee Payments (<?= count($validService) ?> counted<?= $svcExcluded > 0 ? " + $svcExcluded voided/deleted" : '' ?>)</span>
      <span class="section-total"><?= fmt_money((float)$totalServicePaid, $currSymbol) ?></span>
    </div>
    <?php if (empty($servicePayments)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No service payments recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Invoice</th><th>Unit</th><th>Tenant</th>
          <th>Service Type</th><th>Period</th><th>Status</th><th class="r">Amount</th><th>Cashier</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php $sSeq = 0; foreach ($servicePayments as $p):
          $sSeq++;
          $period   = date('M Y', mktime(0,0,0,$p['period_month'],1,$p['period_year']));
          $excluded = !$isValidPayment($p);
          $statusBadge = !empty($p['deleted_at'])
              ? '<span class="badge badge-deleted">Deleted</span>'
              : (($p['status'] ?? 'paid') === 'voided'
                  ? '<span class="badge badge-voided">Voided</span>'
                  : '');
        ?>
        <tr<?= $excluded ? ' class="row-excluded"' : '' ?>>
          <td class="text-muted"><?= $sSeq ?></td>
          <td><?= $p['payment_date'] ?></td>
          <td class="mono"><?= clean($p['invoice_no'] ?: '—') ?></td>
          <td><?= clean($p['unit_name'] ?: '—') ?></td>
          <td><?= clean($p['tenant_name'] ?: '—') ?></td>
          <td><?= clean($p['service_name'] ?: '—') ?></td>
          <td><?= $period ?></td>
          <td><?= $statusBadge ?></td>
          <td class="r"><strong><?= fmt_money((float)$p['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($p['cashier_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($p['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="8">TOTAL SERVICE PAYMENTS (counted)</td>
          <td class="r"><?= fmt_money((float)$totalServicePaid, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
    <?php if ($svcExcluded > 0): ?>
      <div class="excluded-note">Excluded from total: <?= $svcExcluded ?> voided / deleted record(s). Listed above for audit-trail completeness but not summed.</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 3: Expenses by Category ──────────────────────── -->
  <?php $expExcluded = count($expenses) - count($validExpenses); ?>
  <div class="section">
    <div class="section-title">
      <span>3 — Expenses by Category (<?= count($validExpenses) ?> counted<?= $expExcluded > 0 ? " + $expExcluded deleted" : '' ?>)</span>
      <span class="section-total"><?= fmt_money((float)$totalExpenses, $currSymbol) ?></span>
    </div>
    <?php if (empty($expenses)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No expenses recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Category</th><th>Unit</th><th>Description</th>
          <th>Status</th><th class="r">Amount</th><th>Recorded By</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php
        // Group by category. Deleted rows still render so the audit shows the
        // full picture, but they're flagged and excluded from category totals.
        $expGrouped = [];
        foreach ($expenses as $e) {
            $cat = $e['category_name'] ?: 'Uncategorized';
            $expGrouped[$cat][] = $e;
        }
        $eSeq = 0;
        foreach ($expGrouped as $catName => $catRows):
          $valid    = array_filter($catRows, fn($e) => empty($e['deleted_at']));
          $catTotal = money_sum(array_column($valid, 'amount'));
        ?>
        <tr class="cat-row"><td colspan="9"><?= clean($catName) ?> — <?= fmt_money((float)$catTotal, $currSymbol) ?></td></tr>
        <?php foreach ($catRows as $e): $eSeq++; $deleted = !empty($e['deleted_at']); ?>
        <tr<?= $deleted ? ' class="row-excluded"' : '' ?>>
          <td class="text-muted"><?= $eSeq ?></td>
          <td><?= $e['expense_date'] ?></td>
          <td><?= clean($e['category_name'] ?: '—') ?></td>
          <td><?= clean($e['unit_name'] ?: '—') ?></td>
          <td><?= clean($e['description'] ?: '—') ?></td>
          <td><?= $deleted ? '<span class="badge badge-deleted">Deleted</span>' : '' ?></td>
          <td class="r"><strong><?= fmt_money((float)$e['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($e['recorded_by_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($e['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6">TOTAL EXPENSES (counted)</td>
          <td class="r"><?= fmt_money((float)$totalExpenses, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>

    <!-- Category breakdown sidebar -->
    <div style="margin-top:12px;padding:10px 12px;background:#fafafa;border:1px solid var(--border);border-radius:4px">
      <strong style="font-size:11px">Expense Breakdown by Category (counted only):</strong>
      <div style="display:flex;flex-wrap:wrap;gap:8px 24px;margin-top:6px">
        <?php foreach ($expByCategory as $cat => $total): ?>
        <span style="font-size:11px"><span class="text-muted"><?= clean($cat) ?>:</span> <strong><?= fmt_money((float)$total, $currSymbol) ?></strong></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($expExcluded > 0): ?>
      <div class="excluded-note">Excluded from total: <?= $expExcluded ?> deleted record(s). Listed above for audit-trail completeness but not summed.</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 4: Cash Transactions ─────────────────────────── -->
  <div class="section">
    <div class="section-title">
      <span>4 — Cash Transactions (<?= count($cashTxns) ?> records)</span>
      <span class="section-total">&nbsp;</span>
    </div>
    <?php if (empty($cashTxns)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No cash transactions recorded for this period.</p>
    <?php else:
      $totalReceived = '0.00'; $totalRemitted = '0.00'; $totalCashExp = '0.00';
    ?>
    <table>
      <thead>
        <tr><th>#</th><th>Date</th><th>Type</th><th>Staff</th><th>Notes</th><th class="r">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($cashTxns as $i => $ct):
          if ($ct['transaction_type'] === 'received')  $totalReceived  = money_add($totalReceived, $ct['amount']);
          if ($ct['transaction_type'] === 'remitted')  $totalRemitted  = money_add($totalRemitted, $ct['amount']);
          if ($ct['transaction_type'] === 'expense')   $totalCashExp   = money_add($totalCashExp,  $ct['amount']);
          $badgeClass = ['received' => 'badge-recv', 'remitted' => 'badge-remit', 'expense' => 'badge-exp'][$ct['transaction_type']] ?? '';
        ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= $ct['transaction_date'] ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($ct['transaction_type']) ?></span></td>
          <td><?= clean($ct['user_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($ct['notes'] ?: '—') ?></td>
          <td class="r"><strong><?= fmt_money((float)$ct['amount'], $currSymbol) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4">Received</td><td></td><td class="r" style="color:var(--success)"><?= fmt_money((float)$totalReceived, $currSymbol) ?></td></tr>
        <tr><td colspan="4">Remitted</td><td></td><td class="r" style="color:var(--danger)"><?= fmt_money((float)$totalRemitted, $currSymbol) ?></td></tr>
        <tr><td colspan="4">Expenses</td><td></td><td class="r" style="color:var(--danger)"><?= fmt_money((float)$totalCashExp,  $currSymbol) ?></td></tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── Final Summary ─────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title"><span>Summary</span></div>
    <table style="max-width:480px">
      <tbody>
        <tr><td>Rental Payments</td><td class="r"><?= fmt_money((float)$totalRentPaid, $currSymbol) ?></td></tr>
        <tr><td>Service Payments</td><td class="r"><?= fmt_money((float)$totalServicePaid, $currSymbol) ?></td></tr>
        <tr style="background:#f4f4f4"><td><strong>Total Revenue</strong></td><td class="r"><strong><?= fmt_money((float)$totalRevenue, $currSymbol) ?></strong></td></tr>
        <tr><td>Total Expenses</td><td class="r" style="color:var(--danger)"><?= fmt_money((float)$totalExpenses, $currSymbol) ?></td></tr>
        <tr style="background:#f4f4f4">
          <td><strong>Net Income</strong></td>
          <td class="r" style="color:<?= money_gte($netIncome, '0.00') ? 'var(--success)' : 'var(--danger)' ?>">
            <strong><?= fmt_money((float)$netIncome, $currSymbol) ?></strong>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div style="margin-top:32px;padding-top:12px;border-top:1px solid var(--border);font-size:10px;color:var(--muted);text-align:center">
    <?= clean($companyName) ?> — Audit Report for <?= clean($periodLabel) ?> — Generated <?= $generatedAt ?>
  </div>

</div><!-- .report -->
</body>
</html>
