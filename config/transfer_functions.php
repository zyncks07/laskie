<?php
// config/transfer_functions.php — move an existing tenant to another unit.
//
// A tenants row IS a tenancy: one unit, one start→end window, and rent is
// generated virtually from that window everywhere (CLAUDE.md §5 inv. 7, 12).
// So a transfer closes the old row and opens a linked successor row
// (tenants.transferred_from_id) — the Tenants page shows the person once.
//
// Move day X (rent is POST-PAID — functions.php "Rent Billing Cycle"): the old
// tenancy ends X−1 and the new one starts X. The billing cycle holding the move
// splits by days, each side at its own unit's rate, so the two bill exactly one
// cycle between them. The old unit's final bill is dated the move date (bill
// at move-out); the new unit's first bill falls on its next due date. Moving on
// the due day itself is just the case where the split is 0/100.
//
// Money moves between the two tenancies only through primitives every report
// already handles, each row tagged with the transfer id:
//   * prepaid credit / deposit credit — a NON-CASH pair: refunds.non_cash=1 on
//     the old side, a payments row with transfer_id on the new side, and NO
//     cash_transactions row for either (cash-on-hand is untouched; income nets
//     out because every report subtracts refunds, §5 inv. 6);
//   * unpaid rent — rent_charge_voids kind='transfer' on the old periods,
//     re-billed as one pre-billed "Arrears" charge on the new tenancy;
//   * unpaid service charges — re-pointed to the new tenancy (only ones with
//     nothing settled; a part-paid charge can't be waived, §5 inv. 15);
//   * deposit top-up — a pre-billed "Security Deposit" charge.
// Requires config/functions.php.

const TRANSFER_DEPOSIT_MONTHS = 2;   // contract rule: security deposit = 2 months' rent
// Service types that hold a RENT deposit. Utility deposits (Meralco/Maynilad)
// are per-meter and don't change with the unit's rent.
const TRANSFER_DEPOSIT_SERVICES = ['Security Deposit', 'Deposit - RENT'];
const TRANSFER_CREDIT_MAX_MONTHS = 36;

// ─── Pure helpers ────────────────────────────────────────────

/**
 * Billing dates for a move. Returns ['old_end','new_start','mode'] or
 * ['error'=>…]. $oldStart is the old tenancy's contract_start.
 */
function transferDates(string $moveDate, int $oldDueDay, ?string $oldStart): array {
    if ($oldStart && $moveDate <= $oldStart) {
        return ['error' => 'The move date must be after the tenant\'s current start date (' . fmtDate($oldStart) . ').'];
    }
    $d = new DateTimeImmutable($moveDate);
    // 'cycle' = moving on the due day (a clean cycle boundary); 'split' = the
    // cycle holding the move is shared by days. Label only — same arithmetic.
    $mode = $moveDate === rentDueDate($oldDueDay, (int)$d->format('n'), (int)$d->format('Y')) ? 'cycle' : 'split';
    return ['old_end' => $d->modify('-1 day')->format('Y-m-d'), 'new_start' => $moveDate, 'mode' => $mode];
}

// ─── Reads ───────────────────────────────────────────────────

// The person's tenancy ids, oldest → newest ending at $tenantId.
function transferChainIds(PDO $pdo, int $tenantId): array {
    $ids = [$tenantId];
    $q = $pdo->prepare("SELECT transferred_from_id FROM tenants WHERE id=?");
    for ($i = 0, $cur = $tenantId; $i < 50; $i++) {
        $q->execute([$cur]);
        $prev = (int)($q->fetchColumn() ?: 0);
        if (!$prev || in_array($prev, $ids, true)) break;
        array_unshift($ids, $prev);
        $cur = $prev;
    }
    return $ids;
}

/**
 * Signed balance of one tenancy over [contract_start, contract_end]:
 * rent net of waivers − rent paid net of refunds + unpaid service charges.
 * Same arithmetic as getTenantArrears(), which only reports positives.
 * $t may carry a simulated contract_end (the transfer's old_end).
 */
function tenancyBalance(PDO $pdo, array $t): array {
    $tid = (int)$t['id'];
    $uid = (int)$t['unit_id'];
    $u = $pdo->prepare("SELECT monthly_rate, due_day, unit_name FROM rental_units WHERE id=?");
    $u->execute([$uid]);
    $unit = $u->fetch();

    $periods = []; $rentNet = '0.00';
    if (!empty($t['contract_start']) && !empty($t['contract_end']) && $t['contract_end'] >= $t['contract_start']) {
        // Post-paid: the final bill is dated after the last day of occupancy
        // (at move-out, or the next due date), so look a cycle past the end.
        $through = date('Y-m-d', strtotime($t['contract_end'] . ' +40 days'));
        $voidMap = getRentVoidMap($pdo, $uid, $t['contract_start'], $through, $tid);
        $rows = buildRentChargeRows($pdo, $uid, [$t], (int)$unit['due_day'], (float)$unit['monthly_rate'],
                                    $t['contract_start'], $through, $voidMap);
        $paidQ = $pdo->prepare(
            "SELECT p.period_year, p.period_month, COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0) AS paid
             FROM payments p
             LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
             WHERE p.tenant_id=? AND p.payment_type='rent' AND p.deleted_at IS NULL AND p.status != 'voided'
             GROUP BY p.period_year, p.period_month"
        );
        $paidQ->execute([$tid]);
        $paidBy = [];
        foreach ($paidQ->fetchAll() as $r) $paidBy[(int)$r['period_year'] . '-' . (int)$r['period_month']] = from_cents(to_cents($r['paid']));
        foreach ($rows as $rc) {
            $rentNet = money_add($rentNet, $rc['net']);
            $key = $rc['period_year'] . '-' . $rc['period_month'];
            $periods[] = [
                'period_month' => $rc['period_month'], 'period_year' => $rc['period_year'],
                'gross' => $rc['gross'], 'voided' => $rc['voided'],
                'unpaid' => waivableRent($rc['gross'], $paidBy[$key] ?? '0.00', $rc['voided']),
            ];
        }
    }

    $pq = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount - COALESCE(r.refsum,0)),0)
         FROM payments p
         LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
         WHERE p.tenant_id=? AND p.payment_type='rent' AND p.deleted_at IS NULL AND p.status != 'voided'"
    );
    $pq->execute([$tid]);
    $rentPaid = from_cents(to_cents($pq->fetchColumn()));

    $cq = $pdo->prepare(
        "SELECT uc.id, uc.description, uc.amount, " . chargeSettledSql('uc') . " AS settled
         FROM unit_charges uc WHERE uc.tenant_id=? AND uc.voided_at IS NULL ORDER BY uc.charge_date, uc.id"
    );
    $cq->execute([$tid]);
    $charges = []; $svcOut = '0.00';
    foreach ($cq->fetchAll() as $c) {
        $settled = from_cents(to_cents($c['settled']));
        $out = money_max('0.00', money_sub($c['amount'], $settled));
        if (!money_is_pos($out)) continue;
        $charges[] = ['id' => (int)$c['id'], 'description' => $c['description'], 'amount' => from_cents(to_cents($c['amount'])),
                      'settled' => $settled, 'outstanding' => $out, 'movable' => !money_is_pos($settled)];
        $svcOut = money_add($svcOut, $out);
    }

    $rentBalance = money_sub($rentNet, $rentPaid);
    return [
        'rent_net' => $rentNet, 'rent_paid' => $rentPaid, 'rent_balance' => $rentBalance,
        'svc_outstanding' => $svcOut, 'balance' => money_add($rentBalance, $svcOut),
        'periods' => $periods, 'charges' => $charges,
    ];
}

/**
 * Rent deposits recorded for the person (whole transfer chain), net of
 * refunds. Returns ['total'=>…, 'payments'=>[[id, remaining], …] newest first].
 */
function transferDepositHeld(PDO $pdo, int $tenantId): array {
    $chain = transferChainIds($pdo, $tenantId);
    $ph  = implode(',', array_fill(0, count($chain), '?'));
    $nph = implode(',', array_fill(0, count(TRANSFER_DEPOSIT_SERVICES), '?'));
    $q = $pdo->prepare(
        "SELECT p.id, p.amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id = p.id),0) AS remaining
         FROM payments p JOIN service_types st ON st.id = p.service_type_id
         WHERE p.tenant_id IN ($ph) AND p.payment_type='service' AND st.name IN ($nph)
           AND p.deleted_at IS NULL AND p.status != 'voided'
         ORDER BY p.payment_date DESC, p.id DESC"
    );
    $q->execute([...$chain, ...TRANSFER_DEPOSIT_SERVICES]);
    $out = []; $total = '0.00';
    foreach ($q->fetchAll() as $r) {
        $rem = from_cents(to_cents($r['remaining']));
        if (!money_is_pos($rem)) continue;
        $out[] = ['id' => (int)$r['id'], 'remaining' => $rem];
        $total = money_add($total, $rem);
    }
    return ['total' => $total, 'payments' => $out];
}

function transferServiceTypeId(PDO $pdo, string $name): ?int {
    $q = $pdo->prepare("SELECT id FROM service_types WHERE name=? ORDER BY id LIMIT 1");
    $q->execute([$name]);
    return ($id = $q->fetchColumn()) ? (int)$id : null;
}

/**
 * Everything the transfer dialog shows, computed server-side. Never trusts a
 * posted amount except the admin's own "deposit held" figure.
 * $in: tenant_id, unit_id, move_date, deposit_held (''=prefill),
 *      deposit_action ('' = default), cashier_id.
 * Returns ['ok'=>false,'error'=>…] or ['ok'=>true, …plan…].
 */
function transferPlan(PDO $pdo, array $in, bool $lock = false): array {
    $err = fn(string $m) => ['ok' => false, 'error' => $m];
    $tenantId = (int)($in['tenant_id'] ?? 0);
    $unitId   = (int)($in['unit_id'] ?? 0);
    $move     = (string)($in['move_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $move) || !strtotime($move)) return $err('Choose a valid move date.');

    $tq = $pdo->prepare("SELECT * FROM tenants WHERE id=?" . ($lock ? " FOR UPDATE" : ""));
    $tq->execute([$tenantId]);
    $t = $tq->fetch();
    if (!$t)                         return $err('Tenant not found.');
    if ($t['status'] !== 'active')   return $err('Only an active tenant can be transferred.');
    if (!$t['unit_id'])              return $err('This tenant has no unit to move from. Assign a unit on the tenant record instead.');
    if ((int)$t['unit_id'] === $unitId) return $err('Choose a different unit — the tenant already occupies this one.');

    $uq = $pdo->prepare("SELECT * FROM rental_units WHERE id=?");
    $uq->execute([(int)$t['unit_id']]); $old = $uq->fetch();
    $uq->execute([$unitId]);            $new = $uq->fetch();
    if (!$new) return $err('Destination unit not found.');

    $dates = transferDates($move, (int)($old['due_day'] ?: 5), $t['contract_start'] ?: null);
    if (isset($dates['error'])) return $err($dates['error']);
    if ($t['contract_end'] && $t['contract_end'] < $dates['old_end'] && $t['contract_end'] < $move) {
        return $err('This tenancy already ended on ' . fmtDate($t['contract_end']) . '. Set the tenant back to an open or later end date first.');
    }
    // The person's lease term carries over if it reaches past the move.
    $newEnd = ($t['contract_end'] && $t['contract_end'] > $dates['new_start']) ? $t['contract_end'] : null;

    // Destination must be free from the move date on — same rule as the
    // tenancy-overlap check in admin/tenants.php (§5 inv. 12).
    $ov = $pdo->prepare(
        "SELECT full_name, contract_start, contract_end FROM tenants
         WHERE unit_id = ? AND id <> ? AND status IN ('active','former','inactive')
           AND (contract_start IS NULL OR contract_start <= ?)
           AND (contract_end   IS NULL OR contract_end   >= ?)
         ORDER BY COALESCE(contract_start,'1970-01-01') LIMIT 1"
    );
    $ov->execute([$unitId, $tenantId, $newEnd ?: '9999-12-31', $dates['new_start']]);
    if ($clash = $ov->fetch()) {
        return $err($new['unit_name'] . ' is occupied by ' . $clash['full_name'] . ' ('
            . ($clash['contract_start'] ? fmtDate($clash['contract_start']) : 'open') . ' → '
            . ($clash['contract_end'] ? fmtDate($clash['contract_end']) : 'open')
            . '). End that tenancy before ' . fmtDate($dates['new_start']) . ' first.');
    }

    $mm = (int)substr($move, 5, 2); $my = (int)substr($move, 0, 4);
    $oldRent = from_cents(to_cents(getRateForMonth($pdo, (int)$old['id'], (float)$old['monthly_rate'], $mm, $my)));
    // First bill on the new unit and final bill on the old one: the charge
    // months whose cycles hold the move (post-paid).
    [$sm, $sy] = rentCycleMonthFor((int)$new['due_day'], $dates['new_start']);
    $newRent = from_cents(to_cents(getRateForMonth($pdo, (int)$new['id'], (float)$new['monthly_rate'], $sm, $sy)));
    [$em, $ey] = rentCycleMonthFor((int)$old['due_day'], $dates['old_end']);
    $oldLastRate = getRateForMonth($pdo, (int)$old['id'], (float)$old['monthly_rate'], $em, $ey);
    $oldFinal = prorateOccupancyMonth($oldLastRate, (int)$old['due_day'], $t['contract_start'], $dates['old_end'], $em, $ey);
    $newFirst = prorateOccupancyMonth($newRent, (int)$new['due_day'], $dates['new_start'], $newEnd, $sm, $sy);
    $oldFinalDate = chargeDate((int)$old['due_day'], $em, $ey, $dates['old_end']);
    $newFirstDate = chargeDate((int)$new['due_day'], $sm, $sy, $newEnd);

    // Balance of the old tenancy as it will stand once closed.
    $closed = array_merge($t, ['contract_end' => $dates['old_end'], 'status' => 'former']);
    $bal = tenancyBalance($pdo, $closed);
    $carryCharges = array_values(array_filter($bal['charges'], fn($c) => $c['movable']));
    $stayCharges  = array_values(array_filter($bal['charges'], fn($c) => !$c['movable']));
    $carried = money_add($bal['rent_balance'], money_sum(array_column($carryCharges, 'outstanding') ?: ['0.00']));

    // Deposit.
    $dep      = transferDepositHeld($pdo, $tenantId);
    $default  = money_is_pos($dep['total']) ? $dep['total'] : money_mul($oldRent, TRANSFER_DEPOSIT_MONTHS);
    $heldIn   = trim((string)($in['deposit_held'] ?? ''));
    $held     = ($heldIn !== '' && is_numeric(str_replace(',', '', $heldIn)))
              ? from_cents(max(0, to_cents(str_replace(',', '', $heldIn)))) : $default;
    $required = money_mul($newRent, TRANSFER_DEPOSIT_MONTHS);
    $diff     = money_sub($required, $held);
    $action   = (string)($in['deposit_action'] ?? '');
    if (money_is_pos($diff)) {
        $allowed = ['topup', 'none']; $action = in_array($action, $allowed, true) ? $action : 'topup';
    } elseif (money_is_neg($diff)) {
        $allowed = ['credit', 'refund', 'keep']; $action = in_array($action, $allowed, true) ? $action : 'credit';
    } else {
        $allowed = ['none']; $action = 'none';
    }
    $depAmount = in_array($action, ['topup', 'credit', 'refund'], true) ? money_abs($diff) : '0.00';

    $cashierId = (int)($in['cashier_id'] ?? 0) ?: null;
    $cashierOnHand = null;
    if ($action === 'refund') {
        if (!$cashierId) return $err('Choose which cashier hands back the deposit refund.');
        $cu = $pdo->prepare("SELECT full_name FROM users WHERE id=? AND status='active'" . ($lock ? " FOR UPDATE" : ""));
        $cu->execute([$cashierId]);
        $cashierName = $cu->fetchColumn();
        if (!$cashierName) return $err('The selected cashier must be an active user.');
        $cashierOnHand = getUserCashPosition($pdo, $cashierId)['on_hand'];
        if (money_gt($depAmount, $cashierOnHand)) {
            return $err("$cashierName has only " . money($cashierOnHand) . ' cash on hand — short by '
                . money(money_sub($depAmount, $cashierOnHand)) . '. Top them up from the Vault, or credit the excess to rent instead.');
        }
    }

    $cq = $pdo->prepare("SELECT id, contract_no FROM contracts WHERE tenant_id=? AND status='approved' AND (end_date IS NULL OR end_date >= ?)");
    $cq->execute([$tenantId, $dates['old_end']]);
    $contracts = $cq->fetchAll();

    return [
        'ok' => true,
        'tenant' => $t, 'old_unit' => $old, 'new_unit' => $new,
        'move_date' => $move, 'old_end' => $dates['old_end'], 'new_start' => $dates['new_start'],
        'new_end' => $newEnd, 'mode' => $dates['mode'],
        'old_rent' => $oldRent, 'new_rent' => $newRent, 'old_final' => $oldFinal, 'new_first' => $newFirst,
        'old_final_date' => $oldFinalDate, 'new_first_date' => $newFirstDate,
        'balance' => $bal, 'carried' => $carried, 'carry_charges' => $carryCharges, 'stay_charges' => $stayCharges,
        'deposit_recorded' => $dep['total'], 'deposit_payments' => $dep['payments'], 'deposit_default' => $default,
        'deposit_held' => $held, 'deposit_required' => $required, 'deposit_diff' => $diff,
        'deposit_action' => $action, 'deposit_actions' => $allowed, 'deposit_amount' => $depAmount,
        'cashier_id' => $cashierId, 'cashier_on_hand' => $cashierOnHand,
        'contracts' => $contracts,
    ];
}

// ─── Writes (caller owns no transaction — executeTransfer does) ───

// A refund row created by a transfer. Non-cash refunds get no cash row; a cash
// one (deposit refund) mirrors process_refund's cash leg on $cashierId.
function transferRefund(PDO $pdo, int $xid, int $paymentId, string $amount, string $reason, bool $nonCash, array &$snap, ?int $cashierId = null): void {
    $pdo->prepare("INSERT INTO refunds (payment_id, amount, reason, refunded_by, transfer_id, non_cash) VALUES (?,?,?,?,?,?)")
        ->execute([$paymentId, $amount, $reason, $_SESSION['user']['id'], $xid, $nonCash ? 1 : 0]);
    $snap['refunds'][] = (int)$pdo->lastInsertId();
    transferSyncPaymentStatus($pdo, $paymentId);
    if (!$nonCash) {
        $inv = $pdo->prepare("SELECT invoice_no FROM payments WHERE id=?");
        $inv->execute([$paymentId]);
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$cashierId, 'refunded', $amount, $paymentId, 'Refund: ' . $inv->fetchColumn() . " — $reason", date('Y-m-d')]);
        $snap['cash'][] = (int)$pdo->lastInsertId();
    }
}

// payments.status from its refunds: paid / partially_refunded / refunded.
function transferSyncPaymentStatus(PDO $pdo, int $paymentId): void {
    $q = $pdo->prepare("SELECT p.amount, p.status, COALESCE((SELECT SUM(amount) FROM refunds WHERE payment_id=p.id),0) AS refunded FROM payments p WHERE p.id=?");
    $q->execute([$paymentId]);
    $p = $q->fetch();
    if (!$p || $p['status'] === 'voided') return;
    $st = !money_is_pos($p['refunded']) ? 'paid' : (money_gte($p['refunded'], $p['amount']) ? 'refunded' : 'partially_refunded');
    $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$st, $paymentId]);
}

/**
 * Credit $amount of rent to the new tenancy as NON-CASH rent payments, one per
 * period, filling each period's unpaid charge in turn so per-month views stay
 * honest. Anything beyond TRANSFER_CREDIT_MAX_MONTHS lands on the last period.
 */
function transferCreditRent(PDO $pdo, int $xid, array $tenant, array $unit, string $amount, string $notes, array &$snap): void {
    $remaining = $amount;
    [$fm, $fy] = rentCycleMonthFor((int)$unit['due_day'], $tenant['contract_start']);
    $iter = new DateTimeImmutable(sprintf('%04d-%02d-01', $fy, $fm));
    $last = null;
    for ($i = 0; $i < TRANSFER_CREDIT_MAX_MONTHS && money_is_pos($remaining); $i++, $iter = $iter->modify('+1 month')) {
        $m = (int)$iter->format('n'); $y = (int)$iter->format('Y');
        if ($tenant['contract_end'] && rentCycle((int)$unit['due_day'], $m, $y)[0] > $tenant['contract_end']) break;
        $rate  = getRateForMonth($pdo, (int)$unit['id'], (float)$unit['monthly_rate'], $m, $y);
        $gross = prorateOccupancyMonth($rate, (int)$unit['due_day'], $tenant['contract_start'], $tenant['contract_end'], $m, $y);
        $room  = waivableRent($gross,
                              getRentPaidForPeriod($pdo, (int)$unit['id'], $m, $y, (int)$tenant['id']),
                              getRentVoidedForPeriod($pdo, (int)$unit['id'], $m, $y, (int)$tenant['id']));
        $last = [$m, $y];
        $take = money_min($room, $remaining);
        if (!money_is_pos($take)) continue;
        transferInsertPayment($pdo, $xid, $tenant, $unit, $take, $m, $y, $notes, $snap);
        $remaining = money_sub($remaining, $take);
    }
    if (money_is_pos($remaining)) {
        [$m, $y] = $last ?? [$fm, $fy];
        transferInsertPayment($pdo, $xid, $tenant, $unit, $remaining, $m, $y, $notes, $snap);
    }
}

// A non-cash rent payment: no cashier, NO cash_transactions row (that is the
// point — the money already sits with the business).
function transferInsertPayment(PDO $pdo, int $xid, array $tenant, array $unit, string $amount, int $m, int $y, string $notes, array &$snap): void {
    $pdo->prepare(
        "INSERT INTO payments (invoice_no, unit_id, tenant_id, payment_type, amount, period_month, period_year,
                               payment_date, due_date, received_by, notes, status, transfer_id)
         VALUES (?,?,?,'rent',?,?,?,?,?,NULL,?,'paid',?)"
    )->execute([generateInvoiceNo($pdo), (int)$unit['id'], (int)$tenant['id'], $amount, $m, $y, date('Y-m-d'),
                chargeDate((int)$unit['due_day'], $m, $y, $tenant['contract_end']), $notes, $xid]);
    $snap['payments'][] = (int)$pdo->lastInsertId();
}

function transferInsertCharge(PDO $pdo, int $xid, array $tenant, int $unitId, ?int $serviceTypeId, string $amount, string $desc, array &$snap): void {
    $start = $tenant['contract_start'];
    $pdo->prepare(
        "INSERT INTO unit_charges (unit_id, tenant_id, service_type_id, amount, description, charge_date,
                                   period_month, period_year, source, transfer_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,'pre_billed',?,?)"
    )->execute([$unitId, (int)$tenant['id'], $serviceTypeId, $amount, $desc, $start,
                (int)substr($start, 5, 2), (int)substr($start, 0, 4), $xid, $_SESSION['user']['id']]);
    $snap['charges'][] = (int)$pdo->lastInsertId();
}

// Unit occupancy from the truth of the tenants table (same rule as tenants.php).
function transferSyncUnit(PDO $pdo, int $unitId): void {
    $q = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE unit_id=? AND status='active'");
    $q->execute([$unitId]);
    $pdo->prepare("UPDATE rental_units SET status=? WHERE id=?")
        ->execute([((int)$q->fetchColumn() > 0) ? 'occupied' : 'vacant', $unitId]);
}

/**
 * Run a transfer atomically. Returns ['ok'=>true,'transfer_id','to_tenant_id',
 * 'summary'=>[…]] or ['ok'=>false,'error'=>…]. A repeated idempotency key
 * returns the original transfer instead of moving the tenant twice.
 */
// Transfers are switched OFF until billing moves to the post-paid cycle
// (2026-10-04): the move-month rule here assumes rent is paid in advance and
// would drop the final cycle's charge on the old unit. Turned back on by the
// settings key transfers_enabled = '1' once the post-paid engine ships.
function transfersEnabled(PDO $pdo): bool {
    return getSetting($pdo, 'transfers_enabled', '0') === '1';
}

function executeTransfer(PDO $pdo, array $in, ?string $idemKey = null): array {
    if (!transfersEnabled($pdo)) {
        return ['ok' => false, 'error' => 'Tenant transfers are temporarily disabled while billing is being switched to post-paid.'];
    }
    $idemKey = ($idemKey !== null && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $idemKey)) ? $idemKey : null;
    if ($idemKey) {
        $dup = $pdo->prepare("SELECT id, to_tenant_id FROM tenant_transfers WHERE idempotency_key=?");
        $dup->execute([$idemKey]);
        if ($d = $dup->fetch()) return ['ok' => true, 'transfer_id' => (int)$d['id'], 'to_tenant_id' => (int)$d['to_tenant_id'], 'summary' => [], 'replayed' => true];
    }

    $pdo->beginTransaction();
    try {
        $plan = transferPlan($pdo, $in, true);
        if (!$plan['ok']) { $pdo->rollBack(); return $plan; }
        $t = $plan['tenant']; $old = $plan['old_unit']; $new = $plan['new_unit'];
        $uidActor = (int)$_SESSION['user']['id'];
        $pdo->prepare("SELECT id FROM rental_units WHERE id IN (?,?) FOR UPDATE")->execute([(int)$old['id'], (int)$new['id']]);

        $pdo->prepare(
            "INSERT INTO tenant_transfers (from_tenant_id, from_unit_id, to_unit_id, move_date, old_end_date, new_start_date, mode,
                                           old_rent, new_rent, deposit_held, deposit_required, deposit_action, deposit_amount,
                                           cashier_id, snapshot_json, idempotency_key, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'{}',?,?)"
        )->execute([(int)$t['id'], (int)$old['id'], (int)$new['id'], $plan['move_date'], $plan['old_end'], $plan['new_start'], $plan['mode'],
                    $plan['old_rent'], $plan['new_rent'], $plan['deposit_held'], $plan['deposit_required'], $plan['deposit_action'],
                    $plan['deposit_amount'], $plan['cashier_id'], $idemKey, $uidActor]);
        $xid  = (int)$pdo->lastInsertId();
        $tag  = " (transfer #$xid)";
        $from = $old['unit_name']; $to = $new['unit_name'];
        $snap = ['old_tenant' => ['contract_end' => $t['contract_end'], 'status' => $t['status']],
                 'payments' => [], 'refunds' => [], 'waivers' => [], 'charges' => [], 'cash' => [],
                 'repointed' => [], 'docs' => [], 'contracts' => []];
        $summary = [];

        // 1. Close the old tenancy.
        $pdo->prepare("UPDATE tenants SET contract_end=?, status='former', updated_at=NOW() WHERE id=?")
            ->execute([$plan['old_end'], (int)$t['id']]);

        // 2. Open the successor — the same person, no re-typing.
        $note = "Moved from $from on " . fmtDate($plan['move_date']) . $tag . '.';
        $pdo->prepare(
            "INSERT INTO tenants (unit_id, full_name, email, phone, phone2, facebook, instagram, other_social, address,
                                  monthly_rate, contract_start, contract_end, status, notes, transferred_from_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?,?)"
        )->execute([(int)$new['id'], $t['full_name'], $t['email'], $t['phone'], $t['phone2'], $t['facebook'], $t['instagram'],
                    $t['other_social'], $t['address'], $plan['new_rent'], $plan['new_start'], $plan['new_end'],
                    trim(($t['notes'] ? $t['notes'] . "\n" : '') . $note), (int)$t['id'], $uidActor]);
        $newTid = (int)$pdo->lastInsertId();
        $nt = ['id' => $newTid, 'unit_id' => (int)$new['id'], 'contract_start' => $plan['new_start'],
               'contract_end' => $plan['new_end'], 'full_name' => $t['full_name'], 'status' => 'active'];

        // Documents (IDs, old signed contract) follow the person.
        $dq = $pdo->prepare("SELECT id FROM tenant_docs WHERE tenant_id=?");
        $dq->execute([(int)$t['id']]);
        $snap['docs'] = array_map('intval', $dq->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare("UPDATE tenant_docs SET tenant_id=? WHERE tenant_id=?")->execute([$newTid, (int)$t['id']]);

        // 3. Occupancy.
        transferSyncUnit($pdo, (int)$old['id']);
        transferSyncUnit($pdo, (int)$new['id']);
        $summary[] = "$from: occupied through " . fmtDate($plan['old_end']) . '; final bill ' . money($plan['old_final']) . ' on ' . fmtDate($plan['old_final_date']) . '.';
        $summary[] = "$to: from " . fmtDate($plan['new_start']) . ' at ' . money($plan['new_rent']) . '/month; first bill ' . money($plan['new_first']) . ' due ' . fmtDate($plan['new_first_date']) . '.';

        // 4. Carry the old balance.
        $bal = $plan['balance'];
        if (money_is_pos($bal['rent_balance'])) {
            $left = $bal['rent_balance'];
            $ins = $pdo->prepare(
                "INSERT INTO rent_charge_voids (unit_id, tenant_id, period_month, period_year, amount, reason, voided_by, transfer_id, kind)
                 VALUES (?,?,?,?,?,?,?,?,'transfer')"
            );
            foreach ($bal['periods'] as $p) {
                if (!money_is_pos($left)) break;
                $take = money_min($p['unpaid'], $left);
                if (!money_is_pos($take)) continue;
                $ins->execute([(int)$old['id'], (int)$t['id'], $p['period_month'], $p['period_year'], $take, "Carried to $to$tag", $uidActor, $xid]);
                $snap['waivers'][] = (int)$pdo->lastInsertId();
                $left = money_sub($left, $take);
            }
            $carriedRent = money_sub($bal['rent_balance'], $left);
            if (money_is_pos($carriedRent)) {
                transferInsertCharge($pdo, $xid, $nt, (int)$new['id'], transferServiceTypeId($pdo, 'Arrears'), $carriedRent, "Rent balance carried from $from", $snap);
                $summary[] = 'Unpaid rent of ' . money($carriedRent) . " carried from $from as an Arrears charge on $to.";
            }
        } elseif (money_is_neg($bal['rent_balance'])) {
            $credit = money_abs($bal['rent_balance']);
            $left = $credit;
            $rq = $pdo->prepare(
                "SELECT p.id, p.amount - COALESCE((SELECT SUM(amount) FROM refunds r WHERE r.payment_id=p.id),0) AS remaining
                 FROM payments p WHERE p.tenant_id=? AND p.payment_type='rent' AND p.deleted_at IS NULL AND p.status != 'voided'
                 ORDER BY p.period_year DESC, p.period_month DESC, p.id DESC"
            );
            $rq->execute([(int)$t['id']]);
            foreach ($rq->fetchAll() as $rp) {
                if (!money_is_pos($left)) break;
                $take = money_min(from_cents(to_cents($rp['remaining'])), $left);
                if (!money_is_pos($take)) continue;
                transferRefund($pdo, $xid, (int)$rp['id'], $take, "Prepaid rent moved to $to$tag — non-cash", true, $snap);
                $left = money_sub($left, $take);
            }
            transferCreditRent($pdo, $xid, $nt, $new, money_sub($credit, $left), "Prepaid rent carried from $from$tag — non-cash", $snap);
            $summary[] = 'Prepaid rent of ' . money($credit) . " moved from $from to $to (non-cash).";
        }
        foreach ($plan['carry_charges'] as $c) {
            $orig = $pdo->prepare("SELECT unit_id, tenant_id FROM unit_charges WHERE id=?");
            $orig->execute([$c['id']]);
            $o = $orig->fetch();
            $snap['repointed'][] = ['id' => $c['id'], 'unit_id' => (int)$o['unit_id'], 'tenant_id' => $o['tenant_id'] !== null ? (int)$o['tenant_id'] : null];
            $pdo->prepare("UPDATE unit_charges SET unit_id=?, tenant_id=? WHERE id=?")->execute([(int)$new['id'], $newTid, $c['id']]);
            $summary[] = "Unpaid charge “{$c['description']}” (" . money($c['outstanding']) . ") moved to $to.";
        }
        foreach ($plan['stay_charges'] as $c) {
            $summary[] = "Part-paid charge “{$c['description']}” (" . money($c['outstanding']) . " left) stays on $from — collect it there.";
        }

        // 5. Deposit difference.
        $amt = $plan['deposit_amount'];
        if ($plan['deposit_action'] === 'topup') {
            transferInsertCharge($pdo, $xid, $nt, (int)$new['id'], transferServiceTypeId($pdo, 'Security Deposit'), $amt, "Deposit top-up (transfer from $from)", $snap);
            $summary[] = 'Deposit top-up of ' . money($amt) . " charged on $to.";
        } elseif ($plan['deposit_action'] === 'credit' || $plan['deposit_action'] === 'refund') {
            $isCash = $plan['deposit_action'] === 'refund';
            $left = $amt;
            foreach ($plan['deposit_payments'] as $dp) {
                if (!money_is_pos($left)) break;
                $take = money_min($dp['remaining'], $left);
                transferRefund($pdo, $xid, $dp['id'], $take,
                    $isCash ? "Deposit excess refunded on move to $to$tag" : "Deposit excess applied to rent on $to$tag — non-cash",
                    !$isCash, $snap, $plan['cashier_id']);
                $left = money_sub($left, $take);
            }
            if ($isCash && money_is_pos($left)) {
                // Deposit held outside the system (pre-migration): no payment to
                // refund against, so the cash leg is a manual 'refunded' row.
                $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,notes,transaction_date) VALUES (?,?,?,?,?)")
                    ->execute([$plan['cashier_id'], 'refunded', $left, "Deposit refund — {$t['full_name']} moved $from → $to$tag", date('Y-m-d')]);
                $snap['cash'][] = (int)$pdo->lastInsertId();
            }
            if ($isCash) {
                $summary[] = 'Deposit excess of ' . money($amt) . ' refunded in cash.';
            } else {
                transferCreditRent($pdo, $xid, $nt, $new, $amt, "Deposit excess applied to rent (from $from)$tag — non-cash", $snap);
                $summary[] = 'Deposit excess of ' . money($amt) . " credited to rent on $to.";
            }
        }

        // 6. The old lease document no longer describes the tenancy.
        foreach ($plan['contracts'] as $c) {
            $pdo->prepare("UPDATE contracts SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, updated_by=? WHERE id=? AND status='approved'")
                ->execute([$uidActor, "Transferred to $to on " . fmtDate($plan['move_date']) . $tag, $uidActor, (int)$c['id']]);
            $snap['contracts'][] = (int)$c['id'];
            $summary[] = "Contract {$c['contract_no']} voided — generate a new one for $to.";
        }

        $pdo->prepare("UPDATE tenant_transfers SET to_tenant_id=?, carried_balance=?, snapshot_json=? WHERE id=?")
            ->execute([$newTid, $plan['carried'], json_encode($snap), $xid]);
        logChange($pdo, 'TRANSFER_TENANT', 'Tenants',
            ['tenant_id' => (int)$t['id'], 'unit' => $from, 'contract_end' => $t['contract_end'], 'status' => $t['status']],
            ['transfer_id' => $xid, 'new_tenant_id' => $newTid, 'unit' => $to, 'move_date' => $plan['move_date'],
             'old_end' => $plan['old_end'], 'new_start' => $plan['new_start'], 'mode' => $plan['mode'],
             'carried' => $plan['carried'], 'deposit_action' => $plan['deposit_action'], 'deposit_amount' => $amt]);
        $pdo->commit();
        return ['ok' => true, 'transfer_id' => $xid, 'to_tenant_id' => $newTid, 'summary' => $summary];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Undo a transfer — only while nothing else has happened since, so the undo
 * is an exact inverse. Returns ['ok'=>bool,'error'?].
 */
function undoTransfer(PDO $pdo, int $xid, string $reason): array {
    $err = function (string $m) use ($pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'error' => $m]; };
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM tenant_transfers WHERE id=? FOR UPDATE");
        $q->execute([$xid]);
        $x = $q->fetch();
        if (!$x)                     return $err('Transfer not found.');
        if ($x['status'] !== 'done') return $err('This transfer has already been undone.');
        $s = json_decode((string)$x['snapshot_json'], true) ?: [];
        $newTid = (int)$x['to_tenant_id']; $oldTid = (int)$x['from_tenant_id'];
        if (!$newTid || !$oldTid) return $err('One of the tenancies in this transfer no longer exists.');
        $ids = fn(string $k) => array_map('intval', $s[$k] ?? []);

        if ($ids('cash')) return $err('Cash was handed back in this transfer (deposit refund), so it cannot be undone automatically. Reverse it by hand.');
        $later = $pdo->prepare("SELECT COUNT(*) FROM tenant_transfers WHERE from_tenant_id=? AND status='done'");
        $later->execute([$newTid]);
        if ((int)$later->fetchColumn()) return $err('The tenant has been transferred again since. Undo the later transfer first.');

        // Anything recorded since the transfer, on either tenancy, blocks undo.
        // IN-lists are bound as placeholders; an empty list binds a 0 (no row has id 0).
        $ph = fn(array $l) => implode(',', array_fill(0, max(1, count($l)), '?'));
        $vals = fn(array $l) => $l ?: [0];
        $txPay = $ids('payments');
        $txChg = array_merge($ids('charges'), array_map('intval', array_column($s['repointed'] ?? [], 'id')));
        $chk = [
            ["SELECT COUNT(*) FROM payments WHERE tenant_id=? AND deleted_at IS NULL AND id NOT IN (" . $ph($txPay) . ")", [$newTid, ...$vals($txPay)], 'payments recorded on the new unit'],
            ["SELECT COUNT(*) FROM unit_charges WHERE tenant_id=? AND id NOT IN (" . $ph($txChg) . ")", [$newTid, ...$vals($txChg)], 'charges added on the new unit'],
            ["SELECT COUNT(*) FROM rent_charge_voids WHERE tenant_id=? AND restored_at IS NULL", [$newTid], 'rent waived on the new unit'],
            ["SELECT COUNT(*) FROM contracts WHERE tenant_id=? AND status <> 'draft'", [$newTid], 'an approved contract for the new unit'],
            ["SELECT COUNT(*) FROM payments WHERE tenant_id=? AND deleted_at IS NULL AND created_at > ?", [$oldTid, $x['created_at']], 'payments recorded on the old unit'],
            ["SELECT COUNT(*) FROM charge_payments WHERE charge_id IN (" . $ph($ids('charges')) . ")", $vals($ids('charges')), 'payments against the transfer charges'],
        ];
        foreach ($chk as [$sql, $args, $what]) {
            $c = $pdo->prepare($sql); $c->execute($args);
            if ((int)$c->fetchColumn()) return $err("Can't undo — there are $what since the transfer. Reverse those first.");
        }

        $actor = (int)$_SESSION['user']['id'];
        $pdo->prepare("DELETE FROM contracts WHERE tenant_id=? AND status='draft'")->execute([$newTid]);
        if ($p = $ids('payments')) {
            // Soft delete: transfer payments never had a cash row to remove.
            $pdo->prepare("UPDATE payments SET deleted_at=NOW() WHERE id IN (" . $ph($p) . ")")->execute($p);
        }
        if ($r = $ids('refunds')) {
            $aq = $pdo->prepare("SELECT DISTINCT payment_id FROM refunds WHERE id IN (" . $ph($r) . ")");
            $aq->execute($r);
            $aff = $aq->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare("DELETE FROM refunds WHERE id IN (" . $ph($r) . ")")->execute($r);
            foreach ($aff as $pid) transferSyncPaymentStatus($pdo, (int)$pid);
        }
        if ($w = $ids('waivers')) {
            $pdo->prepare("UPDATE rent_charge_voids SET restored_at=NOW(), restored_by=? WHERE id IN (" . $ph($w) . ")")->execute([$actor, ...$w]);
        }
        if ($c = $ids('charges')) {
            $pdo->prepare("UPDATE unit_charges SET voided_at=NOW(), voided_by=?, void_reason=? WHERE id IN (" . $ph($c) . ")")
                ->execute([$actor, "Transfer #$xid undone", ...$c]);
        }
        foreach ($s['repointed'] ?? [] as $rp) {
            $pdo->prepare("UPDATE unit_charges SET unit_id=?, tenant_id=? WHERE id=?")->execute([(int)$rp['unit_id'], $rp['tenant_id'], (int)$rp['id']]);
        }
        $pdo->prepare("UPDATE tenant_docs SET tenant_id=? WHERE tenant_id=?")->execute([$oldTid, $newTid]);
        if ($k = $ids('contracts')) {
            $pdo->prepare("UPDATE contracts SET status='approved', cancelled_by=NULL, cancelled_at=NULL, cancel_reason=NULL
                           WHERE status='cancelled' AND id IN (" . $ph($k) . ")")->execute($k);
        }
        $pdo->prepare("DELETE FROM tenants WHERE id=?")->execute([$newTid]);
        $ot = $s['old_tenant'] ?? [];
        $pdo->prepare("UPDATE tenants SET contract_end=?, status=?, updated_at=NOW() WHERE id=?")
            ->execute([$ot['contract_end'] ?? null, $ot['status'] ?? 'active', $oldTid]);
        transferSyncUnit($pdo, (int)$x['from_unit_id']);
        transferSyncUnit($pdo, (int)$x['to_unit_id']);

        $pdo->prepare("UPDATE tenant_transfers SET status='undone', undone_by=?, undone_at=NOW(), undo_reason=? WHERE id=?")
            ->execute([$actor, mb_substr($reason, 0, 255), $xid]);
        logChange($pdo, 'UNDO_TRANSFER', 'Tenants', ['transfer_id' => $xid, 'status' => 'done'],
                  ['transfer_id' => $xid, 'status' => 'undone', 'reason' => $reason]);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Message if a payment / waiver / charge row is owned by a transfer and must
// not be edited on its own; null otherwise.
function transferLockMessage(?array $row): ?string {
    if (!$row || empty($row['transfer_id'])) return null;
    return 'This entry was created by tenant transfer #' . (int)$row['transfer_id']
         . ' (non-cash). Undo the transfer from the Tenants page instead of changing it here.';
}
