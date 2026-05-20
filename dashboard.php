<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
requireLogin();

$pageTitle = 'Annual Dashboard';
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$depth = '';

// ─── Fetch all rental units ───────────────────────────────────
$units = $pdo->query("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id ORDER BY ru.unit_name")->fetchAll();

// ─── Per-unit revenue & expenses (single query each) ─────────
$revRows = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM payments WHERE YEAR(payment_date)=? GROUP BY unit_id");
$revRows->execute([$selectedYear]);
$unitRevenue = array_column($revRows->fetchAll(), 'total', 'unit_id');

$expRows = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM expenses WHERE YEAR(expense_date)=? GROUP BY unit_id");
$expRows->execute([$selectedYear]);
$unitExpenses = array_column($expRows->fetchAll(), 'total', 'unit_id');

foreach ($units as $u) {
    $unitRevenue[$u['id']]  = (float)($unitRevenue[$u['id']]  ?? 0);
    $unitExpenses[$u['id']] = (float)($unitExpenses[$u['id']] ?? 0);
}

// ─── Monthly totals ───────────────────────────────────────────
$monthlyRev = []; $monthlyExp = [];
for ($m = 1; $m <= 12; $m++) {
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=? AND YEAR(payment_date)=?");
    $r->execute([$m, $selectedYear]);
    $monthlyRev[$m] = (float)$r->fetchColumn();

    $e = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=? AND YEAR(expense_date)=?");
    $e->execute([$m, $selectedYear]);
    $monthlyExp[$m] = (float)$e->fetchColumn();
}

// ─── Expense by category ─────────────────────────────────────
$catExp = $pdo->prepare("SELECT ec.name, COALESCE(SUM(e.amount),0) as total FROM expense_categories ec LEFT JOIN expenses e ON e.category_id=ec.id AND YEAR(e.expense_date)=? GROUP BY ec.id ORDER BY total DESC");
$catExp->execute([$selectedYear]);
$catExpData = $catExp->fetchAll();

// ─── Totals ───────────────────────────────────────────────────
$totalRev = array_sum($unitRevenue);
$totalExp = array_sum($unitExpenses);
$totalNet = $totalRev - $totalExp;
$totalUnits   = count($units);
$occupiedUnits = count(array_filter($units, fn($u) => $u['status'] === 'occupied'));

// ─── Available years ──────────────────────────────────────────
$years = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments UNION SELECT DISTINCT YEAR(expense_date) FROM expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) array_unshift($years, (int)date('Y'));
if (!in_array($selectedYear, $years)) $years[] = $selectedYear;
rsort($years);

logActivity($pdo, 'VIEW_DASHBOARD', 'Dashboard', "Viewed annual dashboard for $selectedYear");
include 'includes/header.php';
?>

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

<!-- Stat Cards -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-coins"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value" style="font-size:17px"><?= money($totalRev) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Expenses</div>
        <div class="stat-value" style="font-size:17px"><?= money($totalExp) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa-solid fa-chart-line"></i></div>
      <div class="stat-body">
        <div class="stat-label">Net Income</div>
        <div class="stat-value" style="font-size:17px;color:<?= $totalNet>=0?'var(--success)':'var(--danger)' ?>"><?= money($totalNet) ?></div>
        <div class="stat-sub"><?= $selectedYear ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon teal"><i class="fa-solid fa-door-open"></i></div>
      <div class="stat-body">
        <div class="stat-label">Units Occupied</div>
        <div class="stat-value"><?= $occupiedUnits ?> / <?= $totalUnits ?></div>
        <div class="stat-sub">Rental units</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-bar me-2"></i>Revenue vs Expenses by Unit</span>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="unitChart"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-pie me-2"></i>Expenses by Category</span>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="catChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<!-- Monthly Net Income Chart -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-chart-line me-2"></i>Monthly Revenue & Net Income — <?= $selectedYear ?></span>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<!-- Annual Summary Table -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-table me-2"></i>Monthly Summary — <?= $selectedYear ?></span>
        <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr>
            <th>Month</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net Income</th>
          </tr></thead>
          <tbody>
          <?php
          $sumRev=0; $sumExp=0;
          for($m=1;$m<=12;$m++){
            $r=$monthlyRev[$m]; $e=$monthlyExp[$m]; $n=$r-$e;
            $sumRev+=$r; $sumExp+=$e;
          ?>
          <tr>
            <td><?= date('F', mktime(0,0,0,$m,1)) ?></td>
            <td class="text-end"><?= money($r) ?></td>
            <td class="text-end"><?= money($e) ?></td>
            <td class="text-end fw-bold" style="color:<?= $n>=0?'var(--success)':'var(--danger)' ?>"><?= money($n) ?></td>
          </tr>
          <?php } ?>
          </tbody>
          <tfoot><tr style="background:#f9fafb;font-weight:700">
            <td>TOTAL <?= $selectedYear ?></td>
            <td class="text-end"><?= money($sumRev) ?></td>
            <td class="text-end"><?= money($sumExp) ?></td>
            <td class="text-end" style="color:<?= ($sumRev-$sumExp)>=0?'var(--success)':'var(--danger)' ?>"><?= money($sumRev-$sumExp) ?></td>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Per-Unit Summary Table -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-building me-2"></i>Revenue, Expenses & Net Income per Unit — <?= $selectedYear ?></span>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr>
            <th>Unit</th><th>Type</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net Income</th>
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
            <td colspan="2">TOTAL</td>
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
  unitLabels:  <?= json_encode(array_column($units, 'unit_name')) ?>,
  unitRev:     <?= json_encode(array_map(fn($u) => $unitRevenue[$u['id']], $units)) ?>,
  unitExp:     <?= json_encode(array_map(fn($u) => $unitExpenses[$u['id']], $units)) ?>,
  catLabels:   <?= json_encode(array_column($catExpData, 'name')) ?>,
  catTotals:   <?= json_encode(array_map(fn($r) => (float)$r['total'], $catExpData)) ?>,
  monthLabels: <?= json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']) ?>,
  monthRev:    <?= json_encode(array_values($monthlyRev)) ?>,
  monthNet:    <?= json_encode(array_map(fn($m) => $monthlyRev[$m] - $monthlyExp[$m], range(1,12))) ?>
};
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var d = CHART_DATA;
  var pallette = ['#1a3a8f','#3b5bdb','#15803d','#b91c1c','#b45309','#6d28d9','#0d9488','#d97706','#0369a1','#6b7280','#be185d','#c026d3'];
  var phpFmt = function(v) { return '₱' + v.toLocaleString('en-PH'); };

  new Chart(document.getElementById('unitChart'), {
    type: 'bar',
    data: {
      labels: d.unitLabels,
      datasets: [
        { label: 'Revenue',  data: d.unitRev, backgroundColor: '#3b5bdb', borderRadius: 4 },
        { label: 'Expenses', data: d.unitExp, backgroundColor: '#ef4444', borderRadius: 4 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      plugins: { legend: { position: 'top', labels: { font: { size: 12, family: 'DM Sans' } } } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: phpFmt, font: { size: 11 } }, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });

  var catFiltered = { labels: [], data: [] };
  d.catLabels.forEach(function(label, i) {
    if (d.catTotals[i] > 0) { catFiltered.labels.push(label); catFiltered.data.push(d.catTotals[i]); }
  });
  new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: {
      labels: catFiltered.labels,
      datasets: [{ data: catFiltered.data, backgroundColor: pallette, hoverOffset: 8, borderWidth: 2 }]
    },
    options: {
      responsive: true, cutout: '62%',
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11, family: 'DM Sans' }, padding: 10, boxWidth: 12 } } }
    }
  });

  new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels: d.monthLabels,
      datasets: [
        { type: 'bar',  label: 'Revenue',    data: d.monthRev, backgroundColor: 'rgba(59,91,219,.25)', borderRadius: 4, yAxisID: 'y' },
        { type: 'line', label: 'Net Income', data: d.monthNet, borderColor: '#15803d', backgroundColor: 'rgba(21,128,61,.08)', borderWidth: 2, pointRadius: 4, tension: .3, fill: true, yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      plugins: { legend: { position: 'top', labels: { font: { size: 12, family: 'DM Sans' } } } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: phpFmt, font: { size: 11 } }, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });
});
</script>

<?php include 'includes/footer.php'; ?>
