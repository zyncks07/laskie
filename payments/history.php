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

// ── Fetch Unit Info ───────────────────────────────────────────
$unitInfo = null;
if ($selUnit) {
    $s = $pdo->prepare("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id WHERE ru.id=?");
    $s->execute([$selUnit]);
    $unitInfo = $s->fetch();
}

// ── Occupants overlapping the requested date range ───────────
// Includes active tenant AND former/inactive tenants whose contract
// period intersects [dateFrom, dateTo] so that historical charges
// are generated correctly even after a tenant moves out or transfers.
$occupants = [];
$tenant    = null; // primary tenant for display (active, or most recent former)
if ($selUnit) {
    $t = $pdo->prepare("
        SELECT * FROM tenants
        WHERE unit_id = ?
          AND status IN ('active','former','inactive')
          AND (contract_start IS NULL OR contract_start <= ?)
          AND (contract_end   IS NULL OR contract_end   >= ?)
        ORDER BY COALESCE(contract_start,'1970-01-01') ASC
    ");
    $t->execute([$selUnit, $dateTo, $dateFrom]);
    $occupants = $t->fetchAll();
    foreach ($occupants as $occ) {
        if ($occ['status'] === 'active') { $tenant = $occ; break; }
    }
    if (!$tenant && !empty($occupants)) $tenant = end($occupants);
}

// ── Payment Records ───────────────────────────────────────────
$payments  = [];
$totalPaid = 0;
if ($selUnit) {
    $q = $pdo->prepare("
        SELECT p.*, st.name AS service_name, u.full_name AS cashier_name
        FROM   payments p
        LEFT JOIN service_types st ON p.service_type_id = st.id
        LEFT JOIN users u          ON p.received_by     = u.id
        WHERE  p.unit_id = ? AND p.payment_date BETWEEN ? AND ? AND p.deleted_at IS NULL AND p.status != 'voided'
        ORDER  BY p.payment_date ASC, p.created_at ASC
    ");
    $q->execute([$selUnit, $dateFrom, $dateTo]);
    $payments  = $q->fetchAll();
    $totalPaid = money_sum(array_column($payments, 'amount'));
}

// ── Fetch Refunds for payments in this range ──────────────────
$refundRows   = [];
$refundedMap  = []; // payment_id => total_refunded
$payStatusMap = []; // payment_id => status
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
// is_outstanding = 1 when the charge has no payment_id OR its linked payment
// was voided / soft-deleted. The filtered LEFT JOIN forces such rows to come
// back with p.id IS NULL, mirroring the soa_pdf.php fix so the rendered SoA
// labels them "(Unpaid)" consistently.
$serviceCharges = [];
if ($selUnit) {
    $sq = $pdo->prepare("
        SELECT uc.*, st.name as service_name, u.full_name as billed_by_name,
               (uc.payment_id IS NULL OR p.id IS NULL) AS is_outstanding
        FROM unit_charges uc
        LEFT JOIN service_types st ON uc.service_type_id = st.id
        LEFT JOIN users u ON uc.created_by = u.id
        LEFT JOIN payments p ON p.id = uc.payment_id
                            AND p.deleted_at IS NULL
                            AND p.status != 'voided'
        WHERE uc.unit_id = ? AND uc.charge_date BETWEEN ? AND ?
        ORDER BY uc.charge_date ASC, uc.created_at ASC
    ");
    $sq->execute([$selUnit, $dateFrom, $dateTo]);
    $serviceCharges = $sq->fetchAll();
}

// ── Build Ledger (charges + payments merged, sorted by date) ──
$ledger = [];
if ($selUnit && $unitInfo) {
    $baseRate = (float)$unitInfo['monthly_rate'];

    // Generate charge rows for every occupant whose period overlaps the range
    $dueDay = (int)$unitInfo['due_day'];
    $multiOccupant = count($occupants) > 1;
    foreach ($occupants as $occupant) {
        $contractStart = $occupant['contract_start'] ?? null;
        $contractEnd   = $occupant['contract_end']   ?? null;

        $chargeFrom = $dateFrom;
        if ($contractStart && $contractStart > $chargeFrom) $chargeFrom = $contractStart;
        $chargeTo = $dateTo;
        if ($contractEnd && $contractEnd < $chargeTo) $chargeTo = $contractEnd;

        $iter  = new DateTime($chargeFrom);
        $iter->modify('first day of this month');
        $endDt = new DateTime($chargeTo);

        while ($iter <= $endDt) {
            $m    = (int)$iter->format('n');
            $y    = (int)$iter->format('Y');
            $rate = getRateForMonth($pdo, $selUnit, $baseRate, $m, $y);
            if ($rate <= 0) { $iter->modify('+1 month'); continue; }
            $charge  = prorateFirstMonth($rate, $dueDay, $contractStart, $m, $y);
            $dateStr = chargeDate($dueDay, $contractStart, $m, $y);
            $desc    = 'Rent — ' . $iter->format('F Y');
            if (money_lt($charge, $rate)) $desc .= ' (prorated)';
            if ($multiOccupant)           $desc .= ' [' . $occupant['full_name'] . ']';
            $ledger[] = [
                'date'        => $dateStr,
                'description' => $desc,
                'type'        => 'charge',
                'debit'       => $charge,
                'credit'      => '0.00',
            ];
            $iter->modify('+1 month');
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
            'pay_status'       => $payStatusMap[$p['id']] ?? 'paid',
            'already_refunded' => $refundedMap[$p['id']] ?? '0.00',
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
        ];
    }

    // Service charge rows from unit_charges
    foreach ($serviceCharges as $c) {
        $period   = date('F Y', mktime(0,0,0,(int)$c['period_month'],1,(int)$c['period_year']));
        $desc     = ($c['service_name'] ?? $c['description']) . ' — ' . $period;
        // is_outstanding accounts for both NULL payment_id AND voided/deleted
        // linked payments, so the badge stays accurate after a void/restore.
        $unpaid   = !empty($c['is_outstanding']);
        if ($unpaid) $desc .= ' (Unpaid)';
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
            'source'      => $c['source'],
        ];
    }
}

// Sort by date asc; within same date: rent charges → service charges → payments → refunds
usort($ledger, function($a,$b){
    $cmp = strcmp($a['date'],$b['date']);
    if ($cmp !== 0) return $cmp;
    $order = ['charge'=>0,'service_charge'=>1,'payment'=>2,'refund'=>3];
    return ($order[$a['type']]??2) - ($order[$b['type']]??2);
});

// Running balance — cents math, no float drift.
$runBal = '0.00';
foreach ($ledger as &$row) {
    $runBal = money_add($runBal, money_sub($row['debit'], $row['credit']));
    $row['balance'] = $runBal;
}
unset($row);

$totalChargesDebit = money_sum(array_map(fn($r) => in_array($r['type'],['charge','service_charge']) ? $r['debit'] : '0.00', $ledger));
$totalDebit        = money_sum(array_column($ledger, 'debit'));
$totalCredit       = money_sum(array_column($ledger, 'credit'));
$finalBal          = money_sub($totalDebit, $totalCredit);

logActivity($pdo, 'VIEW_SOA', 'SOA', "Viewed SOA unit #$selUnit ($dateFrom – $dateTo)");
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-file-invoice me-2 text-primary-custom"></i>Statement of Account</h1>
  <div class="d-flex gap-2">
    <?php if ($selUnit && $unitInfo): ?>
    <a href="soa_pdf.php?unit_id=<?=$selUnit?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
       target="_blank" class="btn btn-sm btn-outline-primary no-print">
      <i class="fa-solid fa-file-pdf me-1"></i>Preview SOA
    </a>
    <a href="soa_pdf_download.php?unit_id=<?=$selUnit?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
       class="btn btn-sm btn-primary no-print">
      <i class="fa-solid fa-download me-1"></i>Download PDF
    </a>
    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
      <i class="fa-solid fa-print me-1"></i>Print
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-4">
        <label class="form-label">Rental Unit</label>
        <select name="unit_id" class="form-select form-select-sm">
          <?php foreach($units as $u): ?>
          <option value="<?=$u['id']?>" <?=$u['id']==$selUnit?'selected':''?>>
            <?=clean($u['unit_name'])?> (<?=ucfirst($u['status'])?>)<?= $u['tenant_name'] ? ' — ' . clean($u['tenant_name']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Date From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?=clean($dateFrom)?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Date To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?=clean($dateTo)?>">
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
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-user me-2"></i>Current Tenant</span></div>
      <div class="card-body py-2">
        <?php if($tenant): ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse">
          <?php $trows=[['Name',$tenant['full_name']],['Phone',$tenant['phone']??'—'],['Email',$tenant['email']??'—'],['Contract',($tenant['contract_start']?fmtDate($tenant['contract_start'],'M j, Y').' – '.($tenant['contract_end']?fmtDate($tenant['contract_end'],'M j, Y'):'Open'):'—')]]; foreach($trows as [$l,$v]): ?>
          <tr><td style="padding:5px 0;color:var(--text-muted);width:130px"><?=$l?></td><td style="padding:5px 0;font-weight:600"><?=clean($v)?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php else: ?>
        <div class="text-center py-3 text-muted"><i class="fa-solid fa-user-slash me-1"></i> No active tenant</div>
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
        <div class="stat-value" style="font-size:17px"><?=money($totalCredit)?></div>
        <div class="stat-sub"><?=count($payments)?> payment<?=count($payments)!=1?'s':''?></div>
      </div>
    </div>
  </div>
  <?php if ($totalRefunded > 0): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-rotate-left"></i></div>
      <div class="stat-body">
        <div class="stat-label">Total Refunded</div>
        <div class="stat-value" style="font-size:17px;color:var(--danger)"><?=money($totalRefunded)?></div>
        <div class="stat-sub"><?=count($refundRows)?> refund<?=count($refundRows)!=1?'s':''?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon <?=money_is_pos($finalBal)?'red':(money_lt($finalBal,'0.00')?'purple':'green')?>">
        <i class="fa-solid fa-scale-balanced"></i>
      </div>
      <div class="stat-body">
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value" style="font-size:17px;color:<?=money_is_pos($finalBal)?'var(--danger)':(money_lt($finalBal,'0.00')?'var(--info)':'var(--success)')?>">
          <?=money(money_abs($finalBal))?>
        </div>
        <div class="stat-sub"><?=money_is_pos($finalBal)?'Due':(money_lt($finalBal,'0.00')?'Overpaid (CR)':'Fully settled')?></div>
      </div>
    </div>
  </div>
  <?php if ($totalRefunded <= 0): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="fa-solid fa-calendar"></i></div>
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
    <table class="table" id="ledgerTable">
      <thead>
        <tr>
          <th>Date</th>
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
        <tr><td colspan="8" class="text-center py-4 text-muted">No records for the selected period and unit.</td></tr>
      <?php endif; ?>
      <?php foreach($ledger as $row): ?>
      <?php
        $isRefund  = $row['type'] === 'refund';
        $isSvcChg  = $row['type'] === 'service_charge';
        $isUnpaid  = $isSvcChg && !empty($row['is_unpaid']);
        $trClass   = $row['type']==='payment' ? 'tr-payment' : ($isRefund ? 'tr-refund' : ($isSvcChg ? 'tr-svc-charge' : ''));
      ?>
      <tr class="<?=$trClass?>">
        <td data-order="<?=$row['date']?>" style="white-space:nowrap;font-size:12.5px"><?=fmtDate($row['date'],'M j, Y')?></td>
        <td class="cell-trunc-lg" style="font-size:12.5px">
          <?php if($row['type']==='charge'): ?>
            <i class="fa-solid fa-file-invoice fa-xs me-1 text-muted"></i><?=clean($row['description'])?>
          <?php elseif($isSvcChg): ?>
            <i class="fa-solid fa-receipt fa-xs me-1" style="color:<?=$isUnpaid?'var(--warning)':'var(--text-muted)'?>"></i>
            <span style="color:<?=$isUnpaid?'var(--warning)':'inherit'?>"><?=clean($row['description'])?></span>
            <?php if($isUnpaid): ?>
            &nbsp;<span class="badge bg-warning text-dark" style="font-size:10px">Outstanding</span>
            <?php endif; ?>
          <?php elseif($isRefund): ?>
            <i class="fa-solid fa-rotate-left fa-xs me-1" style="color:var(--danger)"></i>
            <span style="color:var(--danger)"><?=clean($row['description'])?></span>
          <?php else: ?>
            <i class="fa-solid fa-circle-check fa-xs me-1" style="color:var(--success)"></i>
            <span style="color:var(--success)"><?=clean($row['description'])?></span>
            <?php if(!empty($row['pay_type'])): ?>
            &nbsp;<span class="badge badge-<?=$row['pay_type']?>"><?=$row['pay_type']==='rent'?'Rent':'Service'?></span>
            <?php endif; ?>
            <?php
              $ps = $row['pay_status'] ?? 'paid';
              if ($ps === 'refunded'):
            ?>&nbsp;<span class="badge bg-danger" style="font-size:10px">Refunded</span>
            <?php elseif($ps === 'partially_refunded'): ?>
            &nbsp;<span class="badge bg-warning text-dark" style="font-size:10px">Partial Refund</span>
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
        </td>
        <td style="font-size:12px;color:var(--text-muted)"><?=clean($row['cashier']??'—')?></td>
        <td class="text-end">
          <?php if(money_is_pos($row['debit'])): ?>
            <span style="color:var(--danger);font-weight:500"><?=money($row['debit'])?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end">
          <?php if(money_is_pos($row['credit'])): ?>
            <span style="color:var(--success);font-weight:600"><?=money($row['credit'])?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end fw-600">
          <?php if(money_is_pos($row['balance'])): ?>
            <span style="color:var(--danger)"><?=money($row['balance'])?></span>
          <?php elseif(money_lt($row['balance'],'0.00')): ?>
            <span style="color:var(--info)">(<?=money(money_abs($row['balance']))?>) CR</span>
          <?php else: ?>
            <span style="color:var(--success)">—</span>
          <?php endif; ?>
        </td>
        <td class="text-center no-print">
          <?php if($row['type']==='payment' && ($row['pay_status']??'paid') !== 'refunded'): ?>
            <?php
              $alrRef = (float)($row['already_refunded'] ?? 0);
              $invEsc = htmlspecialchars($row['invoice_no'] ?? '', ENT_QUOTES);
            ?>
            <button class="btn-icon" title="Process Refund"
              onclick="openRefundModal(<?=(int)$row['id']?>,'<?=$invEsc?>',<?=$row['credit']?>,<?=$alrRef?>)">
              <i class="fa-solid fa-rotate-left fa-xs" style="color:var(--danger)"></i>
            </button>
          <?php elseif($isSvcChg && $isUnpaid): ?>
            <button class="btn-icon danger" title="Delete Charge"
              onclick="deleteCharge(<?=(int)$row['id']?>)">
              <i class="fa-solid fa-trash fa-xs"></i>
            </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid var(--border)">
          <td colspan="4" style="font-size:13px">TOTALS</td>
          <td class="text-end" style="color:var(--danger)"><?=money($totalDebit)?></td>
          <td class="text-end" style="color:var(--success)"><?=money($totalCredit)?></td>
          <td class="text-end" style="color:<?=money_is_pos($finalBal)?'var(--danger)':(money_lt($finalBal,'0.00')?'var(--info)':'var(--success)')?>">
            <?php if(money_is_pos($finalBal)): ?><?=money($finalBal)?> <small>DR</small>
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
      <a href="soa_pdf.php?unit_id=<?=$selUnit?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
         target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="fa-solid fa-eye me-1"></i>Preview SOA
      </a>
      <a href="soa_pdf_download.php?unit_id=<?=$selUnit?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"
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

<?php $extraJs = <<<'JS'
<script>
function esc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}

$(document).ready(function(){
  if (document.getElementById('ledgerTable')) {
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
});

function openRefundModal(paymentId, invoiceNo, amount, alreadyRefunded) {
  alreadyRefunded = alreadyRefunded || 0;
  var maxRefund = amount - alreadyRefunded;
  document.getElementById('refPaymentId').value = paymentId;
  document.getElementById('refPaymentInfo').innerHTML =
    '<strong>' + esc(invoiceNo) + '</strong> &nbsp;·&nbsp; Original: <strong>₱' + fmt(amount) + '</strong>' +
    (alreadyRefunded > 0 ? ' &nbsp;·&nbsp; Already refunded: <strong>₱' + fmt(alreadyRefunded) + '</strong>' : '');
  document.getElementById('refAmount').value = maxRefund.toFixed(2);
  document.getElementById('refAmount').max   = maxRefund.toFixed(2);
  document.getElementById('refMaxHint').textContent = 'Max refundable: ₱' + fmt(maxRefund);
  document.getElementById('refReason').value = '';
  document.getElementById('refMsg').style.display = 'none';
  window.refundModal.show();
}

function deleteCharge(id) {
  confirmDelete('Delete this outstanding service charge? This will remove it from the account.', function() {
    apiPost('api_payment.php', {action: 'delete_charge', id: id}, function(err, res) {
      if (err || !res || !res.success) { showToast((res&&res.error)||'Failed.','error'); return; }
      showToast(res.msg, 'success');
      window.location.reload();
    });
  });
}

function processRefund() {
  var paymentId = document.getElementById('refPaymentId').value;
  var amount    = parseFloat(document.getElementById('refAmount').value);
  var reason    = document.getElementById('refReason').value.trim();
  var msgEl     = document.getElementById('refMsg');

  if (!amount || amount <= 0) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Enter a valid amount.'; msgEl.style.display = ''; return;
  }
  if (!reason) {
    msgEl.className = 'alert alert-danger mt-2'; msgEl.textContent = 'Reason is required.'; msgEl.style.display = ''; return;
  }

  apiPost('api_payment.php', {action: 'process_refund', payment_id: paymentId, amount: amount, reason: reason}, function(err, res) {
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
  .tr-payment td { background: rgba(21,128,61,.04); }
  .tr-payment:hover td { background: rgba(21,128,61,.09) !important; }
  .tr-refund td { background: rgba(220,38,38,.04); }
  .tr-refund:hover td { background: rgba(220,38,38,.09) !important; }
  .tr-svc-charge td { background: rgba(217,119,6,.04); }
  .tr-svc-charge:hover td { background: rgba(217,119,6,.09) !important; }
  @media print {
    .card-footer, form, .page-header .btn, .no-print { display:none !important; }
  }
</style>
JS;
include '../includes/footer.php'; ?>
