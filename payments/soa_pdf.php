<?php
// payments/soa_pdf.php — Statement of Account PDF Export
// Renders a clean print-optimised HTML page the browser saves as PDF.
// For server-side PDF (wkhtmltopdf / mPDF), swap the echo block for your library.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

$unitId   = (int)($_GET['unit_id']   ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

if (!$unitId) die('<p style="font-family:sans-serif;padding:2rem;color:red;">Unit ID required.</p>');

// ── Fetch Unit ────────────────────────────────────────────────
$s = $pdo->prepare("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id WHERE ru.id=?");
$s->execute([$unitId]);
$unit = $s->fetch();
if (!$unit) die('<p style="font-family:sans-serif;padding:2rem;color:red;">Unit not found.</p>');

// ── Fetch Occupants overlapping the date range ────────────────
$t = $pdo->prepare("
    SELECT * FROM tenants
    WHERE unit_id = ?
      AND status IN ('active','former','inactive')
      AND (contract_start IS NULL OR contract_start <= ?)
      AND (contract_end   IS NULL OR contract_end   >= ?)
    ORDER BY COALESCE(contract_start,'1970-01-01') ASC
");
$t->execute([$unitId, $dateTo, $dateFrom]);
$occupants = $t->fetchAll();

// Primary tenant for the header/signature panel
$tenant = null;
foreach ($occupants as $occ) {
    if ($occ['status'] === 'active') { $tenant = $occ; break; }
}
if (!$tenant && !empty($occupants)) $tenant = end($occupants);

// ── Fetch Payments ────────────────────────────────────────────
$q = $pdo->prepare("
    SELECT p.*, st.name AS service_name, u.full_name AS cashier_name
    FROM   payments p
    LEFT JOIN service_types st ON p.service_type_id = st.id
    LEFT JOIN users u          ON p.received_by = u.id
    WHERE  p.unit_id = ? AND p.payment_date BETWEEN ? AND ?
    ORDER  BY p.payment_date ASC, p.created_at ASC
");
$q->execute([$unitId, $dateFrom, $dateTo]);
$payments = $q->fetchAll();

// ── Fetch Refunds ─────────────────────────────────────────────
$pdfRefunds = [];
if ($payments) {
    $payIds = array_column($payments, 'id');
    $in = implode(',', array_fill(0, count($payIds), '?'));
    $rq = $pdo->prepare("
        SELECT r.*, u.full_name as refunded_by_name, p.invoice_no as payment_invoice
        FROM refunds r
        LEFT JOIN users u ON r.refunded_by = u.id
        LEFT JOIN payments p ON r.payment_id = p.id
        WHERE r.payment_id IN ($in)
        ORDER BY r.refunded_at ASC
    ");
    $rq->execute($payIds);
    $pdfRefunds = $rq->fetchAll();
}

// ── Fetch Service Charges ─────────────────────────────────────
$pdfServiceCharges = [];
$sq = $pdo->prepare("
    SELECT uc.*, st.name as service_name, u.full_name as billed_by_name
    FROM unit_charges uc
    LEFT JOIN service_types st ON uc.service_type_id = st.id
    LEFT JOIN users u ON uc.created_by = u.id
    WHERE uc.unit_id = ? AND uc.charge_date BETWEEN ? AND ?
    ORDER BY uc.charge_date ASC
");
$sq->execute([$unitId, $dateFrom, $dateTo]);
$pdfServiceCharges = $sq->fetchAll();

// ── Build Ledger ──────────────────────────────────────────────
$ledger   = [];
$baseRate = (float)$unit['monthly_rate'];
$rate = $baseRate;
$dueDay   = (int)$unit['due_day'];
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
        $rate = getRateForMonth($pdo, $unitId, $baseRate, $m, $y);
        if ($rate <= 0) { $iter->modify('+1 month'); continue; }
        $charge  = prorateFirstMonth($rate, $dueDay, $contractStart, $m, $y);
        $dateStr = chargeDate($dueDay, $contractStart, $m, $y);
        $desc    = 'Rent — ' . $iter->format('F Y');
        if ($charge < $rate)  $desc .= ' (prorated)';
        if ($multiOccupant)   $desc .= ' [' . $occupant['full_name'] . ']';
        $ledger[] = ['date'=>$dateStr,'description'=>$desc,'type'=>'charge','debit'=>$charge,'credit'=>0];
        $iter->modify('+1 month');
    }
}
foreach ($payments as $p) {
    $desc = $p['payment_type']==='rent'
        ? 'Payment — '.date('F Y',mktime(0,0,0,(int)$p['period_month'],1,(int)$p['period_year']))
        : ($p['service_name']??'Service').' — '.date('F Y',mktime(0,0,0,(int)$p['period_month'],1,(int)$p['period_year']));
    $ledger[] = ['date'=>$p['payment_date'],'description'=>$desc,'type'=>'payment','debit'=>0,'credit'=>(float)$p['amount'],'invoice_no'=>$p['invoice_no']??'','cashier'=>$p['cashier_name']??''];
}
foreach ($pdfRefunds as $r) {
    $ledger[] = [
        'date'        => date('Y-m-d', strtotime($r['refunded_at'])),
        'description' => 'Refund — ' . ($r['payment_invoice'] ?? '') . ': ' . ($r['reason'] ?? ''),
        'type'        => 'refund',
        'debit'       => (float)$r['amount'],
        'credit'      => 0,
        'invoice_no'  => '',
        'cashier'     => $r['refunded_by_name'] ?? '',
    ];
}
foreach ($pdfServiceCharges as $c) {
    $period = date('F Y', mktime(0,0,0,(int)$c['period_month'],1,(int)$c['period_year']));
    $desc   = ($c['service_name'] ?? $c['description']) . ' — ' . $period;
    if ($c['payment_id'] === null) $desc .= ' (Unpaid)';
    $ledger[] = [
        'date'        => $c['charge_date'],
        'description' => $desc,
        'type'        => 'service_charge',
        'debit'       => (float)$c['amount'],
        'credit'      => 0,
        'invoice_no'  => '',
        'cashier'     => $c['billed_by_name'] ?? '',
    ];
}

usort($ledger, function($a,$b){
    $c = strcmp($a['date'],$b['date']);
    if ($c !== 0) return $c;
    $order = ['charge'=>0,'service_charge'=>1,'payment'=>2,'refund'=>3];
    return ($order[$a['type']]??2) - ($order[$b['type']]??2);
});
$runBal=0;
foreach($ledger as &$row){ $runBal+=$row['debit']-$row['credit']; $row['balance']=$runBal; }
unset($row);

$totalChargesDebit = array_sum(array_map(fn($r) => in_array($r['type'],['charge','service_charge']) ? $r['debit'] : 0, $ledger));
$totalRefunded     = (float)array_sum(array_column($pdfRefunds, 'amount'));
$totalDebit        = array_sum(array_column($ledger,'debit'));
$totalCredit       = array_sum(array_column($ledger,'credit'));
$finalBal          = $totalDebit - $totalCredit;

// ── Company Settings ──────────────────────────────────────────
$companyName    = getSetting($pdo,'company_name',   'Laskie Rental Properties');
$companyAddress = getSetting($pdo,'company_address', '');
$companyPhone   = getSetting($pdo,'company_phone',   '');
$companyEmail   = getSetting($pdo,'company_email',   '');

logActivity($pdo,'EXPORT_SOA_PDF','SOA',"Exported PDF SOA unit #$unitId ($dateFrom – $dateTo)");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SOA — <?=clean($unit['unit_name'])?> — <?=clean($companyName)?></title>
<link href="../assets/vendor/google-fonts.css" rel="stylesheet">
<style>
:root{--primary:#1a3a8f;--danger:#b91c1c;--success:#15803d;--info:#0369a1;--border:#d1d5db;--muted:#6b7280;--bg:#f3f4f8;}
*{box-sizing:border-box;margin:0;}
body{font-family:'DM Sans',sans-serif;font-size:12px;color:#111;background:#fff;padding:0;}

/* Screen wrapper */
.screen-wrap{max-width:780px;margin:0 auto;padding:24px;}

/* Toolbar */
.toolbar{display:flex;gap:10px;justify-content:flex-end;margin-bottom:16px;}
.btn{padding:7px 16px;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary);}
.btn-secondary{background:#fff;color:#111;border-color:var(--border);}

/* SOA Document */
.soa{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;}

/* Header */
.soa-header{background:var(--primary);color:#fff;padding:28px 32px;display:flex;justify-content:space-between;align-items:flex-start;gap:20px;}
.co-name{font-size:18px;font-weight:800;letter-spacing:-.3px;}
.co-sub{font-size:11px;opacity:.75;margin-top:4px;line-height:1.6;}
.soa-title-block{text-align:right;}
.soa-doc-label{font-size:9.5px;text-transform:uppercase;letter-spacing:.12em;opacity:.7;}
.soa-doc-title{font-size:22px;font-weight:800;margin-top:2px;}
.soa-doc-sub{font-size:11px;opacity:.8;margin-top:4px;}

/* Period band */
.soa-period{background:#f0f4ff;border-bottom:1px solid var(--border);padding:10px 32px;display:flex;gap:24px;flex-wrap:wrap;font-size:11.5px;}
.soa-period-item{display:flex;flex-direction:column;gap:2px;}
.soa-period-label{font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;}
.soa-period-value{font-weight:700;color:#111;}

/* Unit + Tenant */
.soa-parties{display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid var(--border);}
.soa-party{padding:18px 32px;}
.soa-party:first-child{border-right:1px solid var(--border);}
.party-label{font-size:9.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700;margin-bottom:8px;}
.party-kv{display:flex;gap:10px;margin-bottom:4px;font-size:11.5px;}
.party-k{color:var(--muted);min-width:90px;}
.party-v{font-weight:600;}

/* Ledger table */
.ledger-wrap{padding:0 32px 0;}
.ledger-title{padding:16px 0 10px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted);border-bottom:2px solid var(--primary);}
table.ledger{width:100%;border-collapse:collapse;}
table.ledger th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:8px 8px;border-bottom:1px solid var(--border);text-align:left;background:#f9fafb;}
table.ledger th.r{text-align:right;}
table.ledger td{padding:8px;border-bottom:1px solid #f3f4f6;font-size:11.5px;vertical-align:middle;}
table.ledger td.r{text-align:right;}
table.ledger tr.charge-row td{color:#374151;}
table.ledger tr.pay-row td{background:rgba(21,128,61,.04);}
table.ledger tr.refund-row td{background:rgba(185,28,28,.04);color:var(--danger);}
table.ledger tr.svc-charge-row td{background:rgba(217,119,6,.05);}
table.ledger tfoot td{padding:10px 8px;font-weight:700;border-top:2px solid var(--border);background:#f0f4ff;font-size:12px;}

/* Balance box */
.balance-section{padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);gap:20px;flex-wrap:wrap;}
.balance-breakdown{display:flex;gap:24px;}
.bal-item{display:flex;flex-direction:column;gap:3px;}
.bal-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;}
.bal-value{font-size:16px;font-weight:800;}
.balance-final{background:var(--primary);color:#fff;border-radius:10px;padding:16px 24px;text-align:center;min-width:180px;}
.balance-final .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.1em;opacity:.8;margin-bottom:4px;}
.balance-final .val{font-size:22px;font-weight:800;font-family:'DM Mono',monospace;}
.balance-final .status{font-size:10.5px;margin-top:4px;opacity:.85;}

/* Footer */
.soa-footer{background:#f9fafb;border-top:1px solid var(--border);padding:14px 32px;display:flex;justify-content:space-between;align-items:center;font-size:10.5px;color:var(--muted);}
.soa-footer strong{color:#111;}

/* Signature block */
.sig-section{padding:16px 32px 24px;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.sig-box{text-align:center;min-width:160px;}
.sig-line{border-top:1.5px solid var(--border);padding-top:8px;font-size:10.5px;color:var(--muted);margin-top:40px;}

/* Print */
@media print{
  .toolbar{display:none!important;}
  .screen-wrap{max-width:100%;padding:0;}
  .soa{border:none;border-radius:0;}
  .soa-header{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .balance-final{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .pay-row td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{background:#fff;}
}
@page{size:A4;margin:10mm 12mm;}
</style>
</head>
<body>
<div class="screen-wrap">

  <!-- Toolbar (hidden on print) -->
  <div class="toolbar no-print">
    <a href="history.php?unit_id=<?=$unitId?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>" class="btn btn-secondary">← Back to SOA</a>
    <button onclick="window.print()" class="btn btn-secondary">🖨 Print</button>
    <a href="soa_pdf_download.php?unit_id=<?=$unitId?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>" class="btn btn-primary">⬇ Download PDF</a>
  </div>

  <div class="soa">

    <!-- Header -->
    <div class="soa-header">
      <div>
        <div class="co-name"><?=clean($companyName)?></div>
        <div class="co-sub">
          <?=clean($companyAddress)?><?=$companyPhone?' · '.$companyPhone:''?><?=$companyEmail?' · '.$companyEmail:''?>
        </div>
      </div>
      <div class="soa-title-block">
        <div class="soa-doc-label">Official Document</div>
        <div class="soa-doc-title">Statement of Account</div>
        <div class="soa-doc-sub">Generated: <?=date('F j, Y')?></div>
      </div>
    </div>

    <!-- Period band -->
    <div class="soa-period">
      <div class="soa-period-item">
        <span class="soa-period-label">Period From</span>
        <span class="soa-period-value"><?=fmtDate($dateFrom,'F j, Y')?></span>
      </div>
      <div class="soa-period-item">
        <span class="soa-period-label">Period To</span>
        <span class="soa-period-value"><?=fmtDate($dateTo,'F j, Y')?></span>
      </div>
      <div class="soa-period-item">
        <span class="soa-period-label">Generated By</span>
        <span class="soa-period-value"><?=clean(currentUser()['full_name']??'—')?></span>
      </div>
      <div class="soa-period-item">
        <span class="soa-period-label">Date Generated</span>
        <span class="soa-period-value"><?=date('F j, Y \a\t g:i A')?></span>
      </div>
    </div>

    <!-- Unit + Tenant Parties -->
    <div class="soa-parties">
      <div class="soa-party">
        <div class="party-label">Rental Unit</div>
        <?php foreach([['Unit Name',$unit['unit_name']],['Unit Type',$unit['type_name']??'—'],['Monthly Rate',money($rate)],['Due Day',$unit['due_day'].'th of each month'],['Status',ucfirst($unit['status'])]] as [$k,$v]): ?>
        <div class="party-kv"><span class="party-k"><?=$k?></span><span class="party-v"><?=clean($v)?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="soa-party">
        <div class="party-label">Tenant Information</div>
        <?php if($tenant):
          foreach([['Name',$tenant['full_name']],['Phone',$tenant['phone']??'—'],['Email',$tenant['email']??'—'],['Contract Start',($tenant['contract_start']?fmtDate($tenant['contract_start'],'M j, Y'):'—')],['Contract End',($tenant['contract_end']?fmtDate($tenant['contract_end'],'M j, Y'):'Open')]] as [$k,$v]): ?>
          <div class="party-kv"><span class="party-k"><?=$k?></span><span class="party-v"><?=clean($v)?></span></div>
          <?php endforeach;
        else: ?>
          <div style="color:var(--muted);font-size:12px;margin-top:8px">No active tenant on record.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ledger -->
    <div class="ledger-wrap">
      <div class="ledger-title">Account Ledger</div>
      <table class="ledger">
        <thead>
          <tr>
            <th style="width:90px">Date</th>
            <th>Description</th>
            <th style="width:110px">Invoice / Ref</th>
            <th style="width:100px">Cashier</th>
            <th class="r" style="width:90px">Charges (Dr)</th>
            <th class="r" style="width:90px">Payments (Cr)</th>
            <th class="r" style="width:90px">Balance</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($ledger as $row): ?>
        <?php
          $rowClass = match($row['type']) {
              'charge'         => 'charge-row',
              'service_charge' => 'svc-charge-row',
              'refund'         => 'refund-row',
              default          => 'pay-row',
          };
        ?>
        <tr class="<?=$rowClass?>">
          <td><?=date('M j, Y',strtotime($row['date']))?></td>
          <td><?=clean($row['description'])?></td>
          <td class="mono" style="font-size:10.5px;color:var(--muted)"><?=clean($row['invoice_no']??'—')?></td>
          <td style="font-size:11px;color:var(--muted)"><?=clean($row['cashier']??'—')?></td>
          <td class="r" style="color:<?=$row['debit']>0?'var(--danger)':'var(--muted)'?>">
            <?=$row['debit']>0?money($row['debit']):'—'?>
          </td>
          <td class="r" style="color:<?=$row['credit']>0?'var(--success)':'var(--muted)'?>;font-weight:<?=$row['credit']>0?'600':'400'?>">
            <?=$row['credit']>0?money($row['credit']):'—'?>
          </td>
          <td class="r" style="font-weight:600;color:<?=$row['balance']>0?'var(--danger)':($row['balance']<0?'var(--info)':'var(--success)')?>">
            <?php if($row['balance']>0): echo money($row['balance']);
            elseif($row['balance']<0): echo '('.money(abs($row['balance'])).') CR';
            else: echo '—'; endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($ledger)): ?>
        <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">No transactions in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4">TOTALS</td>
            <td class="r" style="color:var(--danger)"><?=money($totalDebit)?></td>
            <td class="r" style="color:var(--success)"><?=money($totalCredit)?></td>
            <td class="r" style="color:<?=$finalBal>0?'var(--danger)':($finalBal<0?'var(--info)':'var(--success)')?>">
              <?php if($finalBal>0): echo money($finalBal).' DR';
              elseif($finalBal<0): echo '('.money(abs($finalBal)).') CR';
              else: echo 'BALANCED'; endif; ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Balance Summary -->
    <div class="balance-section">
      <div class="balance-breakdown">
        <div class="bal-item">
          <span class="bal-label">Total Charged</span>
          <span class="bal-value" style="color:var(--danger)"><?=money($totalChargesDebit)?></span>
        </div>
        <div class="bal-item">
          <span class="bal-label">Total Paid</span>
          <span class="bal-value" style="color:var(--success)"><?=money($totalCredit)?></span>
        </div>
        <?php if ($totalRefunded > 0): ?>
        <div class="bal-item">
          <span class="bal-label">Total Refunded</span>
          <span class="bal-value" style="color:var(--danger)"><?=money($totalRefunded)?></span>
        </div>
        <?php endif; ?>
      </div>
      <div class="balance-final">
        <div class="lbl">Outstanding Balance</div>
        <div class="val"><?=money(abs($finalBal))?></div>
        <div class="status">
          <?php if($finalBal>0): ?>Amount Due<?php
          elseif($finalBal<0): ?>Overpaid (Credit)<?php
          else: ?>Fully Settled ✓<?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Signature Block -->
    <div class="sig-section">
      <div class="sig-box">
        <div class="sig-line">Prepared by<br><strong><?=clean(currentUser()['full_name']??'—')?></strong></div>
      </div>
      <div class="sig-box">
        <div class="sig-line">Verified by<br><strong>Accountant / Admin</strong></div>
      </div>
      <div class="sig-box">
        <div class="sig-line">Acknowledged by<br><strong><?=clean($tenant['full_name']??'Tenant')?></strong></div>
      </div>
    </div>

    <!-- Document Footer -->
    <div class="soa-footer">
      <span>This is a computer-generated Statement of Account. &nbsp;·&nbsp; <strong><?=clean($companyName)?></strong></span>
      <span><?=clean(getSetting($pdo,'app_name','Laskie Rental PMS'))?> &nbsp;·&nbsp; <?=date('Y')?></span>
    </div>

  </div><!-- .soa -->
</div><!-- .screen-wrap -->

<script>
// Auto-trigger print on load if ?auto=1
if (new URLSearchParams(window.location.search).get('auto') === '1') {
  window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>
