#!/usr/bin/env php
<?php
// ============================================================
// Laskie Seeder — Jan–Apr 2026 spreadsheet data
// Usage: php seed_spreadsheet.php
// ============================================================

// CLI-only guard. Apache would otherwise serve this file: the script touches
// the production DB, and the .htaccess block list doesn't cover a bare .php
// at the doc-root. Refuse any non-CLI SAPI before loading anything else.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SESSION = [];
$_SERVER += ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => ''];

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';

// ── CLI helpers ──────────────────────────────────────────────
function cliPrompt(string $q): string { fwrite(STDOUT, $q); return trim(fgets(STDIN)); }
function ok(string $m): void  { echo "\033[32m✓\033[0m $m\n"; }
function info(string $m): void { echo "  $m\n"; }
function warn(string $m): void { echo "\033[33m⚠\033[0m  $m\n"; }

function switchUser(array &$users, array &$roles, string $username): void {
    $_SESSION['user'] = [
        'id'        => $users[$username],
        'username'  => $username,
        'full_name' => ucfirst($username),
        'role'      => $roles[$username] ?? 'staff',
        'email'     => '',
    ];
}

function askTagger(array &$users, array &$roles, string $unitName, string $desc, float $amount): string {
    echo "\n\033[33m⚠  TAGGER NEEDED\033[0m\n";
    echo "   Unit:   $unitName\n";
    echo "   Desc:   $desc\n";
    echo "   Amount: ₱" . number_format($amount, 2) . "\n";
    echo "   [1] admin (NJ)   [2] paul   [3] romel (Kokoy)\n";
    $c = cliPrompt("   Enter 1/2/3 [default 1]: ");
    return match($c) { '2' => 'paul', '3' => 'romel', default => 'admin' };
}

function insertPayment(PDO $pdo, array &$users, array &$roles, array &$unitIds, array &$tenantIds, array &$stIds,
    string $unitName, float $amount, string $type, ?string $stName, string $byUser,
    string $date, int $pm, int $py, string $notes): void
{
    $unitId   = $unitIds[$unitName] ?? null;
    $tenantId = $tenantIds[$unitName] ?? null;
    $stId     = ($stName && isset($stIds[$stName])) ? $stIds[$stName] : null;
    if (!$unitId) { warn("Unit not found: $unitName — skipping payment"); return; }

    switchUser($users, $roles, $byUser);
    $invoiceNo = generateInvoiceNo($pdo);
    $pdo->prepare("INSERT INTO payments (invoice_no,unit_id,tenant_id,payment_type,service_type_id,amount,period_month,period_year,payment_date,received_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$invoiceNo,$unitId,$tenantId,$type,$stId,$amount,$pm,$py,$date,$users[$byUser],$notes]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
        ->execute([$users[$byUser],'received',$amount,$newId,"Payment received: $invoiceNo",$date]);

    if ($type === 'service' && $stId) {
        $sn = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
        $sn->execute([$stId]);
        $chgDesc = $notes ?: ($sn->fetchColumn() ?: 'Service');
        $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,payment_id,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$unitId,$tenantId,$stId,$amount,$chgDesc,$date,$pm,$py,$newId,'auto_collected',$users[$byUser]]);
    }
    logActivity($pdo,'RECORD_PAYMENT','Payments',"Seeder: $invoiceNo $unitName ₱$amount");
    info("  + Payment $invoiceNo | $unitName | $type | ₱$amount | $byUser | $date");
}

function insertExpense(PDO $pdo, array &$users, array &$roles, array &$unitIds, array &$catIds,
    ?string $unitName, string $catName, float $amount, string $date, string $byUser, string $desc): void
{
    $unitId = $unitName ? ($unitIds[$unitName] ?? null) : null;
    $catId  = $catIds[$catName] ?? null;
    if (!$unitName && $unitName !== null) { warn("Unit not found: $unitName"); }

    switchUser($users, $roles, $byUser);
    $pdo->prepare("INSERT INTO expenses (unit_id,category_id,amount,expense_date,description,recorded_by) VALUES (?,?,?,?,?,?)")
        ->execute([$unitId,$catId,$amount,$date,$desc,$users[$byUser]]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_expense_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
        ->execute([$users[$byUser],'expense',$amount,$newId,"Expense: $desc",$date]);

    logActivity($pdo,'CREATE_EXPENSE','Expenses',"Seeder: $desc ₱$amount");
    info("  + Expense | $unitName | $catName | ₱$amount | $byUser | $date");
}

// ── Verify users ─────────────────────────────────────────────
$users = [];
foreach ($pdo->query("SELECT id,username FROM users WHERE status='active'")->fetchAll() as $r) {
    $users[$r['username']] = (int)$r['id'];
}
if (!isset($users['admin'],$users['paul'],$users['romel']))
    die("ERROR: accounts admin/paul/romel not found.\n");
$roles = ['admin'=>'admin','paul'=>'admin','romel'=>'staff'];
switchUser($users, $roles, 'admin');
ok("Users: admin(id={$users['admin']}), paul(id={$users['paul']}), romel(id={$users['romel']})");

// ── Phase 1: Expense categories ──────────────────────────────
$catDefs = [
    'Utilities'            => 'Electricity, water, internet, gas',
    'Repairs & Maintenance'=> 'Property repair and maintenance',
    'Salaries & Wages'     => 'Staff salaries and wages',
    'Cleaning'             => 'Cleaning services',
    'Food'                 => 'Food and meals',
    'Bank Fees'            => 'Bank and transaction fees',
    'Travel'               => 'Transportation and travel',
    'Misc/Abuloy'          => 'Miscellaneous and community contributions',
];
$catIds = [];
foreach ($catDefs as $name => $desc) {
    $s = $pdo->prepare("SELECT id FROM expense_categories WHERE name=?"); $s->execute([$name]);
    $id = $s->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO expense_categories (name,description) VALUES (?,?)")->execute([$name,$desc]);
        $id = (int)$pdo->lastInsertId(); info("+ Category: $name");
    }
    $catIds[$name] = (int)$id;
}
ok("Expense categories: " . count($catIds));

// ── Phase 1b: Service types ──────────────────────────────────
$stDefs = [
    'Late Payment Fee' => ['Late payment penalty',   500.00],
    'Prepaid Internet' => ['Prepaid internet/WiFi',  600.00],
    'Security Deposit' => ['Security/damage deposit',  0.00],
];
$stIds = [];
foreach ($stDefs as $name => [$desc, $amt]) {
    $s = $pdo->prepare("SELECT id FROM service_types WHERE name=?"); $s->execute([$name]);
    $id = $s->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO service_types (name,description,default_amount,is_active) VALUES (?,?,?,1)")->execute([$name,$desc,$amt]);
        $id = (int)$pdo->lastInsertId(); info("+ Service type: $name");
    }
    $stIds[$name] = (int)$id;
}
ok("Service types: " . count($stIds));

// ── Phase 2: Rental units ────────────────────────────────────
$unitDefs = [
    ['359-A',        10000, 'occupied'], ['359-A2',       5000, 'occupied'],
    ['359-B',         3500, 'occupied'], ['359-D',        8500, 'occupied'],
    ['359-G1',        2500, 'vacant'],   ['359-G3',       4500, 'occupied'],
    ['359-H',         7500, 'occupied'], ['359-I',        5500, 'occupied'],
    ['359-J',         5000, 'occupied'], ['359-K',        6000, 'occupied'],
    ['359-L',         6000, 'occupied'], ['359-M',        5500, 'occupied'],
    ['800-A1',        4500, 'occupied'], ['800-A2',       3500, 'occupied'],
    ['800-A3',        3500, 'occupied'], ['800-B1',       4500, 'occupied'],
    ['800-B2',        3500, 'occupied'], ['800-B3',       2500, 'occupied'],
    ['800-B4',        2500, 'occupied'], ['800-B5',       4000, 'vacant'],
    ['800-B6',        3000, 'occupied'], ['800-Basement', 2000, 'vacant'],
    ['800-01',        3000, 'vacant'],   ['800-02',       3000, 'occupied'],
    ['800-03',        1000, 'occupied'], ['800-04',       3000, 'occupied'],
    ['800-05',        3000, 'occupied'], ['800-06',       3000, 'occupied'],
    ['800-07',        3500, 'occupied'], ['800-08',       3500, 'occupied'],
    ['Camilla 2K',       0, 'vacant'],   ['NANAYS PLACE',    0, 'vacant'],
];
$unitIds = [];
foreach ($unitDefs as [$name, $rate, $status]) {
    $s = $pdo->prepare("SELECT id FROM rental_units WHERE unit_name=?"); $s->execute([$name]);
    $id = $s->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO rental_units (unit_name,monthly_rate,status,due_day) VALUES (?,?,?,5)")->execute([$name,$rate,$status]);
        $id = (int)$pdo->lastInsertId(); info("+ Unit: $name");
    }
    $unitIds[$name] = (int)$id;
}
ok("Units: " . count($unitIds));

// ── Phase 3: Tenants ─────────────────────────────────────────
// [unit, full_name, phone, email, notes]
$tenantDefs = [
    ['359-A',  'RENANTE DULAY',           '9919989532', 'reydulay99@gmail.com',       '2nd occupant: MARGIE DULAY'],
    ['359-A2', 'JONHNEZZA FRANCISCO',     '9663757122', '',                            ''],
    ['359-B',  'JHUN LESTER A. BELLEZA',  '9531964019', 'lstrbllz@gmail.com',          '2nd occupant: JOHN MICHAEL ANTOLIN'],
    ['359-D',  'EDEN MENDOZA',            '9178734150', '',                            ''],
    ['359-G3', 'ARNOLD ALCORIZA BENTOSO', '9067733941', 'bentosoarnold@gmail.com',     '2nd occupant: JUDY ANN DE LAS ALAS'],
    ['359-H',  'CHRISTIAN CLANOR',        '9615746379', 'christian.clanor@gmail.com',  '2nd occupant: JACELL FLORES'],
    ['359-I',  'JEFFREY SARIO',           '9979276263', '',                            ''],
    ['359-J',  'LARY PENEYRA',            '9692374033', '',                            ''],
    ['359-K',  'DANICO MARZAN',           '9226663360', 'marzandanico22@gmail.com',    '2nd occupant: SYRA JANE MERIN'],
    ['359-L',  'RASHID ZOZOBRADO',        '9701517009', 'rashidzozobrado@gmail.com',   '2nd occupant: ROSE ANN CLORES'],
    ['359-M',  'ALFREDO IGDALINO',        '9452668522', '',                            '2nd occupant: CYNTHIA IGDALINO'],
    ['800-A1', 'MARICEL BALAD-ON',        '', '', ''],
    ['800-A2', 'MYRLINDA A. LOZANO',      '', '', ''],
    ['800-A3', 'MARY JOY M. MACARANAS',   '', '', ''],
    ['800-B1', 'JULIET CARDENO',          '', '', ''],
    ['800-B2', 'RICHARD DONQUE',          '', '', ''],
    ['800-B3', 'JOVY PUGADO',             '', '', ''],
    ['800-B4', 'PAUL ERADIO',             '', '', ''],
    ['800-B6', 'BOY ETCHON',              '', '', ''],
    ['800-02', 'GEORGE BALDOMAR',         '', '', ''],
    ['800-03', 'CARMEN JARDALEZA',        '', '', ''],
    ['800-04', 'ADA FLORES',              '', '', ''],
    ['800-05', 'ROMULO LO',               '', '', ''],
    ['800-06', 'RHIZA ETCHON',            '', '', ''],
    ['800-07', 'ARLENE GUEVARRA',         '', '', ''],
    ['800-08', 'JOCELYN',                 '', '', ''],
];
$tenantIds = [];
foreach ($tenantDefs as [$unit, $name, $phone, $email, $notes]) {
    $uid = $unitIds[$unit] ?? null;
    if (!$uid) { warn("Unit $unit not found for tenant $name"); continue; }
    $s = $pdo->prepare("SELECT id FROM tenants WHERE unit_id=? AND full_name=?"); $s->execute([$uid,$name]);
    $id = $s->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO tenants (unit_id,full_name,phone,email,contract_start,contract_end,status,notes) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$uid,$name,$phone?:null,$email?:null,'2026-01-05','2027-01-05','active',$notes?:null]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE rental_units SET status='occupied' WHERE id=?")->execute([$uid]);
        info("+ Tenant: $name → $unit");
    }
    $tenantIds[$unit] = (int)$id;
}
ok("Tenants: " . count($tenantIds));

// ── Phase 4: January 2026 Payments ──────────────────────────
echo "\n=== JANUARY 2026 ===\n";
// [unit, amount, type, service_type|null, user, date, period_month, period_year, notes]
$janPayments = [
    ['359-A2', 5000,  'rent', null,             'romel', '2026-01-19', 1, 2026, 'Cash to Kokoy'],
    ['359-D',  8500,  'rent', null,             'romel', '2026-01-26', 1, 2026, 'Cash to Kokoy'],
    ['359-G3', 4500,  'rent', null,             'romel', '2026-01-08', 1, 2026, 'Cash to Kokoy'],
    ['359-H',  15000, 'rent', null,             'romel', '2026-01-08', 1, 2026, 'Cash to Kokoy'],
    ['359-I',  5500,  'rent', null,             'paul',  '2026-01-20', 1, 2026, 'GCASH to Paul'],
    ['359-J',  5000,  'rent', null,             'romel', '2026-01-05', 1, 2026, 'Cash to Kokoy'],
    ['359-K',  6000,  'rent', null,             'paul',  '2026-01-05', 1, 2026, 'GCASH to Paul'],
    ['359-M',  5500,  'rent', null,             'romel', '2026-01-05', 1, 2026, 'Cash to Kokoy'],
    ['800-A1', 4500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-A3', 3500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-B1', 4500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-B2', 3500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-B4', 2500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-B6', 3000,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-02', 3000,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-04', 3000,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-05', 3000,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-06', 3000,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-07', 3500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['800-08', 3500,  'rent', null,             'admin', '2026-01-05', 1, 2026, 'Cash to NJ'],
    ['359-H',  500,   'service','Late Payment Fee','romel','2026-01-06',1, 2026, 'Cash to Kokoy (late fee)'],
    ['359-D',  600,   'service','Prepaid Internet','romel','2026-01-24', 1, 2026, 'Cash to Kokoy (internet)'],
];
foreach ($janPayments as $p)
    insertPayment($pdo,$users,$roles,$unitIds,$tenantIds,$stIds,...$p);

// [unit|null, category, amount, date, user|ASK, description]
$janExpenses = [
    ['800-01',       'Salaries & Wages', 300.00,    '2026-01-13', 'ASK',  'Hakot panambak'],
    ['800-B5',       'Utilities',        365.00,    '2026-01-19', 'ASK',  '800-B5 Remaining Maynilad Balance'],
    ['800-A1',       'Salaries & Wages', 300.00,    '2026-01-26', 'ASK',  'Putol Malunggay'],
    ['800-A3',       'Utilities',        300.00,    '2026-01-26', 'ASK',  'Malabanan Maynilad Balance'],
    ['800-01',       'Utilities',        200.00,    '2026-01-26', 'romel','Maynilad Abono VACANT 800-01'],
    ['NANAYS PLACE', 'Utilities',        9993.74,   '2026-01-08', 'paul', 'Meralco (main meter)'],
    ['NANAYS PLACE', 'Utilities',        805.96,    '2026-01-08', 'paul', 'Meralco (secondary meter)'],
    ['NANAYS PLACE', 'Utilities',        2107.00,   '2026-01-20', 'paul', 'PLDT'],
    ['NANAYS PLACE', 'Repairs & Maintenance', 6032.00, '2026-01-20', 'ASK', 'Nail Gun and dust cover film, GAS, FOOD'],
];
foreach ($janExpenses as $e) {
    [$unit, $cat, $amt, $date, $user, $desc] = $e;
    if ($user === 'ASK') $user = askTagger($users, $roles, $unit ?? 'N/A', $desc, $amt);
    insertExpense($pdo,$users,$roles,$unitIds,$catIds,$unit,$cat,$amt,$date,$user,$desc);
}
ok("January done.");

// ── Phase 5: February 2026 Payments ─────────────────────────
echo "\n=== FEBRUARY 2026 ===\n";
$febPayments = [
    ['359-A',  2900,  'rent', null,              'romel', '2026-02-08', 2, 2026, 'Cash to Kokoy'],
    ['359-A',  7100,  'rent', null,              'paul',  '2026-02-08', 2, 2026, 'GCASH to Paul'],
    ['359-A',  500,   'service','Late Payment Fee','romel','2026-02-08', 2, 2026, 'Cash to Kokoy (late fee)'],
    ['359-A',  600,   'service','Prepaid Internet','romel','2026-02-08', 2, 2026, 'Cash to Kokoy (internet)'],
    ['359-A2', 5000,  'rent', null,              'romel', '2026-02-13', 2, 2026, 'Cash to Kokoy'],
    ['359-D',  8500,  'rent', null,              'paul',  '2026-02-20', 2, 2026, 'GCASH to Paul'],
    ['359-G3', 4500,  'rent', null,              'romel', '2026-02-26', 2, 2026, 'Cash to Kokoy'],
    ['359-H',  7500,  'rent', null,              'romel', '2026-02-03', 2, 2026, 'Cash to Kokoy'],
    ['359-I',  5500,  'rent', null,              'paul',  '2026-02-12', 2, 2026, 'GCASH to Paul'],
    ['359-J',  5000,  'rent', null,              'romel', '2026-02-05', 2, 2026, 'Cash to Kokoy'],
    ['359-K',  6000,  'rent', null,              'paul',  '2026-02-08', 2, 2026, 'PNB to Paul'],
    ['359-M',  5500,  'rent', null,              'romel', '2026-02-24', 2, 2026, 'Cash to Kokoy'],
    ['800-A1', 4500,  'rent', null,              'admin', '2026-02-06', 2, 2026, 'Cash to NJ'],
    ['800-A2', 3500,  'rent', null,              'admin', '2026-02-09', 2, 2026, 'Cash to NJ'],
    ['800-A3', 3500,  'rent', null,              'admin', '2026-02-17', 2, 2026, 'Cash to NJ'],
    ['800-B1', 4500,  'rent', null,              'admin', '2026-02-06', 2, 2026, 'Cash to NJ'],
    ['800-B2', 3500,  'rent', null,              'admin', '2026-02-09', 2, 2026, 'Cash to NJ'],
    ['800-B3', 5000,  'service','Security Deposit','admin','2026-02-24', 2, 2026, 'Cash to NJ (deposit/advance)'],
    ['800-B4', 2500,  'rent', null,              'admin', '2026-02-27', 2, 2026, 'Cash to NJ'],
    ['800-B6', 3000,  'rent', null,              'admin', '2026-02-24', 2, 2026, 'Cash to NJ'],
    ['800-02', 3000,  'rent', null,              'admin', '2026-02-01', 2, 2026, 'Cash to NJ'],
    ['800-04', 3000,  'rent', null,              'admin', '2026-02-25', 2, 2026, 'Cash to NJ'],
    ['800-05', 3000,  'rent', null,              'admin', '2026-02-01', 2, 2026, 'Cash to NJ'],
    ['800-06', 3000,  'rent', null,              'admin', '2026-02-05', 2, 2026, 'Cash to NJ'],
    ['800-07', 3500,  'rent', null,              'admin', '2026-02-21', 2, 2026, 'Cash to NJ'],
];
foreach ($febPayments as $p)
    insertPayment($pdo,$users,$roles,$unitIds,$tenantIds,$stIds,...$p);

$febExpenses = [
    ['NANAYS PLACE','Utilities',        9937.31,  '2026-02-08','paul', 'Meralco (main meter)'],
    ['NANAYS PLACE','Utilities',         408.88,  '2026-02-08','paul', 'Meralco (secondary meter)'],
    ['NANAYS PLACE','Utilities',        1020.88,  '2026-02-08','paul', 'Maynilad'],
    ['NANAYS PLACE','Utilities',        2107.00,  '2026-02-08','paul', 'PLDT'],
    ['800-B1',      'Repairs & Maintenance',2780.00,'2026-02-24','ASK','Kisame and doors repair, roof patching'],
    ['800-01',      'Utilities',         244.00,  '2026-02-25','romel','800-01 Abono water bill'],
    ['359-L',       'Repairs & Maintenance',34167.11,'2026-02-22','ASK','Materials,tools,abono,food,sahod,transpo'],
    ['800-01',      'Repairs & Maintenance',7000.00,'2026-02-28','ASK', 'Panambak 2 trucks from Valenzuela'],
];
foreach ($febExpenses as $e) {
    [$unit,$cat,$amt,$date,$user,$desc] = $e;
    if ($user === 'ASK') $user = askTagger($users,$roles,$unit??'N/A',$desc,$amt);
    insertExpense($pdo,$users,$roles,$unitIds,$catIds,$unit,$cat,$amt,$date,$user,$desc);
}
ok("February done.");

// ── Phase 6: March 2026 Payments ────────────────────────────
echo "\n=== MARCH 2026 ===\n";
$marPayments = [
    ['359-A',  10000, 'rent', null,               'paul',  '2026-03-08', 3, 2026, 'GCASH to Paul'],
    ['359-A2', 5000,  'rent', null,               'romel', '2026-03-12', 3, 2026, 'Cash to Kokoy'],
    ['359-B',  9000,  'service','Security Deposit','romel', '2026-03-07', 3, 2026, 'Cash to Kokoy (deposit)'],
    ['359-D',  8500,  'rent', null,               'paul',  '2026-03-21', 3, 2026, 'GCASH to Paul'],
    ['359-H',  7500,  'rent', null,               'romel', '2026-03-04', 3, 2026, 'Cash to Kokoy'],
    ['359-I',  5500,  'rent', null,               'paul',  '2026-03-14', 3, 2026, 'GCASH to Paul'],
    ['359-J',  5000,  'rent', null,               'romel', '2026-03-04', 3, 2026, 'Cash to Kokoy'],
    ['359-K',  6000,  'rent', null,               'paul',  '2026-03-14', 3, 2026, 'PNB to Paul'],
    ['359-L',  15000, 'service','Security Deposit','paul',  '2026-03-01', 3, 2026, 'GCASH to Paul (deposit)'],
    ['359-M',  5500,  'rent', null,               'romel', '2026-03-15', 3, 2026, 'Cash to Kokoy'],
    ['800-A1', 4500,  'rent', null,               'admin', '2026-03-06', 3, 2026, 'Cash to NJ'],
    ['800-A2', 3500,  'rent', null,               'admin', '2026-03-04', 3, 2026, 'Cash to NJ'],
    ['800-B1', 4500,  'rent', null,               'admin', '2026-03-16', 3, 2026, 'Cash to NJ'],
    ['800-B2', 3500,  'rent', null,               'admin', '2026-03-09', 3, 2026, 'Cash to NJ'],
    ['800-B3', 2500,  'rent', null,               'admin', '2026-03-16', 3, 2026, 'Cash to NJ'],
    ['800-B4', 2500,  'rent', null,               'admin', '2026-03-28', 3, 2026, 'Cash to NJ'],
    ['800-B6', 3000,  'rent', null,               'admin', '2026-03-23', 3, 2026, 'Cash to NJ'],
    ['800-02', 3000,  'rent', null,               'admin', '2026-03-01', 3, 2026, 'Cash to NJ'],
    ['800-04', 3000,  'rent', null,               'admin', '2026-03-26', 3, 2026, 'Cash to NJ'],
    ['800-05', 3000,  'rent', null,               'admin', '2026-03-07', 3, 2026, 'Cash to NJ'],
    ['800-06', 3000,  'rent', null,               'admin', '2026-03-07', 3, 2026, 'Cash to NJ'],
    ['800-07', 3500,  'rent', null,               'admin', '2026-03-28', 3, 2026, 'Cash to NJ'],
    ['800-08', 3500,  'rent', null,               'admin', '2026-03-14', 3, 2026, 'Cash to NJ'],
    // Arrears — period_month = 2 (February)
    ['800-A2', 3500,  'rent', null,               'admin', '2026-03-24', 2, 2026, 'Cash to NJ (Feb arrears)'],
    ['800-B2', 3500,  'rent', null,               'admin', '2026-03-26', 2, 2026, 'Cash to NJ (Feb arrears)'],
    ['800-08', 3500,  'rent', null,               'admin', '2026-03-31', 2, 2026, 'Cash to NJ (Feb arrears)'],
    // Service charges
    ['359-A',  500,   'service','Prepaid Internet','paul',  '2026-03-08', 3, 2026, 'GCASH to Paul (internet)'],
    ['359-A',  500,   'service','Late Payment Fee','paul',  '2026-03-08', 3, 2026, 'GCASH to Paul (late fee)'],
    ['359-M',  500,   'service','Prepaid Internet','romel', '2026-03-15', 3, 2026, 'Cash to Kokoy (internet)'],
];
foreach ($marPayments as $p)
    insertPayment($pdo,$users,$roles,$unitIds,$tenantIds,$stIds,...$p);

$marExpenses = [
    ['359-L',       'Repairs & Maintenance', 8744.00,  '2026-03-04','ASK',  'Maintenance APT L'],
    ['NANAYS PLACE','Cleaning',              1200.00,  '2026-03-01','ASK',  'Aircon Cleaning'],
    ['NANAYS PLACE','Utilities',             9428.31,  '2026-03-09','paul', 'Meralco (main meter)'],
    ['NANAYS PLACE','Utilities',              613.15,  '2026-03-09','paul', 'Meralco (secondary meter)'],
    ['NANAYS PLACE','Utilities',              444.12,  '2026-03-09','paul', 'Maynilad'],
    ['NANAYS PLACE','Utilities',             2107.00,  '2026-03-10','paul', 'PLDT'],
    ['359-G3',      'Utilities',              364.00,  '2026-03-01','romel','Abono APT G3 Meralco'],
    ['359-G3',      'Bank Fees',               30.00,  '2026-03-01','romel','GCASH cash in fee (abono)'],
    ['359-B',       'Utilities',              249.32,  '2026-03-23','romel','Abono APT B Meralco'],
    ['359-L',       'Utilities',              772.30,  '2026-03-24','romel','Abono APT L Meralco'],
    ['NANAYS PLACE','Food',                  1000.00,  '2026-03-27','ASK',  'Nanay Birthday Foods'],
    ['NANAYS PLACE','Cleaning',               600.00,  '2026-03-15','ASK',  'Eternal Gardens Upkeep'],
];
foreach ($marExpenses as $e) {
    [$unit,$cat,$amt,$date,$user,$desc] = $e;
    if ($user === 'ASK') $user = askTagger($users,$roles,$unit??'N/A',$desc,$amt);
    insertExpense($pdo,$users,$roles,$unitIds,$catIds,$unit,$cat,$amt,$date,$user,$desc);
}
ok("March done.");

// ── Phase 7: April 2026 Payments ────────────────────────────
echo "\n=== APRIL 2026 ===\n";
$aprPayments = [
    ['359-A2', 5000,  'rent', null,  'romel', '2026-04-14', 4, 2026, 'Cash to Kokoy'],
    ['359-B',  3220,  'rent', null,  'romel', '2026-04-05', 4, 2026, 'Cash to Kokoy'],
    ['359-D',  8500,  'rent', null,  'paul',  '2026-04-22', 4, 2026, 'GCASH to Paul'],
    ['359-G3', 4500,  'rent', null,  'paul',  '2026-04-27', 4, 2026, 'GCASH to Paul'],
    ['359-I',  5500,  'rent', null,  'paul',  '2026-04-06', 4, 2026, 'GCASH to Paul'],
    ['359-J',  5000,  'rent', null,  'romel', '2026-04-11', 4, 2026, 'Cash to Kokoy'],
    ['359-K',  6000,  'rent', null,  'paul',  '2026-04-11', 4, 2026, 'PNB to Paul'],
    ['359-L',  6000,  'rent', null,  'romel', '2026-04-15', 4, 2026, 'Cash to Kokoy'],
    ['359-M',  5500,  'rent', null,  'romel', '2026-04-27', 4, 2026, 'Cash to Kokoy'],
    ['800-A1', 4500,  'rent', null,  'admin', '2026-04-30', 4, 2026, 'Cash to NJ'],
    ['800-A2', 3500,  'rent', null,  'admin', '2026-04-30', 4, 2026, 'Cash to NJ'],
    ['800-A3', 3500,  'rent', null,  'admin', '2026-04-15', 4, 2026, 'Cash to NJ'],
    ['800-B1', 4500,  'rent', null,  'admin', '2026-04-20', 4, 2026, 'Cash to NJ'],
    ['800-B3', 2500,  'rent', null,  'admin', '2026-04-17', 4, 2026, 'Cash to NJ'],
    ['800-B4', 3000,  'rent', null,  'admin', '2026-04-24', 4, 2026, 'Cash to NJ'],
    ['800-B6', 3000,  'rent', null,  'admin', '2026-04-23', 4, 2026, 'Cash to NJ'],
    ['800-02', 3000,  'rent', null,  'admin', '2026-04-27', 4, 2026, 'Cash to NJ'],
    ['800-04', 3000,  'rent', null,  'admin', '2026-04-28', 4, 2026, 'Cash to NJ'],
    ['800-05', 3000,  'rent', null,  'admin', '2026-04-17', 4, 2026, 'Cash to NJ'],
    ['800-06', 3000,  'rent', null,  'admin', '2026-04-21', 4, 2026, 'Cash to NJ'],
    ['800-07', 3500,  'rent', null,  'admin', '2026-04-23', 4, 2026, 'Cash to NJ'],
    ['800-08', 3500,  'rent', null,  'admin', '2026-04-15', 4, 2026, 'Cash to NJ'],
    // Arrears — period_month = 3 (March)
    ['800-A3', 3500,  'rent', null,  'admin', '2026-04-15', 3, 2026, 'Cash to NJ (Mar arrears)'],
    // Service charges
    ['359-M',  500,   'service','Late Payment Fee','romel','2026-04-24', 4, 2026, 'Cash to Kokoy (late fee)'],
];
foreach ($aprPayments as $p)
    insertPayment($pdo,$users,$roles,$unitIds,$tenantIds,$stIds,...$p);

$aprExpenses = [
    ['NANAYS PLACE','Utilities',        2107.00,  '2026-04-08','paul', 'PLDT'],
    ['NANAYS PLACE','Utilities',         614.70,  '2026-04-08','paul', 'Meralco (secondary meter)'],
    ['NANAYS PLACE','Utilities',       10243.50,  '2026-04-08','paul', 'Meralco (main meter)'],
    ['NANAYS PLACE','Cleaning',         1200.00,  '2026-04-15','ASK',  'Eternal Gardens Upkeep (2 months)'],
    ['800-02',      'Misc/Abuloy',      3000.00,  '2026-04-01','ASK',  '800-02 Abuloy'],
    ['800-06',      'Repairs & Maintenance',500.00,'2026-04-24','ASK', 'Baesa Unit 800-06 circuit breaker replacement'],
    ['800-01',      'Utilities',         180.00,  '2026-04-27','romel','Unit 800-01 Maynilad Abono'],
    ['800-B1',      'Cleaning',          300.00,  '2026-04-30','ASK',  'Linis Kanal'],
    ['NANAYS PLACE','Repairs & Maintenance',620.00,'2026-05-06','ASK', 'Duplicate Keys for 3rd floor'],
    ['NANAYS PLACE','Travel',           1000.00,  '2026-05-06','ASK',  'Gas'],
    ['NANAYS PLACE','Repairs & Maintenance',460.00,'2026-05-11','ASK', 'Hardware'],
    ['359-L',       'Utilities',         188.00,  '2026-05-11','ASK',  'APT-L Bill Maynilad'],
    ['NANAYS PLACE','Cleaning',          600.00,  '2026-05-15','ASK',  'Eternal Gardens upkeep'],
    ['NANAYS PLACE','Repairs & Maintenance',295.00,'2026-05-27','ASK', 'Hardware'],
    ['NANAYS PLACE','Travel',           1000.00,  '2026-05-30','ASK',  'Gas'],
];
foreach ($aprExpenses as $e) {
    [$unit,$cat,$amt,$date,$user,$desc] = $e;
    if ($user === 'ASK') $user = askTagger($users,$roles,$unit??'N/A',$desc,$amt);
    insertExpense($pdo,$users,$roles,$unitIds,$catIds,$unit,$cat,$amt,$date,$user,$desc);
}
ok("April done.");

// ── Phase 8: Verification ────────────────────────────────────
echo "\n=== VERIFICATION ===\n";
$expected = [
    1 => ['revenue'=>96600.00, 'expenses'=>20403.70, 'label'=>'January'],
    2 => ['revenue'=>104100.00,'expenses'=>57665.18, 'label'=>'February'],
    3 => ['revenue'=>132000.00,'expenses'=>25552.20, 'label'=>'March'],
    4 => ['revenue'=>96720.00, 'expenses'=>22308.20, 'label'=>'April'],
];

echo str_pad("Month",10).str_pad("SS Revenue",14).str_pad("SYS Revenue",14).str_pad("SS Expenses",14).str_pad("SYS Expenses",14)."Status\n";
echo str_repeat("-",80)."\n";

foreach ($expected as $pm => $exp) {
    $r = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE period_month=? AND period_year=2026");
    $r->execute([$pm]); $sysRev = (float)$r->fetchColumn();

    $e = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=? AND YEAR(expense_date)=2026");
    $e->execute([$pm]); $sysExp = (float)$e->fetchColumn();

    $revDiff = abs($sysRev - $exp['revenue']);
    $expDiff = abs($sysExp - $exp['expenses']);
    $status  = ($revDiff < 1 && $expDiff < 1) ? "\033[32mOK\033[0m" : "\033[31mANOMALY\033[0m";

    printf("%-10s %-14s %-14s %-14s %-14s %s\n",
        $exp['label'],
        '₱'.number_format($exp['revenue'],2),
        '₱'.number_format($sysRev,2),
        '₱'.number_format($exp['expenses'],2),
        '₱'.number_format($sysExp,2),
        $status
    );
    if ($revDiff >= 1)
        warn("  Revenue gap: ₱" . number_format($revDiff,2) . " (spreadsheet ₱{$exp['revenue']} vs system ₱$sysRev)");
    if ($expDiff >= 1)
        warn("  Expense gap: ₱" . number_format($expDiff,2) . " (spreadsheet ₱{$exp['expenses']} vs system ₱$sysExp)");
}

// Per-collector totals
echo "\nCollector totals (Jan–Apr):\n";
$cols = $pdo->query("SELECT u.username, p.period_month, SUM(p.amount) as total
    FROM payments p JOIN users u ON p.received_by=u.id
    WHERE p.period_year=2026
    GROUP BY u.username, p.period_month ORDER BY p.period_month, u.username")->fetchAll();
foreach ($cols as $c)
    printf("  %s | Month %d | ₱%s\n",$c['username'],$c['period_month'],number_format($c['total'],2));

echo "\n";
ok("Seeder complete.");
