<?php
// admin/demand_letter.php — demand letters for tenants behind on rent.
//   POST (CSRF) tenant_id, letter_type (pay|vacate), deadline_days
//        → issues DL-YYYY-####, records it, streams the PDF
//   GET  ?id=N → reprints letter N exactly as issued (from its snapshot)
// Launched from the dashboard Balance Tracker. Admin only.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/balance_functions.php';
requireAdmin();

function demandFail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Demand letter</title>'
       . '<body style="font-family:sans-serif;padding:2rem;max-width:640px">'
       . '<h3>Could not generate the demand letter</h3><p>' . clean($msg) . '</p>'
       . '<p><a href="../dashboard.php">Back to the dashboard</a></p></body>';
    exit;
}

// Render and stream a reprint (throws if chromium fails; the caller reports it).
function demandStream(string $html, string $refNo, string $tenantName): void {
    $pdf  = renderHtmlToPdf($html);
    $slug = fn(string $v) => preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9\-_]/', '_', $v), '_'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Demand-Letter-' . $slug($refNo) . '-' . $slug($tenantName) . '.pdf"');
    header('Content-Length: ' . filesize($pdf));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($pdf);
    @unlink($pdf);
    exit;
}

// ── Reprint ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = $pdo->prepare("SELECT * FROM demand_letters WHERE id=?");
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $d = $q->fetch();
    if (!$d) demandFail('Demand letter not found.', 404);
    $ctx = json_decode((string)$d['snapshot_json'], true) ?: [];
    try {
        $html = renderDemandLetterDocument($ctx, $d['ref_no'], 'pdf');
        logActivity($pdo, 'REPRINT_DEMAND_LETTER', 'Tenants', "Reprinted {$d['ref_no']} ({$ctx['tenant_name']}, {$ctx['unit_name']})");
        demandStream($html, $d['ref_no'], (string)($ctx['tenant_name'] ?? 'Tenant'));
    } catch (Throwable $e) {
        error_log('demand_letter reprint: ' . $e->getMessage());
        demandFail('The PDF could not be generated. See the system audit log for details.', 500);
    }
}

// ── Issue ───────────────────────────────────────────────────
csrfRequirePost();
$tenantId = (int)($_POST['tenant_id'] ?? 0);
$type     = (string)($_POST['letter_type'] ?? '');
$days     = (int)($_POST['deadline_days'] ?? DEMAND_DEFAULT_DAYS);
if (!isset(DEMAND_TYPES[$type])) demandFail('Choose the type of demand letter.');
if ($days < DEMAND_MIN_DAYS || $days > DEMAND_MAX_DAYS) {
    demandFail('The deadline must be between ' . DEMAND_MIN_DAYS . ' and ' . DEMAND_MAX_DAYS . ' days.');
}

$q = $pdo->prepare("SELECT t.*, ru.unit_name, ru.monthly_rate AS unit_rate, ru.due_day
                    FROM tenants t JOIN rental_units ru ON ru.id = t.unit_id WHERE t.id=? AND t.status='active'");
$q->execute([$tenantId]);
$t = $q->fetch();
if (!$t) demandFail('Active tenant not found.');

$st = tenantStatement($pdo, $t);
if (!money_is_pos($st['balance']) || !money_is_pos($st['rent_owed'])) {
    demandFail($t['full_name'] . ' has no unpaid rent as of today — there is nothing to demand.');
}
$ctx = demandLetterContext($pdo, $t, $st, $type, $days);

// Number, record and render in one transaction: if chromium fails the number
// is rolled back with the row, so a printed letter and its number always pair.
$pdo->beginTransaction();
try {
    $ref = generateDemandLetterNo($pdo);
    $pdo->prepare(
        "INSERT INTO demand_letters (ref_no, tenant_id, unit_id, letter_type, amount_due, months_behind, deadline_days, snapshot_json, issued_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([$ref, $tenantId, (int)$t['unit_id'], $type, $ctx['total'], $st['months_behind'], $days,
                json_encode($ctx, JSON_UNESCAPED_UNICODE), (int)$_SESSION['user']['id']]);
    $html = renderDemandLetterDocument($ctx, $ref, 'pdf');
    $pdf  = renderHtmlToPdf($html);
    logActivity($pdo, 'ISSUE_DEMAND_LETTER', 'Tenants',
        "Issued $ref ({$ctx['title']}) to {$t['full_name']}, {$t['unit_name']}: " . money($ctx['total'])
        . " due within $days days (" . number_format($st['months_behind'], 1) . " months' rent behind)");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('demand_letter issue: ' . $e->getMessage());
    logActivity($pdo, 'ISSUE_DEMAND_LETTER_FAILED', 'Tenants', substr($e->getMessage(), 0, 240));
    demandFail('The PDF could not be generated, so no letter number was used. ' . $e->getMessage(), 500);
}

$slug = fn(string $v) => preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9\-_]/', '_', $v), '_'));
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Demand-Letter-' . $slug($ref) . '-' . $slug($t['full_name']) . '.pdf"');
header('Content-Length: ' . filesize($pdf));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($pdf);
@unlink($pdf);
exit;
