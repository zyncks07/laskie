<?php
// includes/demand/letter.php — demand letter for unpaid rent (Caloocan City).
// Rendered by renderDemandLetterDocument() with $ctx (demandLetterContext(),
// frozen in demand_letters.snapshot_json), $refNo and $assetBase.
//
// Two forms, picked per letter:
//   pay    — "Demand to Pay": formal demand for payment, warns of barangay
//            conciliation and court action.
//   vacate — "Final Demand to Pay and Vacate": the demand to pay AND vacate
//            that Rule 70 §2 requires before an unlawful detainer case, and
//            that the Lupon will ask to see at barangay conciliation.
// Standard Metro Manila form; not legal advice — have counsel review before
// relying on it in a case. Every value goes through clean().

$isVacate = $ctx['type'] === 'vacate';
$fmt      = fn(string $d) => date('F j, Y', strtotime($d));
$short    = fn(string $d) => date('M j, Y', strtotime($d));
$days     = (int)$ctx['days'];
$daysTxt  = strtoupper(numberToWords($days)) . " ($days) DAYS";
$brgy     = trim((string)$ctx['barangay_no']);
$brgyTxt  = $brgy !== '' ? 'Barangay ' . clean($brgy) . ', Caloocan City' : 'the barangay where the Unit is located, Caloocan City';
$unitAddr = 'Unit No. ' . clean($ctx['unit_name'])
          . (trim((string)$ctx['building_address']) !== '' ? ', ' . clean($ctx['building_address']) : '')
          . ($brgy !== '' ? ', Barangay ' . clean($brgy) : '')
          . ', Caloocan City, Metro Manila';
$contact  = array_filter([trim((string)$ctx['lessor_mobile']), trim((string)$ctx['lessor_email'])]);
$cssStr   = fn(string $s) => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= clean($ctx['title'] . ' — ' . $ctx['tenant_name'] . ' — ' . $refNo) ?></title>
<link href="<?= clean($assetBase) ?>google-fonts.css" rel="stylesheet">
<style>
:root{ --ink:#111; --ink2:#3a3a3a; --mute:#6b6b6b; --rule:#b9b9b9;
  --serif:"Liberation Serif","Times New Roman",Times,Georgia,serif; --sans:"DM Sans","Liberation Sans",Arial,sans-serif; }
@page{ size:A4; margin:20mm 21mm 22mm 21mm;
  @bottom-left  { content: <?= $cssStr('Ref. No. ' . $refNo) ?>; font:7.5pt var(--sans); color:#6b6b6b; }
  @bottom-right { content: "Page " counter(page) " of " counter(pages); font:7.5pt var(--sans); color:#6b6b6b; } }
*{ box-sizing:border-box; }
body{ margin:0; font-family:var(--serif); font-size:11.5pt; line-height:1.5; color:var(--ink); background:#fff; }
p{ margin:0 0 9pt; text-align:justify; orphans:3; widows:3; }
.head{ text-align:center; border-bottom:2px solid var(--ink); padding-bottom:8pt; margin-bottom:16pt; }
.head .name{ font:700 13pt var(--sans); letter-spacing:.14em; text-transform:uppercase; }
.head .sub{ font:9pt var(--sans); color:var(--ink2); margin-top:2pt; }
.meta{ display:flex; justify-content:space-between; align-items:flex-start; gap:16pt; margin-bottom:14pt; }
.meta .date{ font-size:11.5pt; }
.meta .ref{ font:9pt var(--sans); color:var(--ink2); text-align:right; line-height:1.6; }
.service{ font:8.5pt var(--sans); letter-spacing:.06em; text-transform:uppercase; color:var(--ink2); margin-bottom:12pt; }
.service span{ margin-right:14pt; white-space:nowrap; }
.service .box{ display:inline-block; width:9pt; height:9pt; border:1px solid var(--ink); vertical-align:-1pt; margin-right:4pt; }
.addressee{ margin-bottom:14pt; line-height:1.4; }
.addressee .to{ font-weight:700; text-transform:uppercase; }
.re{ margin:0 0 14pt; font-weight:700; }
.re .lbl{ display:inline-block; width:34pt; }
.re u{ text-decoration-thickness:1px; text-underline-offset:2pt; }
table{ width:100%; border-collapse:collapse; margin:4pt 0 12pt; font-size:10.5pt; break-inside:avoid; }
th{ font:600 7.5pt var(--sans); letter-spacing:.1em; text-transform:uppercase; color:var(--ink2); text-align:left;
    border-bottom:1.2px solid var(--ink); padding:4pt 6pt; }
td{ padding:4pt 6pt; border-bottom:.6px solid var(--rule); vertical-align:top; }
.r{ text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
tfoot td{ font-weight:700; border-top:1.2px solid var(--ink); border-bottom:3px double var(--ink); }
.demand{ border-left:3px solid var(--ink); padding:2pt 0 2pt 10pt; margin:12pt 0; }
ol.acts{ margin:4pt 0 0 0; padding-left:18pt; }
.sign{ margin-top:22pt; break-inside:avoid; }
.sign .line{ width:62mm; border-bottom:.9pt solid var(--ink); height:16mm; }
.sign .who{ font-weight:700; text-transform:uppercase; margin-top:3pt; }
.received{ margin-top:24pt; border:1px solid var(--ink); padding:10pt 12pt; break-inside:avoid; font:9.5pt var(--sans); }
.received .t{ font-weight:700; letter-spacing:.12em; text-transform:uppercase; font-size:8.5pt; margin-bottom:10pt; }
.received .grid{ display:grid; grid-template-columns:1.4fr 1fr; gap:14pt; }
.received .f{ border-bottom:.8pt solid var(--ink); height:12mm; }
.received .c{ font-size:8pt; color:var(--mute); margin-top:3pt; }
.small{ font-size:9.5pt; color:var(--ink2); }
</style>
</head>
<body>

<div class="head">
  <div class="name"><?= clean($ctx['lessor_name']) ?></div>
  <div class="sub"><?= clean($ctx['lessor_address']) ?><?= $ctx['lessor_address'] && stripos($ctx['lessor_address'], 'caloocan') === false ? ', Caloocan City' : '' ?><?= $contact ? ' · ' . clean(implode(' · ', $contact)) : '' ?></div>
</div>

<div class="meta">
  <div class="date"><?= clean($fmt($ctx['issued'])) ?><br>Caloocan City, Metro Manila</div>
  <div class="ref">Ref. No. <strong><?= clean($refNo) ?></strong><br><?= clean($ctx['title']) ?></div>
</div>

<div class="service">
  <span><i class="box"></i>Personal service</span>
  <span><i class="box"></i>Registered mail</span>
  <span><i class="box"></i>Electronic mail<?= $ctx['tenant_email'] !== '' ? ' (' . clean($ctx['tenant_email']) . ')' : '' ?></span>
</div>

<div class="addressee">
  <div class="to"><?= clean($ctx['tenant_name']) ?></div>
  <div>Lessee, <?= $unitAddr ?></div>
</div>

<p class="re"><span class="lbl">Re:</span><u><?= $isVacate ? 'FINAL DEMAND TO PAY AND VACATE' : 'DEMAND TO PAY UNPAID RENT' ?></u> — Unit No. <?= clean($ctx['unit_name']) ?></p>

<p>Dear <?= clean($ctx['tenant_name']) ?>:</p>

<p>We write on behalf of the Lessor of <?= $unitAddr ?> (the &ldquo;Unit&rdquo;), which you occupy as lessee<?= $ctx['contract_start'] ? ' since ' . clean($fmt($ctx['contract_start'])) : '' ?>. Under your lease, rent of <strong><?= clean(money($ctx['rate'])) ?></strong> a month is payable after each monthly billing period, on or before the <?= clean($ctx['due_day']) ?> day of the following month.</p>

<p>Our records show that, as of the date of this letter, the following amounts have fallen due and remain unpaid:</p>

<table>
  <thead><tr><th>Billing period</th><th>Due date</th><th class="r">Billed</th><th class="r">Unpaid</th></tr></thead>
  <tbody>
  <?php foreach ($ctx['bills'] as $b): ?>
    <tr>
      <td><?= !empty($b['description']) ? clean($b['description'])
                : 'Rent — ' . clean($short($b['cycle_from'])) . ' to ' . clean($short($b['cycle_to'])) ?></td>
      <td><?= clean($short($b['due_date'])) ?></td>
      <td class="r"><?= clean(money($b['amount'])) ?></td>
      <td class="r"><?= clean(money($b['unpaid'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php foreach ($ctx['charges'] as $c): ?>
    <tr>
      <td><?= clean($c['description']) ?></td>
      <td><?= clean($short($c['date'])) ?></td>
      <td class="r"></td>
      <td class="r"><?= clean(money($c['unpaid'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="3">Total amount due</td><td class="r"><?= clean(money($ctx['total'])) ?></td></tr></tfoot>
</table>

<?php if (!$isVacate): ?>
<div class="demand">
  <p>In view of the foregoing, <strong>FORMAL DEMAND</strong> is hereby made upon you to pay the total amount of <strong><?= clean(money($ctx['total'])) ?></strong> within <strong><?= $daysTxt ?></strong> from your receipt of this letter<?= trim((string)$ctx['payment_methods']) !== '' ? ', through ' . clean($ctx['payment_methods']) . ',' : '' ?> or personally at the Admin office, upon which an official receipt will be issued to you.</p>
</div>
<p>Please be reminded that non-payment of rent is a violation of your lease and a ground for its termination and for judicial ejectment under Article 1673 of the Civil Code of the Philippines. Should you fail to pay within the period stated, we shall be constrained to take the appropriate legal action to protect the Lessor&rsquo;s rights, beginning with a complaint before the Lupong Tagapamayapa of <?= $brgyTxt ?>, under the Katarungang Pambarangay provisions of the Local Government Code (Republic Act No. 7160, Sections 399&ndash;422), and thereafter the filing of the proper case in court, without further notice.</p>
<?php else: ?>
<div class="demand">
  <p>In view of the foregoing, <strong>FINAL DEMAND</strong> is hereby made upon you, within <strong><?= $daysTxt ?></strong> from your receipt of this letter, to:</p>
  <ol class="acts">
    <li><strong>PAY</strong> the total amount of <strong><?= clean(money($ctx['total'])) ?></strong><?= trim((string)$ctx['payment_methods']) !== '' ? ' through ' . clean($ctx['payment_methods']) . ' or personally at the Admin office' : ' at the Admin office' ?>; and</li>
    <li><strong>VACATE</strong> the Unit and peacefully surrender its possession to the Lessor, removing all your belongings and leaving the Unit in the condition required by your lease.</li>
  </ol>
</div>
<p>This demand is made pursuant to Article 1673 of the Civil Code of the Philippines<?= ($ctx['rent_controlled'] && (float)$ctx['months_behind'] >= 3) ? ', Section 9 of Republic Act No. 9653 (the Rent Control Act of 2009),' : '' ?> and Section 2, Rule 70 of the Rules of Court. Should you fail to comply within the period stated, the Lessor shall, without further notice, file a complaint before the Lupong Tagapamayapa of <?= $brgyTxt ?>, as required by the Katarungang Pambarangay provisions of the Local Government Code (Republic Act No. 7160), and thereafter an action for unlawful detainer before the Metropolitan Trial Court of Caloocan City, to recover possession of the Unit, the unpaid rent, reasonable compensation for your continued use and occupancy of the Unit, attorney&rsquo;s fees, and costs of suit.</p>
<?php endif; ?>

<p>Rent and charges falling due after the date of this letter remain payable on their respective due dates and are not waived by this demand. If you have already settled the amounts above, kindly disregard this letter and present your official receipt(s) to the Admin office so that our records may be updated<?= $contact ? '. For any concern, you may contact us at ' . clean(implode(' or ', $contact)) : '' ?>.</p>

<p>We trust that you will give this matter your immediate attention.</p>

<div class="sign">
  <p style="margin-bottom:0">Very truly yours,</p>
  <div class="line"></div>
  <div class="who"><?= clean($ctx['lessor_name']) ?></div>
  <div class="small">Lessor / Authorized Representative</div>
</div>

<div class="received">
  <div class="t">Acknowledgment of receipt</div>
  <div class="grid">
    <div><div class="f"></div><div class="c">Signature over printed name of Lessee / person who received this letter</div></div>
    <div><div class="f"></div><div class="c">Date and time received</div></div>
  </div>
  <div class="c" style="margin-top:8pt">If received by someone other than the Lessee, state relationship to the Lessee: ______________________</div>
</div>

</body>
</html>
