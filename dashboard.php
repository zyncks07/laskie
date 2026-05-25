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
$monthlyRev = []; $monthlyExp = [];
$revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided'");
$expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL");
for ($m = 1; $m <= 12; $m++) {
    [$mStart, $mEnd] = monthRange($m, $selectedYear);
    $revStmt->execute([$mStart, $mEnd]);
    $monthlyRev[$m] = (float)$revStmt->fetchColumn();
    $expStmt->execute([$mStart, $mEnd]);
    $monthlyExp[$m] = (float)$expStmt->fetchColumn();
}

// ─── Expense by category ─────────────────────────────────────
$catExp = $pdo->prepare("SELECT ec.name, COALESCE(SUM(e.amount),0) as total FROM expense_categories ec LEFT JOIN expenses e ON e.category_id=ec.id AND e.expense_date >= ? AND e.expense_date < ? AND e.deleted_at IS NULL GROUP BY ec.id ORDER BY total DESC");
$catExp->execute([$yrStart, $yrEnd]);
$catExpData = $catExp->fetchAll();

// ─── Totals ───────────────────────────────────────────────────
$totalRev = array_sum($unitRevenue);
$totalExp = array_sum($unitExpenses);
$totalNet = $totalRev - $totalExp;
$totalUnits   = count($units);
$occupiedUnits = count(array_filter($units, fn($u) => $u['status'] === 'occupied'));

// ─── Current month stats ─────────────────────────────────────
$curMonth = (int)date('n');
$curYear  = (int)date('Y');
$cmRev = $cmExp = 0.0;
if ($selectedYear === $curYear) {
    [$cmStart, $cmEnd] = monthRange($curMonth, $curYear);
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided'");
    $s->execute([$cmStart, $cmEnd]); $cmRev = (float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL");
    $s->execute([$cmStart, $cmEnd]); $cmExp = (float)$s->fetchColumn();
}
$cmNet = $cmRev - $cmExp;

// ─── Available years ──────────────────────────────────────────
$years = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments UNION SELECT DISTINCT YEAR(expense_date) FROM expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) array_unshift($years, (int)date('Y'));
if (!in_array($selectedYear, $years)) $years[] = $selectedYear;
rsort($years);

// ─── Unit chart period dropdown data ─────────────────────────
$mnNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$chartMonthsByYear = [];
foreach ($years as $ychk) {
    $mQ = $pdo->prepare("
        SELECT DISTINCT m FROM (
            SELECT MONTH(payment_date) AS m FROM payments
             WHERE YEAR(payment_date)=? AND deleted_at IS NULL AND status != 'voided'
            UNION
            SELECT MONTH(expense_date) FROM expenses
             WHERE YEAR(expense_date)=? AND deleted_at IS NULL
        ) sub ORDER BY m ASC
    ");
    $mQ->execute([$ychk, $ychk]);
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
/* ── Dashboard compact overrides ─────────────────────────── */
.page-header           { margin-bottom: 10px; }
.db-row                { margin-bottom: 10px; }
.db-card .card-header  { padding: 7px 13px; }
.db-card .card-body    { padding: 10px 12px; }
.db-stat               { padding: 10px 12px; gap: 10px; }
.db-stat .stat-icon    { width: 36px; height: 36px; font-size: 15px; border-radius: 8px; }
.db-stat .stat-value   { font-size: 16px; }
.db-stat .stat-label   { font-size: 11px; }
.db-stat .stat-sub     { font-size: 10.5px; margin-top: 1px; }
.db-tbl td, .db-tbl th { padding: 5px 10px !important; font-size: 12.5px; }
.db-chart              { position: relative; height: 195px; }
.db-card-fill          { display: flex; flex-direction: column; }
.db-chart-grow         { position: relative; flex: 1; min-height: 200px; }
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
    <div class="stat-card db-stat">
      <div class="stat-icon blue"><i class="fa-solid fa-coins"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value"><?= money($totalRev) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat">
      <div class="stat-icon red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Expenses</div>
        <div class="stat-value"><?= money($totalExp) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat">
      <div class="stat-icon green"><i class="fa-solid fa-chart-line"></i></div>
      <div class="stat-body">
        <div class="stat-label">Net Income</div>
        <div class="stat-value" style="color:<?= $totalNet>=0?'var(--success)':'var(--danger)' ?>"><?= money($totalNet) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card db-stat">
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
    <div class="card db-card" style="border-left:3px solid var(--primary)">
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
            <div class="fw-bold" style="font-size:14px;color:<?= $cmNet>=0?'var(--success)':'var(--danger)' ?>"><?= money($cmNet) ?></div>
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
      <div class="unit-status-scroll" style="overflow-y:auto;height:680px">
        <table class="table db-tbl mb-0">
          <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
            <tr><th>Unit</th><th>Tenant</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($unitStatusData as $u):
            if ($u['status'] === 'vacant'): ?>
            <tr>
              <td class="fw-600"><?= clean($u['unit_name']) ?></td>
              <td class="text-muted">—</td>
              <td><span class="badge" style="background:#e2e8f0;color:#64748b;font-size:10px">Vacant</span></td>
            </tr>
          <?php else:
              $rate         = getRateForMonth($pdo, (int)$u['id'], (float)$u['monthly_rate'], $curMonth, $curYear);
              $expected     = prorateFirstMonth($rate, (int)$u['due_day'], $u['contract_start'] ?? null, $curMonth, $curYear);
              $curPaid      = $u['cur_paid'];
              $curMonthPaid = money_is_pos($expected) && money_gte($curPaid, $expected);
              $isLate       = !$curMonthPaid && money_is_pos($expected) && $today > ((int)$u['due_day'] + 10);
              $amountDue    = money_max('0.00', money_sub($expected, $curPaid));
          ?>
            <tr>
              <td class="fw-600"><?= clean($u['unit_name']) ?></td>
              <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($u['tenant_name'] ?? '—') ?></td>
              <td>
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
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:5px;padding-top:5px;border-top:1px solid #e8ecf5">
          <span style="color:#64748b">Rev: <strong id="ucRev" style="color:var(--primary)">—</strong></span>
          <span style="color:#64748b">Exp: <strong id="ucExp" style="color:var(--danger)">—</strong></span>
          <span style="color:#64748b">Net: <strong id="ucNet">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:#94a3b8;font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart-grow" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,.82);z-index:10;align-items:center;justify-content:center;border-radius:0 0 12px 12px">
          <div class="spinner-border spinner-border-sm" style="color:var(--primary)" role="status"><span class="visually-hidden">Loading…</span></div>
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
        <div id="unitChartStats" style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;margin-top:5px;padding-top:5px;border-top:1px solid #e8ecf5">
          <span style="color:#64748b">Rev: <strong id="ucRev" style="color:var(--primary)">—</strong></span>
          <span style="color:#64748b">Exp: <strong id="ucExp" style="color:var(--danger)">—</strong></span>
          <span style="color:#64748b">Net: <strong id="ucNet">—</strong></span>
          <span id="ucTitle" style="margin-left:auto;color:#94a3b8;font-style:italic;font-size:10.5px"></span>
        </div>
      </div>
      <div class="card-body db-chart" style="position:relative">
        <div id="unitChartSpinner" style="display:none;position:absolute;inset:0;background:rgba(255,255,255,.82);z-index:10;align-items:center;justify-content:center;border-radius:0 0 12px 12px">
          <div class="spinner-border spinner-border-sm" style="color:var(--primary)" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <canvas id="unitChart"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card db-card h-100">
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
        <table class="table db-tbl">
          <thead><tr>
            <th>Month</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net</th>
          </tr></thead>
          <tbody>
          <?php
          $sumRev=0; $sumExp=0;
          for($m=1;$m<=12;$m++){
            $r=$monthlyRev[$m]; $e=$monthlyExp[$m]; $n=$r-$e;
            $sumRev+=$r; $sumExp+=$e;
            $isCurrent = ($selectedYear === $curYear && $m === $curMonth);
          ?>
          <tr<?= $isCurrent ? ' style="background:#eff6ff;"' : '' ?>>
            <td>
              <?= date('M', mktime(0,0,0,$m,1)) ?>
              <?php if ($isCurrent): ?><span class="badge ms-1" style="background:var(--primary);font-size:9px">Now</span><?php endif; ?>
            </td>
            <td class="text-end"><?= money($r) ?></td>
            <td class="text-end"><?= money($e) ?></td>
            <td class="text-end fw-bold" style="color:<?= $n>=0?'var(--success)':'var(--danger)' ?>"><?= money($n) ?></td>
          </tr>
          <?php } ?>
          </tbody>
          <tfoot><tr style="background:#f9fafb;font-weight:700">
            <td>Total</td>
            <td class="text-end"><?= money($sumRev) ?></td>
            <td class="text-end"><?= money($sumExp) ?></td>
            <td class="text-end" style="color:<?= ($sumRev-$sumExp)>=0?'var(--success)':'var(--danger)' ?>"><?= money($sumRev-$sumExp) ?></td>
          </tfoot>
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
        <table class="table db-tbl">
          <thead><tr>
            <th>Unit</th><th>Type</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net</th>
          </tr></thead>
          <tbody>
          <?php foreach($units as $u): $net=$unitRevenue[$u['id']]-$unitExpenses[$u['id']]; ?>
          <tr>
            <td class="fw-600"><?= clean($u['unit_name']) ?></td>
            <td><span class="badge badge-staff"><?= clean($u['type_name']??'—') ?></span></td>
            <td class="text-end"><?= money($unitRevenue[$u['id']]) ?></td>
            <td class="text-end"><?= money($unitExpenses[$u['id']]) ?></td>
            <td class="text-end fw-bold" style="color:<?= $net>=0?'var(--success)':'var(--danger)' ?>"><?= money($net) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr style="background:#f9fafb;font-weight:700">
            <td colspan="2">Total</td>
            <td class="text-end"><?= money($totalRev) ?></td>
            <td class="text-end"><?= money($totalExp) ?></td>
            <td class="text-end" style="color:<?= $totalNet>=0?'var(--success)':'var(--danger)' ?>"><?= money($totalNet) ?></td>
          </tfoot>
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
  monthNet:    <?= json_encode(array_map(fn($m) => $monthlyRev[$m] - $monthlyExp[$m], range(1,12))) ?>
};
var UNIT_CHART_API  = '<?= pageUrl('api/unit_chart_api.php') ?>';
var UNIT_CHART_INIT = '<?= $chartInitPeriod ?>';
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
  gRev.addColorStop(0, 'rgba(59,91,219,0.90)');
  gRev.addColorStop(1, 'rgba(59,91,219,0.42)');

  var gExp = ctx.createLinearGradient(0, 0, 0, h);
  gExp.addColorStop(0, 'rgba(239,68,68,0.90)');
  gExp.addColorStop(1, 'rgba(239,68,68,0.42)');

  // Update stats strip
  var net = (data.totalRev || 0) - (data.totalExp || 0);
  var ucRev = document.getElementById('ucRev'), ucExp = document.getElementById('ucExp'),
      ucNet = document.getElementById('ucNet'), ucTitle = document.getElementById('ucTitle');
  if (ucRev)   ucRev.textContent = _phpFmt(data.totalRev);
  if (ucExp)   ucExp.textContent = _phpFmt(data.totalExp);
  if (ucNet) {
    ucNet.textContent = (net < 0 ? '-' : '') + _phpFmt(Math.abs(net));
    ucNet.style.color = net >= 0 ? 'var(--success)' : 'var(--danger)';
  }
  if (ucTitle) ucTitle.textContent = data.title || '';

  _unitChartInst = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.labels,
      datasets: [
        { label: 'Revenue',  data: data.revenue,  backgroundColor: gRev, borderRadius: 6, borderSkipped: false, borderWidth: 0 },
        { label: 'Expenses', data: data.expenses, backgroundColor: gExp, borderRadius: 6, borderSkipped: false, borderWidth: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 420, easing: 'easeOutQuart' },
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 11, family: 'DM Sans' }, boxWidth: 10, padding: 10, usePointStyle: true, pointStyleWidth: 10 }
        },
        tooltip: {
          mode: 'index',
          intersect: false,
          backgroundColor: 'rgba(15,23,42,0.88)',
          titleFont: { size: 12, family: 'DM Sans', weight: '600' },
          bodyFont:  { size: 11, family: 'DM Sans' },
          padding: 10,
          cornerRadius: 8,
          callbacks: {
            label: function(c) { return '  ' + c.dataset.label + ': ' + _phpFmt(c.parsed.y); },
            afterBody: function(items) {
              if (items.length < 2) return [];
              var n = (items[0].parsed.y || 0) - (items[1].parsed.y || 0);
              return ['──────────────────', '  Net: ' + (n < 0 ? '-' : '') + _phpFmt(Math.abs(n))];
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid:   { color: 'rgba(226,232,240,0.7)' },
          border: { display: false },
          ticks:  { callback: _phpFmt, font: { size: 10, family: 'DM Sans' }, color: '#94a3b8' }
        },
        x: {
          grid:   { display: false },
          border: { display: false },
          ticks:  { font: { size: 10, family: 'DM Sans' }, color: '#64748b' }
        }
      }
    }
  });
}

// ── Page init ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var d = CHART_DATA;
  var pallette = ['#1a3a8f','#3b5bdb','#15803d','#b91c1c','#b45309','#6d28d9','#0d9488','#d97706','#0369a1','#6b7280','#be185d','#c026d3'];

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
      datasets: [{ data: catFiltered.data, backgroundColor: pallette, hoverOffset: 6, borderWidth: 2 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '60%',
      plugins: { legend: { position: 'bottom', labels: { font: { size: 10, family: 'DM Sans' }, padding: 8, boxWidth: 10 } } }
    }
  });

  new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels: d.monthLabels,
      datasets: [
        { type: 'bar',  label: 'Revenue',    data: d.monthRev, backgroundColor: 'rgba(59,91,219,.25)', borderRadius: 3, yAxisID: 'y' },
        { type: 'line', label: 'Net Income', data: d.monthNet, borderColor: '#15803d', backgroundColor: 'rgba(21,128,61,.08)', borderWidth: 2, pointRadius: 3, tension: .3, fill: true, yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { font: { size: 11, family: 'DM Sans' }, boxWidth: 10, padding: 8 } } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: _phpFmt, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false }, ticks: { font: { size: 10 } } }
      }
    }
  });
});
</script>

<?php include 'includes/footer.php'; ?>
