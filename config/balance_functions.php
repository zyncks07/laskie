<?php
// config/balance_functions.php — per-tenant balance statements for the
// dashboard Balance Tracker, and the demand letters it can issue.
//
// One statement builder, tenantStatement(), feeds both the card and the
// letter, so the amount a letter demands is always the amount the card shows.
// Rent follows the post-paid engine (CLAUDE.md §5 inv. 2): only bills whose
// charge date has passed are owed. Requires config/functions.php.

require_once __DIR__ . '/contract_functions.php';   // numberToWords, ordinalDay, transferChainIds

const BALANCE_FLAG_MONTHS  = 2;    // "needs intervention": unpaid rent ≥ 2 × monthly rent
const DEMAND_DEFAULT_DAYS  = 15;
const DEMAND_MIN_DAYS      = 5;    // Rule 70 §2: five days for buildings
const DEMAND_MAX_DAYS      = 60;
const DEMAND_TYPES         = ['pay' => 'Demand to Pay', 'vacate' => 'Final Demand to Pay and Vacate'];

/**
 * Where a tenant stands as of $asOf (default today).
 *
 * Rent is itemised FIFO: every peso of rent paid (net of refunds) is applied to
 * the oldest bill first, so the unpaid bills listed always sum to the rent
 * owed even when a payment was tagged to the wrong month.
 *
 * Rent arrears charges (is_arrears in statementChargesSql()) are rent, not service:
 * their unpaid part is in rent_owed / arrears_owed and itemised in bills[] (with
 * a 'description' and no cycle), never in charges[] / svc_owed.
 *
 * Returns: rate, rent_billed, rent_paid, arrears_owed, rent_owed (signed),
 * bills[] (unpaid, by due date), charges[] (unpaid service charges), svc_owed,
 * balance (signed), months_behind, flagged, last_payment|null.
 */
function tenantStatement(PDO $pdo, array $t, ?string $asOf = null, ?array $pre = null): array {
    $asOf = $asOf ?? date('Y-m-d');
    $tid  = (int)$t['id'];
    $uid  = (int)$t['unit_id'];
    $due  = (int)($t['due_day'] ?? 5) ?: 5;
    $base = (float)($t['unit_rate'] ?? $t['monthly_rate'] ?? 0);

    [$cm, $cy] = [(int)substr($asOf, 5, 2), (int)substr($asOf, 0, 4)];
    $rate = from_cents(to_cents(getRateForMonth($pdo, $uid, $base, $cm, $cy)));

    $rows = [];
    if (!empty($t['contract_start']) && $t['contract_start'] <= $asOf) {
        $voids = $pre !== null ? ($pre['voids'][$tid] ?? []) : getRentVoidMap($pdo, $uid, $t['contract_start'], $asOf, $tid);
        $rows  = buildRentChargeRows($pdo, $uid, [$t], $due, $base, $t['contract_start'], $asOf, $voids);
    }
    $billed = money_sum(array_column($rows, 'net') ?: ['0.00']);

    if ($pre !== null) {
        $paid = from_cents(to_cents($pre['paid'][$tid] ?? 0));
    } else {
        $pq = $pdo->prepare(
            "SELECT COALESCE(SUM(p.amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id = p.id),0)),0)
             FROM payments p WHERE p.tenant_id=? AND p.payment_type='rent' AND p.deleted_at IS NULL AND p.status != 'voided'"
        );
        $pq->execute([$tid]);
        $paid = from_cents(to_cents($pq->fetchColumn()));
    }

    if ($pre !== null) {
        $chargeRows = $pre['charges'][$tid] ?? [];
    } else {
        $cq = $pdo->prepare(statementChargesSql('uc.tenant_id = ?'));
        $cq->execute([$asOf, $tid]);
        $chargeRows = $cq->fetchAll();
    }
    // Rent arrears charges join the rent bills; their 'net' is what is still
    // outstanding on the charge itself (instalments settle via charge_payments).
    // Rent payments clear the rent bills first — their receipts name those
    // months — and only a rent credit left over is applied to the arrears.
    $charges = []; $svc = '0.00'; $arrears = '0.00';
    foreach ($chargeRows as $c) {
        $out = money_max('0.00', money_sub($c['amount'], from_cents(to_cents($c['settled']))));
        if (!money_is_pos($out)) continue;
        $desc = $c['description'] ?: ($c['service_name'] ?? 'Charge');
        if (!empty($c['is_arrears'])) {
            $rows[] = ['date' => $c['charge_date'], 'net' => $out, 'amount' => from_cents(to_cents($c['amount'])), 'description' => $desc, 'arrears' => true];
            $arrears = money_add($arrears, $out);
            continue;
        }
        $charges[] = ['description' => $desc, 'date' => $c['charge_date'], 'unpaid' => $out];
        $svc = money_add($svc, $out);
    }
    usort($rows, fn($a, $b) => [!empty($a['arrears']), $a['date']] <=> [!empty($b['arrears']), $b['date']]);

    $bills = []; $left = $paid;
    foreach ($rows as $r) {
        $cover  = money_min($left, $r['net']);
        $left   = money_sub($left, $cover);
        $unpaid = money_sub($r['net'], $cover);
        if (!money_is_pos($unpaid)) continue;
        if (!empty($r['arrears'])) {
            $bills[] = ['period' => $r['description'], 'description' => $r['description'], 'cycle_from' => null, 'cycle_to' => null,
                        'due_date' => $r['date'], 'amount' => $r['amount'], 'unpaid' => $unpaid];
            continue;
        }
        [$cs, $ce] = rentCycle($due, $r['period_month'], $r['period_year']);
        $bills[] = [
            'period'       => date('F Y', mktime(0, 0, 0, $r['period_month'], 1, $r['period_year'])),
            'cycle_from'   => max($cs, $t['contract_start']),
            'cycle_to'     => ($t['contract_end'] && $t['contract_end'] < $ce) ? $t['contract_end'] : $ce,
            'due_date'     => $r['date'],
            'amount'       => $r['net'],
            'unpaid'       => $unpaid,
        ];
    }
    usort($bills, fn($a, $b) => [$a['due_date'], empty($a['description'])] <=> [$b['due_date'], empty($b['description'])]);

    $rentOwed = money_add(money_sub($billed, $paid), $arrears);
    $months   = money_is_pos($rate) ? round(to_cents($rentOwed) / to_cents($rate), 2) : 0.0;

    // Last money actually handed over, across a transfer chain; non-cash
    // transfer entries are bookkeeping, not payments.
    $chain = function_exists('transferChainIds') ? transferChainIds($pdo, $tid) : [$tid];
    if ($pre !== null) {
        $last = null;
        foreach ($chain as $cid) {
            $p = $pre['last'][$cid] ?? null;
            if ($p && (!$last || [$p['payment_date'], (int)$p['id']] > [$last['payment_date'], (int)$last['id']])) $last = $p;
        }
    } else {
        $ph = implode(',', array_fill(0, count($chain), '?'));
        $lq = $pdo->prepare(
            "SELECT p.id, p.amount, p.payment_date, p.invoice_no, p.payment_type, st.name AS service_name
             FROM payments p LEFT JOIN service_types st ON st.id = p.service_type_id
             WHERE p.tenant_id IN ($ph) AND p.deleted_at IS NULL AND p.status != 'voided' AND p.transfer_id IS NULL
             ORDER BY p.payment_date DESC, p.id DESC LIMIT 1"
        );
        $lq->execute($chain);
        $last = $lq->fetch() ?: null;
    }

    return [
        'rate' => $rate, 'rent_billed' => $billed, 'rent_paid' => $paid, 'arrears_owed' => $arrears, 'rent_owed' => $rentOwed,
        'bills' => $bills, 'charges' => $charges, 'svc_owed' => $svc,
        'balance' => money_add($rentOwed, $svc),
        'months_behind' => $months,
        'flagged' => money_is_pos($rate) && money_gte($rentOwed, money_mul($rate, BALANCE_FLAG_MONTHS)),
        'last_payment' => $last,
    ];
}

// Everything tenantStatement() would otherwise query per tenant, fetched once
// for all tenancies — pass the result as its $pre argument.
function tenantStatementPrefetch(PDO $pdo, string $asOf): array {
    $pre = ['paid' => [], 'charges' => [], 'voids' => [], 'last' => []];
    foreach ($pdo->query(
        "SELECT p.tenant_id, SUM(p.amount - COALESCE(r.refsum,0)) AS paid
         FROM payments p LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
         WHERE p.payment_type='rent' AND p.deleted_at IS NULL AND p.status != 'voided' AND p.tenant_id IS NOT NULL
         GROUP BY p.tenant_id")->fetchAll() as $r) {
        $pre['paid'][(int)$r['tenant_id']] = $r['paid'];
    }
    $cq = $pdo->prepare(statementChargesSql('uc.tenant_id IS NOT NULL'));
    $cq->execute([$asOf]);
    foreach ($cq->fetchAll() as $c) $pre['charges'][(int)$c['tenant_id']][] = $c;
    foreach ($pdo->query(
        "SELECT rcv.*, u.full_name AS voided_by_name FROM rent_charge_voids rcv LEFT JOIN users u ON u.id = rcv.voided_by
         WHERE rcv.restored_at IS NULL AND rcv.tenant_id IS NOT NULL
         ORDER BY rcv.period_year, rcv.period_month, rcv.voided_at, rcv.id")->fetchAll() as $v) {
        $pre['voids'][(int)$v['tenant_id']][(int)$v['period_year'] . '-' . (int)$v['period_month']][] = $v;
    }
    foreach ($pdo->query(
        "SELECT p.id, p.tenant_id, p.amount, p.payment_date, p.invoice_no, p.payment_type, st.name AS service_name
         FROM payments p LEFT JOIN service_types st ON st.id = p.service_type_id
         JOIN (SELECT tenant_id, MAX(CONCAT(payment_date, LPAD(id, 10, '0'))) AS k FROM payments
               WHERE deleted_at IS NULL AND status != 'voided' AND transfer_id IS NULL AND tenant_id IS NOT NULL GROUP BY tenant_id) x
           ON x.tenant_id = p.tenant_id AND CONCAT(p.payment_date, LPAD(p.id, 10, '0')) = x.k")->fetchAll() as $p) {
        $pre['last'][(int)$p['tenant_id']] = $p;
    }

    return $pre;
}

// Every ACTIVE tenancy with a unit, with its statement and latest demand letter.
// Former tenants are deliberately excluded — this is a live intervention list.
function activeTenantBalances(PDO $pdo, ?string $asOf = null): array {
    $rows = $pdo->query(
        "SELECT t.*, ru.unit_name, ru.monthly_rate AS unit_rate, ru.due_day
         FROM tenants t JOIN rental_units ru ON ru.id = t.unit_id
         WHERE t.status = 'active'
         ORDER BY ru.unit_name"
    )->fetchAll();
    $dl = [];
    try {
        foreach ($pdo->query("SELECT d.tenant_id, d.ref_no, d.letter_type, d.created_at, d.id FROM demand_letters d
                              JOIN (SELECT tenant_id, MAX(id) AS mid FROM demand_letters GROUP BY tenant_id) x ON x.mid = d.id")->fetchAll() as $d) {
            $dl[(int)$d['tenant_id']] = $d;
        }
    } catch (Throwable $_) { /* table absent before migration 016 */ }
    $asOf = $asOf ?? date('Y-m-d');

    $pre = tenantStatementPrefetch($pdo, $asOf);   // batched — this runs on every dashboard load

    $out = [];
    foreach ($rows as $t) {
        $out[] = ['tenant' => $t, 'st' => tenantStatement($pdo, $t, $asOf, $pre), 'last_demand' => $dl[(int)$t['id']] ?? null];
    }
    return $out;
}

// Unpaid-service-charge rows due by a date (first placeholder = as-of date).
// is_arrears marks pre-billed charges that are really unpaid RENT from outside
// the virtual rent engine — the 2025 opening balances imported at migration (no
// service type, "Balance brought forward …") and the Arrears charge a transfer
// carries to the new tenancy. tenantStatement() counts them as rent owed.
function statementChargesSql(string $where): string {
    return "SELECT uc.tenant_id, uc.description, uc.charge_date, uc.amount, " . chargeSettledSql('uc') . " AS settled, st.name AS service_name,
                   (st.name = 'Arrears' OR (uc.service_type_id IS NULL AND uc.description LIKE 'Balance brought forward%')) AS is_arrears
            FROM unit_charges uc LEFT JOIN service_types st ON st.id = uc.service_type_id
            WHERE uc.voided_at IS NULL AND uc.charge_date <= ? AND $where
            ORDER BY uc.charge_date, uc.id";
}

// Next demand-letter number, DL-YYYY-####: monotonic settings counter, never
// reissued (same scheme as invoice and contract numbers, §5 inv. 8).
function generateDemandLetterNo(PDO $pdo): string {
    $year = date('Y');
    $key  = 'demand_seq_DL_' . $year;
    $bump = $pdo->prepare("UPDATE settings SET setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1) WHERE setting_key = ?");
    $bump->execute([$key]);
    if ($bump->rowCount() === 0) {
        $max = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(ref_no,'-',-1) AS UNSIGNED)),0) FROM demand_letters WHERE ref_no LIKE ?");
        $max->execute(['DL-' . $year . '-%']);
        $seed = (int)$max->fetchColumn() + 1;
        $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1)");
        $ins->execute([$key, (string)$seed]);
        $next = ($ins->rowCount() === 1) ? $seed : (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    } else {
        $next = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    }
    return 'DL-' . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Everything a demand letter prints, resolved once and frozen into the
 * letter's snapshot. Property / lessor details come from the tenant's latest
 * contract, then the contract defaults, then the company settings.
 */
function demandLetterContext(PDO $pdo, array $t, array $st, string $type, int $days): array {
    $fields = [];
    if (function_exists('transferChainIds')) {
        $chain = transferChainIds($pdo, (int)$t['id']);
        $q = $pdo->prepare("SELECT fields_json FROM contracts WHERE tenant_id IN (" . implode(',', array_fill(0, count($chain), '?')) . ")
                            AND status IN ('approved','cancelled') ORDER BY (status='approved') DESC, id DESC LIMIT 1");
        $q->execute($chain);
        $fields = json_decode((string)($q->fetchColumn() ?: ''), true) ?: [];
    }
    $pick = function (string $k, string $fallback = '') use ($pdo, $fields) {
        $v = trim((string)($fields[$k] ?? ''));
        if ($v === '') $v = trim(getSetting($pdo, 'contract_default_' . $k, ''));
        return $v !== '' ? $v : $fallback;
    };
    $issued = date('Y-m-d');
    return [
        'type' => $type, 'title' => DEMAND_TYPES[$type], 'days' => $days,
        'issued' => $issued,
        'deadline_note' => numberToWords($days) . " ($days) days",
        'lessor_name'    => $pick('lessor_name', getSetting($pdo, 'company_name', '')),
        'lessor_address' => $pick('lessor_address', getSetting($pdo, 'company_address', '')),
        'lessor_email'   => $pick('lessor_email', getSetting($pdo, 'company_email', '')),
        'lessor_mobile'  => $pick('lessor_mobile', getSetting($pdo, 'company_phone', '')),
        'payment_methods'=> $pick('payment_methods', ''),
        'building_address' => $pick('building_address', ''),
        'barangay_no'    => $pick('barangay_no', ''),
        'tenant_name'    => $t['full_name'],
        'tenant_email'   => (string)($t['email'] ?? ''),
        'unit_name'      => $t['unit_name'],
        'contract_start' => $t['contract_start'],
        'due_day'        => ordinalDay((int)($t['due_day'] ?? 5) ?: 5),
        'rate'           => $st['rate'],
        'months_behind'  => $st['months_behind'],
        'bills'          => $st['bills'],
        'charges'        => $st['charges'],
        'rent_owed'      => money_max('0.00', $st['rent_owed']),
        'svc_owed'       => $st['svc_owed'],
        'total'          => money_max('0.00', $st['balance']),
        'rent_controlled'=> money_lte($st['rate'], defined('CONTRACT_RENT_CONTROL_CAP') ? CONTRACT_RENT_CONTROL_CAP : '10000.00'),
    ];
}

function renderDemandLetterDocument(array $ctx, string $refNo, string $mode = 'pdf'): string {
    $assetBase = $mode === 'pdf' ? pdfAssetsBaseUrl() : '../assets/vendor/';
    ob_start();
    include __DIR__ . '/../includes/demand/letter.php';
    return (string)ob_get_clean();
}
