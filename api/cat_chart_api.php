<?php
// api/cat_chart_api.php — Expenses by Category for the dashboard chart
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$periodType = $_GET['period_type'] ?? 'month';
$month      = (int)($_GET['month'] ?? date('n'));
$year       = (int)($_GET['year']  ?? date('Y'));

if (!in_array($periodType, ['month', 'year'], true)) jsonErr('Invalid period_type.');
if ($year < 2000 || $year > 2100) jsonErr('Invalid year.');
if ($periodType === 'month' && ($month < 1 || $month > 12)) jsonErr('Invalid month.');

if ($periodType === 'month') {
    [$start, $end] = monthRange($month, $year);
    $isMtd = ($month === (int)date('n') && $year === (int)date('Y'));
    $title = date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ($isMtd ? ' — Month to Date' : '');
} else {
    [$start, $end] = yearRange($year);
    $title = "Full Year $year";
}

// Category is optional on an expense, so uncategorised spending gets its own
// bar — dropping it made the chart total disagree with every other page.
$catQ = $pdo->prepare("SELECT COALESCE(ec.name, 'Uncategorized') AS name, SUM(e.amount) AS total
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    WHERE e.expense_date >= ? AND e.expense_date < ? AND e.deleted_at IS NULL
    GROUP BY ec.id, ec.name ORDER BY total DESC");
$catQ->execute([$start, $end]);
$rows = $catQ->fetchAll();

$labels = $totals = [];
foreach ($rows as $r) {
    $t = (float)$r['total'];
    if ($t === 0.0) continue;
    $labels[] = $r['name'];
    $totals[] = $t;
}

jsonOk([
    'labels' => $labels,
    'totals' => $totals,
    'title'  => $title,
    'total'  => array_sum($totals),
]);
