<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
$pageTitle = 'Statement of Account';
$depth = '../';

// ── Selectors ─────────────────────────────────────────────────
$units    = $pdo->query("
    SELECT ru.id, ru.unit_name, ru.monthly_rate, ru.status, ru.due_day,
           t.full_name AS tenant_name
    FROM rental_units ru
    LEFT JOIN tenants t ON t.unit_id = ru.id AND t.status = 'active'
    ORDER BY ru.unit_name
")->fetchAll();
$selUnit  = (int)($_GET['unit_id']   ?? ($units[0]['id'] ?? 0));
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
// Scope selector: a tenant id narrows the statement to that one occupancy;
// 'all' is the landlord's unit ledger (every occupant, segmented).
$tenantParam = trim((string)($_GET['tenant_id'] ?? ''));
// Active users for the refund "Returned by (cashier)" selector.
$activeUsers = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll();

// ── Fetch Unit Info ───────────────────────────────────────────
$unitInfo = null;
if ($selUnit) {
    $s = $pdo->prepare("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id WHERE ru.id=?");
    $s->execute([$selUnit]);
    $unitInfo = $s->fetch();
}

// ── Occupancy scope ──────────────────────────────────────────
// A Statement of Account is addressed to a TENANT, about their occupancy of a
// unit — so the ledger is scoped to one tenancy by default. Without this, a
// tenant who moves out owing money leaves their arrears sitting at the head of
// the next tenant's statement (see CLAUDE.md §5 invariant 12).
//
// $scopeAll switches to the unit ledger: every occupant, segmented by tenancy
// with its own closing balance. That is the landlord's receivable view.
$unitTenants = [];   // every tenancy of this unit — the selector's options
$occupants   = [];   // the tenancies this statement actually renders
$tenant      = null; // the tenancy being rendered (null in unit-ledger mode)
$scopeAll    = ($tenantParam === 'all');
if ($selUnit) {
    $t = $pdo->prepare("
        SELECT * FROM tenants
        WHERE unit_id = ? AND status IN ('active','former','inactive')
        ORDER BY COALESCE(contract_start,'1970-01-01') ASC
    ");
    $t->execute([$selUnit]);
    // Resolve every blank contract_end up front so handing the generator a
    // single tenancy below yields exactly the charges the full list would.
    $unitTenants = resolveOccupancyEnds($pdo, $t->fetchAll());

    if (!$scopeAll) {
        if ($tenantParam !== '') {
            foreach ($unitTenants as $occ) {
                if ((int)$occ['id'] === (int)$tenantParam) { $tenant = $occ; break; }
            }
        }
        // No explicit pick: the active tenant, else the most recent occupant so
        // a vacated unit still opens on the tenant whose arrears you're chasing.
        if (!$tenant) {
            foreach ($unitTenants as $occ) {
                if ($occ['status'] === 'active') { $tenant = $occ; break; }
            }
        }
        if (!$tenant && $unitTenants) $tenant = end($unitTenants);
    }

    if ($tenant) {
        // Deliberately NOT clamping the range to the contract window here.
        // buildRentChargeRows() already bounds the virtual rent charges by the
        // tenancy, and recorded rows are selected by tenant_id — so the scope is
        // already right. Clamping as well would drop rows that genuinely belong
        // to this tenant but sit a few days outside their contract dates, which
        // is exactly where an opening "balance brought forward" charge lands.
        // The date inputs still default to the occupancy window (see
        // onTenantScopeChange) so the usual view reads as the tenancy.
        $occupants = [$tenant];
    } else {
        $scopeAll  = true;
        $occupants = array_values(array_filter($unitTenants, fn($o) =>
            (empty($o['contract_start']) || $o['contract_start'] <= $dateTo) &&
            (empty($o['contract_end'])   || $o['contract_end']   >= $dateFrom)));
    }
}
// Tenant filter applied to every ledger source below. Unattributed rows
// (tenant_id IS NULL) are matched by date against the tenancy window, so a row
// recorded before payments carried a tenant can never silently vanish.
$scopeTenantId = $tenant ? (int)$tenant['id'] : null;

// ── Payment Records ───────────────────────────────────────────
$payments  = [];
$totalPaid = 0;
if ($selUnit) {
    $paySql  = "SELECT p.*, st.name AS service_name, u.full_name AS cashier_name
                FROM   payments p
                LEFT JOIN service_types st ON p.service_type_id = st.id
                LEFT JOIN users u          ON p.received_by     = u.id
                WHERE  p.unit_id = ? AND p.payment_date BETWEEN ? AND ?
                  AND  p.deleted_at IS NULL AND p.status != 'voided'";
    $payArgs = [$selUnit, $dateFrom, $dateTo];
    if ($scopeTenantId !== null) {
        $paySql   .= " AND (p.tenant_id = ? OR p.tenant_id IS NULL)";
        $payArgs[] = $scopeTenantId;
    }
    $paySql .= " ORDER BY p.payment_date ASC, p.created_at ASC";
    $q = $pdo->prepare($paySql);
    $q->execute($payArgs);
    $payments  = $q->fetchAll();
    $totalPaid = money_sum(array_column($payments, 'amount'));
}

// ── Fetch Refunds for payments in this range ──────────────────
$refundRows   = [];
$refundedMap  = []; // payment_id => total_refunded
$payStatusMap = []; // payment_id => status
// payment_id => tenant_id, so a refund lands in the same occupancy segment as
// the payment it reverses. Refunds reach a tenant only through their payment.
$payTenantMap = [];
foreach ($payments as $p) {
    $payTenantMap[$p['id']] = isset($p['tenant_id']) ? (int)$p['tenant_id'] : null;
}
if ($payments) {
    $payIds = array_column($payments, 'id');
    $in     = implode(',', array_fill(0, count($payIds), '?'));
    $rq = $pdo->prepare("
        SELECT r.*, u.full_name as refunded_by_name, p.invoice_no as payment_invoice
        FROM refunds r
        LEFT JOIN users u ON r.refunded_by = u.id
        LEFT JOIN payments p ON r.payment_id = p.id
        WHERE r.payment_id IN ($in)
        ORDER BY r.refunded_at ASC
    ");
    $rq->execute($payIds);
    $refundRows = $rq->fetchAll();
    foreach ($refundRows as $r) {
        $refundedMap[$r['payment_id']] = money_add($refundedMap[$r['payment_id']] ?? '0.00', $r['amount']);
    }
    $sq = $pdo->prepare("SELECT id, status FROM payments WHERE id IN ($in)");
    $sq->execute($payIds);
    foreach ($sq->fetchAll() as $s) {
        $payStatusMap[$s['id']] = $s['status'];
    }
}
$totalRefunded = money_sum(array_column($refundRows, 'amount'));

// ── Fetch Service Charges (unit_charges) ──────────────────────
// is_outstanding = 1 when the charge still has an unsettled remainder. Only
// LIVE payments settle it, so a voided or soft-deleted payment puts the charge
// back to outstanding — the same behaviour the old filtered LEFT JOIN on
// payment_id gave, now generalised to partial settlement.
$serviceCharges = [];
if ($selUnit) {
    // settled / outstanding come from charge_payments, so a charge paid down in
    // instalments reports its true remainder instead of flipping to "paid" on
    // the first payment. The charge still posts as a debit at its FULL amount
    // and each payment as its own credit, so the running balance is unchanged —
    // settled only drives the label.
    $chgSql = "SELECT uc.*, st.name as service_name, u.full_name as billed_by_name,
                      vu.full_name as voided_by_name,
                      " . chargeSettledSql('uc') . " AS settled,
                      GREATEST(uc.amount - " . chargeSettledSql('uc') . ", 0) AS outstanding,
                      (uc.amount - " . chargeSettledSql('uc') . " > 0) AS is_outstanding
               FROM unit_charges uc
               LEFT JOIN service_types st ON uc.service_type_id = st.id
               LEFT JOIN users u  ON uc.created_by = u.id
               LEFT JOIN users vu ON uc.voided_by  = vu.id
               WHERE uc.unit_id = ? AND uc.charge_date BETWEEN ? AND ?";
    $chgArgs = [$selUnit, $dateFrom, $dateTo];
    if ($scopeTenantId !== null) {
        $chgSql   .= " AND (uc.tenant_id = ? OR uc.tenant_id IS NULL)";
        $chgArgs[] = $scopeTenantId;
    }
    $chgSql .= " ORDER BY uc.charge_date ASC, uc.created_at ASC";
    $sq = $pdo->prepare($chgSql);
    $sq->execute($chgArgs);
    $serviceCharges = $sq->fetchAll();
}

// ── Waived (voided) rent charges for this unit + range ────────
// Rent charges are virtual, so an admin write-off lives in rent_charge_voids
// and is rendered as an offsetting credit line, never by hiding the charge.
$rentVoidMap  = $selUnit ? getRentVoidMap($pdo, $selUnit, $dateFrom, $dateTo, $scopeTenantId) : [];
$rentPaidMap  = $selUnit ? getRentPaidByPeriod($pdo, $selUnit, $scopeTenantId) : [];

// ── Build Ledger (charges + payments merged, sorted by date) ──
$ledger   = [];
$waivable = []; // rows the bulk "Void Charges" modal may offer (admin only)
if ($selUnit && $unitInfo) {
    $baseRate = (float)$unitInfo['monthly_rate'];
    $dueDay   = (int)$unitInfo['due_day'];

    // Shared generator — payments/soa_pdf.php renders from the same helper.
    $rentRows = buildRentChargeRows($pdo, $selUnit, $occupants, $dueDay, $baseRate, $dateFrom, $dateTo, $rentVoidMap);

    // Rent charges are per-tenant, so the waiver cap must be too: a unit-wide
    // paid figure makes the outgoing tenant's arrears look settled by the
    // incoming tenant's payment when they share a period (a mid-month handover).
    $rentPaidMaps = [];
    foreach ($occupants as $o) {
        $rentPaidMaps[(int)$o['id']] = getRentPaidByPeriod($pdo, $selUnit, (int)$o['id']);
    }

    foreach ($rentRows as $rc) {
        $periodKey = $rc['period_year'] . '-' . $rc['period_month'];
        $paidMap   = $rentPaidMaps[(int)$rc['tenant_id']] ?? $rentPaidMap;
        $paid      = $paidMap[$periodKey] ?? '0.00';
        $canWaive  = waivableRent($rc['gross'], $paid, $rc['voided']);
        $ledger[]  = [
            'date'         => $rc['date'],
            'description'  => $rc['description'],
            'type'         => 'charge',
            'debit'        => $rc['gross'],
            'credit'       => '0.00',
            'encoded'      => null, // computed rent charge — no recorded row
            'period_month' => $rc['period_month'],
            'period_year'  => $rc['period_year'],
            'tenant_id'    => $rc['tenant_id'],
            'owner'        => $rc['tenant_id'],
            'waivable'     => $canWaive,
        ];
        if (money_is_pos($canWaive)) {
            $waivable[] = [
                'type'   => 'rent',
                'month'  => $rc['period_month'],
                'year'   => $rc['period_year'],
                'tenant' => $rc['tenant_id'],
                'label'  => $rc['description'],
                'amount' => $canWaive,
            ];
        }
        // One credit row per waiver, stamped on the charge date so it nets
        // against its charge in the running balance.
        foreach ($rc['waivers'] as $w) {
            // 'applied', not 'amount' — a stored waiver can exceed the charge it
            // offsets (rate history moved, or move-out proration shrank the
            // final month) and crediting the raw figure would open a phantom
            // credit in the running balance.
            if (!money_is_pos($w['applied'])) continue;
            $ledger[] = [
                'date'        => $rc['date'],
                'description' => 'Rent Waived — ' . date('F Y', mktime(0,0,0,$rc['period_month'],1,$rc['period_year']))
                                 . ' (' . $w['reason'] . ')',
                'type'        => 'rent_waiver',
                'debit'       => '0.00',
                'credit'      => $w['applied'],
                'invoice_no'  => '',
                'cashier'     => $w['voided_by_name'] ?? '',
                'id'          => (int)$w['id'],
                'owner'       => $rc['tenant_id'],
                'encoded'     => $w['voided_at'] ?? null,
            ];
        }
    }

    // Payment rows
    foreach ($payments as $p) {
        $desc = $p['payment_type'] === 'rent'
            ? 'Payment — ' . date('F Y', mktime(0,0,0,(int)$p['period_month'],1,(int)$p['period_year']))
            : ($p['service_name'] ?? 'Service') . ' — ' . date('F Y', mktime(0,0,0,(int)$p['period_month'],1,(int)$p['period_year']));
        $ledger[] = [
            'date'             => $p['payment_date'],
            'description'      => $desc,
            'type'             => 'payment',
            'debit'            => '0.00',
            'credit'           => $p['amount'],
            'invoice_no'       => $p['invoice_no']  ?? '',
            'cashier'          => $p['cashier_name'] ?? '',
            'pay_type'         => $p['payment_type'],
            'id'               => $p['id'],
            'owner'            => isset($p['tenant_id']) ? (int)$p['tenant_id'] : null,
            'received_by'      => $p['received_by'] ?? 0,
            'pay_status'       => $payStatusMap[$p['id']] ?? 'paid',
            'already_refunded' => $refundedMap[$p['id']] ?? '0.00',
            'receipt'          => $p['receipt_path'] ?: ($p['receipt_url'] ?? ''),
            'encoded'          => $p['created_at'] ?? null,
        ];
    }

    // Refund rows
    foreach ($refundRows as $r) {
        $ledger[] = [
            'date'        => date('Y-m-d', strtotime($r['refunded_at'])),
            'description' => 'Refund — ' . ($r['payment_invoice'] ?? '') . ': ' . ($r['reason'] ?? ''),
            'type'        => 'refund',
            'debit'       => $r['amount'],
            'credit'      => '0.00',
            'invoice_no'  => '',
            'cashier'     => $r['refunded_by_name'] ?? '',
            'id'          => null,
            'owner'       => $payTenantMap[$r['payment_id']] ?? null,
            'encoded'     => $r['refunded_at'] ?? null,
        ];
    }

    // Service charge rows from unit_charges
    foreach ($serviceCharges as $c) {
        $period   = date('F Y', mktime(0,0,0,(int)$c['period_month'],1,(int)$c['period_year']));
        $desc     = ($c['service_name'] ?? $c['description']) . ' — ' . $period;
        // is_outstanding accounts for both NULL payment_id AND voided/deleted
        // linked payments, so the badge stays accurate after a void/restore.
        // A waived charge is settled, not outstanding.
        $isVoided = !empty($c['voided_at']);
        $unpaid   = !empty($c['is_outstanding']) && !$isVoided;
        $settled  = from_cents(to_cents($c['settled'] ?? 0));
        $partPaid = $unpaid && money_is_pos($settled);
        if ($partPaid)             $desc .= ' (' . money($settled) . ' of ' . money($c['amount']) . ' paid)';
        elseif ($unpaid)           $desc .= ' (Unpaid)';
        if ($isVoided)             $desc .= ' (Voided)';
        $ledger[] = [
            'date'        => $c['charge_date'],
            'description' => $desc,
            'type'        => 'service_charge',
            'debit'       => $c['amount'],
            'credit'      => '0.00',
            'invoice_no'  => '',
            'cashier'     => $c['billed_by_name'] ?? '',
            'id'          => (int)$c['id'],
            'is_unpaid'   => $unpaid,
            'is_voided'   => $isVoided,
            'part_paid'   => $partPaid,
            'source'      => $c['source'],
            'owner'       => isset($c['tenant_id']) ? (int)$c['tenant_id'] : null,
            'encoded'     => $c['created_at'] ?? null,
        ];
        if ($isVoided) {
            $ledger[] = [
                'date'        => $c['charge_date'],
                'description' => 'Charge Voided — ' . ($c['service_name'] ?? $c['description'])
                                 . ' (' . ($c['void_reason'] ?? '') . ')',
                'type'        => 'service_waiver',
                'debit'       => '0.00',
                'credit'      => $c['amount'],
                'invoice_no'  => '',
                'cashier'     => $c['voided_by_name'] ?? '',
                'id'          => (int)$c['id'],
                'owner'       => isset($c['tenant_id']) ? (int)$c['tenant_id'] : null,
                'encoded'     => $c['voided_at'] ?? null,
            ];
        } elseif ($unpaid && !$partPaid) {
            // A charge that has already taken money cannot be waived — doing so
            // would cancel a receivable the tenant has paid against. Leave it
            // out of the bulk offer rather than have the handler refuse it.
            $waivable[] = [
                'type'   => 'service',
                'id'     => (int)$c['id'],
                'label'  => $desc,
                'amount' => from_cents(to_cents($c['outstanding'] ?? $c['amount'])),
            ];
        }
    }
}

// Occupancy ordering — in unit-ledger mode the ledger is grouped by tenancy so
// each occupant gets their own running balance and closing figure, instead of
// one number that silently rolls a departed tenant's debt onto the next tenant.
$occSeq   = [];  // tenant_id => position
$occNames = [];  // tenant_id => display name
foreach ($occupants as $i => $o) {
    $occSeq[(int)$o['id']]   = $i;
    $occNames[(int)$o['id']] = $o['full_name'];
}
$occRank = function ($row) use ($occSeq) {
    $owner = $row['owner'] ?? null;
    // Unattributed rows sort after every known occupancy.
    return $owner !== null && isset($occSeq[$owner]) ? $occSeq[$owner] : PHP_INT_MAX;
};

// Sort by occupancy, then date asc; within same date:
// rent charges → service charges → payments → refunds
usort($ledger, function($a,$b) use ($scopeAll, $occRank) {
    if ($scopeAll) {
        $cmp = $occRank($a) <=> $occRank($b);
        if ($cmp !== 0) return $cmp;
    }
    $cmp = strcmp($a['date'],$b['date']);
    if ($cmp !== 0) return $cmp;
    $order = ['charge'=>0,'rent_waiver'=>1,'service_charge'=>2,'service_waiver'=>3,'payment'=>4,'refund'=>5];
    return ($order[$a['type']]??4) - ($order[$b['type']]??4);
});

// Running balance — cents math, no float drift. In unit-ledger mode it resets
// at each handover, so no tenant's statement line inherits the previous one.
$runBal      = '0.00';
$segBal      = [];   // owner key => closing balance for that occupancy
$prevOwner   = false;
foreach ($ledger as &$row) {
    $owner = $row['owner'] ?? null;
    if ($scopeAll && $prevOwner !== false && $owner !== $prevOwner) {
        $row['segment_start'] = true;
        $runBal = '0.00';
    }
    $prevOwner = $owner;
    $runBal = money_add($runBal, money_sub($row['debit'], $row['credit']));
    $row['balance'] = $runBal;
    $segBal[$owner === null ? '' : $owner] = $runBal;
}
unset($row);

$totalChargesDebit = money_sum(array_map(fn($r) => in_array($r['type'],['charge','service_charge']) ? $r['debit'] : '0.00', $ledger));
$totalWaived       = money_sum(array_map(fn($r) => in_array($r['type'],['rent_waiver','service_waiver']) ? $r['credit'] : '0.00', $ledger));
$totalDebit        = money_sum(array_column($ledger, 'debit'));
$totalCredit       = money_sum(array_column($ledger, 'credit'));
$finalBal          = money_sub($totalDebit, $totalCredit);

// Link/scope helpers shared by the PDF buttons, the filter form and the header.
$scopeParam = $scopeAll ? 'all' : (string)$scopeTenantId;
$scopeLabel = $scopeAll ? 'All occupants' : ($tenant['full_name'] ?? '—');

logActivity($pdo, 'VIEW_SOA', 'SOA',
    "Viewed SOA unit #$selUnit ($dateFrom – $dateTo) — scope: $scopeLabel");
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-file-invoice me-2 text-primary-custom"></i>Statement of Account</h1>
  <div class="d-flex gap-2">
    <?php if ($selUnit && $unitInfo): ?>
    <a href="soa_pdf.php?unit_id=<?=$selUnit?>&tenant_id=<?=urlencode($scopeParam)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
       target="_blank" class="btn btn-sm btn-outline-primary no-print">
      <i class="fa-solid fa-file-pdf me-1"></i>Preview SOA
    </a>
    <a href="soa_pdf_download.php?unit_id=<?=$selUnit?>&tenant_id=<?=urlencode($scopeParam)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
       class="btn btn-sm btn-primary no-print">
      <i class="fa-solid fa-download me-1"></i>Download PDF
    </a>
    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
      <i class="fa-solid fa-print me-1"></i>Print
    </button>
    <?php if (isAdmin() && !empty($waivable)): ?>
    <button class="btn btn-sm btn-outline-danger no-print" onclick="openBulkVoidModal()">
      <i class="fa-solid fa-file-circle-xmark me-1"></i>Void Charges
    </button>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Filter Bar -->
<div class="card filter-card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Rental Unit</label>
        <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach($units as $u): ?>
          <option value="<?=$u['id']?>" <?=$u['id']==$selUnit?'selected':''?>>
            <?=clean($u['unit_name'])?> (<?=ucfirst($u['status'])?>)<?= $u['tenant_name'] ? ' — ' . clean($u['tenant_name']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Tenant</label>
        <select name="tenant_id" id="soaTenant" class="form-select form-select-sm" onchange="onTenantScopeChange(this)">
          <?php foreach($unitTenants as $ut): ?>
          <option value="<?=(int)$ut['id']?>"
                  data-from="<?=clean($ut['contract_start'] ?? '')?>"
                  data-to="<?=clean($ut['contract_end'] ?? '')?>"
                  <?= (!$scopeAll && $scopeTenantId === (int)$ut['id']) ? 'selected' : '' ?>>
            <?=clean($ut['full_name'])?><?= $ut['status'] !== 'active' ? ' (' . ucfirst($ut['status']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
          <option value="all" <?=$scopeAll?'selected':''?>>— All occupants (unit ledger) —</option>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Date From</label>
        <input type="date" name="date_from" id="soaFrom" class="form-control form-control-sm" value="<?=clean($dateFrom)?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Date To</label>
        <input type="date" name="date_to" id="soaTo" class="form-control form-control-sm" value="<?=clean($dateTo)?>">
      </div>
      <div class="col-12 col-md-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search me-1"></i>View</button>
        <a href="history.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if (!$selUnit || !$unitInfo): ?>
<div class="empty-state">
  <i class="fa-solid fa-file-invoice" style="font-size:2.5rem;color:var(--text-muted)"></i>
  <p>Select a rental unit above to view its Statement of Account.</p>
</div>

<?php else: ?>

<!-- Unit + Tenant Info -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-building me-2"></i>Unit Details</span></div>
      <div class="card-body py-2">
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <?php $rows=[['Unit Name',$unitInfo['unit_name']],['Type',$unitInfo['type_name']??'—'],['Monthly Rate',money((float)$unitInfo['monthly_rate'])],['Due Day',$unitInfo['due_day'].'th of each month'],['Status',ucfirst($unitInfo['status'])]]; foreach($rows as [$l,$v]): ?>
          <tr><td style="padding:5px 0;color:var(--text-muted);width:130px"><?=$l?></td><td style="padding:5px 0;font-weight:600"><?=clean($v)?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><span class="card-header-title">
        <i class="fa-solid fa-<?=$scopeAll?'users':'user'?> me-2"></i><?=$scopeAll?'Unit Ledger':'Statement For'?>
      </span></div>
      <div class="card-body py-2">
        <?php if($tenant): ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <?php $trows=[['Name',$tenant['full_name']],['Phone',$tenant['phone']??'—'],['Email',$tenant['email']??'—'],['Occupancy',($tenant['contract_start']?fmtDate($tenant['contract_start'],'M j, Y').' – '.($tenant['contract_end']?fmtDate($tenant['contract_end'],'M j, Y'):'Open'):'—')]]; foreach($trows as [$l,$v]): ?>
          <tr><td style="padding:5px 0;color:var(--text-muted);width:130px"><?=$l?></td><td style="padding:5px 0;font-weight:600"><?=clean($v)?></td></tr>
          <?php endforeach; ?>
          <tr><td style="padding:5px 0;color:var(--text-muted)">Status</td>
              <td style="padding:5px 0"><span class="badge badge-<?=clean($tenant['status'])?>"><?=ucfirst(clean($tenant['status']))?></span></td></tr>
        </table>
        <div class="stat-sub mt-2">
          <i class="fa-solid fa-circle-info me-1"></i>Covers this occupancy only — charges and
          payments from other tenants of this unit are not included.
        </div>
        <?php elseif($scopeAll && $occupants): ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <?php foreach($occupants as $o): ?>
          <tr>
            <td style="padding:5px 0;font-weight:600"><?=clean($o['full_name'])?></td>
            <td style="padding:5px 0;color:var(--text-muted);text-align:right">
              <?=$o['contract_start']?fmtDate($o['contract_start'],'M j, Y'):'—'?> –
              <?=$o['contract_end']?fmtDate($o['contract_end'],'M j, Y'):'Open'?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <div class="stat-sub mt-2">
          <i class="fa-solid fa-circle-info me-1"></i>Every occupancy of this unit, each with its
          own running balance. Not a tenant statement.
        </div>
        <?php else: ?>
        <div class="text-center py-3 text-muted"><i class="fa-solid fa-user-slash me-1"></i> No tenant on record for this unit</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Charged</div>
        <div class="stat-value" style="font-size:17px"><?=money($totalChargesDebit)?></div>
        <div class="stat-sub"><?=count($ledger)?> entries</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa-solid fa-money-bill-wave"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Paid</div>
        <!-- payments only: waivers are credits in the ledger but nobody paid them -->
        <div class="stat-value" style="font-size:17px"><?=money($totalPaid)?></div>
        <div class="stat-sub"><?=count($payments)?> payment<?=count($payments)!=1?'s':''?></div>
      </div>
    </div>
  </div>
  <?php if (money_is_pos($totalWaived)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-file-circle-xmark"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Waived</div>
        <div class="stat-value num" style="font-size:17px"><?=money($totalWaived)?></div>
        <div class="stat-sub">written off by admin</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (money_is_pos($totalRefunded)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-rotate-left"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Refunded</div>
        <div class="stat-value num" style="font-size:17px"><?=money($totalRefunded)?></div>
        <div class="stat-sub"><?=count($refundRows)?> refund<?=count($refundRows)!=1?'s':''?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
      <div class="stat-body">
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value num" style="font-size:17px">
          <?php if(money_is_pos($finalBal)): ?><span class="delta-neg"><?=money(money_abs($finalBal))?></span>
          <?php else: ?><?=money(money_abs($finalBal))?><?php endif; ?>
        </div>
        <div class="stat-sub"><?=money_is_pos($finalBal)?'Due':(money_lt($finalBal,'0.00')?'Overpaid (CR)':'Fully settled')?></div>
      </div>
    </div>
  </div>
  <?php if (!money_is_pos($totalRefunded) && !money_is_pos($totalWaived)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-calendar"></i></div>
      <div class="stat-body">
        <div class="stat-label">Period</div>
        <div class="stat-value" style="font-size:14px"><?=date('M Y',strtotime($dateFrom))?></div>
        <div class="stat-sub">to <?=date('M Y',strtotime($dateTo))?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Ledger Table -->
<div class="card" id="soaCard">
  <div class="card-header">
    <span class="card-header-title">
      <i class="fa-solid fa-list-ul me-2"></i>Account Ledger — <?=clean($unitInfo['unit_name'])?>
    </span>
    <span style="font-size:12px;color:var(--text-muted)"><?=fmtDate($dateFrom,'M j, Y')?> – <?=fmtDate($dateTo,'M j, Y')?></span>
  </div>
  <div class="table-responsive">
    <table class="table" id="ledgerTable"<?= $scopeAll ? ' data-segmented="1"' : '' ?>>
      <thead>
        <tr>
          <th>Date</th>
          <th>Encode Date</th>
          <th>Description</th>
          <th>Invoice / Ref</th>
          <th>Cashier</th>
          <th class="text-end">Charges (Dr)</th>
          <th class="text-end">Payments (Cr)</th>
          <th class="text-end">Running Balance</th>
          <th class="text-center no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($ledger)): ?>
        <tr><td colspan="9" class="text-center py-4 text-muted">No records for the selected period and unit.</td></tr>
      <?php endif; ?>
      <?php $segSeen = false; foreach($ledger as $li => $row): ?>
      <?php
        // Occupancy banner in unit-ledger mode: one per tenancy, so it is always
        // visible whose debt a given block of rows belongs to.
        if ($scopeAll && (!$segSeen || !empty($row['segment_start']))):
            $segSeen = true;
            $segOwner = $row['owner'] ?? null;
            $segName  = $segOwner !== null ? ($occNames[$segOwner] ?? 'Unknown tenant') : 'Unattributed';
      ?>
      <tr class="soa-segment">
        <td colspan="9" style="background:var(--gray-100);font-weight:700;font-size:12px;border-top:2px solid var(--gray-300)">
          <i class="fa-solid fa-user fa-xs me-1"></i>Occupancy — <?=clean($segName)?>
        </td>
      </tr>
      <?php endif; ?>
      <?php
        $isRefund  = $row['type'] === 'refund';
        $isSvcChg  = $row['type'] === 'service_charge';
        $isWaiver  = $row['type'] === 'rent_waiver' || $row['type'] === 'service_waiver';
        $isUnpaid  = $isSvcChg && !empty($row['is_unpaid']);
        $trClass   = $row['type']==='payment' ? 'tr-payment' : ($isRefund ? 'tr-refund' : ($isSvcChg ? 'tr-svc-charge' : ''));
      ?>
      <tr class="<?=$trClass?>">
        <td data-order="<?=$row['date']?>" style="white-space:nowrap;font-size:12.5px"><?=fmtDate($row['date'],'M j, Y')?></td>
        <td data-order="<?=clean($row['encoded'] ?? '')?>" style="white-space:nowrap;font-size:12px;color:var(--text-muted)"><?=fmtDateTime($row['encoded'] ?? null)?></td>
        <td class="cell-trunc-lg" style="font-size:12.5px">
          <?php if($row['type']==='charge'): ?>
            <i class="fa-solid fa-file-invoice fa-xs me-1 text-muted"></i><?=clean($row['description'])?>
          <?php elseif($isWaiver): ?>
            <i class="fa-solid fa-file-circle-xmark fa-xs me-1 text-muted"></i>
            <span class="row-voided"><?=clean($row['description'])?></span>
            &nbsp;<span class="muted-pill" style="font-size:10px">Waived</span>
          <?php elseif($isSvcChg): ?>
            <i class="fa-solid fa-receipt fa-xs me-1 text-muted"></i>
            <?php if(!empty($row['is_voided'])): ?>
            <span class="row-voided"><?=clean($row['description'])?></span>
            <?php elseif($isUnpaid): ?>
            <strong><?=clean($row['description'])?></strong>
            &nbsp;<span class="attn-pill" style="font-size:10px">Outstanding</span>
            <?php else: ?>
            <?=clean($row['description'])?>
            <?php endif; ?>
          <?php elseif($isRefund): ?>
            <i class="fa-solid fa-rotate-left fa-xs me-1 text-muted"></i>
            <span class="row-voided"><?=clean($row['description'])?></span>
          <?php else: ?>
            <i class="fa-solid fa-circle-check fa-xs me-1 text-muted"></i>
            <?=clean($row['description'])?>
            <?php if(!empty($row['pay_type'])): ?>
            &nbsp;<span class="badge badge-<?=$row['pay_type']?>"><?=$row['pay_type']==='rent'?'Rent':'Service'?></span>
            <?php endif; ?>
            <?php
              $ps = $row['pay_status'] ?? 'paid';
              if ($ps === 'refunded'):
            ?>&nbsp;<span class="muted-pill" style="font-size:10px">Refunded</span>
            <?php elseif($ps === 'partially_refunded'): ?>
            &nbsp;<span class="muted-pill" style="font-size:10px">Partial Refund</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if(!empty($row['invoice_no'])): ?>
            <a href="invoice_print.php?id=<?=(int)$row['id']?>" target="_blank"
               class="mono text-primary" style="font-size:11.5px" title="View invoice">
              <?=clean($row['invoice_no'])?>
            </a>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          <?php
            // Payment-proof link (collection-page upload / external URL). Only
            // emit for safe schemes — app-served uploads or absolute http(s).
            // no-print so it stays off the printed Statement of Account.
            $rcpt = $row['receipt'] ?? '';
            if ($rcpt !== '' && (str_starts_with($rcpt, '/uploads/') || preg_match('#^https?://#i', $rcpt))):
          ?>
            <a href="<?=clean($rcpt)?>" target="_blank" rel="noopener noreferrer"
               class="no-print ms-1 text-muted" style="font-size:11.5px" title="View payment proof">
              <i class="fa-solid fa-paperclip fa-xs"></i>
            </a>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--text-muted)"><?=clean($row['cashier']??'—')?></td>
        <td class="text-end num">
          <?php if(money_is_pos($row['debit'])): ?>
            <strong><?=money($row['debit'])?></strong>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end num">
          <?php if(money_is_pos($row['credit'])): ?>
            <?=money($row['credit'])?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end fw-600 num">
          <?php if(money_is_pos($row['balance'])): ?>
            <span class="delta-neg"><?=money($row['balance'])?></span>
          <?php elseif(money_lt($row['balance'],'0.00')): ?>
            <span class="text-muted">(<?=money(money_abs($row['balance']))?>) CR</span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-center no-print">
          <?php if($row['type']==='payment' && ($row['pay_status']??'paid') !== 'refunded' && isAdmin()): ?>
            <?php
              $alrRef = number_format((float)($row['already_refunded'] ?? 0), 2, '.', '');
              $maxRef = money_sub((string)$row['credit'], $alrRef);
              $invEsc = htmlspecialchars($row['invoice_no'] ?? '', ENT_QUOTES);
            ?>
            <button class="btn-icon" title="Process Refund"
              onclick="openRefundModal(<?=(int)$row['id']?>,'<?=$invEsc?>',<?=number_format((float)$row['credit'],2,'.','')?>,<?=$alrRef?>,<?=$maxRef?>,<?=(int)($row['received_by'] ?? 0)?>)">
              <i class="fa-solid fa-rotate-left fa-xs" style="color:var(--danger)"></i>
            </button>
          <?php elseif($isSvcChg && $isUnpaid && empty($row['part_paid']) && isAdmin()): ?>
            <button class="btn-icon danger" title="Void Charge"
              onclick="openVoidServiceModal(<?=(int)$row['id']?>,'<?=htmlspecialchars($row['description'], ENT_QUOTES)?>',<?=number_format((float)$row['debit'],2,'.','')?>)">
              <i class="fa-solid fa-file-circle-xmark fa-xs"></i>
            </button>
          <?php elseif($row['type']==='charge' && isAdmin() && money_is_pos($row['waivable'] ?? '0.00')): ?>
            <button class="btn-icon danger" title="Void Rent Charge"
              onclick="openVoidRentModal(<?=(int)$row['period_month']?>,<?=(int)$row['period_year']?>,<?=(int)($row['tenant_id'] ?? 0)?>,<?=$row['waivable']?>,'<?=htmlspecialchars($row['description'], ENT_QUOTES)?>')">
              <i class="fa-solid fa-file-circle-xmark fa-xs"></i>
            </button>
          <?php elseif($isWaiver && isAdmin()): ?>
            <button class="btn-icon" title="Restore this charge"
              onclick="restoreWaiver('<?=$row['type']==='rent_waiver'?'rent':'service'?>',<?=(int)$row['id']?>)">
              <i class="fa-solid fa-rotate-left fa-xs"></i>
            </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php
        // Close the occupancy when the next row belongs to a different tenancy
        // (or there is no next row): each tenant gets their own bottom line.
        $nextRow = $ledger[$li + 1] ?? null;
        if ($scopeAll && ($nextRow === null || !empty($nextRow['segment_start']))):
      ?>
      <tr class="soa-segment-total">
        <td colspan="7" class="text-end" style="font-weight:600;font-size:12px;color:var(--text-muted)">
          Closing balance — <?=clean($segName ?? '')?>
        </td>
        <td class="text-end fw-600 num" style="border-top:1px solid var(--gray-300)">
          <?php if(money_is_pos($row['balance'])): ?><span class="delta-neg"><?=money($row['balance'])?></span>
          <?php elseif(money_lt($row['balance'],'0.00')): ?><span class="text-muted">(<?=money(money_abs($row['balance']))?>) CR</span>
          <?php else: ?><span class="text-muted">Settled</span><?php endif; ?>
        </td>
        <td class="no-print"></td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--gray-100);font-weight:700;border-top:2px solid var(--gray-200)">
          <td colspan="5" style="font-size:13px">TOTALS</td>
          <td class="text-end num fw-600"><?=money($totalDebit)?></td>
          <td class="text-end num fw-600"><?=money($totalCredit)?></td>
          <td class="text-end num fw-600">
            <?php if(money_is_pos($finalBal)): ?><span class="delta-neg"><?=money($finalBal)?></span> <small>DR</small>
            <?php elseif(money_lt($finalBal,'0.00')): ?>(<?=money(money_abs($finalBal))?>) <small>CR</small>
            <?php else: ?>BALANCED<?php endif; ?>
          </td>
          <td class="no-print"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php if(!empty($ledger)): ?>
  <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div style="font-size:11.5px;color:var(--text-muted)">
      Dr = Charges &nbsp;·&nbsp; Cr = Payments &nbsp;·&nbsp; Balance is cumulative Dr minus Cr
    </div>
    <div class="d-flex gap-2">
      <a href="soa_pdf.php?unit_id=<?=$selUnit?>&tenant_id=<?=urlencode($scopeParam)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
         target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="fa-solid fa-eye me-1"></i>Preview SOA
      </a>
      <a href="soa_pdf_download.php?unit_id=<?=$selUnit?>&tenant_id=<?=urlencode($scopeParam)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
         class="btn btn-sm btn-primary">
        <i class="fa-solid fa-download me-1"></i>Download PDF
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- Refund Modal -->
<div class="modal fade" id="refundModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-rotate-left me-2 text-danger"></i>Process Refund</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="refPaymentId">
        <div class="alert alert-info py-2 mb-3" id="refPaymentInfo" style="font-size:13px"></div>
        <div class="mb-3">
          <label class="form-label">Refund Amount (₱) *</label>
          <input type="number" step="0.01" min="0.01" class="form-control" id="refAmount">
          <div class="form-text" id="refMaxHint"></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Returned by (cashier) *</label>
          <select class="form-select" id="refCashier">
            <?php foreach ($activeUsers as $au): ?>
              <option value="<?= (int)$au['id'] ?>"><?= clean($au['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Whose cash-on-hand funds this refund. Must have enough cash, or request a vault return first.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <textarea class="form-control" id="refReason" rows="2" placeholder="e.g. Overpayment, duplicate payment, cancellation..."></textarea>
        </div>
        <div id="refMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" onclick="processRefund()">
          <i class="fa-solid fa-rotate-left me-1"></i>Process Refund
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Void Charge Modal (single rent period or one service charge) -->
<div class="modal fade" id="voidChargeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-file-circle-xmark me-2 text-danger"></i>Void Charge</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="voidType">
        <input type="hidden" id="voidMonth">
        <input type="hidden" id="voidYear">
        <input type="hidden" id="voidTenant">
        <input type="hidden" id="voidChargeId">
        <div class="alert alert-info py-2 mb-3" id="voidChargeInfo" style="font-size:13px"></div>
        <div class="mb-3">
          <label class="form-label">Amount to Waive (₱) *</label>
          <input type="number" step="0.01" min="0.01" class="form-control" id="voidAmount">
          <div class="form-text" id="voidMaxHint"></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <textarea class="form-control" id="voidReason" rows="2"
                    placeholder="e.g. Advance rent applied, arrears written off on move-out..."></textarea>
        </div>
        <div class="alert alert-warning py-2 mb-0" style="font-size:12px">
          <i class="fa-solid fa-circle-info me-1"></i>A waiver cancels what is still owed. No cash moves and no
          income changes — collected payments are untouched. It is logged and can be restored.
        </div>
        <div id="voidMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" onclick="submitVoid()">
          <i class="fa-solid fa-file-circle-xmark me-1"></i>Void Charge
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Void Modal — every waivable charge in the current unit + date range -->
<div class="modal fade" id="bulkVoidModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-file-circle-xmark me-2 text-danger"></i>Void Charges</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 mb-3" style="font-size:12.5px">
          <i class="fa-solid fa-circle-info me-1"></i>Writes off what is still owed on the charges you tick —
          for advance rent applied at move-out, or a departing tenant's arrears. No cash moves, no income
          changes; every waiver is logged and can be restored from this ledger.
        </div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <div class="d-flex gap-1">
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllWaivable(true)">Select all</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllWaivable(false)">Clear</button>
          </div>
          <div style="font-size:13px">Total to waive: <strong class="num" id="bulkVoidTotal">₱0.00</strong></div>
        </div>
        <div id="bulkVoidList" class="mb-3"></div>
        <div class="mb-2">
          <label class="form-label">Reason *</label>
          <textarea class="form-control" id="bulkVoidReason" rows="2"
                    placeholder="e.g. 2 months advance rent applied on move-out"></textarea>
        </div>
        <div id="bulkVoidMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" onclick="submitBulkVoid()">
          <i class="fa-solid fa-file-circle-xmark me-1"></i>Void Selected
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  // Charges the admin may still waive in the current unit + date range,
  // computed server-side (the API re-validates every one of them on submit).
  window.LASKIE_UNIT_ID  = <?= (int)$selUnit ?>;
  window.LASKIE_WAIVABLE = <?= json_encode(isAdmin() ? $waivable : [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>

<?php $extraJs = <<<'JS'
<script>
function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}

$(document).ready(function(){
  // The unit ledger is grouped by occupancy, so it is a document, not a
  // sortable grid — DataTables would sort the banner and subtotal rows in
  // among the entries and destroy the grouping.
  var ledgerEl = document.getElementById('ledgerTable');
  if (ledgerEl && !ledgerEl.dataset.segmented) {
    $('#ledgerTable').DataTable({
      pageLength: 50,
      order: [[0,'asc']],
      columnDefs: [{ targets: -1, orderable: false }],
      dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rtip',
      language: { search:'Filter:', lengthMenu:'Show _MENU_' }
    });
  }
  var refundModalEl = document.getElementById('refundModal');
  if (refundModalEl) {
    window.refundModal = new bootstrap.Modal(refundModalEl);
  }
  var voidModalEl = document.getElementById('voidChargeModal');
  if (voidModalEl) window.voidChargeModal = new bootstrap.Modal(voidModalEl);
  var bulkVoidEl = document.getElementById('bulkVoidModal');
  if (bulkVoidEl) window.bulkVoidModal = new bootstrap.Modal(bulkVoidEl);
});

// Switching tenancy resets the date range to that occupancy's contract window,
// so the statement opens on the period it is actually for. The server clamps
// the range again anyway — this just keeps the inputs honest.
function onTenantScopeChange(sel) {
  var opt  = sel.options[sel.selectedIndex];
  var from = document.getElementById('soaFrom');
  var to   = document.getElementById('soaTo');
  if (sel.value !== 'all') {
    if (opt.dataset.from) from.value = opt.dataset.from;
    to.value = opt.dataset.to || new Date().toISOString().slice(0, 10);
  }
  sel.form.submit();
}

function openRefundModal(paymentId, invoiceNo, amount, alreadyRefunded, maxRefund, cashierId) {
  alreadyRefunded = alreadyRefunded || 0;
  document.getElementById('refPaymentId').value = paymentId;
  var cashierSel = document.getElementById('refCashier');
  if (cashierId && cashierSel.querySelector('option[value="' + cashierId + '"]')) cashierSel.value = cashierId;
  document.getElementById('refPaymentInfo').innerHTML =
    '<strong>' + esc(invoiceNo) + '</strong> &nbsp;·&nbsp; Original: <strong>' + fmt(amount) + '</strong>' +
    (alreadyRefunded > 0 ? ' &nbsp;·&nbsp; Already refunded: <strong>' + fmt(alreadyRefunded) + '</strong>' : '');
  document.getElementById('refAmount').value = maxRefund;
  document.getElementById('refAmount').max   = maxRefund;
  document.getElementById('refMaxHint').textContent = 'Max refundable: ' + fmt(maxRefund);
  document.getElementById('refReason').value = '';
  document.getElementById('refMsg').style.display = 'none';
  window.refundModal.show();
}

// ── Charge waivers (admin write-offs) ───────────────────────────────────────
// A waiver cancels an unpaid charge; it never touches cash or income. Rent
// charges are virtual so they are waived by (period, tenant); service charges
// are real rows so they are waived by id.
function openVoidRentModal(month, year, tenantId, maxAmount, label) {
  document.getElementById('voidType').value     = 'rent';
  document.getElementById('voidMonth').value    = month;
  document.getElementById('voidYear').value     = year;
  document.getElementById('voidTenant').value   = tenantId || 0;
  document.getElementById('voidChargeId').value = '';
  document.getElementById('voidChargeInfo').innerHTML = '<strong>' + esc(label) + '</strong>';
  var amt = document.getElementById('voidAmount');
  amt.value = maxAmount; amt.max = maxAmount; amt.readOnly = false;
  document.getElementById('voidMaxHint').textContent =
    'Max waivable: ' + fmt(maxAmount) + ' (the unpaid part of this month). Lower it to waive only part.';
  document.getElementById('voidReason').value = '';
  document.getElementById('voidMsg').style.display = 'none';
  window.voidChargeModal.show();
}

function openVoidServiceModal(chargeId, label, amount) {
  document.getElementById('voidType').value     = 'service';
  document.getElementById('voidChargeId').value = chargeId;
  document.getElementById('voidChargeInfo').innerHTML = '<strong>' + esc(label) + '</strong>';
  var amt = document.getElementById('voidAmount');
  amt.value = amount; amt.readOnly = true;   // service charges void whole, never partly
  document.getElementById('voidMaxHint').textContent = 'Service charges are voided in full.';
  document.getElementById('voidReason').value = '';
  document.getElementById('voidMsg').style.display = 'none';
  window.voidChargeModal.show();
}

function submitVoid() {
  var type   = document.getElementById('voidType').value;
  var reason = document.getElementById('voidReason').value.trim();
  var amount = parseFloat(document.getElementById('voidAmount').value);
  var msgEl  = document.getElementById('voidMsg');
  var fail   = function(t) { msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = t; msgEl.style.display = ''; };

  if (!reason) return fail('Reason is required.');
  if (type === 'rent' && (!amount || amount <= 0)) return fail('Enter a valid amount.');

  var data = type === 'rent'
    ? {action: 'void_rent_charge', unit_id: window.LASKIE_UNIT_ID,
       period_month: document.getElementById('voidMonth').value,
       period_year:  document.getElementById('voidYear').value,
       tenant_id:    document.getElementById('voidTenant').value,
       amount: amount, reason: reason}
    : {action: 'delete_charge', id: document.getElementById('voidChargeId').value, reason: reason};

  apiPost('api_payment.php', data, function(err, res) {
    if (err || !res || !res.success) return fail((res && res.error) ? res.error : (err || 'Failed.'));
    showToast(res.msg, 'success');
    window.voidChargeModal.hide();
    window.location.reload();
  });
}

function restoreWaiver(type, id) {
  confirmDelete('Restore this charge? The tenant will owe it again.', function() {
    var action = type === 'rent' ? 'restore_rent_charge' : 'restore_charge';
    apiPost('api_payment.php', {action: action, id: id}, function(err, res) {
      if (err || !res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
      showToast(res.msg, 'success');
      window.location.reload();
    });
  });
}

function openBulkVoidModal() {
  var items = window.LASKIE_WAIVABLE || [];
  var html  = '<table class="table table-sm mb-0" style="font-size:12.5px"><thead><tr>' +
              '<th style="width:36px"></th><th>Charge</th><th class="text-end">Amount</th>' +
              '</tr></thead><tbody>';
  items.forEach(function(it, i) {
    html += '<tr>' +
      '<td><input type="checkbox" class="form-check-input waivable-chk" data-idx="' + i + '" ' +
        'data-amount="' + (parseFloat(it.amount) || 0) + '" onchange="updateBulkVoidTotal()"></td>' +
      '<td>' + esc(it.label) + '</td>' +
      '<td class="text-end num fw-600">' + fmt(it.amount) + '</td>' +
    '</tr>';
  });
  html += '</tbody></table>';
  document.getElementById('bulkVoidList').innerHTML = items.length
    ? html
    : '<div class="empty-state"><p>Nothing left to void in this date range.</p></div>';
  document.getElementById('bulkVoidReason').value = '';
  document.getElementById('bulkVoidMsg').style.display = 'none';
  updateBulkVoidTotal();
  window.bulkVoidModal.show();
}

function toggleAllWaivable(on) {
  document.querySelectorAll('.waivable-chk').forEach(function(c) { c.checked = !!on; });
  updateBulkVoidTotal();
}

function updateBulkVoidTotal() {
  var total = 0;
  document.querySelectorAll('.waivable-chk:checked').forEach(function(c) {
    total += parseFloat(c.dataset.amount) || 0;
  });
  document.getElementById('bulkVoidTotal').textContent = fmt(total);
}

function submitBulkVoid() {
  var reason = document.getElementById('bulkVoidReason').value.trim();
  var msgEl  = document.getElementById('bulkVoidMsg');
  var fail   = function(t) { msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = t; msgEl.style.display = ''; };
  var picked = [];

  document.querySelectorAll('.waivable-chk:checked').forEach(function(c) {
    var it = (window.LASKIE_WAIVABLE || [])[parseInt(c.dataset.idx)];
    if (!it) return;
    picked.push(it.type === 'rent'
      ? {type: 'rent', period_month: it.month, period_year: it.year, tenant_id: it.tenant || 0}
      : {type: 'service', id: it.id});
  });

  if (!picked.length) return fail('Select at least one charge.');
  if (!reason)        return fail('Reason is required.');

  apiPost('api_payment.php', {action: 'bulk_void_charges', unit_id: window.LASKIE_UNIT_ID,
                              reason: reason, items: JSON.stringify(picked)}, function(err, res) {
    if (err || !res || !res.success) return fail((res && res.error) ? res.error : (err || 'Failed.'));
    showToast(res.msg, 'success');
    window.bulkVoidModal.hide();
    window.location.reload();
  });
}

function processRefund() {
  var paymentId = document.getElementById('refPaymentId').value;
  var amount    = parseFloat(document.getElementById('refAmount').value);
  var reason    = document.getElementById('refReason').value.trim();
  var cashier   = document.getElementById('refCashier').value;
  var msgEl     = document.getElementById('refMsg');

  if (!amount || amount <= 0) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Enter a valid amount.'; msgEl.style.display = ''; return;
  }
  if (!reason) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Reason is required.'; msgEl.style.display = ''; return;
  }

  apiPost('api_payment.php', {action: 'process_refund', payment_id: paymentId, amount: amount, reason: reason, cashier_id: cashier}, function(err, res) {
    if (err || !res || !res.success) {
      msgEl.className = 'alert alert-danger mt-2';
      msgEl.textContent = (res && res.error) ? res.error : (err || 'Failed.');
      msgEl.style.display = '';
      return;
    }
    showToast(res.msg, 'success');
    window.refundModal.hide();
    window.location.reload();
  });
}
</script>
<style>
  .tr-payment td { background: var(--gray-100); }
  .tr-payment:hover td { background: var(--gray-200) !important; }
  .tr-refund td { background: var(--gray-100); }
  .tr-refund:hover td { background: var(--gray-200) !important; }
  .tr-svc-charge td { background: var(--gray-100); }
  .tr-svc-charge:hover td { background: var(--gray-200) !important; }
  @media print {
    .card-footer, form, .page-header .btn, .no-print { display:none !important; }
  }
</style>
JS;
include '../includes/footer.php'; ?>
