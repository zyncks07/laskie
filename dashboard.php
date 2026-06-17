<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
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
               t.full_name AS tenant_name, t.contract_start,
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

    $today = (int)date('j');
}

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
.db-accent             { border-left: 3px solid var(--ink); }

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
</div>

<?php if ($selectedYear === $curYear): ?>
<!-- ── Row 2: Month summary + Unit status | Bar chart ─────── -->
<div class="row g-2 db-row">

  <div class="col-lg-5 d-flex flex-column gap-2">

    <!-- Month-to-date -->
    <div class="card db-card db-accent">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-calendar-day me-1"></i><?= date('F Y') ?> — Month to Date</span>
      </div>
      <div class="card-body py-1">
        <div class="row g-0 text-center">
          <div class="col-4 border-end">
            <div class="text-muted" style="font-size:11px">Revenue</div>
            <div class="fw-bold num" style="font-size:14px"><?= money($cmRev) ?></div>
          </div>
          <div class="col-4 border-end">
            <div class="text-muted" style="font-size:11px">Expenses</div>
            <div class="fw-bold num" style="font-size:14px"><?= money($cmExp) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted" style="font-size:11px">Net Income</div>
            <div class="num <?= money_gte($cmNet, '0.00') ? 'delta-pos' : 'delta-neg' ?>" style="font-size:14px"><?= money($cmNet) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Unit payment status -->
    <?php if (!empty($unitStatusData)): ?>
    <div class="card db-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-building me-1"></i><?= date('F Y') ?> — Unit Status</span>
      </div>
      <div class="card-body py-2">
        <!-- data-order on Status column = numeric weight so DataTables can
             group "unpaid" rows ahead of "paid" / "vacant" when sorted asc.
             0 = late (most urgent), 1 = unpaid not late, 2 = paid, 3 = vacant -->
        <table class="table db-tbl mb-0" id="unitStatusTable" style="width:100%">
          <thead>
            <tr><th>Unit</th><th>Tenant</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($unitStatusData as $u):
            if ($u['status'] === 'vacant'): ?>
            <tr>
              <td class="fw-600"><?= clean($u['unit_name']) ?></td>
              <td class="text-muted">—</td>
              <td data-order="3"><span class="muted-pill">Vacant</span></td>
            </tr>
          <?php else:
              $rate         = getRateForMonth($pdo, (int)$u['id'], (float)$u['monthly_rate'], $curMonth, $curYear);
              $expected     = prorateFirstMonth($rate, (int)$u['due_day'], $u['contract_start'] ?? null, $curMonth, $curYear);
              $curPaid      = $u['cur_paid'];
              $curMonthPaid = money_is_pos($expected) && money_gte($curPaid, $expected);
              // 10-day grace period — clamped to month-end so units with
              // due_day=30 in February (or any short month) still trigger.
              $daysInCurMo  = (int)date('t', mktime(0,0,0,$curMonth,1,$curYear));
              $graceDay     = min((int)$u['due_day'] + 10, $daysInCurMo);
              $isLate       = !$curMonthPaid && money_is_pos($expected) && $today > $graceDay;
              $amountDue    = money_max('0.00', money_sub($expected, $curPaid));
              $sortKey      = $curMonthPaid ? 2 : ($isLate ? 0 : 1);
          ?>
            <tr>
              <td class="fw-600"><?= clean($u['unit_name']) ?></td>
              <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($u['tenant_name'] ?? '—') ?></td>
              <td data-order="<?= $sortKey ?>">
                <?php if ($curMonthPaid): ?>
                  <span class="ok-pill"><i class="fa-solid fa-check"></i>Paid</span>
                <?php else: ?>
                  <span class="attn-pill"><i class="fa-solid fa-xmark"></i><?= money($amountDue) ?></span>
                  <?php if ($isLate): ?>
                  <div class="stat-sub mt-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Late fee applies</div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>
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
          <span class="card-header-title me-auto"><i class="fa-solid fa-chart-bar me-1"></i>Revenue vs Expenses by Unit</span>
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
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-1"></i>Monthly Revenue & Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body db-chart"><canvas id="monthlyChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card h-100">
      <div class="card-header" style="display:flex;align-items:center;gap:8px">
        <span class="card-header-title me-auto"><i class="fa-solid fa-chart-pie me-1"></i>Expenses by Category</span>
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
          <span class="card-header-title me-auto"><i class="fa-solid fa-chart-bar me-1"></i>Revenue vs Expenses by Unit</span>
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
        <span class="card-header-title me-auto"><i class="fa-solid fa-chart-pie me-1"></i>Expenses by Category</span>
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
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-1"></i>Monthly Revenue & Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body db-chart"><canvas id="monthlyChart"></canvas></div>
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
          <!-- Unallocated row — sums revenue/expenses whose unit_id is NULL
               (rows that survived a unit deletion via FK ON DELETE SET NULL).
               Without this, $totalRev/$totalExp at the top would silently
               disagree with the per-unit body sum below. -->
          <tr class="db-row-now">
            <td class="fw-600"><i class="fa-solid fa-link-slash me-1"></i>Unallocated</td>
            <td><span class="badge" style="background:var(--ink);color:var(--paper);font-size:10px">deleted unit</span></td>
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

function _renderUnitChart(data) {
  if (_unitChartInst) { _unitChartInst.destroy(); _unitChartInst = null; }
  var canvas = document.getElementById('unitChart');
  if (!canvas) return;
  var T = window.chartTheme();
  var ctx = canvas.getContext('2d');

  // Update stats strip
  var net = (data.totalRev || 0) - (data.totalExp || 0);
  var ucRev = document.getElementById('ucRev'), ucExp = document.getElementById('ucExp'),
      ucNet = document.getElementById('ucNet'), ucTitle = document.getElementById('ucTitle');
  if (ucRev)   ucRev.textContent = _phpFmt(data.totalRev);
  if (ucExp)   ucExp.textContent = _phpFmt(data.totalExp);
  if (ucNet) {
    ucNet.textContent = (net < 0 ? '▼ ' : '▲ ') + _phpFmt(Math.abs(net));
    ucNet.style.fontWeight = net < 0 ? '700' : '600';
  }
  if (ucTitle) ucTitle.textContent = data.title || '';

  // Net income per unit = revenue − expenses (top, black); expenses = grey base
  var netData = (data.revenue || []).map(function(r, i) { return r - (data.expenses[i] || 0); });

  _unitChartInst = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [
        { label: 'Expenses',   data: data.expenses, backgroundColor: T.series[1], borderColor: T.paper, borderWidth: 1, borderRadius: 3, stack: 'unit' },
        { label: 'Net Income', data: netData,        backgroundColor: T.ink,       borderColor: T.paper, borderWidth: 1, borderRadius: 3, stack: 'unit' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      animation: { duration: 420, easing: 'easeOutQuart' },
      layout: { padding: { top: 8 } },
      plugins: {
        legend: { position: 'top', align: 'start', labels: _monoLegend(T) },
        tooltip: _monoTooltip(T, {
          mode: 'index', intersect: false,
          callbacks: {
            label: function(c) { return ' ' + c.dataset.label + ': ' + _phpFmt(c.parsed.y); },
            afterBody: function(items) {
              var rev = items.reduce(function(s, c) { return s + (c.parsed.y || 0); }, 0);
              return [' Revenue: ' + _phpFmt(rev)];
            }
          }
        })
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false },
             ticks: { color: T.tick, font: { size: 11, family: 'DM Sans' }, padding: 6 } },
        y: { stacked: true, beginAtZero: true, grid: { color: T.grid }, border: { display: false },
             ticks: { callback: _phpFmt, color: T.tick, font: { size: 10, family: 'DM Sans' } } }
      }
    }
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

function _renderCatChart(data) {
  var el = document.getElementById('catChart');
  if (!el) return;
  if (_catChartInst) { _catChartInst.destroy(); _catChartInst = null; }
  var T = window.chartTheme();
  // grayscale ramp spread across slices (cycles if categories exceed ramp)
  var ramp = [T.ink, T.series[2], T.series[1], T.series[3], T.series[4], T.tick, T.grid];
  var colors = (data.labels || []).map(function(_, i) { return ramp[i % ramp.length]; });
  _catChartInst = new Chart(el, {
    type: 'doughnut',
    data: { labels: data.labels,
      datasets: [{ data: data.totals, backgroundColor: colors, hoverOffset: 8, borderWidth: 2, borderColor: T.paper }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '62%',
      plugins: {
        legend: { position: 'bottom', labels: _monoLegend(T) },
        tooltip: _monoTooltip(T, { callbacks: { label: function(c) { return ' ' + c.label + ': ' + _phpFmt(c.parsed); } } })
      }
    }
  });
}

function buildMonthlyChart() {
  var el = document.getElementById('monthlyChart');
  if (!el) return;
  if (_monthlyChartInst) { _monthlyChartInst.destroy(); _monthlyChartInst = null; }
  var T = window.chartTheme();
  var d = CHART_DATA;
  // Expenses (grey base) + Net Income (black top) stacked = Revenue height
  var monthExp = d.monthRev.map(function(r, i) { return r - d.monthNet[i]; });
  _monthlyChartInst = new Chart(el, {
    type: 'bar',
    data: {
      labels: d.monthLabels,
      datasets: [
        { label: 'Expenses',   data: monthExp,    backgroundColor: T.series[1], borderColor: T.paper, borderWidth: 1, borderRadius: 3, stack: 'monthly' },
        { label: 'Net Income', data: d.monthNet,  backgroundColor: T.ink,       borderColor: T.paper, borderWidth: 1, borderRadius: 3, stack: 'monthly' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 8 } },
      plugins: {
        legend: { position: 'top', align: 'start', labels: _monoLegend(T) },
        tooltip: _monoTooltip(T, {
          mode: 'index', intersect: false,
          callbacks: {
            label: function(c) { return ' ' + c.dataset.label + ': ' + _phpFmt(c.parsed.y); },
            afterBody: function(items) {
              var rev = items.reduce(function(s, c) { return s + (c.parsed.y || 0); }, 0);
              return [' Revenue: ' + _phpFmt(rev)];
            }
          }
        })
      },
      scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false },
             ticks: { color: T.tick, font: { size: 11, family: 'DM Sans' }, padding: 6 } },
        y: { stacked: true, beginAtZero: true, grid: { color: T.grid }, border: { display: false },
             ticks: { callback: _phpFmt, color: T.tick, font: { size: 10, family: 'DM Sans' } } }
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

  if (document.getElementById('unitStatusTable')) {
    // scrollY keeps the previous fixed-height feel (the column used to be
    // pinned at 680px). Search box lets the user type "vacant"/"paid"/etc.
    // to filter; default order surfaces late > unpaid > paid > vacant.
    $('#unitStatusTable').DataTable({
      paging: false,
      scrollY: '600px',
      scrollCollapse: true,
      order: [[2,'asc']],
      dom: '<"d-flex justify-content-end mb-2"f>rt',
      language: { search:'Filter:' }
    });
  }
});
</script>

<?php include 'includes/footer.php'; ?>
