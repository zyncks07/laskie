<?php
// scripts/reconcile.php — records-mismatch hunt.
//
//   php scripts/reconcile.php --copy            live → laskie_test (live is only SELECTed)
//   php scripts/reconcile.php --check [--out=F] render every money page against
//                                               laskie_test and compare the figures
//
// Every page is rendered in its own subprocess pinned to laskie_test (the same
// isolation rule as tests/Integration/IsolatedDbTestCase::callScript) with
// RECON_MODE defined, so each page emits the figures it actually displayed via
// reconEmit(). Those are compared tenant by tenant, unit by unit and against an
// independent truth computed from the data. Data problems are REPORTED only —
// nothing here ever writes to the live database (CLAUDE.md §12 DB policy).
//
// CLI only: scripts/ is blocked from the web by the root .htaccess.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const RECON_DB = 'laskie_test';
$ROOT = dirname(__DIR__);
$opts = getopt('', ['copy', 'check', 'out::', 'today::']);
if (!isset($opts['copy']) && !isset($opts['check'])) {
    fwrite(STDERR, "usage: php scripts/reconcile.php --copy | --check [--out=report.txt]\n");
    exit(2);
}

require_once $ROOT . '/config/env.php';

function reconConnect(string $db): PDO {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $db . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $cur = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($cur !== $db) throw new RuntimeException("Connected to '$cur', expected '$db' — refusing.");
    return $pdo;
}

// ═══ --copy ═════════════════════════════════════════════════════
if (isset($opts['copy'])) {
    $live = reconConnect(DB_NAME);
    if (DB_NAME === RECON_DB) throw new RuntimeException('Live and recon DB are the same — refusing.');
    $test = reconConnect(RECON_DB);

    // Rebuild the schema from install.sql, exactly like the test harness.
    $clean = [];
    foreach (explode("\n", (string)file_get_contents($ROOT . '/install.sql')) as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '--') || preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $t)) continue;
        $clean[] = $line;
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($test->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $test->exec("DROP TABLE IF EXISTS `$t`");
    foreach (explode(';', implode("\n", $clean)) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '' && !preg_match('/^INSERT\s+INTO/i', $stmt)) $test->exec($stmt);   // schema only, no seeds
    }

    $liveTables = $live->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $skip = ['system_logs', 'notifications'];   // audit trail / bell feed: not money, not needed
    foreach ($test->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (in_array($table, $skip, true) || !in_array($table, $liveTables, true)) continue;
        $tc = $test->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $lc = $live->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_values(array_intersect($tc, $lc));
        if ($drift = array_diff($lc, $tc)) echo "  schema drift: live $table has columns not in install.sql: " . implode(', ', $drift) . "\n";
        $list = '`' . implode('`,`', $cols) . '`';
        $rows = $live->query("SELECT $list FROM `$table`")->fetchAll(PDO::FETCH_NUM);
        if (!$rows) continue;
        $ins = $test->prepare("INSERT INTO `$table` ($list) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
        $test->beginTransaction();
        foreach ($rows as $r) $ins->execute($r);
        $test->commit();
        printf("  %-24s %5d rows\n", $table, count($rows));
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "Copied live → " . RECON_DB . " (live was only read).\n";
    if (!isset($opts['check'])) exit(0);
}

// ═══ --check ════════════════════════════════════════════════════
date_default_timezone_set('Asia/Manila');
$pdo = reconConnect(RECON_DB);
$GLOBALS['pdo'] = $pdo;
require_once $ROOT . '/config/functions.php';
require_once $ROOT . '/config/balance_functions.php';
$today = $opts['today'] ?? date('Y-m-d');
$admin = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();

/** Render one page in a subprocess against laskie_test; returns [recon blocks by page, raw output]. */
function render(string $rel, array $get = [], array $post = [], bool $json = false): array {
    global $ROOT, $admin;
    $method = $post ? 'POST' : 'GET';
    if ($post) $post['csrf_token'] = str_repeat('r', 64);
    $abs  = $ROOT . '/' . $rel;
    $code = "<?php\n"
          . "session_start();\n"
          . "define('RECON_MODE', true);\n"
          . ($json ? "define('JSON_RESPONSE', true);\n" : '')
          . "define('DB_NAME', " . var_export(RECON_DB, true) . ");\n"
          . "ini_set('display_errors', '0');\n"
          . "\$_SESSION['user'] = ['id' => $admin, 'username' => 'recon', 'full_name' => 'Recon', 'role' => 'admin', 'avatar_path' => null];\n"
          . "\$_SESSION['csrf_token'] = str_repeat('r', 64);\n"
          . "\$_SESSION['settings_unlocked'] = time();\n"
          . "\$_GET = " . var_export($get, true) . ";\n"
          . "\$_POST = " . var_export($post, true) . ";\n"
          . "\$_SERVER['REQUEST_METHOD'] = '$method';\n"
          . "\$_SERVER['PHP_SELF'] = " . var_export('/' . $rel, true) . ";\n"
          . "chdir(" . var_export(dirname($abs), true) . ");\n"
          . "include " . var_export($abs, true) . ";\n";
    $tmp = tempnam(sys_get_temp_dir(), 'laskie_recon_');
    file_put_contents($tmp, $code);
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    if ($json) return [json_decode($out, true), $out];
    $blocks = [];
    if (preg_match_all('#<script type="application/json" class="laskie-recon" data-page="([^"]+)">(.*?)</script>#s', $out, $m, PREG_SET_ORDER)) {
        foreach ($m as $b) $blocks[$b[1]] = json_decode($b[2], true);
    }
    return [$blocks, $out];
}

$findings = [];   // [category, subject, detail]
$checks   = 0;
function expectEq(string $cat, string $subject, array $values): void {
    global $findings, $checks;
    $checks++;
    $norm = [];
    foreach ($values as $page => $v) $norm[$page] = $v === null ? null : from_cents(to_cents($v));
    $distinct = array_unique(array_filter($norm, fn($v) => $v !== null));
    if (count($distinct) > 1 || in_array(null, $norm, true)) {
        $findings[] = [$cat, $subject, implode('  ·  ', array_map(fn($p, $v) => "$p=" . ($v ?? 'MISSING'), array_keys($norm), $norm))];
    }
}

// ── 1. Tenant balances & range integrity ─────────────────────────
$tenancies = $pdo->query(
    "SELECT t.*, ru.unit_name, ru.monthly_rate AS unit_rate, ru.due_day
     FROM tenants t JOIN rental_units ru ON ru.id = t.unit_id
     WHERE t.status IN ('active','former','inactive') ORDER BY ru.unit_name, t.contract_start"
)->fetchAll();

$truth = [];
foreach ($tenancies as $t) {
    $tid = (int)$t['id']; $uid = (int)$t['unit_id'];
    $label = "{$t['unit_name']} {$t['full_name']} (#$tid, {$t['status']})";
    $st = tenantStatement($pdo, $t, $today);
    $truth[$tid] = $st;
    $start = $t['contract_start'] ?: date('Y-01-01');
    $sub   = date('Y-m-01', strtotime($today . ' -3 months'));

    [$a] = render('payments/history.php', ['unit_id' => $uid, 'tenant_id' => $tid]);
    [$b] = render('payments/history.php', ['unit_id' => $uid, 'tenant_id' => $tid, 'date_from' => $start, 'date_to' => $today]);
    [$c] = render('payments/soa_pdf.php', ['unit_id' => $uid, 'tenant_id' => $tid, 'date_from' => $start, 'date_to' => $today]);
    [$d] = render('payments/soa_pdf.php', ['unit_id' => $uid, 'tenant_id' => $tid]);
    [$e] = render('payments/history.php', ['unit_id' => $uid, 'tenant_id' => $tid, 'date_from' => $sub, 'date_to' => $today]);
    [$f] = render('payments/soa_pdf.php', ['unit_id' => $uid, 'tenant_id' => $tid, 'date_from' => $sub, 'date_to' => $today]);

    expectEq('Tenant balance', $label, [
        'truth(tenantStatement)'        => $st['balance'],
        'SoA page (default range)'      => $a['soa']['balance'] ?? null,
        'SoA page (tenancy→today)'      => $b['soa']['balance'] ?? null,
        'Preview (tenancy→today)'       => $c['soa_preview']['balance'] ?? null,
        'Preview (no dates)'            => $d['soa_preview']['balance'] ?? null,
        "SoA page ({$sub}→today)"         => $e['soa']['balance'] ?? null,
        "Preview ({$sub}→today)"          => $f['soa_preview']['balance'] ?? null,
    ]);

    // Range integrity: every rent bill dated inside tenancy→today must be listed.
    $voids = getRentVoidMap($pdo, $uid, $start, $today, $tid);
    $bills = buildRentChargeRows($pdo, $uid, [$t], (int)$t['due_day'], (float)$t['unit_rate'], $start, $today, $voids);
    foreach (['SoA page' => $b['soa']['rows'] ?? [], 'Preview' => $c['soa_preview']['rows'] ?? [], 'SoA page (default)' => $a['soa']['rows'] ?? [], 'Preview (no dates)' => $d['soa_preview']['rows'] ?? []] as $page => $rows) {
        $listed = [];
        foreach ($rows as $r) if ($r['type'] === 'charge') $listed[$r['date'] . '|' . from_cents(to_cents($r['debit']))] = true;
        foreach ($bills as $bill) {
            $checks++;
            if (empty($listed[$bill['date'] . '|' . $bill['gross']])) {
                $findings[] = ['Missing bill', $label, "$page does not list the {$bill['date']} bill ({$bill['description']}, " . $bill['gross'] . ')'];
            }
        }
    }
}

// Unit ledgers (tenant_id=all): each occupancy closes on its own balance, so
// the ledger total is the sum over the occupancies the range touches.
$byUnit = [];
foreach ($tenancies as $t) $byUnit[(int)$t['unit_id']][] = $t;
foreach ($byUnit as $uid => $ts) {
    $starts = array_filter(array_column($ts, 'contract_start'));
    foreach ([$starts ? min($starts) : date('Y-01-01'), date('Y-m-01', strtotime($today . ' -3 months'))] as $from) {
        $touch = array_filter($ts, fn($t) => (empty($t['contract_start']) || $t['contract_start'] <= $today)
                                         && (empty($t['contract_end']) || $t['contract_end'] >= $from));
        $want  = money_sum(array_map(fn($t) => $truth[(int)$t['id']]['balance'], $touch) ?: ['0.00']);
        [$a] = render('payments/history.php', ['unit_id' => $uid, 'tenant_id' => 'all', 'date_from' => $from, 'date_to' => $today]);
        [$c] = render('payments/soa_pdf.php', ['unit_id' => $uid, 'tenant_id' => 'all', 'date_from' => $from, 'date_to' => $today]);
        expectEq('Unit ledger', $ts[0]['unit_name'] . " ({$from}→today, all occupants)", [
            'Σ truth of occupancies' => $want, 'SoA page' => $a['soa']['balance'] ?? null, 'Preview' => $c['soa_preview']['balance'] ?? null]);
    }
}

// ── 2. Dashboard: tracker, receivables, unit status, revenue, expenses ──
[$dash] = render('dashboard.php');
$D = $dash['dashboard'] ?? null;
if (!$D) {
    $findings[] = ['Harness', 'dashboard', 'dashboard did not emit recon data'];
} else {
    foreach ($D['tracker'] as $row) {
        $t = array_values(array_filter($tenancies, fn($x) => (int)$x['id'] === $row['tenant_id']))[0] ?? null;
        expectEq('Balance Tracker', ($t['unit_name'] ?? '?') . ' ' . ($t['full_name'] ?? '?'), [
            'truth' => $truth[$row['tenant_id']]['balance'] ?? null, 'Balance Tracker' => $row['balance']]);
    }
    $sumPos = fn(string $status) => money_sum(array_map(fn($t) => money_max('0.00', $truth[(int)$t['id']]['balance']),
        array_filter($tenancies, fn($t) => $status === 'active' ? $t['status'] === 'active' : $t['status'] !== 'active')) ?: ['0.00']);
    expectEq('Receivables', 'Hero "Current" receivables', ['truth Σ active balances' => $sumPos('active'), 'dashboard hero' => $D['arrears_current']]);
    expectEq('Receivables', 'Hero "Past tenants" receivables', ['truth Σ former balances' => $sumPos('former'), 'dashboard hero' => $D['arrears_past']]);

    // Unit Status card vs collection grid (monthly_summary) for this month.
    [$ms] = render('payments/api_payment.php', [], ['action' => 'monthly_summary', 'month' => (int)date('n', strtotime($today)), 'year' => (int)date('Y', strtotime($today))], true);
    $grid = [];
    foreach (($ms['summary'] ?? $ms['units'] ?? $ms['rows'] ?? []) as $g) $grid[(int)$g['id']] = $g;
    foreach ($D['unit_status'] as $us) {
        if ($us['state'] === 'vacant') continue;
        $g = $grid[$us['unit_id']] ?? null;
        $name = $g['unit_name'] ?? ('unit #' . $us['unit_id']);
        expectEq('This month', "$name billed", ['Unit Status card' => $us['expected'], 'Collection grid' => $g['expected_charge'] ?? null]);
        expectEq('This month', "$name rent paid", ['Unit Status card' => $us['paid'], 'Collection grid' => $g['rent_paid'] ?? null]);
    }

    // Revenue & expenses for the year.
    $y = (int)$D['year'];
    $truthRev = from_cents(to_cents($pdo->query(
        "SELECT COALESCE(SUM(p.amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id=p.id),0)),0)
         FROM payments p WHERE p.deleted_at IS NULL AND p.status != 'voided' AND p.payment_date >= '$y-01-01' AND p.payment_date < '" . ($y + 1) . "-01-01'")->fetchColumn()));
    $truthExp = from_cents(to_cents($pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE deleted_at IS NULL AND expense_date >= '$y-01-01' AND expense_date < '" . ($y + 1) . "-01-01'")->fetchColumn()));
    [$uc]  = render('api/unit_chart_api.php', ['period_type' => 'year', 'year' => $y], [], true);
    [$cc]  = render('api/cat_chart_api.php',  ['period_type' => 'year', 'year' => $y], [], true);
    [$aud] = render('payments/audit_pdf.php', ['year' => $y, 'month' => 0]);
    [$txp] = render('admin/transactions.php', ['from' => "$y-01-01", 'to' => "$y-12-31"]);
    $truthGross = from_cents(to_cents($pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE deleted_at IS NULL AND status != 'voided' AND payment_date >= '$y-01-01' AND payment_date < '" . ($y + 1) . "-01-01'")->fetchColumn()));
    expectEq('Revenue', "Payments received $y (gross, before refunds)", [
        'truth' => $truthGross, 'transactions page' => $txp['transactions']['by_type']['payment'] ?? null]);
    expectEq('Revenue', "Revenue $y", [
        'truth (payments − refunds)'  => $truthRev,
        'dashboard hero'              => $D['total_rev'],
        'dashboard monthly table Σ'   => money_sum($D['monthly_rev']),
        'dashboard per-unit Σ'        => money_sum(array_values($D['unit_rev']) ?: ['0.00']),
        'unit chart (full year)'      => isset($uc['totalRev']) ? (string)$uc['totalRev'] : null,
        'audit report (rent+service)' => isset($aud['audit']) ? money_add($aud['audit']['rent_paid'], $aud['audit']['service_paid']) : null,
    ]);
    expectEq('Expenses', "Expenses $y", [
        'truth'                      => $truthExp,
        'dashboard hero'             => $D['total_exp'],
        'dashboard monthly table Σ'  => money_sum($D['monthly_exp']),
        'dashboard per-unit Σ'       => money_sum(array_values($D['unit_exp']) ?: ['0.00']),
        'unit chart (full year)'     => isset($uc['totalExp']) ? (string)$uc['totalExp'] : null,
        'category chart (full year)' => isset($cc['total']) ? (string)$cc['total'] : null,
        'audit report'               => $aud['audit']['expenses'] ?? null,
        'transactions page'          => $txp['transactions']['by_type']['expense'] ?? null,
    ]);
}

// ── 3. Cash on hand ──────────────────────────────────────────────
[$cash] = render('api/cash_api.php', [], ['action' => 'all_users_balance'], true);
foreach (($cash['users'] ?? $cash['data'] ?? []) as $u) {
    expectEq('Cash on hand', $u['full_name'], ['getUserCashOnHand' => getUserCashOnHand($pdo, (int)$u['id']), 'Cash page' => $u['cash_on_hand'] ?? null]);
}

// ── 4. Data checks (report only) ─────────────────────────────────
$data = [
    'Rent periods with 2+ payments (instalments or mis-tagged?)' =>
        "SELECT CONCAT(ru.unit_name,' ',t.full_name,' — ',DATE_FORMAT(STR_TO_DATE(CONCAT(p.period_year,'-',p.period_month,'-01'),'%Y-%c-%d'),'%b %Y'),': ',
                GROUP_CONCAT(CONCAT(p.invoice_no,' ',p.amount,' paid ',p.payment_date) ORDER BY p.payment_date SEPARATOR '; '))
         FROM payments p JOIN tenants t ON t.id=p.tenant_id JOIN rental_units ru ON ru.id=p.unit_id
         WHERE p.payment_type='rent' AND p.deleted_at IS NULL AND p.status!='voided'
         GROUP BY p.tenant_id, p.period_year, p.period_month HAVING COUNT(*) > 1",
    'Rent payments tagged to a month with no bill for that tenant' => null,   // computed below
    'Payments with no tenant' =>
        "SELECT CONCAT(invoice_no,' ',amount,' ',payment_date) FROM payments WHERE tenant_id IS NULL AND deleted_at IS NULL AND status!='voided'",
    'Service charges with no tenant' =>
        "SELECT CONCAT('#',id,' ',description,' ',amount,' dated ',charge_date) FROM unit_charges WHERE tenant_id IS NULL AND voided_at IS NULL",
    'Rent waivers with no tenant' =>
        "SELECT CONCAT('#',id,' ',amount,' for ',period_month,'/',period_year) FROM rent_charge_voids WHERE tenant_id IS NULL AND restored_at IS NULL",
    'Payments on a unit other than the tenant’s' =>
        "SELECT CONCAT(p.invoice_no,' tenant #',p.tenant_id) FROM payments p JOIN tenants t ON t.id=p.tenant_id WHERE p.unit_id<>t.unit_id AND p.deleted_at IS NULL",
    'Live payments without their cash row' =>
        "SELECT CONCAT(p.invoice_no,' ',p.amount) FROM payments p WHERE p.deleted_at IS NULL AND p.status!='voided' AND p.transfer_id IS NULL
         AND NOT EXISTS (SELECT 1 FROM cash_transactions c WHERE c.reference_payment_id=p.id AND c.transaction_type='received')",
    'Voided/deleted payments that still have a cash row' =>
        "SELECT CONCAT(p.invoice_no,' ',p.amount) FROM payments p JOIN cash_transactions c ON c.reference_payment_id=p.id AND c.transaction_type='received'
         WHERE p.deleted_at IS NOT NULL OR p.status='voided'",
    'Refunds larger than their payment' =>
        "SELECT CONCAT(p.invoice_no,' paid ',p.amount,' refunded ',SUM(r.amount)) FROM refunds r JOIN payments p ON p.id=r.payment_id GROUP BY p.id, p.invoice_no, p.amount HAVING SUM(r.amount) > MAX(p.amount)",
    'Overlapping tenancies on one unit' =>
        "SELECT CONCAT(ru.unit_name,': ',a.full_name,' / ',b.full_name) FROM tenants a JOIN tenants b ON a.unit_id=b.unit_id AND a.id<b.id
         JOIN rental_units ru ON ru.id=a.unit_id
         WHERE a.status IN ('active','former','inactive') AND b.status IN ('active','former','inactive')
           AND (a.contract_end IS NULL OR b.contract_start IS NULL OR a.contract_end >= b.contract_start)
           AND (b.contract_end IS NULL OR a.contract_start IS NULL OR b.contract_end >= a.contract_start)",
    'Active tenants whose end date has passed' =>
        "SELECT CONCAT(full_name,' ended ',contract_end) FROM tenants WHERE status='active' AND contract_end IS NOT NULL AND contract_end < '$today'",
    'Former/inactive tenants with no end date' =>
        "SELECT full_name FROM tenants WHERE status IN ('former','inactive') AND unit_id IS NOT NULL AND contract_end IS NULL",
];
$dataFindings = [];
foreach ($data as $title => $sql) {
    if ($sql === null) continue;
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $line) $dataFindings[$title][] = $line;
}
foreach ($tenancies as $t) {
    $billed = [];
    foreach (buildRentChargeRows($pdo, (int)$t['unit_id'], [$t], (int)$t['due_day'], (float)$t['unit_rate'], $t['contract_start'] ?: '2000-01-01', '2100-12-31') as $r) {
        $billed[$r['period_year'] . '-' . $r['period_month']] = true;
    }
    $q = $pdo->prepare("SELECT invoice_no, amount, period_month, period_year, payment_date FROM payments
                        WHERE tenant_id=? AND payment_type='rent' AND deleted_at IS NULL AND status!='voided' AND transfer_id IS NULL");
    $q->execute([(int)$t['id']]);
    foreach ($q->fetchAll() as $p) {
        if (empty($billed[$p['period_year'] . '-' . $p['period_month']])) {
            $dataFindings['Rent payments tagged to a month with no bill for that tenant'][] =
                "{$t['unit_name']} {$t['full_name']}: {$p['invoice_no']} {$p['amount']} paid {$p['payment_date']} tagged " . date('M Y', mktime(0, 0, 0, (int)$p['period_month'], 1, (int)$p['period_year']));
        }
    }
}

// ── Report ───────────────────────────────────────────────────────
$lines = [];
$lines[] = "Laskie reconciliation — " . RECON_DB . " as of $today";
$lines[] = str_repeat('=', 72);
$lines[] = sprintf("%d comparisons, %d mismatches, %d data findings", $checks, count($findings), array_sum(array_map('count', $dataFindings)));
$byCat = [];
foreach ($findings as [$cat, $subj, $det]) $byCat[$cat][] = "  • $subj\n      $det";
foreach ($byCat as $cat => $items) { $lines[] = "\n## $cat (" . count($items) . ")"; array_push($lines, ...$items); }
if ($dataFindings) {
    $lines[] = "\n## Data findings (report only — corrections need approval)";
    foreach ($dataFindings as $title => $items) { $lines[] = "  $title (" . count($items) . "):"; foreach ($items as $i) $lines[] = "    - $i"; }
}
$report = implode("\n", $lines) . "\n";
if (!empty($opts['out'])) file_put_contents($opts['out'], $report);
echo $report;
exit($findings ? 1 : 0);
