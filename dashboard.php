<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
require_once 'config/balance_functions.php';
requireLogin();

$pageTitle    = 'Annual Dashboard';
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$depth        = '';
$needsChartJs = true;

// ─── Fetch all rental units ───────────────────────────────────
$units = $pdo->query("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id ORDER BY ru.unit_name")->fetchAll();

// ─── Per-unit revenue & expenses (single query each, sargable ranges) ─────
[$yrStart, $yrEnd] = yearRange($selectedYear);
// Net of refunds: each payment contributes amount − its refunded total, so a
// fully-refunded payment adds 0 and a partial refund nets out. Voided/deleted
// stay excluded (refunds can only attach to live, non-voided payments).
$revRows = $pdo->prepare("SELECT p.unit_id, COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0) AS total
    FROM payments p
    LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
    WHERE p.payment_date >= ? AND p.payment_date < ? AND p.deleted_at IS NULL AND p.status != 'voided' GROUP BY p.unit_id");
$revRows->execute([$yrStart, $yrEnd]);
$unitRevenue = array_column($revRows->fetchAll(), 'total', 'unit_id');

$expRows = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL GROUP BY unit_id");
$expRows->execute([$yrStart, $yrEnd]);
$unitExpenses = array_column($expRows->fetchAll(), 'total', 'unit_id');

foreach ($units as $u) {
    $unitRevenue[$u['id']]  = (float)($unitRevenue[$u['id']]  ?? 0);
    $unitExpenses[$u['id']] = (float)($unitExpenses[$u['id']] ?? 0);
}

// ─── Monthly totals ───────────────────────────────────────────
// Kept as canonical "0.00" strings so 12-month accumulation in the tfoot
// matches the year stat cards exactly (no sub-cent float drift).
$monthlyRev = []; $monthlyExp = [];
$revStmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0)
    FROM payments p
    LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
    WHERE p.payment_date >= ? AND p.payment_date < ? AND p.deleted_at IS NULL AND p.status != 'voided'");
$expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL");
for ($m = 1; $m <= 12; $m++) {
    [$mStart, $mEnd] = monthRange($m, $selectedYear);
    $revStmt->execute([$mStart, $mEnd]);
    $monthlyRev[$m] = (string)$revStmt->fetchColumn();
    $expStmt->execute([$mStart, $mEnd]);
    $monthlyExp[$m] = (string)$expStmt->fetchColumn();
}

// ─── Totals ───────────────────────────────────────────────────
// Sum in cents-based helpers to avoid float drift across many units.
$totalRev = money_sum(array_values($unitRevenue));
$totalExp = money_sum(array_values($unitExpenses));
$totalNet = money_sub($totalRev, $totalExp);
$totalUnits   = count($units);
$occupiedUnits = count(array_filter($units, fn($u) => $u['status'] === 'occupied'));

// Orphan payments/expenses: FK ON DELETE SET NULL means rows whose unit
// was later deleted come back with unit_id=NULL. PHP keys those as ''.
// They're included in $totalRev/$totalExp above; the per-unit table
// also needs to surface them so on-page totals reconcile.
$orphanRev = (string)($unitRevenue[''] ?? '0.00');
$orphanExp = (string)($unitExpenses[''] ?? '0.00');

// ─── Current month stats ─────────────────────────────────────
$curMonth = (int)date('n');
$curYear  = (int)date('Y');
$cmRev = '0.00';
$cmExp = '0.00';
if ($selectedYear === $curYear) {
    [$cmStart, $cmEnd] = monthRange($curMonth, $curYear);
    $s = $pdo->prepare("SELECT COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0)
        FROM payments p
        LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
        WHERE p.payment_date >= ? AND p.payment_date < ? AND p.deleted_at IS NULL AND p.status != 'voided'");
    $s->execute([$cmStart, $cmEnd]); $cmRev = (string)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL");
    $s->execute([$cmStart, $cmEnd]); $cmExp = (string)$s->fetchColumn();
}
$cmNet = money_sub($cmRev, $cmExp);

// ─── Available years ──────────────────────────────────────────
$years = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments WHERE deleted_at IS NULL AND status != 'voided' UNION SELECT DISTINCT YEAR(expense_date) FROM expenses WHERE deleted_at IS NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) array_unshift($years, (int)date('Y'));
if (!in_array($selectedYear, $years)) $years[] = $selectedYear;
rsort($years);

// ─── Unit chart period dropdown data ─────────────────────────
// MONTH() in the SELECT is a projection — the WHERE uses a sargable
// half-open range so idx_pay_date / idx_exp_date can be used. This
// loop runs once per year of history (small N) but adds up fast on
// a full table scan once payments/expenses grow.
$mnNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$chartMonthsByYear = [];
foreach ($years as $ychk) {
    [$yChkStart, $yChkEnd] = yearRange((int)$ychk);
    $mQ = $pdo->prepare("
        SELECT DISTINCT m FROM (
            SELECT MONTH(payment_date) AS m FROM payments
             WHERE payment_date >= ? AND payment_date < ?
               AND deleted_at IS NULL AND status != 'voided'
            UNION
            SELECT MONTH(expense_date) FROM expenses
             WHERE expense_date >= ? AND expense_date < ?
               AND deleted_at IS NULL
        ) sub ORDER BY m ASC
    ");
    $mQ->execute([$yChkStart, $yChkEnd, $yChkStart, $yChkEnd]);
    $chartMonthsByYear[$ychk] = $mQ->fetchAll(PDO::FETCH_COLUMN);
}
$chartInitPeriod = ($selectedYear === $curYear)
    ? 'month_' . $curYear . '_' . $curMonth
    : 'year_' . $selectedYear;

// ─── Unit payment status for current month ───────────────────
$unitStatusData = [];
if ($selectedYear === $curYear) {
    $usStmt = $pdo->prepare("
        SELECT ru.id, ru.unit_name, ru.monthly_rate, ru.due_day, ru.status,
               t.full_name AS tenant_name, t.contract_start, t.contract_end,
               COALESCE(cm.paid, 0) AS cur_paid
        FROM rental_units ru
        LEFT JOIN tenants t ON t.unit_id = ru.id AND t.status = 'active'
        LEFT JOIN (
            SELECT p.unit_id, SUM(p.amount - COALESCE(r.refsum,0)) AS paid
            FROM payments p
            LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
            WHERE p.payment_type = 'rent'
              AND p.period_month = ? AND p.period_year = ?
              AND p.deleted_at IS NULL AND p.status != 'voided'
            GROUP BY p.unit_id
        ) cm ON cm.unit_id = ru.id
        ORDER BY ru.unit_name
    ");
    $usStmt->execute([$curMonth, $curYear]);
    $unitStatusData = $usStmt->fetchAll();

    // Outstanding receivables, split by whether the tenant is still in place.
    // Past-tenant arrears are invisible everywhere else on this page and on the
    // collection grid — both join tenants on status='active' and zero out vacant
    // units — so a departed tenant's debt would otherwise vanish at move-out.
    // As-of-today, not year-scoped, so it is only shown on the current year.
    $arrearsCurrent = money_sum(array_values(getCurrentTenantArrears($pdo)));
    $arrearsPast    = money_sum(array_values(getPastTenantArrears($pdo)));
    $arrearsTotal   = money_add($arrearsCurrent, $arrearsPast);
    // Admin rent waivers for the current month, keyed by unit_id. Batched here
    // so the status loop below stays free of per-unit queries.
    $curRentVoids = getRentVoidTotals($pdo, $curMonth, $curYear);

    $today = (int)date('j');
}

// ─── Balance Tracker: every ACTIVE tenant's position as of today ─────
// Former tenants are deliberately left out (their arrears show in the hero
// strip); this list is for spotting who needs intervention now.
$balanceRows = null;
try {
    $balanceRows = activeTenantBalances($pdo);
    usort($balanceRows, fn($a, $b) => [$b['st']['flagged'], $b['st']['months_behind'], to_cents($b['st']['balance'])]
                                    <=> [$a['st']['flagged'], $a['st']['months_behind'], to_cents($a['st']['balance'])]);
} catch (Throwable $e) {
    error_log('dashboard balance tracker: ' . $e->getMessage());
}
$btFlagged = $balanceRows ? count(array_filter($balanceRows, fn($r) => $r['st']['flagged'])) : 0;
$btBehind  = $balanceRows ? count(array_filter($balanceRows, fn($r) => money_is_pos($r['st']['rent_owed']))) : 0;
$btOwed    = $balanceRows ? money_sum(array_map(fn($r) => money_max('0.00', $r['st']['balance']), $balanceRows) ?: ['0.00']) : '0.00';

logActivity($pdo, 'VIEW_DASHBOARD', 'Dashboard', "Viewed annual dashboard for $selectedYear");
include 'includes/header.php';
?>
<style>
/* ── Dashboard — monochrome ───────────────────────────────── */
.page-header           { margin-bottom: 14px; }
.db-row                { margin-bottom: 12px; }

/* All non-hero cards are clean white surfaces (hairline + whisper shadow) */
.db-card               { background: var(--paper); border: 1px solid var(--gray-200); border-radius: var(--laskie-radius-card); box-shadow: var(--laskie-shadow-card); }
.db-card .card-header  { padding: 12px 16px; border-bottom: 1px solid var(--gray-200); }
.db-card .card-header-title { color: var(--ink); font-size: 14px; }
.db-card .card-body    { padding: 12px 16px; }

/* ── Balance Tracker ── */
.bt-head               { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; }
.bt-summary            { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.bt-sub                { font-size: 11.5px; color: var(--gray-500); }
#balanceTable td       { vertical-align: middle; }
#balanceTable .bt-name { font-weight: 600; }
#balanceTable .bt-meta { font-size: 11px; color: var(--gray-500); }
#balanceTable tr.bt-flag td:first-child { box-shadow: inset 3px 0 0 var(--ink); }
#balanceTable .bt-num  { font-family: 'DM Mono', monospace; font-weight: 600; white-space: nowrap; }
#balanceTable .bt-actions { white-space: nowrap; text-align: right; }
#balanceTable .bt-mobile { display: none; }
/* Phones: keep Balance, Status and the action buttons on screen; the two
   detail columns fold into a line under the tenant's name instead. */
@media (max-width: 575.98px) {
  #balanceTable th:nth-child(n+3), #balanceTable td:nth-child(n+3) { display: none; }
  #balanceTable .bt-mobile { display: block; white-space: normal; }
  #balanceTable .bt-mobile-row { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 6px; }
  #balanceTable .bt-mobile-row .btn-icon { width: 34px; height: 34px; }
  #balanceTable td:first-child .cell-trunc { max-width: 200px; }
}
#balanceTable .bt-dl   { font-size: 10.5px; color: var(--gray-600); text-decoration: none; border-bottom: 1px dashed var(--gray-400); margin-right: 6px; }
.bt-type label         { display: flex; gap: 10px; align-items: flex-start; padding: 9px 11px; border: 1px solid var(--gray-300);
                         border-radius: var(--radius); cursor: pointer; margin-bottom: 6px; font-size: 12.5px; }
.bt-type label:has(input:checked) { border-color: var(--ink); background: var(--gray-100); }
.bt-type strong        { display: block; font-size: 13px; }
.bt-warn               { font-size: 12px; padding: 8px 11px; border-radius: var(--radius); background: var(--gray-100);
                         border: 1px solid var(--gray-200); color: var(--ink); }

/* ── Inverted KPI hero strip — the single B&W "hero" (§3.6) ── */
.db-hero {
    display: flex; flex-wrap: wrap;
    background: var(--ink); color: var(--paper);
    border: 1px solid var(--ink);
    border-radius: var(--laskie-radius-card);
    box-shadow: var(--laskie-shadow-hero);
    overflow: hidden;
}
.db-hero-cell {
    flex: 1 1 0; min-width: 150px;
    padding: 18px 20px;
    border-left: 1px solid rgba(128,128,128,.32);
}
.db-hero-cell:first-child { border-left: none; }
/* Second strip (Month to Date): same language, one shade softer than the hero.
   --gray-800 sits one step from --ink in BOTH themes (lighter in light mode,
   darker in dark mode, where the hero itself inverts to near-white). */
.db-hero-soft { background: var(--gray-800); border-color: var(--gray-800); box-shadow: var(--laskie-shadow-card); }
.db-hero-soft .stat-value { font-size: 20px; }

/* ── Unit Status ── */
.us-head { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; }
.us-sub  { font-size: 11.5px; color: var(--gray-500); }
.us-sub strong { color: var(--ink); }
#unitStatusTable .us-tenant { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#unitStatusTable .stat-sub { font-size: 10.5px; color: var(--gray-500); white-space: normal; font-family: 'DM Sans', sans-serif; }
@media (max-width: 575.98px) {
  #unitStatusTable .us-tenant { max-width: 84px; }
  #unitStatusTable td, #unitStatusTable th { padding-left: 8px !important; padding-right: 8px !important; }
}


.db-hero .stat-label { font-size: 11px; font-weight: 500; color: var(--paper); opacity: .62; margin-bottom: 8px; letter-spacing: .02em; }
.db-hero .stat-value { font-size: 22px; font-weight: 700; color: var(--paper); line-height: 1.15; overflow-wrap: anywhere; }
.db-hero .stat-value .caret { font-size: .72em; vertical-align: 1px; margin-right: 3px; opacity: .85; }
.db-hero .stat-sub   { font-size: 10.5px; color: var(--paper); opacity: .45; margin-top: 6px; }
@media (max-width: 575.98px) {
  .db-hero-cell { flex: 1 1 50%; min-width: 0; padding: 14px 16px; }
  .db-hero-cell:nth-child(odd) { border-left: none; }
  .db-hero-cell:nth-child(n+3) { border-top: 1px solid rgba(128,128,128,.32); }
  .db-hero .stat-value { font-size: 17px; }
}

/* Tighter table cells inside dashboard cards; right-aligned = tabular money */
.db-tbl td, .db-tbl th { padding: 7px 12px !important; font-size: 12.5px; }
.db-tbl thead th       { background: transparent; border-bottom: 1px solid var(--gray-200); }
.db-tbl td.text-end    { font-family: 'DM Mono', monospace; font-variant-numeric: tabular-nums; }
.db-tbl tfoot tr       { background: var(--gray-50) !important; font-weight: 700; }
.db-tbl tfoot td       { border-top: 1px solid var(--gray-200); }
.db-row-now            { background: var(--gray-100) !important; }
/* Dark mode: gray-50 (#0f0f0f) is darker than card surface (#161616) — invert to gray-200 */
[data-theme="dark"] .db-tbl tfoot tr { background: var(--gray-200) !important; }
[data-theme="dark"] .db-tbl tfoot td { border-top-color: var(--gray-300); }
[data-theme="dark"] .db-row-now      { background: var(--gray-200) !important; }
/* Unit-status: give the status cell room so amount + "Late fee" can stack
   without overflowing (§3.5) */
#unitStatusTable td:last-child { min-width: 124px; }

.db-chart              { position: relative; height: 220px; }
.db-card-fill          { display: flex; flex-direction: column; }
.db-chart-grow         { position: relative; flex: 1; min-height: 220px; }
@media (max-width: 767.98px) {
  .db-chart      { height: 440px !important; }
  .db-chart-grow { min-height: 440px !important; }
}

/* Unit-chart stats strip + period selector (on white card now) */
#unitChartStats span     { color: var(--gray-500); }
#unitChartStats #ucTitle { color: var(--gray-400); }
</style>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-gauge-high me-2 text-primary-custom"></i>Annual Dashboard</h1>
  <form method="GET" class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 text-muted" style="white-space:nowrap">Year:</label>
    <select name="year" class="form-select form-select-sm" style="width:100px" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $y==$selectedYear?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- ── Row 1: Inverted KPI hero strip ──────────────────────── -->
<div class="db-hero db-row">
  <div class="db-hero-cell">
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value num"><?= money($totalRev) ?></div>
    <div class="stat-sub"><?= $selectedYear ?></div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Total Expenses</div>
    <div class="stat-value num"><?= money($totalExp) ?></div>
    <div class="stat-sub"><?= $selectedYear ?></div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Net Income</div>
    <div class="stat-value num<?= money_gte($totalNet, '0.00') ? '' : ' fw-bold' ?>"><span class="caret" aria-hidden="true"><?= money_gte($totalNet, '0.00') ? '▲' : '▼' ?></span><?= money($totalNet) ?></div>
    <div class="stat-sub"><?= $selectedYear ?></div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Units Occupied</div>
    <div class="stat-value"><?= $occupiedUnits ?> / <?= $totalUnits ?></div>
    <div class="stat-sub">Rental units</div>
  </div>
  <?php if ($selectedYear === $curYear): ?>
  <div class="db-hero-cell">
    <div class="stat-label">Outstanding Receivables</div>
    <div class="stat-value num"><?= money($arrearsTotal) ?></div>
    <div class="stat-sub">
      Current <?= money($arrearsCurrent) ?>
      <?php if (money_is_pos($arrearsPast)): ?>
      &middot; <a href="payments/collection.php" style="color:inherit;text-decoration:underline">Past tenants <?= money($arrearsPast) ?></a>
      <?php else: ?>
      &middot; Past tenants <?= money($arrearsPast) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($selectedYear === $curYear): ?>
<!-- ── Month to Date — second hero strip, one shade softer than the first ── -->
<div class="db-hero db-hero-soft db-row">
  <div class="db-hero-cell">
    <div class="stat-label"><i class="fa-solid fa-calendar-day me-1"></i>Month to Date</div>
    <div class="stat-value"><?= date('F Y') ?></div>
    <div class="stat-sub"><?= date('M 1') ?> – <?= date('M j') ?></div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Revenue</div>
    <div class="stat-value num"><?= money($cmRev) ?></div>
    <div class="stat-sub">Collected so far</div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Expenses</div>
    <div class="stat-value num"><?= money($cmExp) ?></div>
    <div class="stat-sub">Spent so far</div>
  </div>
  <div class="db-hero-cell">
    <div class="stat-label">Net Income</div>
    <div class="stat-value num<?= money_gte($cmNet, '0.00') ? '' : ' fw-bold' ?>"><span class="caret" aria-hidden="true"><?= money_gte($cmNet, '0.00') ? '▲' : '▼' ?></span><?= money($cmNet) ?></div>
    <div class="stat-sub"><?= money_is_pos($cmRev) ? round(to_cents($cmNet) / to_cents($cmRev) * 100) . '% of revenue' : 'Revenue − expenses' ?></div>
  </div>
</div>
<?php endif; ?>

<?php if ($balanceRows !== null): ?>
<!-- ── Balance Tracker — active tenants only, as of today ───── -->
<div class="card db-card db-row" id="balanceTracker">
  <div class="card-header bt-head">
    <span class="card-header-title"><i class="fa-solid fa-scale-unbalanced me-1"></i>Balance Tracker</span>
    <span class="bt-summary">
      <?php if ($btFlagged): ?><span class="attn-pill"><?= $btFlagged ?> need<?= $btFlagged === 1 ? 's' : '' ?> intervention</span><?php endif; ?>
      <span class="muted-pill"><?= $btBehind ?> behind on rent</span>
      <span class="bt-sub">Owed by active tenants: <strong><?= money($btOwed) ?></strong> · as of <?= date('M j, Y') ?></span>
    </span>
  </div>
  <div class="card-body pt-2">
    <div class="table-responsive">
    <table class="table db-tbl mb-0" id="balanceTable" style="width:100%">
      <thead><tr>
        <th>Tenant</th><th class="text-end">Balance</th><th class="text-end">Rent behind</th>
        <th>Last payment</th><th>Status</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($balanceRows as $br):
        $bt = $br['tenant']; $bs = $br['st']; $lp = $bs['last_payment']; $ld = $br['last_demand'];
        if ($bs['flagged'])                      { $pill = ['attn-pill', number_format($bs['months_behind'], 1) . ' mo behind']; $ord = 0; }
        elseif (money_is_pos($bs['rent_owed']))  { $pill = ['muted-pill', 'Behind']; $ord = 1; }
        elseif (money_is_pos($bs['svc_owed']))   { $pill = ['muted-pill', 'Charges due']; $ord = 2; }
        elseif (money_is_neg($bs['balance']))    { $pill = ['ok-pill', 'Credit']; $ord = 3; }
        else                                     { $pill = ['ok-pill', 'Paid up']; $ord = 4; }
        // Straight to the printable statement preview — easier to read than the SoA screen.
        $soa = 'payments/soa_pdf.php?' . http_build_query(['unit_id' => (int)$bt['unit_id'], 'tenant_id' => (int)$bt['id'],
                                                           'date_from' => $bt['contract_start'] ?: date('Y-01-01'), 'date_to' => date('Y-m-d')]);
        ob_start(); ?>
          <?php if (isAdmin() && $ld): ?>
            <a class="bt-dl" href="admin/demand_letter.php?id=<?= (int)$ld['id'] ?>" target="_blank" rel="noopener"
               title="Reprint <?= clean(DEMAND_TYPES[$ld['letter_type']] ?? '') ?>"><?= clean($ld['ref_no']) ?> · <?= fmtDate($ld['created_at'], 'M j') ?></a>
          <?php endif; ?>
          <?php if (isAdmin() && $bs['flagged']): ?>
            <button type="button" class="btn-icon" title="Demand letter"
                    data-id="<?= (int)$bt['id'] ?>" data-name="<?= clean($bt['full_name']) ?>" data-unit="<?= clean($bt['unit_name']) ?>"
                    data-total="<?= clean($bs['balance']) ?>" data-rent="<?= clean($bs['rent_owed']) ?>" data-svc="<?= clean($bs['svc_owed']) ?>"
                    data-months="<?= (float)$bs['months_behind'] ?>" data-rc="<?= money_lte($bs['rate'], defined('CONTRACT_RENT_CONTROL_CAP') ? CONTRACT_RENT_CONTROL_CAP : '10000.00') ? 1 : 0 ?>"
                    onclick="openDemand(this.dataset)"><i class="fa-solid fa-envelope-open-text fa-xs"></i></button>
          <?php endif; ?>
          <a class="btn-icon" href="<?= clean($soa) ?>" target="_blank" rel="noopener" title="Statement of Account (opens in a new tab)"><i class="fa-solid fa-file-invoice fa-xs"></i></a>
        <?php $actions = ob_get_clean();
      ?>
      <tr class="<?= $bs['flagged'] ? 'bt-flag' : '' ?>">
        <td data-order="<?= clean($bt['full_name']) ?>">
          <div class="bt-name cell-trunc"><?= clean($bt['full_name']) ?></div>
          <div class="bt-meta"><?= clean($bt['unit_name']) ?> · <?= money($bs['rate']) ?>/mo</div>
          <div class="bt-meta bt-mobile">
            <?= money_is_pos($bs['rent_owed']) ? 'Rent behind ' . money($bs['rent_owed']) . ' (' . number_format($bs['months_behind'], 1) . ' mo)' : 'Rent up to date' ?>
            · <?= $lp ? 'Last paid ' . money($lp['amount']) . ' on ' . fmtDate($lp['payment_date'], 'M j') : 'No payments yet' ?>
          </div>
          <div class="bt-mobile bt-mobile-row"><span class="<?= $pill[0] ?>"><?= clean($pill[1]) ?></span><span><?= $actions ?></span></div>
        </td>
        <td class="text-end" data-order="<?= to_cents($bs['balance']) ?>">
          <span class="bt-num"><?= money($bs['balance']) ?></span>
          <?php if (money_is_pos($bs['svc_owed'])): ?><div class="bt-meta">incl. <?= money($bs['svc_owed']) ?> charges</div><?php endif; ?>
        </td>
        <td class="text-end" data-order="<?= (float)$bs['months_behind'] ?>">
          <?php if (money_is_pos($bs['rent_owed'])): ?>
            <span class="bt-num"><?= money($bs['rent_owed']) ?></span>
            <div class="bt-meta"><?= number_format($bs['months_behind'], 1) ?> month<?= abs($bs['months_behind'] - 1) < 0.005 ? '' : 's' ?></div>
          <?php else: ?><span class="bt-meta">—</span><?php endif; ?>
        </td>
        <td data-order="<?= clean($lp['payment_date'] ?? '0000-00-00') ?>">
          <?php if ($lp): ?>
            <span class="bt-num"><?= money($lp['amount']) ?></span>
            <div class="bt-meta"><?= fmtDate($lp['payment_date'], 'M j, Y') ?> · <?= clean($lp['payment_type'] === 'rent' ? 'Rent' : ($lp['service_name'] ?: 'Service')) ?></div>
          <?php else: ?><span class="bt-meta">No payments yet</span><?php endif; ?>
        </td>
        <td data-order="<?= $ord ?>"><span class="<?= $pill[0] ?>"><?= clean($pill[1]) ?></span></td>
        <td class="bt-actions"><?= $actions ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if (isAdmin()): ?>
<!-- Demand letter — admin only; the PDF opens in a new tab to print or save -->
<div class="modal fade" id="demandModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="demandForm" method="POST" action="admin/demand_letter.php" target="_blank">
      <?= csrfField() ?>
      <input type="hidden" name="tenant_id" id="dmTenant">
      <div class="modal-header">
        <h5 class="modal-title" id="dmTitle">Demand letter</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="bt-warn mb-3" id="dmSummary"></div>
        <label class="form-label">Type of letter</label>
        <div class="bt-type">
          <label><input type="radio" name="letter_type" value="pay" checked>
            <span><strong>Demand to Pay</strong>Formal demand for the unpaid amount, warning of barangay conciliation and court action if unpaid.</span></label>
          <label><input type="radio" name="letter_type" value="vacate">
            <span><strong>Final Demand to Pay and Vacate</strong>The formal demand to pay <em>and</em> vacate required before an unlawful detainer (ejectment) case under Rule 70.</span></label>
        </div>
        <label class="form-label mt-2" for="dmDays">Days to comply</label>
        <input type="number" class="form-control" name="deadline_days" id="dmDays" min="<?= DEMAND_MIN_DAYS ?>" max="<?= DEMAND_MAX_DAYS ?>" value="<?= DEMAND_DEFAULT_DAYS ?>" required>
        <div class="form-text">Counted from the tenant's receipt of the letter (<?= DEMAND_MIN_DAYS ?>–<?= DEMAND_MAX_DAYS ?> days).</div>
        <div class="bt-warn mt-2" id="dmRcWarn" style="display:none"></div>
        <div class="form-text mt-2">Issuing records the letter with the next DL number. Have counsel review before relying on it in a case.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="dmGo"><i class="fa-solid fa-file-pdf me-1"></i>Generate PDF</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($selectedYear === $curYear): ?>
<!-- ── Row 2: Month summary + Unit status | Bar chart ─────── -->
<div class="row g-2 db-row">

  <div class="col-lg-5 d-flex flex-column gap-2">


    <!-- Unit payment status — this month's rent bill, unit by unit -->
    <?php if (!empty($unitStatusData)):
      // Rent is post-paid (CLAUDE.md §5 inv. 2): this month's bill covers the
      // cycle that just ended. Work every row out first so the header can
      // summarise them and each row can say in words where it stands.
      $usRows = []; $usCount = ['paid' => 0, 'open' => 0, 'vacant' => 0];
      $todayIso = date('Y-m-d');
      foreach ($unitStatusData as $u) {
          if ($u['status'] === 'vacant' || empty($u['tenant_name'])) {
              $usRows[] = ['u' => $u, 'state' => 'vacant', 'order' => 9]; $usCount['vacant']++; continue;
          }
          $due      = (int)$u['due_day'] ?: 5;
          $rate     = getRateForMonth($pdo, (int)$u['id'], (float)$u['monthly_rate'], $curMonth, $curYear);
          $billed   = prorateOccupancyMonth($rate, $due, $u['contract_start'] ?? null, $u['contract_end'] ?? null, $curMonth, $curYear);
          $waived   = $curRentVoids[(int)$u['id']] ?? '0.00';
          $expected = money_max('0.00', money_sub($billed, $waived));
          $paid     = from_cents(to_cents($u['cur_paid']));
          $left     = money_max('0.00', money_sub($expected, $paid));
          $dueDate  = chargeDate($due, $curMonth, $curYear, $u['contract_end'] ?? null);
          [$cs, $ce] = rentCycle($due, $curMonth, $curYear);
          $daysPast = (int)floor((strtotime($todayIso) - strtotime($dueDate)) / 86400);
          if (!money_is_pos($billed)) {
              // New tenancy: its first bill falls next month.
              $state = 'nobill'; $order = 6;
          } elseif (!money_is_pos($left)) {
              $state = 'paid'; $order = 7;
          } elseif ($daysPast < 0) {
              $state = 'upcoming'; $order = 4;
          } elseif (money_is_pos($paid)) {
              $state = 'partial'; $order = 2;
          } else {
              // 10-day grace before "overdue" (same rule as before).
              $state = $daysPast > 10 ? 'overdue' : 'unpaid'; $order = $daysPast > 10 ? 0 : 1;
          }
          $usCount[$state === 'paid' || $state === 'nobill' ? 'paid' : 'open']++;
          $usRows[] = compact('u', 'state', 'order', 'billed', 'waived', 'expected', 'paid', 'left', 'dueDate', 'cs', 'ce', 'daysPast');
      }
      usort($usRows, fn($a, $b) => [$a['order'], $a['u']['unit_name']] <=> [$b['order'], $b['u']['unit_name']]);
      // The cycle most units bill this month, for the header (due day 5 for all today).
      $hdr = null; foreach ($usRows as $r) { if (isset($r['cs'])) { $hdr = $r; break; } }
    ?>
    <div class="card db-card">
      <div class="card-header us-head">
        <span class="card-header-title"><i class="fa-solid fa-building me-1"></i><?= date('F Y') ?> Rent — Unit Status</span>
        <span class="us-sub">
          <?php if ($hdr): ?>The <?= date('F') ?> bill covers <?= date('M j', strtotime($hdr['cs'])) ?> – <?= date('M j', strtotime($hdr['ce'])) ?>, due <?= date('M j', strtotime(rentDueDate((int)$hdr['u']['due_day'] ?: 5, $curMonth, $curYear))) ?>.<?php endif; ?>
          <strong><?= $usCount['paid'] ?></strong> settled · <strong><?= $usCount['open'] ?></strong> to collect<?= $usCount['vacant'] ? ' · ' . $usCount['vacant'] . ' vacant' : '' ?>
        </span>
      </div>
      <div class="card-body py-2">
        <!-- data-order on Status = urgency: overdue, unpaid, partly paid,
             not yet due, no bill, paid, vacant. -->
        <table class="table db-tbl mb-0" id="unitStatusTable" style="width:100%">
          <thead>
            <tr><th>Unit</th><th>Tenant</th><th><?= date('F') ?> bill</th></tr>
          </thead>
          <tbody>
          <?php foreach ($usRows as $r): $u = $r['u']; ?>
            <tr>
              <td class="fw-600 text-nowrap"><?= clean($u['unit_name']) ?></td>
              <?php if ($r['state'] === 'vacant'): ?>
              <td class="text-muted">—</td>
              <td data-order="9"><span class="muted-pill">Vacant</span></td>
              <?php else:
                $dueTxt = date('M j', strtotime($r['dueDate']));
                // A partial cycle (move-in / move-out) is prorated — say which days.
                $from = max($r['cs'], (string)$u['contract_start']);
                $to   = ($u['contract_end'] && $u['contract_end'] < $r['ce']) ? $u['contract_end'] : $r['ce'];
                $pro  = ($from !== $r['cs'] || $to !== $r['ce']) ? ' (' . date('M j', strtotime($from)) . '–' . date('j', strtotime($to)) . ', prorated)' : '';
                switch ($r['state']) {
                  case 'paid':     $pill = ['ok-pill', '<i class="fa-solid fa-check"></i>Paid'];
                                   $sub  = money($r['paid']) . ' received' . (money_is_pos($r['waived']) ? ' · ' . money($r['waived']) . ' waived' : ''); break;
                  case 'nobill':   $pill = ['muted-pill', 'No bill yet'];
                                   $sub  = 'First bill due ' . date('M j', strtotime(rentDueDate((int)$u['due_day'] ?: 5, $curMonth === 12 ? 1 : $curMonth + 1, $curMonth === 12 ? $curYear + 1 : $curYear))); break;
                  case 'upcoming': $pill = ['muted-pill', 'Due ' . $dueTxt];
                                   $sub  = money($r['expected']) . ' bill' . $pro . (money_is_pos($r['paid']) ? ' · ' . money($r['paid']) . ' paid early' : ''); break;
                  case 'partial':  $pill = ['attn-pill', 'Partly paid'];
                                   $sub  = money($r['paid']) . ' of ' . money($r['expected']) . ' paid · ' . money($r['left']) . ' left'; break;
                  case 'unpaid':   $pill = ['attn-pill', 'Unpaid'];
                                   $sub  = money($r['left']) . ' owed · was due ' . $dueTxt . ($r['daysPast'] ? ' (' . $r['daysPast'] . ' day' . ($r['daysPast'] === 1 ? '' : 's') . ' ago)' : ' (today)'); break;
                  default:         $pill = ['attn-pill', '<i class="fa-solid fa-triangle-exclamation"></i>Overdue'];
                                   $sub  = money($r['left']) . ' owed · ' . $r['daysPast'] . ' days past ' . $dueTxt . ' · late fee applies';
                }
              ?>
              <td class="us-tenant"><?= clean($u['tenant_name']) ?></td>
              <td data-order="<?= $r['order'] ?>">
                <span class="<?= $pill[0] ?>"><?= $pill[1] ?></span>
                <div class="stat-sub mt-1"><?= clean($sub) ?></div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /col-lg-5 -->

  <div class="col-lg-7">
    <div class="card db-card db-card-fill h-100">
      <div class="card-header" style="display:block">
        <div style="display:flex;align-items:center;gap:8px">
          <span class="card-header-title me-auto"><i class="fa-solid fa-chart-bar me-1"></i>Collected vs Spent by Unit</span>
          <select id="unitChartPeriod" class="form-select form-select-sm" style="width:auto;max-width:205px;font-size:11.5px">
            <?php foreach ($years as $ychk): $isCY = ($ychk === $curYear); ?>
            <optgroup label="<?= $ychk ?>">
              <?php if ($isCY): ?>
              <option value="month_<?= $curYear ?>_<?= $curMonth ?>"<?= $chartInitPeriod === "month_{$curYear}_{$curMonth}" ? ' selected' : '' ?>><?= $mnNames[$curMonth] ?> <?= $curYear ?> — MTD</option>
              <?php endif; ?>
              <option value="year_<?= $ychk ?>"<?= $chartInitPeriod === "year_{$ychk}" ? ' selected' : '' ?>>Full Year <?= $ychk ?></option>
              <?php foreach ($chartMonthsByYear[$ychk] ?? [] as $cm):
                if ($isCY && (int)$cm === $curMonth) continue; ?>
              <option value="month_<?= $ychk ?>_<?= $cm ?>"<?= $chartInitPeriod === "month_{$ychk}_{$cm}" ? ' selected' : '' ?>><?= $mnNames[(int)$cm] ?> <?= $ychk ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:6px;padding-top:6px;border-top:1px solid var(--gray-200)">
          <span style="color:var(--gray-500)">Rev: <strong id="ucRev" class="num">—</strong></span>
          <span style="color:var(--gray-500)">Exp: <strong id="ucExp" class="num">—</strong></span>
          <span style="color:var(--gray-500)">Net: <strong id="ucNet" class="num">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:var(--gray-400);font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart-grow" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:var(--paper);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--ink)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <canvas id="unitChart"></canvas>
      </div>
    </div>
  </div>

</div><!-- /row 2 -->

<!-- ── Row 3 (current year): Monthly chart | Category pie ─── -->
<div class="row g-2 db-row">
  <div class="col-lg-8">
    <div class="card db-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-1"></i>Monthly Revenue, Expenses &amp; Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body db-chart" style="height:300px"><canvas id="monthlyChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card h-100">
      <div class="card-header" style="display:flex;align-items:center;gap:8px">
        <span class="card-header-title me-auto"><i class="fa-solid fa-chart-simple me-1"></i>Expenses by Category</span>
        <select id="catChartPeriod" class="form-select form-select-sm" style="width:auto;max-width:185px;font-size:11.5px">
          <?php foreach ($years as $ychk): $isCY = ($ychk === $curYear); ?>
          <optgroup label="<?= $ychk ?>">
            <?php if ($isCY): ?>
            <option value="month_<?= $curYear ?>_<?= $curMonth ?>"<?= $chartInitPeriod === "month_{$curYear}_{$curMonth}" ? ' selected' : '' ?>><?= $mnNames[$curMonth] ?> <?= $curYear ?> — MTD</option>
            <?php endif; ?>
            <option value="year_<?= $ychk ?>"<?= $chartInitPeriod === "year_{$ychk}" ? ' selected' : '' ?>>Full Year <?= $ychk ?></option>
            <?php foreach ($chartMonthsByYear[$ychk] ?? [] as $cm):
              if ($isCY && (int)$cm === $curMonth) continue; ?>
            <option value="month_<?= $ychk ?>_<?= $cm ?>"<?= $chartInitPeriod === "month_{$ychk}_{$cm}" ? ' selected' : '' ?>><?= $mnNames[(int)$cm] ?> <?= $ychk ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="card-body db-chart" style="position:relative">
        <div id="catChartSpinner" style="display:none;position:absolute;inset:0;background:var(--paper);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--ink)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <div id="catChartEmpty" class="text-muted small text-center py-4" style="display:none">No expenses recorded in this period.</div>
        <canvas id="catChart"></canvas>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Row 2 (other years): Bar chart | Category pie ─────── -->
<div class="row g-2 db-row">
  <div class="col-lg-8">
    <div class="card db-card">
      <div class="card-header" style="display:block">
        <div style="display:flex;align-items:center;gap:8px">
          <span class="card-header-title me-auto"><i class="fa-solid fa-chart-bar me-1"></i>Collected vs Spent by Unit</span>
          <select id="unitChartPeriod" class="form-select form-select-sm" style="width:auto;max-width:205px;font-size:11.5px">
            <?php foreach ($years as $ychk): $isCY = ($ychk === $curYear); ?>
            <optgroup label="<?= $ychk ?>">
              <?php if ($isCY): ?>
              <option value="month_<?= $curYear ?>_<?= $curMonth ?>"><?= $mnNames[$curMonth] ?> <?= $curYear ?> — MTD</option>
              <?php endif; ?>
              <option value="year_<?= $ychk ?>"<?= $chartInitPeriod === "year_{$ychk}" ? ' selected' : '' ?>>Full Year <?= $ychk ?></option>
              <?php foreach ($chartMonthsByYear[$ychk] ?? [] as $cm):
                if ($isCY && (int)$cm === $curMonth) continue; ?>
              <option value="month_<?= $ychk ?>_<?= $cm ?>"<?= $chartInitPeriod === "month_{$ychk}_{$cm}" ? ' selected' : '' ?>><?= $mnNames[(int)$cm] ?> <?= $ychk ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:6px;padding-top:6px;border-top:1px solid var(--gray-200)">
          <span style="color:var(--gray-500)">Rev: <strong id="ucRev" class="num">—</strong></span>
          <span style="color:var(--gray-500)">Exp: <strong id="ucExp" class="num">—</strong></span>
          <span style="color:var(--gray-500)">Net: <strong id="ucNet" class="num">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:var(--gray-400);font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:var(--paper);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--ink)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <canvas id="unitChart"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card h-100">
      <div class="card-header" style="display:flex;align-items:center;gap:8px">
        <span class="card-header-title me-auto"><i class="fa-solid fa-chart-simple me-1"></i>Expenses by Category</span>
        <select id="catChartPeriod" class="form-select form-select-sm" style="width:auto;max-width:185px;font-size:11.5px">
          <?php foreach ($years as $ychk): $isCY = ($ychk === $curYear); ?>
          <optgroup label="<?= $ychk ?>">
            <?php if ($isCY): ?>
            <option value="month_<?= $curYear ?>_<?= $curMonth ?>"<?= $chartInitPeriod === "month_{$curYear}_{$curMonth}" ? ' selected' : '' ?>><?= $mnNames[$curMonth] ?> <?= $curYear ?> — MTD</option>
            <?php endif; ?>
            <option value="year_<?= $ychk ?>"<?= $chartInitPeriod === "year_{$ychk}" ? ' selected' : '' ?>>Full Year <?= $ychk ?></option>
            <?php foreach ($chartMonthsByYear[$ychk] ?? [] as $cm):
              if ($isCY && (int)$cm === $curMonth) continue; ?>
            <option value="month_<?= $ychk ?>_<?= $cm ?>"<?= $chartInitPeriod === "month_{$ychk}_{$cm}" ? ' selected' : '' ?>><?= $mnNames[(int)$cm] ?> <?= $ychk ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="card-body db-chart" style="position:relative">
        <div id="catChartSpinner" style="display:none;position:absolute;inset:0;background:var(--paper);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--ink)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <div id="catChartEmpty" class="text-muted small text-center py-4" style="display:none">No expenses recorded in this period.</div>
        <canvas id="catChart"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- ── Row 3 (other years): Monthly chart ─────────────────── -->
<div class="row g-2 db-row">
  <div class="col-12">
    <div class="card db-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-1"></i>Monthly Revenue, Expenses &amp; Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body db-chart" style="height:300px"><canvas id="monthlyChart"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Row 4: Monthly summary | Per-unit summary ──────────── -->
<div class="row g-2 db-row">
  <div class="col-lg-5">
    <div class="card db-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-table me-1"></i>Monthly Summary — <?= $selectedYear ?></span>
        <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
      </div>
      <div class="table-responsive">
        <table class="table db-tbl" id="monthlySummaryTable">
          <thead><tr>
            <th>Month</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net</th>
          </tr></thead>
          <tbody>
          <?php
          for($m=1;$m<=12;$m++){
            $r = $monthlyRev[$m];                  // canonical "0.00" string
            $e = $monthlyExp[$m];
            $n = money_sub($r, $e);                // cents-exact per-row net
            $isCurrent = ($selectedYear === $curYear && $m === $curMonth);
            // data-order on month so DataTables sorts chronologically by
            // numeric month (1..12) regardless of displayed Jan/Feb names.
          ?>
          <tr<?= $isCurrent ? ' class="db-row-now"' : '' ?>>
            <td data-order="<?= $m ?>">
              <?= date('M', mktime(0,0,0,$m,1)) ?>
              <?php if ($isCurrent): ?><span class="badge ms-1" style="background:var(--ink);color:var(--paper);font-size:9px">Now</span><?php endif; ?>
            </td>
            <td class="text-end" data-order="<?= (float)$r ?>"><?= money($r) ?></td>
            <td class="text-end" data-order="<?= (float)$e ?>"><?= money($e) ?></td>
            <td class="text-end fw-bold<?= money_gte($n, '0.00') ? '' : ' delta-neg' ?>" data-order="<?= (float)$n ?>"><?= money($n) ?></td>
          </tr>
          <?php } ?>
          </tbody>
          <tfoot><tr style="font-weight:700">
            <td>Total</td>
            <td class="text-end"><?= money($totalRev) ?></td>
            <td class="text-end"><?= money($totalExp) ?></td>
            <td class="text-end<?= money_gte($totalNet, '0.00') ? '' : ' delta-neg' ?>"><?= money($totalNet) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card db-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-building me-1"></i>Revenue, Expenses & Net per Unit — <?= $selectedYear ?></span>
      </div>
      <div class="table-responsive">
        <table class="table db-tbl" id="perUnitTable">
          <thead><tr>
            <th>Unit</th><th>Type</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net</th>
          </tr></thead>
          <tbody>
          <?php foreach($units as $u):
            $rev = (string)$unitRevenue[$u['id']];
            $exp = (string)$unitExpenses[$u['id']];
            $net = money_sub($rev, $exp);
          ?>
          <tr>
            <td class="fw-600"><?= clean($u['unit_name']) ?></td>
            <td><span class="badge badge-staff"><?= clean($u['type_name']??'—') ?></span></td>
            <td class="text-end" data-order="<?= (float)$rev ?>"><?= money($rev) ?></td>
            <td class="text-end" data-order="<?= (float)$exp ?>"><?= money($exp) ?></td>
            <td class="text-end fw-bold<?= money_gte($net, '0.00') ? '' : ' delta-neg' ?>" data-order="<?= (float)$net ?>"><?= money($net) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (money_is_pos($orphanRev) || money_is_pos($orphanExp)):
            $orphanNet = money_sub($orphanRev, $orphanExp);
          ?>
          <!-- General row — revenue/expenses with no unit_id: expenses entered as
               "General / not unit-specific" (the usual case), plus rows that
               survived a unit deletion (FK ON DELETE SET NULL). Without it,
               $totalRev/$totalExp at the top would disagree with this table. -->
          <tr class="db-row-now">
            <td class="fw-600">General</td>
            <td><span class="badge badge-staff">not unit-specific</span></td>
            <td class="text-end" data-order="<?= (float)$orphanRev ?>"><?= money($orphanRev) ?></td>
            <td class="text-end" data-order="<?= (float)$orphanExp ?>"><?= money($orphanExp) ?></td>
            <td class="text-end fw-bold<?= money_gte($orphanNet, '0.00') ? '' : ' delta-neg' ?>" data-order="<?= (float)$orphanNet ?>"><?= money($orphanNet) ?></td>
          </tr>
          <?php endif; ?>
          </tbody>
          <tfoot><tr style="font-weight:700">
            <td colspan="2">Total</td>
            <td class="text-end"><?= money($totalRev) ?></td>
            <td class="text-end"><?= money($totalExp) ?></td>
            <td class="text-end<?= money_gte($totalNet, '0.00') ? '' : ' delta-neg' ?>"><?= money($totalNet) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
var CHART_DATA = {
  monthLabels: <?= json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']) ?>,
  monthRev:    <?= json_encode(array_values($monthlyRev)) ?>,
  monthNet:    <?= json_encode(array_map(fn($m) => (float)money_sub($monthlyRev[$m], $monthlyExp[$m]), range(1,12))) ?>
};
var UNIT_CHART_API  = '<?= pageUrl('api/unit_chart_api.php') ?>';
var UNIT_CHART_INIT = '<?= $chartInitPeriod ?>';
var CAT_CHART_API   = '<?= pageUrl('api/cat_chart_api.php') ?>';
var CAT_CHART_INIT  = '<?= $chartInitPeriod ?>';
// -1 when viewing a past/future year (no bar gets highlighted); 0–11 otherwise
var MONTHLY_HIGHLIGHT_IDX = <?= ($selectedYear === $curYear) ? ($curMonth - 1) : -1 ?>;
</script>
<script>
// ── Shared formatter ─────────────────────────────────────────
var _phpFmt = function(v) {
  return '₱' + parseFloat(v || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
};

// Monochrome chart scaffolding — every color comes from window.chartTheme()
// (read live from CSS vars) so light/dark stay in sync. Series are split by
// lightness + dash + point shape, never hue (§3.4).
function _monoTooltip(T, extra) {
  return Object.assign({
    backgroundColor: T.paper, titleColor: T.tick, bodyColor: T.ink,
    borderColor: T.grid, borderWidth: 1,
    titleFont: { size: 10, family: 'DM Sans' },
    bodyFont:  { size: 12, family: 'DM Sans', weight: '700' },
    padding: { x: 12, y: 8 }, cornerRadius: 8, caretSize: 6, caretPadding: 8,
    displayColors: true
  }, extra || {});
}
function _monoLegend(T) {
  return { font: { size: 11, family: 'DM Sans' }, color: T.tick, padding: 16, usePointStyle: true, pointStyle: 'rect' };
}
function _monoScales(T, tickColor, tickWeight) {
  return {
    y: { beginAtZero: true, grid: { color: T.grid }, border: { display: false },
         ticks: { callback: _phpFmt, font: { size: 10, family: 'DM Sans' }, color: T.tick } },
    x: { grid: { display: false }, border: { display: false },
         ticks: { font: { size: 11, family: 'DM Sans', weight: tickWeight || '500' }, color: tickColor || T.tick, padding: 6 } }
  };
}

// ── Unit chart — AJAX-driven ─────────────────────────────────
var _unitChartInst = null;
var _unitChartData = null;          // last payload, for theme re-render
var _currentUnitPeriod = null;

function loadUnitChart(period) {
  period = period || (document.getElementById('unitChartPeriod') ? document.getElementById('unitChartPeriod').value : null);
  if (!period) return;
  _currentUnitPeriod = period;
  var parts = period.split('_');
  var qs = parts[0] === 'year'
    ? 'period_type=year&year=' + parts[1]
    : 'period_type=month&year=' + parts[1] + '&month=' + parts[2];
  var spinner = document.getElementById('unitChartSpinner');
  if (spinner) spinner.style.display = 'flex';
  fetch(UNIT_CHART_API + '?' + qs)
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (spinner) spinner.style.display = 'none';
      if (res.success) { _unitChartData = res; _renderUnitChart(res); }
      else showToast(res.error || 'Chart load failed.', 'danger');
    })
    .catch(function() {
      if (spinner) spinner.style.display = 'none';
      showToast('Chart load failed.', 'danger');
    });
}

// ── Shared helpers for the redesigned charts ─────────────────
// Compact peso label for bar ends: ₱950 · ₱12.5k · ₱1.25M.
function _compact(v) {
  var a = Math.abs(v), sign = v < 0 ? '−' : '';
  if (a >= 1e6) return sign + '₱' + (a / 1e6).toFixed(a >= 1e7 ? 1 : 2).replace(/\.?0+$/, '') + 'M';
  if (a >= 1e3) return sign + '₱' + (a / 1e3).toFixed(a >= 1e5 ? 0 : 1).replace(/\.0$/, '') + 'k';
  return sign + '₱' + Math.round(a);
}
// Writes a text label just past the end of each row of a horizontal bar chart
// (Chart.js has no data-label support in our vendored build). opts.text(i)
// returns the label for row i; it is drawn after the longest bar in that row.
var _rowLabels = {
  id: 'laskieRowLabels',
  afterDatasetsDraw: function (chart, args, opts) {
    if (!opts || typeof opts.text !== 'function') return;
    var T = window.chartTheme(), ctx = chart.ctx, metas = chart.getSortedVisibleDatasetMetas();
    ctx.save();
    ctx.textBaseline = 'middle';
    var n = chart.data.labels.length;
    for (var i = 0; i < n; i++) {
      var x = chart.chartArea.left, y = null;
      metas.forEach(function (m) { var el = m.data[i]; if (el) { x = Math.max(x, el.x); if (y === null) y = el.y; } });
      var t = opts.text(i);
      if (!t || y === null) continue;
      ctx.font = (t.bold ? '700 ' : '600 ') + '10.5px "DM Sans", sans-serif';
      ctx.fillStyle = t.bold ? T.ink : T.tick;
      ctx.fillText(t.text, x + 6, y);
    }
    ctx.restore();
  }
};
// Height for a horizontal bar chart: one comfortable row per label.
function _sizeRows(canvas, rows) {
  var box = canvas.parentNode;
  var px = Math.max(220, rows * 30 + 70);
  box.style.minHeight = px + 'px';
  if (!box.classList.contains('db-chart-grow')) box.style.height = px + 'px';
}

// ── By unit: collected vs spent, one row per unit, ranked by net ──
// Black bar = revenue collected; thinner grey bar on top = expenses. A unit
// that lost money shows a grey bar longer than its black one, and its net
// label is bold with a minus sign.
function _renderUnitChart(data) {
  if (_unitChartInst) { _unitChartInst.destroy(); _unitChartInst = null; }
  var canvas = document.getElementById('unitChart');
  if (!canvas) return;
  var T = window.chartTheme();

  var net = (data.totalRev || 0) - (data.totalExp || 0);
  var ucRev = document.getElementById('ucRev'), ucExp = document.getElementById('ucExp'),
      ucNet = document.getElementById('ucNet'), ucTitle = document.getElementById('ucTitle');
  if (ucRev)   ucRev.textContent = _phpFmt(data.totalRev);
  if (ucExp)   ucExp.textContent = _phpFmt(data.totalExp);
  if (ucNet) { ucNet.textContent = (net < 0 ? '▼ ' : '▲ ') + _phpFmt(Math.abs(net)); ucNet.style.fontWeight = net < 0 ? '700' : '600'; }
  if (ucTitle) ucTitle.textContent = data.title || '';

  var rows = (data.labels || []).map(function (l, i) {
    var r = data.revenue[i] || 0, e = data.expenses[i] || 0;
    return { label: l, rev: r, exp: e, net: r - e };
  }).sort(function (a, b) { return b.net - a.net; });
  _sizeRows(canvas, rows.length);

  _unitChartInst = new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: {
      labels: rows.map(function (r) { return r.label; }),
      datasets: [
        { label: 'Collected (revenue)', data: rows.map(function (r) { return r.rev; }),
          backgroundColor: T.ink, borderRadius: 3, barPercentage: 0.9, categoryPercentage: 0.8, grouped: false, order: 2 },
        { label: 'Spent (expenses)', data: rows.map(function (r) { return r.exp; }),
          backgroundColor: T.series[1], borderRadius: 3, barPercentage: 0.45, categoryPercentage: 0.8, grouped: false, order: 1 }
      ]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      animation: { duration: 380, easing: 'easeOutQuart' },
      layout: { padding: { right: 78, top: 4 } },
      plugins: {
        legend: { position: 'top', align: 'start', labels: Object.assign(_monoLegend(T), { sort: function (a, b) { return a.datasetIndex - b.datasetIndex; } }) },
        laskieRowLabels: { text: function (i) { var n = rows[i].net; return { text: _compact(n) + ' net', bold: n < 0 }; } },
        tooltip: _monoTooltip(T, {
          mode: 'index', intersect: false,
          callbacks: {
            label: function (c) { return ' ' + c.dataset.label + ': ' + _phpFmt(c.parsed.x); },
            afterBody: function (items) {
              var r = rows[items[0].dataIndex];
              return [' Net income: ' + (r.net < 0 ? '−' : '') + _phpFmt(Math.abs(r.net))];
            }
          }
        })
      },
      scales: {
        x: { beginAtZero: true, grid: { color: T.grid }, border: { display: false },
             ticks: { callback: function (v) { return _compact(v); }, color: T.tick, font: { size: 10, family: 'DM Sans' } } },
        y: { grid: { display: false }, border: { display: false },
             ticks: { color: T.ink, font: { size: 11, family: 'DM Sans', weight: '600' } } }
      }
    },
    plugins: [_rowLabels]
  });
}

// ── Page init ────────────────────────────────────────────────
var _catChartInst = null, _monthlyChartInst = null;

// ── Category chart — AJAX-driven, mirrors loadUnitChart() ─────
var _catChartData = null;

function loadCatChart(period) {
  period = period || (document.getElementById('catChartPeriod') ? document.getElementById('catChartPeriod').value : null);
  if (!period) return;
  var parts = period.split('_');
  var qs = parts[0] === 'year'
    ? 'period_type=year&year=' + parts[1]
    : 'period_type=month&year=' + parts[1] + '&month=' + parts[2];
  var spinner = document.getElementById('catChartSpinner');
  if (spinner) spinner.style.display = 'flex';
  fetch(CAT_CHART_API + '?' + qs)
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (spinner) spinner.style.display = 'none';
      if (res.success) { _catChartData = res; _renderCatChart(res); }
      else showToast(res.error || 'Chart load failed.', 'danger');
    })
    .catch(function() {
      if (spinner) spinner.style.display = 'none';
      showToast('Chart load failed.', 'danger');
    });
}

// ── By category: where the money went, largest first ─────────
function _renderCatChart(data) {
  var el = document.getElementById('catChart');
  if (!el) return;
  if (_catChartInst) { _catChartInst.destroy(); _catChartInst = null; }
  var T = window.chartTheme();
  var total = (data.totals || []).reduce(function (s, v) { return s + (v || 0); }, 0);
  var rows = (data.labels || []).map(function (l, i) { return { label: l, v: data.totals[i] || 0 }; })
                                .sort(function (a, b) { return b.v - a.v; });
  var empty = document.getElementById('catChartEmpty');
  if (empty) empty.style.display = rows.length ? 'none' : '';
  _sizeRows(el, Math.max(rows.length, 3));
  _catChartInst = new Chart(el, {
    type: 'bar',
    data: { labels: rows.map(function (r) { return r.label; }),
            datasets: [{ label: 'Spent', data: rows.map(function (r) { return r.v; }),
                         backgroundColor: rows.map(function (_, i) { return i === 0 ? T.ink : T.series[2]; }),
                         borderRadius: 3, barPercentage: 0.75 }] },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      layout: { padding: { right: 92, top: 4 } },
      plugins: {
        legend: { display: false },
        laskieRowLabels: { text: function (i) {
          var pct = total ? Math.round(rows[i].v / total * 100) : 0;
          return { text: _compact(rows[i].v) + ' · ' + pct + '%', bold: i === 0 };
        } },
        tooltip: _monoTooltip(T, { callbacks: {
          label: function (c) { var pct = total ? (c.parsed.x / total * 100).toFixed(1) : 0; return ' ' + _phpFmt(c.parsed.x) + ' (' + pct + '% of expenses)'; }
        } })
      },
      scales: {
        x: { beginAtZero: true, grid: { color: T.grid }, border: { display: false },
             ticks: { callback: function (v) { return _compact(v); }, color: T.tick, font: { size: 10, family: 'DM Sans' } } },
        y: { grid: { display: false }, border: { display: false },
             ticks: { color: T.ink, font: { size: 11, family: 'DM Sans', weight: '600' } } }
      }
    },
    plugins: [_rowLabels]
  });
}

// ── Monthly: money in vs money out, with the result as a line ─
// Revenue (black) and expenses (grey) side by side for each month; the dashed
// line is net income (revenue − expenses) and drops below zero in a losing
// month. Months that haven't happened yet are left empty, not drawn as zero.
function buildMonthlyChart() {
  var el = document.getElementById('monthlyChart');
  if (!el) return;
  if (_monthlyChartInst) { _monthlyChartInst.destroy(); _monthlyChartInst = null; }
  var T = window.chartTheme();
  var d = CHART_DATA, hi = MONTHLY_HIGHLIGHT_IDX;
  var future = function (i) { return hi >= 0 && i > hi; };
  var rev = d.monthRev.map(function (v, i) { return future(i) ? null : v; });
  var exp = d.monthRev.map(function (v, i) { return future(i) ? null : v - d.monthNet[i]; });
  var net = d.monthNet.map(function (v, i) { return future(i) ? null : v; });
  _monthlyChartInst = new Chart(el, {
    data: {
      labels: d.monthLabels,
      datasets: [
        { type: 'line', label: 'Net income (revenue − expenses)', data: net, order: 0,
          borderColor: T.series[2], backgroundColor: T.paper, borderWidth: 2, borderDash: [5, 4],
          pointStyle: 'rectRot', pointRadius: 4, pointHoverRadius: 6, pointBorderWidth: 2, tension: 0.25, spanGaps: false },
        { type: 'bar', label: 'Revenue (collected)', data: rev, order: 1,
          backgroundColor: T.ink, borderRadius: 3, barPercentage: 0.85, categoryPercentage: 0.7 },
        { type: 'bar', label: 'Expenses', data: exp, order: 2,
          backgroundColor: T.series[1], borderRadius: 3, barPercentage: 0.85, categoryPercentage: 0.7 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 8 } },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'start', labels: Object.assign(_monoLegend(T), {
          usePointStyle: true,
          sort: function (a, b) { var o = [1, 2, 0]; return o.indexOf(a.datasetIndex) - o.indexOf(b.datasetIndex); },
          generateLabels: function (chart) {
            return Chart.defaults.plugins.legend.labels.generateLabels(chart).map(function (l) {
              if (l.datasetIndex === 0) { l.pointStyle = 'line'; l.lineDash = [5, 4]; l.lineWidth = 2; l.strokeStyle = T.series[2]; }
              return l;
            });
          }
        }) },
        tooltip: _monoTooltip(T, {
          itemSort: function (a, b) { return a.datasetIndex === 0 ? 1 : b.datasetIndex === 0 ? -1 : a.datasetIndex - b.datasetIndex; },
          callbacks: {
            label: function (c) {
              if (c.parsed.y === null) return null;
              return ' ' + c.dataset.label.replace(/ \(.*\)/, '') + ': ' + (c.parsed.y < 0 ? '−' : '') + _phpFmt(Math.abs(c.parsed.y));
            },
            afterBody: function (items) {
              var r = d.monthRev[items[0].dataIndex], n = d.monthNet[items[0].dataIndex];
              return r > 0 ? [' Margin: ' + Math.round(n / r * 100) + '%'] : [];
            }
          }
        })
      },
      scales: {
        y: { beginAtZero: true,
             grid: { color: function (c) { return c.tick.value === 0 ? T.tick : T.grid; }, lineWidth: function (c) { return c.tick.value === 0 ? 1.5 : 1; } },
             border: { display: false },
             ticks: { callback: function (v) { return _compact(v); }, color: T.tick, font: { size: 10, family: 'DM Sans' } } },
        x: { grid: { display: false }, border: { display: false },
             ticks: { font: function (c) { return { size: 11, family: 'DM Sans', weight: c.index === hi ? '800' : '500' }; },
                      color: function (c) { return future(c.index) ? T.muted : (c.index === hi ? T.ink : T.tick); }, padding: 6 } }
      }
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  // Period dropdown wires up loadUnitChart on change
  var ucSel = document.getElementById('unitChartPeriod');
  if (ucSel) {
    ucSel.addEventListener('change', function() { loadUnitChart(this.value); });
    loadUnitChart(UNIT_CHART_INIT);
  }
  // Period dropdown wires up loadCatChart on change
  var ccSel = document.getElementById('catChartPeriod');
  if (ccSel) {
    ccSel.addEventListener('change', function() { loadCatChart(this.value); });
    loadCatChart(CAT_CHART_INIT);
  }
  buildMonthlyChart();

  // Re-theme every chart when the user toggles dark mode (§3.4)
  window.addEventListener('laskie:themechange', function() {
    buildMonthlyChart();
    if (_unitChartData) _renderUnitChart(_unitChartData);
    if (_catChartData)  _renderCatChart(_catChartData);
  });

  // ── DataTables wiring for the three dashboard tables ──────────────
  // Same dom template / language strings as my_summary.php so the look
  // and feel matches across the app.
  var dtDom = '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rtip';
  var dtLang = { search:'Filter:', lengthMenu:'Show _MENU_', info:'_START_–_END_ of _TOTAL_' };

  if (document.getElementById('perUnitTable')) {
    $('#perUnitTable').DataTable({
      pageLength: 50,                          // typical install has ~30 units; one page is plenty
      order: [[4,'desc']],                     // Net descending — best earners first
      dom: dtDom,
      language: dtLang
    });
  }

  if (document.getElementById('monthlySummaryTable')) {
    $('#monthlySummaryTable').DataTable({
      paging: false,                           // 12 rows always; no need to paginate
      order: [[0,'asc']],                      // chronological (uses data-order=1..12)
      info: false,                             // hide "1–12 of 12" — would be noise
      dom: '<"d-flex justify-content-end mb-2"f>rt',
      language: { search:'Filter:' }
    });
  }

  if (document.getElementById('balanceTable')) {
    // Initial order comes from PHP (most-behind first); clicking a header re-sorts.
    $('#balanceTable').DataTable({
      paging: false,
      order: [],
      autoWidth: false,
      columnDefs: [{ orderable: false, targets: 5 }],
      dom: '<"d-flex justify-content-end mb-2"f>rt',
      language: { search:'Filter:' }
    });
  }

  if (document.getElementById('unitStatusTable')) {
    // scrollY keeps the previous fixed-height feel (the column used to be
    // pinned at 680px). Search box lets the user type "vacant"/"paid"/etc.
    // to filter; default order surfaces late > unpaid > paid > vacant.
    $('#unitStatusTable').DataTable({
      paging: false,
      scrollY: '600px',
      scrollCollapse: true,
      order: [],                     // PHP already sorts most urgent first
      autoWidth: false,
      dom: '<"d-flex justify-content-end mb-2"f>rt',
      language: { search:'Filter:' }
    });
  }
});
</script>

<?php if (isAdmin() && $balanceRows !== null): ?>
<script>
// Demand letter dialog. The form posts to admin/demand_letter.php in a new tab
// (the PDF opens there to print or save); this page reloads afterwards so the
// row shows the letter's number.
var dmModal = null;
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('demandModal');
  if (!el) return;
  dmModal = new bootstrap.Modal(el);
  var form = document.getElementById('demandForm');
  form.addEventListener('change', dmCheckRc);
  form.addEventListener('submit', function () {
    document.getElementById('dmGo').disabled = true;
    setTimeout(function () { dmModal.hide(); showToast('Demand letter issued — it opened in a new tab.', 'success'); }, 300);
    setTimeout(function () { location.reload(); }, 2500);
  });
});
var _dm = {};
function openDemand(d) {
  _dm = d;
  document.getElementById('dmTenant').value = d.id;
  document.getElementById('dmTitle').textContent = 'Demand letter — ' + d.name + ' (' + d.unit + ')';
  var months = parseFloat(d.months || 0);
  document.getElementById('dmSummary').textContent = 'Total due ' + fmt(d.total) + ' — rent ' + fmt(d.rent)
    + ' (' + months.toFixed(1) + ' months)' + (parseFloat(d.svc) > 0 ? ', charges ' + fmt(d.svc) : '') + '.';
  document.querySelector('#demandForm input[value="pay"]').checked = true;
  document.getElementById('dmDays').value = <?= DEMAND_DEFAULT_DAYS ?>;
  document.getElementById('dmGo').disabled = false;
  dmCheckRc();
  dmModal.show();
}
// Rent-controlled units (₱10,000/mo or less): RA 9653 §9 makes arrears a ground
// for ejectment only at three months. Warn, don't block.
function dmCheckRc() {
  var vacate = document.querySelector('#demandForm input[name="letter_type"]:checked').value === 'vacate';
  var w = document.getElementById('dmRcWarn');
  var show = vacate && _dm.rc === '1' && parseFloat(_dm.months || 0) < 3;
  w.style.display = show ? '' : 'none';
  w.textContent = show ? 'This unit is rent-controlled (RA 9653). Non-payment becomes a ground for ejectment only at three months of arrears — this tenant is at '
    + parseFloat(_dm.months).toFixed(1) + '. A Demand to Pay may be the better step for now.' : '';
}
</script>
<?php endif; ?>
<?php reconEmit('dashboard', [
    'year' => $selectedYear, 'total_rev' => (string)$totalRev, 'total_exp' => (string)$totalExp,
    'monthly_rev' => array_values($monthlyRev), 'monthly_exp' => array_values($monthlyExp),
    'unit_rev' => $unitRevenue, 'unit_exp' => $unitExpenses,
    'arrears_current' => (string)($arrearsCurrent ?? ''), 'arrears_past' => (string)($arrearsPast ?? ''),
    'tracker' => array_map(fn($r) => ['tenant_id' => (int)$r['tenant']['id'], 'unit_id' => (int)$r['tenant']['unit_id'],
        'balance' => $r['st']['balance'], 'rent_owed' => $r['st']['rent_owed'], 'svc_owed' => $r['st']['svc_owed'], 'flagged' => $r['st']['flagged']], $balanceRows ?? []),
    'unit_status' => array_map(fn($r) => ['unit_id' => (int)$r['u']['id'], 'state' => $r['state'], 'billed' => $r['billed'] ?? null,
        'expected' => $r['expected'] ?? null, 'paid' => $r['paid'] ?? null, 'left' => $r['left'] ?? null], $usRows ?? []),
]); ?>
<?php include 'includes/footer.php'; ?>
