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
$revRows = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided' GROUP BY unit_id");
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
$revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided'");
$expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL");
for ($m = 1; $m <= 12; $m++) {
    [$mStart, $mEnd] = monthRange($m, $selectedYear);
    $revStmt->execute([$mStart, $mEnd]);
    $monthlyRev[$m] = (string)$revStmt->fetchColumn();
    $expStmt->execute([$mStart, $mEnd]);
    $monthlyExp[$m] = (string)$expStmt->fetchColumn();
}

// ─── Expense by category ─────────────────────────────────────
$catExp = $pdo->prepare("SELECT ec.name, COALESCE(SUM(e.amount),0) as total FROM expense_categories ec LEFT JOIN expenses e ON e.category_id=ec.id AND e.expense_date >= ? AND e.expense_date < ? AND e.deleted_at IS NULL GROUP BY ec.id ORDER BY total DESC");
$catExp->execute([$yrStart, $yrEnd]);
$catExpData = $catExp->fetchAll();

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
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided'");
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
            SELECT unit_id, SUM(amount) AS paid
            FROM payments
            WHERE payment_type = 'rent'
              AND period_month = ? AND period_year = ?
              AND deleted_at IS NULL AND status != 'voided'
            GROUP BY unit_id
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
/* ── Dashboard — Magix-style overrides ───────────────────── */
.page-header           { margin-bottom: 14px; }
.db-row                { margin-bottom: 12px; }

.db-card               { background: var(--laskie-card-bg); border: 1px solid transparent; border-radius: var(--laskie-radius-card); box-shadow: var(--laskie-shadow-card); }
.db-card .card-header  { padding: 12px 16px; border-bottom: 1px solid var(--laskie-divider); }
.db-card .card-body    { padding: 12px 16px; }

/* Stat cards — icon in top-right corner, label + value left-aligned */
.db-stat {
    position: relative;
    display: block;
    padding: 18px 18px 16px;
    background: var(--laskie-card-bg);
    border-radius: var(--laskie-radius-stat);
    box-shadow: var(--laskie-shadow-card);
    border: 1px solid transparent;
    min-height: 92px;
}
.db-stat .stat-icon {
    position: absolute;
    top: 14px; right: 14px;
    width: 36px; height: 36px;
    border-radius: 50%;
    font-size: 13px;
}
.db-stat .stat-body  { padding-right: 44px; min-width: 0; }
.db-stat .stat-label { font-size: 11px; color: var(--laskie-ink-mute); font-weight: 500; margin-bottom: 8px; letter-spacing: .02em; }
.db-stat .stat-value { font-size: 22px; font-weight: 700; color: var(--laskie-ink); line-height: 1.1; overflow-wrap: anywhere; }
.db-stat .stat-sub   { font-size: 10.5px; color: var(--laskie-ink-faint); margin-top: 6px; }

/* Mobile: 2-col stat-card layout can't fit a ₱500k value at 22px next to a 36px icon.
   Pull padding/icon in and scale the value type so the icon and number can't touch. */
@media (max-width: 575.98px) {
  .db-stat                { padding: 14px 14px 12px; min-height: 78px; }
  .db-stat .stat-icon     { top: 10px; right: 10px; width: 30px; height: 30px; font-size: 11.5px; }
  .db-stat .stat-body     { padding-right: 38px; }
  .db-stat .stat-label    { font-size: 10.5px; margin-bottom: 6px; }
  .db-stat .stat-value    { font-size: 16.5px; }
  .db-stat .stat-sub      { font-size: 10px; margin-top: 4px; }
}

/* Tighter table cells inside dashboard cards */
.db-tbl td, .db-tbl th { padding: 7px 12px !important; font-size: 12.5px; }
.db-tbl thead th       { background: transparent; border-bottom: 1px solid var(--laskie-divider); }
.db-tbl tfoot tr       { background: #faf7ef !important; }

.db-chart              { position: relative; height: 220px; }
.db-card-fill          { display: flex; flex-direction: column; }
.db-chart-grow         { position: relative; flex: 1; min-height: 220px; }

/* Magix dark-hero treatment — shared by every box on the dashboard.
   Goal: SOLID violet plane inside the card — no internal hairlines,
   dividers, hover tints or zebra stripes. */
.db-card.dark-card                    { background: var(--laskie-card-dark); color: #fff; border: none; box-shadow: var(--laskie-shadow-hero); }
.db-card.dark-card .card-header       { border-bottom: none !important; padding: 16px 20px 4px; }
.db-card.dark-card .card-header-title { color: #fff; font-size: 14.5px; letter-spacing: .01em; }
.db-card.dark-card .card-body         { padding: 10px 18px 16px; }
.db-card.dark-card .db-chart          { height: 260px; }

/* Unit-chart card — period strip, selector, spinner — borderless */
.db-card.dark-card #unitChartStats          { border: none !important; padding-top: 6px !important; margin-top: 4px !important; }
.db-card.dark-card #unitChartStats span     { color: rgba(255,255,255,.55) !important; }
.db-card.dark-card #unitChartStats #ucTitle { color: rgba(255,255,255,.45) !important; }
.db-card.dark-card #unitChartPeriod {
    background: rgba(255,255,255,.06);
    border-color: transparent;
    color: #fff;
    background-image: linear-gradient(45deg, transparent 50%, rgba(255,255,255,.65) 50%),
                      linear-gradient(135deg, rgba(255,255,255,.65) 50%, transparent 50%),
                      none;
    background-position: calc(100% - 14px) 50%, calc(100% - 9px) 50%;
    background-size: 5px 5px;
    background-repeat: no-repeat;
}
.db-card.dark-card #unitChartPeriod:focus    { box-shadow: 0 0 0 3px rgba(239,159,39,.25); border-color: transparent; }
.db-card.dark-card #unitChartPeriod option,
.db-card.dark-card #unitChartPeriod optgroup { color: var(--laskie-ink); background: #fff; }
.db-card.dark-card #unitChartSpinner         { background: rgba(38,33,92,.78) !important; }

/* Stat cards on dark — solid plane */
.db-stat.dark-card {
    background: var(--laskie-card-dark);
    color: #fff;
    border: none;
    box-shadow: var(--laskie-shadow-hero);
}
.db-stat.dark-card .stat-label { color: rgba(255,255,255,.55); }
.db-stat.dark-card .stat-value { color: #fff; }
.db-stat.dark-card .stat-sub   { color: rgba(255,255,255,.35); }

/* Stat-icon: solid coloured chip on the violet plane (no pastel pill) */
.db-stat.dark-card .stat-icon.blue,
.db-stat.dark-card .stat-icon.purple { background: var(--laskie-indigo); color: #fff; }
.db-stat.dark-card .stat-icon.red    { background: var(--laskie-coral);  color: #fff; }
.db-stat.dark-card .stat-icon.green,
.db-stat.dark-card .stat-icon.teal   { background: var(--laskie-teal);   color: #fff; }
.db-stat.dark-card .stat-icon.amber  { background: var(--laskie-amber);  color: #fff; }

/* Tables on dark cards — zero internal borders, zero hover/foot tints */
.db-card.dark-card .table                          { color: rgba(255,255,255,.88); margin: 0; --bs-table-bg: transparent; --bs-table-color: rgba(255,255,255,.88); --bs-table-striped-bg: transparent; --bs-table-hover-bg: transparent; --bs-table-border-color: transparent; }
.db-card.dark-card .table thead th                 { color: rgba(255,255,255,.5); background: transparent; border: none !important; }
.db-card.dark-card .table tbody td                 { color: rgba(255,255,255,.88); background: transparent; border: none !important; }
.db-card.dark-card .table tbody tr:last-child td   { border: none !important; }
.db-card.dark-card .table tbody tr:hover td        { background: transparent !important; }
.db-card.dark-card .table tfoot tr                 { background: transparent !important; color: #fff; }
.db-card.dark-card .table tfoot td                 { border: none !important; }
.db-card.dark-card .text-muted                     { color: rgba(255,255,255,.45) !important; }
.db-card.dark-card .border-end                     { border-color: transparent !important; }

/* DataTables chrome on dark cards — borderless input pills */
.db-card.dark-card .dataTables_filter input,
.db-card.dark-card .dataTables_length select {
    background: rgba(255,255,255,.06);
    border: 1px solid transparent;
    color: #fff;
}
.db-card.dark-card .dataTables_filter input:focus,
.db-card.dark-card .dataTables_length select:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,159,39,.20); border-color: transparent; }
.db-card.dark-card .dataTables_filter input::placeholder { color: rgba(255,255,255,.35); }
.db-card.dark-card .dataTables_filter,
.db-card.dark-card .dataTables_length,
.db-card.dark-card .dataTables_info { color: rgba(255,255,255,.55); }
.db-card.dark-card .dataTables_paginate .paginate_button { color: rgba(255,255,255,.65) !important; border-color: transparent !important; background: transparent !important; }
.db-card.dark-card .dataTables_paginate .paginate_button:hover    { background: rgba(255,255,255,.08) !important; color: #fff !important; border-color: transparent !important; }
.db-card.dark-card .dataTables_paginate .paginate_button.current,
.db-card.dark-card .dataTables_paginate .paginate_button.current:hover { background: var(--laskie-amber) !important; color: #fff !important; border-color: transparent !important; }

/* DataTables scroll-y container (Unit Status) — kill the white inner border */
.db-card.dark-card .dataTables_scroll,
.db-card.dark-card .dataTables_scrollBody,
.db-card.dark-card .dataTables_scrollHead { border: none !important; background: transparent !important; }

/* Year-picker (page-header) — translucent pill on warm bg */
.page-header .form-select { background: rgba(255,255,255,.6); border-color: var(--laskie-divider); color: var(--laskie-ink); }
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

<!-- ── Row 1: Stat Cards ───────────────────────────────────── -->
<div class="row g-2 db-row">
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat dark-card">
      <div class="stat-icon blue"><i class="fa-solid fa-coins"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value"><?= money($totalRev) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat dark-card">
      <div class="stat-icon red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Expenses</div>
        <div class="stat-value"><?= money($totalExp) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat dark-card">
      <div class="stat-icon green"><i class="fa-solid fa-chart-line"></i></div>
      <div class="stat-body">
        <div class="stat-label">Net Income</div>
        <div class="stat-value" style="color:<?= money_gte($totalNet, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($totalNet) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat dark-card">
      <div class="stat-icon teal"><i class="fa-solid fa-door-open"></i></div>
      <div class="stat-body">
        <div class="stat-label">Units Occupied</div>
        <div class="stat-value"><?= $occupiedUnits ?> / <?= $totalUnits ?></div>
        <div class="stat-sub">Rental units</div>
      </div>
    </div>
  </div>
</div>

<?php if ($selectedYear === $curYear): ?>
<!-- ── Row 2: Month summary + Unit status | Bar chart ─────── -->
<div class="row g-2 db-row">

  <div class="col-lg-5 d-flex flex-column gap-2">

    <!-- Month-to-date -->
    <div class="card db-card dark-card" style="border-left:3px solid var(--laskie-amber)">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-calendar-day me-1"></i><?= date('F Y') ?> — Month to Date</span>
      </div>
      <div class="card-body py-1">
        <div class="row g-0 text-center">
          <div class="col-4 border-end">
            <div class="text-muted" style="font-size:11px">Revenue</div>
            <div class="fw-bold" style="font-size:14px;color:var(--primary)"><?= money($cmRev) ?></div>
          </div>
          <div class="col-4 border-end">
            <div class="text-muted" style="font-size:11px">Expenses</div>
            <div class="fw-bold" style="font-size:14px;color:var(--danger)"><?= money($cmExp) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted" style="font-size:11px">Net Income</div>
            <div class="fw-bold" style="font-size:14px;color:<?= money_gte($cmNet, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($cmNet) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Unit payment status -->
    <?php if (!empty($unitStatusData)): ?>
    <div class="card db-card dark-card">
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
              <td data-order="3"><span class="badge" style="background:rgba(255,255,255,.10);color:rgba(255,255,255,.65);font-size:10px">Vacant</span></td>
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
                  <span style="color:var(--success);font-weight:600;font-size:12px"><i class="fa-solid fa-circle-check me-1"></i>Paid</span>
                <?php else: ?>
                  <span style="color:var(--danger);font-weight:600;font-size:12px"><i class="fa-solid fa-circle-xmark me-1"></i><?= money($amountDue) ?></span>
                  <?php if ($isLate): ?>
                  <br><span style="color:var(--warning);font-size:11px"><i class="fa-solid fa-triangle-exclamation me-1"></i>Late fee applies</span>
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
    <div class="card db-card db-card-fill dark-card h-100">
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
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:6px;padding-top:6px;border-top:1px solid var(--laskie-divider)">
          <span style="color:var(--laskie-ink-mute)">Rev: <strong id="ucRev" style="color:var(--laskie-amber)">—</strong></span>
          <span style="color:var(--laskie-ink-mute)">Exp: <strong id="ucExp" style="color:var(--laskie-coral)">—</strong></span>
          <span style="color:var(--laskie-ink-mute)">Net: <strong id="ucNet">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:var(--laskie-ink-faint);font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart-grow" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,.82);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--laskie-amber)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <canvas id="unitChart"></canvas>
      </div>
    </div>
  </div>

</div><!-- /row 2 -->

<!-- ── Row 3 (current year): Monthly chart | Category pie ─── -->
<div class="row g-2 db-row">
  <div class="col-lg-8">
    <div class="card db-card dark-card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-1"></i>Monthly Revenue & Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body db-chart"><canvas id="monthlyChart"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card dark-card h-100">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-pie me-1"></i>Expenses by Category</span>
      </div>
      <div class="card-body db-chart"><canvas id="catChart"></canvas></div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Row 2 (other years): Bar chart | Category pie ─────── -->
<div class="row g-2 db-row">
  <div class="col-lg-8">
    <div class="card db-card dark-card">
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
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:6px;padding-top:6px;border-top:1px solid var(--laskie-divider)">
          <span style="color:var(--laskie-ink-mute)">Rev: <strong id="ucRev" style="color:var(--laskie-amber)">—</strong></span>
          <span style="color:var(--laskie-ink-mute)">Exp: <strong id="ucExp" style="color:var(--laskie-coral)">—</strong></span>
          <span style="color:var(--laskie-ink-mute)">Net: <strong id="ucNet">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:var(--laskie-ink-faint);font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,.82);z-index:10;align-items:center;justify-content:center;border-radius:0 0 var(--laskie-radius-card) var(--laskie-radius-card)">
          <div class="spinner-border spinner-border-sm" style="color:var(--laskie-amber)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <canvas id="unitChart"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card dark-card h-100">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-pie me-1"></i>Expenses by Category</span>
      </div>
      <div class="card-body db-chart"><canvas id="catChart"></canvas></div>
    </div>
  </div>
</div>

<!-- ── Row 3 (other years): Monthly chart ─────────────────── -->
<div class="row g-2 db-row">
  <div class="col-12">
    <div class="card db-card dark-card">
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
    <div class="card db-card dark-card">
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
          <tr<?= $isCurrent ? ' style="background:rgba(239,159,39,.18);"' : '' ?>>
            <td data-order="<?= $m ?>">
              <?= date('M', mktime(0,0,0,$m,1)) ?>
              <?php if ($isCurrent): ?><span class="badge ms-1" style="background:var(--laskie-amber);color:#fff;font-size:9px">Now</span><?php endif; ?>
            </td>
            <td class="text-end" data-order="<?= (float)$r ?>"><?= money($r) ?></td>
            <td class="text-end" data-order="<?= (float)$e ?>"><?= money($e) ?></td>
            <td class="text-end fw-bold" data-order="<?= (float)$n ?>" style="color:<?= money_gte($n, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($n) ?></td>
          </tr>
          <?php } ?>
          </tbody>
          <tfoot><tr style="font-weight:700">
            <td>Total</td>
            <td class="text-end"><?= money($totalRev) ?></td>
            <td class="text-end"><?= money($totalExp) ?></td>
            <td class="text-end" style="color:<?= money_gte($totalNet, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($totalNet) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card db-card dark-card">
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
            <td class="text-end fw-bold" data-order="<?= (float)$net ?>" style="color:<?= money_gte($net, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($net) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (money_is_pos($orphanRev) || money_is_pos($orphanExp)):
            $orphanNet = money_sub($orphanRev, $orphanExp);
          ?>
          <!-- Unallocated row — sums revenue/expenses whose unit_id is NULL
               (rows that survived a unit deletion via FK ON DELETE SET NULL).
               Without this, $totalRev/$totalExp at the top would silently
               disagree with the per-unit body sum below. -->
          <tr style="background:rgba(239,159,39,.15)">
            <td class="fw-600" style="color:var(--laskie-amber)"><i class="fa-solid fa-link-slash me-1"></i>Unallocated</td>
            <td><span class="badge" style="background:var(--laskie-amber);color:#fff;font-size:10px">deleted unit</span></td>
            <td class="text-end" data-order="<?= (float)$orphanRev ?>"><?= money($orphanRev) ?></td>
            <td class="text-end" data-order="<?= (float)$orphanExp ?>"><?= money($orphanExp) ?></td>
            <td class="text-end fw-bold" data-order="<?= (float)$orphanNet ?>" style="color:<?= money_gte($orphanNet, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($orphanNet) ?></td>
          </tr>
          <?php endif; ?>
          </tbody>
          <tfoot><tr style="font-weight:700">
            <td colspan="2">Total</td>
            <td class="text-end"><?= money($totalRev) ?></td>
            <td class="text-end"><?= money($totalExp) ?></td>
            <td class="text-end" style="color:<?= money_gte($totalNet, '0.00') ? 'var(--success)' : 'var(--danger)' ?>"><?= money($totalNet) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
var CHART_DATA = {
  catLabels:   <?= json_encode(array_column($catExpData, 'name')) ?>,
  catTotals:   <?= json_encode(array_map(fn($r) => (float)$r['total'], $catExpData)) ?>,
  monthLabels: <?= json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']) ?>,
  monthRev:    <?= json_encode(array_values($monthlyRev)) ?>,
  monthNet:    <?= json_encode(array_map(fn($m) => (float)money_sub($monthlyRev[$m], $monthlyExp[$m]), range(1,12))) ?>
};
var UNIT_CHART_API  = '<?= pageUrl('api/unit_chart_api.php') ?>';
var UNIT_CHART_INIT = '<?= $chartInitPeriod ?>';
// -1 when viewing a past/future year (no bar gets highlighted); 0–11 otherwise
var MONTHLY_HIGHLIGHT_IDX = <?= ($selectedYear === $curYear) ? ($curMonth - 1) : -1 ?>;
</script>
<script>
// ── Shared formatter ─────────────────────────────────────────
var _phpFmt = function(v) {
  return '₱' + parseFloat(v || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
};

// ── Unit chart — AJAX-driven ─────────────────────────────────
var _unitChartInst = null;

function loadUnitChart(period) {
  period = period || (document.getElementById('unitChartPeriod') ? document.getElementById('unitChartPeriod').value : null);
  if (!period) return;
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
      if (res.success) _renderUnitChart(res);
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
  var ctx = canvas.getContext('2d');
  var h = canvas.parentElement.offsetHeight || 300;

  var gRev = ctx.createLinearGradient(0, 0, 0, h);
  gRev.addColorStop(0,    '#A26515');
  gRev.addColorStop(0.45, '#EF9F27');
  gRev.addColorStop(1,    '#FAC775');

  var gExp = ctx.createLinearGradient(0, 0, 0, h);
  gExp.addColorStop(0,    '#7A2A12');
  gExp.addColorStop(0.45, '#D85A30');
  gExp.addColorStop(1,    '#F5C4B3');

  // Update stats strip
  var net = (data.totalRev || 0) - (data.totalExp || 0);
  var ucRev = document.getElementById('ucRev'), ucExp = document.getElementById('ucExp'),
      ucNet = document.getElementById('ucNet'), ucTitle = document.getElementById('ucTitle');
  if (ucRev)   ucRev.textContent = _phpFmt(data.totalRev);
  if (ucExp)   ucExp.textContent = _phpFmt(data.totalExp);
  if (ucNet) {
    ucNet.textContent = (net < 0 ? '-' : '') + _phpFmt(Math.abs(net));
    ucNet.style.color = net >= 0 ? 'var(--laskie-teal)' : 'var(--laskie-coral)';
  }
  if (ucTitle) ucTitle.textContent = data.title || '';

  _unitChartInst = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [
        { label: 'Revenue',  data: data.revenue,  backgroundColor: gRev, borderRadius: 10, borderSkipped: false, borderWidth: 0, barPercentage: 0.7, categoryPercentage: 0.7 },
        { label: 'Expenses', data: data.expenses, backgroundColor: gExp, borderRadius: 10, borderSkipped: false, borderWidth: 0, barPercentage: 0.7, categoryPercentage: 0.7 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 420, easing: 'easeOutQuart' },
      layout: { padding: { top: 24 } },
      plugins: {
        legend: {
          position: 'top',
          align: 'start',
          labels: { font: { size: 11, family: 'DM Sans' }, color: 'rgba(255,255,255,.75)', boxWidth: 8, boxHeight: 8, padding: 10, usePointStyle: true, pointStyleWidth: 8 }
        },
        tooltip: {
          mode: 'index',
          intersect: false,
          backgroundColor: '#EF9F27',
          titleColor: 'rgba(255,255,255,.85)',
          bodyColor: '#fff',
          titleFont: { size: 10, family: 'DM Sans', weight: '500' },
          bodyFont:  { size: 12, family: 'DM Sans', weight: '700' },
          padding: { x: 12, y: 8 },
          cornerRadius: 14,
          caretSize: 6,
          caretPadding: 8,
          displayColors: false,
          callbacks: {
            label: function(c) { return c.dataset.label + ': ' + _phpFmt(c.parsed.y); },
            afterBody: function(items) {
              if (items.length < 2) return [];
              var n = (items[0].parsed.y || 0) - (items[1].parsed.y || 0);
              return ['Net: ' + (n < 0 ? '-' : '') + _phpFmt(Math.abs(n))];
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid:   { color: 'rgba(255,255,255,.08)' },
          border: { display: false },
          ticks:  { callback: _phpFmt, font: { size: 10, family: 'DM Sans' }, color: 'rgba(255,255,255,.45)' }
        },
        x: {
          grid:   { display: false },
          border: { display: false },
          ticks:  { font: { size: 11, family: 'DM Sans', weight: '500' }, color: 'rgba(255,255,255,.65)', padding: 6 }
        }
      }
    }
  });
}

// ── Page init ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var d = CHART_DATA;
  var pallette = ['#EF9F27','#1D9E75','#D85A30','#5754A8','#FAC775','#9FE1CB','#F5C4B3','#26215C','#D88914','#085041','#712B13','#2B295F'];

  // Period dropdown wires up loadUnitChart on change
  var ucSel = document.getElementById('unitChartPeriod');
  if (ucSel) {
    ucSel.addEventListener('change', function() { loadUnitChart(this.value); });
    loadUnitChart(UNIT_CHART_INIT);
  }

  var catFiltered = { labels: [], data: [] };
  d.catLabels.forEach(function(label, i) {
    if (d.catTotals[i] > 0) { catFiltered.labels.push(label); catFiltered.data.push(d.catTotals[i]); }
  });
  new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: {
      labels: catFiltered.labels,
      datasets: [{ data: catFiltered.data, backgroundColor: pallette, hoverOffset: 8, borderWidth: 2, borderColor: '#26215C' }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '62%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 10, family: 'DM Sans' }, color: 'rgba(255,255,255,.75)', padding: 8, boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyleWidth: 8 }
        },
        tooltip: {
          backgroundColor: '#EF9F27',
          titleColor: 'rgba(255,255,255,.85)',
          bodyColor: '#fff',
          titleFont: { size: 10, family: 'DM Sans', weight: '500' },
          bodyFont:  { size: 12, family: 'DM Sans', weight: '700' },
          padding: { x: 12, y: 8 },
          cornerRadius: 14,
          caretSize: 6,
          caretPadding: 8,
          displayColors: false,
          callbacks: { label: function(c) { return _phpFmt(c.parsed); } }
        }
      }
    }
  });

  // Magix-style bar: two-tone amber gradient per bar; current month painted teal.
  function _barColor(ctx) {
    if (ctx.dataIndex === MONTHLY_HIGHLIGHT_IDX) return '#1D9E75';
    var chart = ctx.chart;
    var area  = chart.chartArea;
    if (!area) return '#EF9F27';
    var g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0,    '#A26515');   // top — deep amber
    g.addColorStop(0.45, '#EF9F27');   // mid — brand amber
    g.addColorStop(1,    '#FAC775');   // bottom — soft amber
    return g;
  }
  function _tickColor(ctx) {
    return ctx.index === MONTHLY_HIGHLIGHT_IDX ? '#fff' : 'rgba(255,255,255,.55)';
  }
  function _tickWeight(ctx) {
    return ctx.index === MONTHLY_HIGHLIGHT_IDX ? '700' : '500';
  }

  new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels: d.monthLabels,
      datasets: [
        { type: 'bar',  label: 'Revenue',    data: d.monthRev,
          backgroundColor: _barColor,
          borderRadius: 10, borderSkipped: false, borderWidth: 0,
          barPercentage: 0.55, categoryPercentage: 0.7, yAxisID: 'y' },
        { type: 'line', label: 'Net Income', data: d.monthNet,
          borderColor: 'rgba(255,255,255,.55)', backgroundColor: 'transparent',
          borderDash: [5,5], borderWidth: 1.5,
          pointRadius: 2, pointBackgroundColor: 'rgba(255,255,255,.85)', pointBorderWidth: 0,
          tension: .25, fill: false, yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 30 } },
      plugins: {
        legend: { position: 'top', align: 'start', labels: { font: { size: 11, family: 'DM Sans' }, color: 'rgba(255,255,255,.75)', boxWidth: 8, boxHeight: 8, padding: 8, usePointStyle: true, pointStyleWidth: 8 } },
        tooltip: {
          backgroundColor: '#EF9F27',
          titleColor: 'rgba(255,255,255,.85)',
          bodyColor: '#fff',
          titleFont: { size: 10, family: 'DM Sans', weight: '500' },
          bodyFont:  { size: 13, family: 'DM Sans', weight: '700' },
          padding: { x: 12, y: 8 },
          cornerRadius: 14,
          caretSize: 6,
          caretPadding: 8,
          displayColors: false,
          callbacks: { label: function(c) { return _phpFmt(c.parsed.y); } }
        }
      },
      scales: {
        y: { beginAtZero: true,
             ticks: { callback: _phpFmt, font: { size: 10, family: 'DM Sans' }, color: 'rgba(255,255,255,.45)' },
             grid:  { color: 'rgba(255,255,255,.08)' },
             border:{ display: false } },
        x: { grid: { display: false }, border: { display: false },
             ticks: { font: { size: 11, family: 'DM Sans', weight: _tickWeight }, color: _tickColor, padding: 6 } }
      }
    }
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
