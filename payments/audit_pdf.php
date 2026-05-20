<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
if (empty($_SESSION['settings_unlocked'])) {
    die('<p style="font-family:sans-serif;padding:2rem;color:#dc2626">Settings must be unlocked to access the audit report. <a href="../admin/settings.php">Go to Settings</a></p>');
}

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0); // 0 = all months

$companyName = getSetting($pdo, 'company_name', 'Laskie Rental Properties');
$currSymbol  = getSetting($pdo, 'currency_symbol', '₱');
$generatedAt = date('F j, Y \a\t g:i A');

function fmt_money(float $n, string $sym): string {
    return $sym . number_format($n, 2);
}

// ── Payments ─────────────────────────────────────────────────
$payWhere = $month > 0 ? "YEAR(p.payment_date)=? AND MONTH(p.payment_date)=?" : "YEAR(p.payment_date)=?";
$payParams = $month > 0 ? [$year, $month] : [$year];
$payStmt = $pdo->prepare(
    "SELECT p.*, ru.unit_name, t.full_name AS tenant_name, u.full_name AS cashier_name,
            st.name AS service_name
     FROM payments p
     LEFT JOIN rental_units ru ON ru.id = p.unit_id
     LEFT JOIN tenants t       ON t.id  = p.tenant_id
     LEFT JOIN users u         ON u.id  = p.received_by
     LEFT JOIN service_types st ON st.id = p.service_type_id
     WHERE $payWhere
     ORDER BY p.payment_date, p.id"
);
$payStmt->execute($payParams);
$payments = $payStmt->fetchAll();

$rentPayments    = array_filter($payments, fn($p) => $p['payment_type'] === 'rent');
$servicePayments = array_filter($payments, fn($p) => $p['payment_type'] === 'service');

// ── Expenses ─────────────────────────────────────────────────
$expWhere = $month > 0 ? "YEAR(e.expense_date)=? AND MONTH(e.expense_date)=?" : "YEAR(e.expense_date)=?";
$expParams = $month > 0 ? [$year, $month] : [$year];
$expStmt = $pdo->prepare(
    "SELECT e.*, ru.unit_name, ec.name AS category_name, u.full_name AS recorded_by_name
     FROM expenses e
     LEFT JOIN rental_units ru     ON ru.id = e.unit_id
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     LEFT JOIN users u               ON u.id  = e.recorded_by
     WHERE $expWhere
     ORDER BY e.expense_date, e.id"
);
$expStmt->execute($expParams);
$expenses = $expStmt->fetchAll();

// ── Cash transactions ─────────────────────────────────────────
$cashWhere = $month > 0 ? "YEAR(ct.transaction_date)=? AND MONTH(ct.transaction_date)=?" : "YEAR(ct.transaction_date)=?";
$cashParams = $month > 0 ? [$year, $month] : [$year];
$cashStmt = $pdo->prepare(
    "SELECT ct.*, u.full_name AS user_name
     FROM cash_transactions ct
     LEFT JOIN users u ON u.id = ct.user_id
     WHERE $cashWhere
     ORDER BY ct.transaction_date, ct.id"
);
$cashStmt->execute($cashParams);
$cashTxns = $cashStmt->fetchAll();

// ── Summaries ─────────────────────────────────────────────────
$totalRentPaid    = array_sum(array_column($rentPayments, 'amount'));
$totalServicePaid = array_sum(array_column($servicePayments, 'amount'));
$totalRevenue     = $totalRentPaid + $totalServicePaid;
$totalExpenses    = array_sum(array_column($expenses, 'amount'));
$netIncome        = $totalRevenue - $totalExpenses;

// Expenses by category
$expByCategory = [];
foreach ($expenses as $e) {
    $cat = $e['category_name'] ?: 'Uncategorized';
    if (!isset($expByCategory[$cat])) $expByCategory[$cat] = 0;
    $expByCategory[$cat] += (float)$e['amount'];
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
  --primary: #1a3a8f;
  --danger:  #b91c1c;
  --success: #15803d;
  --border:  #d1d5db;
  --bg:      #f8fafc;
  --muted:   #6b7280;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; font-size: 11px; color: #111; background: #fff; }

/* Print toolbar (hidden when printing) */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 999;
  background: var(--primary); color: #fff; padding: 10px 20px;
  display: flex; align-items: center; gap: 12px; font-size: 13px;
}
.toolbar h2 { font-size: 15px; font-weight: 700; flex: 1; }
.btn-print {
  background: #fff; color: var(--primary); border: none; padding: 6px 16px;
  border-radius: 4px; font-weight: 700; cursor: pointer; font-size: 13px;
}
.btn-print:hover { background: #e8eef8; }

/* Page body */
.report { padding: 80px 32px 40px; max-width: 1100px; margin: 0 auto; }
.report-header { border-bottom: 3px solid var(--primary); padding-bottom: 12px; margin-bottom: 20px; }
.report-header h1 { font-size: 20px; font-weight: 800; color: var(--primary); }
.report-header .sub { font-size: 12px; color: var(--muted); margin-top: 4px; }
.report-meta { display: flex; gap: 32px; margin-top: 8px; font-size: 12px; }
.report-meta span { color: var(--muted); }
.report-meta strong { color: #111; }

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
  background: #eef2ff; padding: 7px 12px; border-left: 4px solid var(--primary);
  margin-bottom: 0; display: flex; justify-content: space-between; align-items: center;
}
.section-title .section-total { font-size: 13px; font-weight: 700; }

/* Tables */
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead tr { background: #f1f5f9; }
th { padding: 6px 8px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); color: #374151; white-space: nowrap; }
th.r, td.r { text-align: right; }
td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
tr:hover { background: #fafafa; }
tfoot td { background: #f8fafc; font-weight: 700; border-top: 2px solid var(--border); }
.badge { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 10px; font-weight: 600; }
.badge-rent    { background: #dbeafe; color: #1e40af; }
.badge-service { background: #fef3c7; color: #92400e; }
.badge-recv    { background: #d1fae5; color: #065f46; }
.badge-remit   { background: #fee2e2; color: #991b1b; }
.badge-exp     { background: #fce7f3; color: #9d174d; }
.mono { font-family: 'DM Mono', monospace; font-size: 10px; }
.text-muted { color: var(--muted); }

/* Category sub-header */
.cat-row td { background: #f9fafb; font-weight: 600; color: var(--primary); font-size: 11px; padding: 5px 8px; border-top: 1px solid var(--border); }

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
      <div class="value"><?= fmt_money($totalRevenue, $currSymbol) ?></div>
    </div>
    <div class="sum-card expense">
      <div class="label">Total Expenses</div>
      <div class="value"><?= fmt_money($totalExpenses, $currSymbol) ?></div>
    </div>
    <div class="sum-card net <?= $netIncome >= 0 ? 'positive' : 'negative' ?>">
      <div class="label">Net Income</div>
      <div class="value"><?= fmt_money($netIncome, $currSymbol) ?></div>
    </div>
    <div class="sum-card">
      <div class="label">Cash Transactions</div>
      <div class="value"><?= count($cashTxns) ?></div>
    </div>
  </div>

  <!-- ── SECTION 1: Rental Payments ─────────────────────────── -->
  <div class="section">
    <div class="section-title">
      <span>1 — Rental Payments (<?= count($rentPayments) ?> records)</span>
      <span class="section-total"><?= fmt_money($totalRentPaid, $currSymbol) ?></span>
    </div>
    <?php if (empty($rentPayments)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No rental payments recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Invoice</th><th>Unit</th><th>Tenant</th>
          <th>Period</th><th class="r">Amount</th><th>Cashier</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php $rSeq = 0; foreach ($rentPayments as $p):
          $rSeq++;
          $period = date('M Y', mktime(0,0,0,$p['period_month'],1,$p['period_year']));
        ?>
        <tr>
          <td class="text-muted"><?= $rSeq ?></td>
          <td><?= $p['payment_date'] ?></td>
          <td class="mono"><?= clean($p['invoice_no'] ?: '—') ?></td>
          <td><?= clean($p['unit_name'] ?: '—') ?></td>
          <td><?= clean($p['tenant_name'] ?: '—') ?></td>
          <td><?= $period ?></td>
          <td class="r"><strong><?= fmt_money((float)$p['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($p['cashier_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($p['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6">TOTAL RENTAL PAYMENTS</td>
          <td class="r"><?= fmt_money($totalRentPaid, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 2: Service Payments ───────────────────────────── -->
  <div class="section">
    <div class="section-title">
      <span>2 — Service / Fee Payments (<?= count($servicePayments) ?> records)</span>
      <span class="section-total"><?= fmt_money($totalServicePaid, $currSymbol) ?></span>
    </div>
    <?php if (empty($servicePayments)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No service payments recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Invoice</th><th>Unit</th><th>Tenant</th>
          <th>Service Type</th><th>Period</th><th class="r">Amount</th><th>Cashier</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php $sSeq = 0; foreach ($servicePayments as $p):
          $sSeq++;
          $period = date('M Y', mktime(0,0,0,$p['period_month'],1,$p['period_year']));
        ?>
        <tr>
          <td class="text-muted"><?= $sSeq ?></td>
          <td><?= $p['payment_date'] ?></td>
          <td class="mono"><?= clean($p['invoice_no'] ?: '—') ?></td>
          <td><?= clean($p['unit_name'] ?: '—') ?></td>
          <td><?= clean($p['tenant_name'] ?: '—') ?></td>
          <td><?= clean($p['service_name'] ?: '—') ?></td>
          <td><?= $period ?></td>
          <td class="r"><strong><?= fmt_money((float)$p['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($p['cashier_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($p['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7">TOTAL SERVICE PAYMENTS</td>
          <td class="r"><?= fmt_money($totalServicePaid, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 3: Expenses by Category ──────────────────────── -->
  <div class="section">
    <div class="section-title">
      <span>3 — Expenses by Category (<?= count($expenses) ?> records)</span>
      <span class="section-total"><?= fmt_money($totalExpenses, $currSymbol) ?></span>
    </div>
    <?php if (empty($expenses)): ?>
      <p class="text-muted" style="padding:10px 12px;font-size:11px">No expenses recorded for this period.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Category</th><th>Unit</th><th>Description</th>
          <th class="r">Amount</th><th>Recorded By</th><th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php
        // Group by category
        $expGrouped = [];
        foreach ($expenses as $e) {
            $cat = $e['category_name'] ?: 'Uncategorized';
            $expGrouped[$cat][] = $e;
        }
        $eSeq = 0;
        foreach ($expGrouped as $catName => $catRows):
          $catTotal = array_sum(array_column($catRows, 'amount'));
        ?>
        <tr class="cat-row"><td colspan="8"><?= clean($catName) ?> — <?= fmt_money($catTotal, $currSymbol) ?></td></tr>
        <?php foreach ($catRows as $e): $eSeq++; ?>
        <tr>
          <td class="text-muted"><?= $eSeq ?></td>
          <td><?= $e['expense_date'] ?></td>
          <td><?= clean($e['category_name'] ?: '—') ?></td>
          <td><?= clean($e['unit_name'] ?: '—') ?></td>
          <td><?= clean($e['description'] ?: '—') ?></td>
          <td class="r"><strong><?= fmt_money((float)$e['amount'], $currSymbol) ?></strong></td>
          <td><?= clean($e['recorded_by_name'] ?: '—') ?></td>
          <td class="text-muted"><?= clean($e['notes'] ?: '') ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5">TOTAL EXPENSES</td>
          <td class="r"><?= fmt_money($totalExpenses, $currSymbol) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>

    <!-- Category breakdown sidebar -->
    <div style="margin-top:12px;padding:10px 12px;background:#fafafa;border:1px solid var(--border);border-radius:4px">
      <strong style="font-size:11px">Expense Breakdown by Category:</strong>
      <div style="display:flex;flex-wrap:wrap;gap:8px 24px;margin-top:6px">
        <?php foreach ($expByCategory as $cat => $total): ?>
        <span style="font-size:11px"><span class="text-muted"><?= clean($cat) ?>:</span> <strong><?= fmt_money($total, $currSymbol) ?></strong></span>
        <?php endforeach; ?>
      </div>
    </div>
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
      $totalReceived = 0; $totalRemitted = 0; $totalCashExp = 0;
    ?>
    <table>
      <thead>
        <tr><th>#</th><th>Date</th><th>Type</th><th>Staff</th><th>Notes</th><th class="r">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($cashTxns as $i => $ct):
          if ($ct['transaction_type'] === 'received')  $totalReceived  += (float)$ct['amount'];
          if ($ct['transaction_type'] === 'remitted')  $totalRemitted  += (float)$ct['amount'];
          if ($ct['transaction_type'] === 'expense')   $totalCashExp   += (float)$ct['amount'];
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
        <tr><td colspan="4">Received</td><td></td><td class="r" style="color:var(--success)"><?= fmt_money($totalReceived, $currSymbol) ?></td></tr>
        <tr><td colspan="4">Remitted</td><td></td><td class="r" style="color:var(--danger)"><?= fmt_money($totalRemitted, $currSymbol) ?></td></tr>
        <tr><td colspan="4">Expenses</td><td></td><td class="r" style="color:var(--danger)"><?= fmt_money($totalCashExp, $currSymbol) ?></td></tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── Final Summary ─────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title"><span>Summary</span></div>
    <table style="max-width:480px">
      <tbody>
        <tr><td>Rental Payments</td><td class="r"><?= fmt_money($totalRentPaid, $currSymbol) ?></td></tr>
        <tr><td>Service Payments</td><td class="r"><?= fmt_money($totalServicePaid, $currSymbol) ?></td></tr>
        <tr style="background:#eef2ff"><td><strong>Total Revenue</strong></td><td class="r"><strong><?= fmt_money($totalRevenue, $currSymbol) ?></strong></td></tr>
        <tr><td>Total Expenses</td><td class="r" style="color:var(--danger)"><?= fmt_money($totalExpenses, $currSymbol) ?></td></tr>
        <tr style="background:<?= $netIncome >= 0 ? '#f0fdf4' : '#fef2f2' ?>">
          <td><strong>Net Income</strong></td>
          <td class="r" style="color:<?= $netIncome >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <strong><?= fmt_money($netIncome, $currSymbol) ?></strong>
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
