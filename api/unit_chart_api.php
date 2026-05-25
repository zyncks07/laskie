<?php
// api/unit_chart_api.php — Per-unit Revenue vs Expenses for the dashboard chart
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

$units = $pdo->query("SELECT id, unit_name FROM rental_units ORDER BY unit_name")->fetchAll();

if ($periodType === 'month') {
    [$start, $end] = monthRange($month, $year);
    $isMtd = ($month === (int)date('n') && $year === (int)date('Y'));
    $title = date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ($isMtd ? ' — Month to Date' : '');
} else {
    [$start, $end] = yearRange($year);
    $title = "Full Year $year";
}

$revQ = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM payments WHERE payment_date >= ? AND payment_date < ? AND deleted_at IS NULL AND status != 'voided' GROUP BY unit_id");
$revQ->execute([$start, $end]);
$revMap = array_column($revQ->fetchAll(), 'total', 'unit_id');

$expQ = $pdo->prepare("SELECT unit_id, COALESCE(SUM(amount),0) AS total FROM expenses WHERE expense_date >= ? AND expense_date < ? AND deleted_at IS NULL GROUP BY unit_id");
$expQ->execute([$start, $end]);
$expMap = array_column($expQ->fetchAll(), 'total', 'unit_id');

$labels = $revenue = $expenses = [];
foreach ($units as $u) {
    $rev = (float)($revMap[$u['id']] ?? 0);
    $exp = (float)($expMap[$u['id']] ?? 0);
    if ($rev === 0.0 && $exp === 0.0) continue;
    $labels[]   = $u['unit_name'];
    $revenue[]  = $rev;
    $expenses[] = $exp;
}

jsonOk([
    'labels'   => $labels,
    'revenue'  => $revenue,
    'expenses' => $expenses,
    'title'    => $title,
    'totalRev' => array_sum($revenue),
    'totalExp' => array_sum($expenses),
]);
