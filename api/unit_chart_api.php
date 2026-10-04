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

// Net of refunds (CLAUDE.md §5 inv. 6) so this chart agrees with the hero strip.
$revQ = $pdo->prepare("SELECT p.unit_id, COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0) AS total
    FROM payments p
    LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
    WHERE p.payment_date >= ? AND p.payment_date < ? AND p.deleted_at IS NULL AND p.status != 'voided' GROUP BY p.unit_id");
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
// Rows with no unit — "General / not unit-specific" expenses, or rows whose unit
// was deleted. Left out, the chart understated spending against the hero strip
// and the per-unit table (which has the same General row).
$genRev = (float)($revMap[''] ?? 0);
$genExp = (float)($expMap[''] ?? 0);
if ($genRev !== 0.0 || $genExp !== 0.0) {
    $labels[]   = 'General';
    $revenue[]  = $genRev;
    $expenses[] = $genExp;
}

jsonOk([
    'labels'   => $labels,
    'revenue'  => $revenue,
    'expenses' => $expenses,
    'title'    => $title,
    'totalRev' => array_sum($revenue),
    'totalExp' => array_sum($expenses),
]);
