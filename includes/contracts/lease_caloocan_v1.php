<?php
// includes/contracts/lease_caloocan_v1.php
// Contract of Lease — Caloocan City, template version 1.
// Source draft: includes/contracts/lease-caloocan-v1.md (reviewed 2026-10-04).
//
// Rendered by renderContractBody() with:
//   $v    normalised fields (CONTRACT_FIELDS keys + computed money)
//   $meta ['contract_no','status','company_name','template_version']
//
// Everything outside $v is FIXED contract text. The non-negotiables (no pets,
// no modifications, no sublease, visitor rule, two-month notice, deposit
// applied to the last two months, ₱2,000 / ₱1,000 utility deposits) are
// hard-coded on purpose and are not editable fields.
//
// A blank field renders as a fill-in line so a draft can still be printed and
// completed by hand. Every value goes through clean().

// $x('key') → escaped value, or a blank fill-in line.
$x = function (string $k, string $blankWidth = '9em') use ($v): string {
    $val = trim((string)($v[$k] ?? ''));
    if ($val === '') return '<span class="fill" style="min-width:' . $blankWidth . '" data-field="' . clean($k) . '"></span>';
    return '<span class="val" data-field="' . clean($k) . '">' . clean($val) . '</span>';
};
// $sig('key') → printed name under a signature line; blank → muted cue
// instead of a second rule beneath the signature line.
$sig = function (string $k) use ($v): string {
    $val = trim((string)($v[$k] ?? ''));
    if ($val === '') return '<span class="sig-ph" data-field="' . clean($k) . '">Printed name</span>';
    return '<span class="val" data-field="' . clean($k) . '">' . clean($val) . '</span>';
};
// $m('key') → peso amount via money(), or a blank.
$m = function (string $k) use ($v): string {
    $val = (string)($v[$k] ?? '');
    if ($val === '') return '<span class="fill" style="min-width:6em" data-field="' . clean($k) . '"></span>';
    return '<span class="val amt" data-field="' . clean($k) . '">' . clean(money($val)) . '</span>';
};
// $d('key') → "October 5, 2026", or a blank.
$d = function (string $k) use ($v): string {
    $val = (string)($v[$k] ?? '');
    if (!contractIsoDate($val)) return '<span class="fill" style="min-width:8em" data-field="' . clean($k) . '"></span>';
    return '<span class="val" data-field="' . clean($k) . '">' . clean(date('F j, Y', strtotime($val))) . '</span>';
};

$signDate  = contractIsoDate($v['contract_date'] ?? '') ? strtotime($v['contract_date']) : null;
$signDay   = $signDate ? '<span class="val" data-field="contract_date">' . ordinalDay((int)date('j', $signDate)) . '</span>' : '<span class="fill" style="min-width:3em"></span>';
$signMonth = $signDate ? '<span class="val" data-field="contract_date">' . date('F', $signDate) . '</span>' : '<span class="fill" style="min-width:6em"></span>';
$signYear  = $signDate ? '<span class="val" data-field="contract_date">' . date('Y', $signDate) . '</span>' : '<span class="fill" style="min-width:3em"></span>';

$civil     = trim((string)($v['lessor_civil_status'] ?? ''));
$repClause = trim((string)($v['lessor_rep_clause'] ?? ''));
if ($repClause !== '' && !str_starts_with($repClause, ',')) $repClause = ', ' . $repClause;

$util      = CONTRACT_UTILITY_DEPOSITS;
$utilTotal = money_sum(array_values($util));
?>
<header class="doc-head">
  <div class="doc-head-top">
    <div class="doc-company"><?= clean($meta['company_name'] ?? '') ?></div>
    <div class="doc-ref">
      <?php if (!empty($meta['contract_no'])): ?>
        <div><span class="lbl">Contract No.</span> <span class="mono"><?= clean($meta['contract_no']) ?></span></div>
      <?php else: ?>
        <div><span class="lbl">Contract No.</span> <span class="mono">— draft —</span></div>
      <?php endif; ?>
      <div><span class="lbl">Unit</span> <?= $x('unit_no', '4em') ?></div>
    </div>
  </div>
  <h1 class="doc-title">Contract of Lease</h1>
  <div class="doc-sub">Residential Unit &middot; Caloocan City, Metro Manila</div>
</header>

<p class="lead"><strong>KNOW ALL MEN BY THESE PRESENTS:</strong></p>

<p>This Contract of Lease is made and entered into this <?= $signDay ?> day of <?= $signMonth ?>, <?= $signYear ?>, in Caloocan City, Metro Manila, Philippines, by and between:</p>

<p class="party"><strong class="name"><?= $x('lessor_name', '14em') ?></strong>, of legal age, Filipino<?= $civil !== '' ? ', <span class="val" data-field="lessor_civil_status">' . clean($civil) . '</span>' : '' ?>, with address at <?= $x('lessor_address', '16em') ?>, Caloocan City<?= $repClause !== '' ? '<span class="val" data-field="lessor_rep_clause">' . clean($repClause) . '</span>' : '' ?>, hereinafter called the <strong>&ldquo;LESSOR&rdquo;</strong>;</p>

<p class="and">&mdash; and &mdash;</p>

<p class="party"><strong class="name"><?= $x('lessee_name', '14em') ?></strong>, of legal age, <?= $x('lessee_nationality', '5em') ?>, <?= $x('lessee_civil_status', '5em') ?>, with present address at <?= $x('lessee_address', '16em') ?> and valid ID <?= $x('lessee_id', '10em') ?>, hereinafter called the <strong>&ldquo;LESSEE&rdquo;</strong>.</p>

<p>The LESSOR and LESSEE are each a &ldquo;Party&rdquo; and together the &ldquo;Parties.&rdquo; Where this Contract refers to &ldquo;Admin&rdquo; or &ldquo;Management,&rdquo; it means the LESSOR or the building administrator the LESSOR names in writing.</p>

<p class="lead"><strong>WITNESSETH:</strong></p>

<ol class="clauses">
  <li>The LESSOR is the registered owner / lawful administrator of the residential building located at <?= $x('building_address', '14em') ?>, Barangay <?= $x('barangay_no', '3em') ?>, <?= $x('caloocan_side', '4em') ?> Caloocan, Caloocan City, Metro Manila, covered by TCT / Tax Declaration No. <?= $x('title_ref', '8em') ?>.</li>
  <li>The LESSOR agrees to lease to the LESSEE, and the LESSEE agrees to lease from the LESSOR, <strong>Unit No. <?= $x('unit_no', '4em') ?></strong> of said building (the &ldquo;Unit&rdquo;), with a floor area of approximately <?= $x('floor_area_sqm', '3em') ?> sq. m., consisting of <?= $x('bedrooms', '2em') ?> bedroom(s), <?= $x('toilet_baths', '2em') ?> toilet and bath, and the fixtures and furnishings listed in the <strong>Inventory and Condition Report (Annex &ldquo;A&rdquo;)</strong>.</li>
  <li>The Unit shall be used <strong>solely as a private residence</strong> of the LESSEE and the persons listed in <strong>Annex &ldquo;B&rdquo; (Authorized Occupants)</strong>, not exceeding <?= $x('max_occupants', '2em') ?> persons in total.</li>
</ol>

<p>NOW, THEREFORE, the Parties agree to the following terms and conditions:</p>

<h2>I. Term, Rent and Deposits</h2>

<p class="sec"><span class="sec-t">Section 1. Term.</span> This lease is for a period of <strong><?= $x('term_words', '8em') ?></strong>, starting <strong><?= $d('start_date') ?></strong> and ending <strong><?= $d('end_date') ?></strong>. It may be renewed only by a new written contract or a written renewal signed by both Parties at least thirty (30) days before expiry. If the LESSEE stays on after expiry without a written renewal and the LESSOR accepts rent, the lease continues on a month-to-month basis (Arts. 1670 and 1687, Civil Code) under the same terms, including the two-month notice rule in Section 18.</p>

<p class="sec"><span class="sec-t">Section 2. Monthly Rent.</span> The monthly rent is <strong><?= $x('monthly_rent_words', '12em') ?> PESOS (<?= $m('monthly_rent') ?>)</strong>, payable in advance on or before the <strong><?= $x('rent_due_day', '3em') ?></strong> day of each month, without need of demand, through <?= $x('payment_methods', '16em') ?>. The LESSOR shall issue an official receipt for every payment.</p>

<p class="sec"><span class="sec-t">Section 3. Late Payment.</span> Rent not paid within five (5) days after its due date earns a penalty of <?= $x('late_penalty_pct', '7em') ?> of the unpaid amount per month of delay. Dishonored checks are treated as non-payment and earn a <?= $m('bounced_check_fee') ?> handling fee, without prejudice to remedies under Batas Pambansa Blg. 22.</p>

<p class="sec"><span class="sec-t">Section 4. Rent Increase.</span> If the Unit&rsquo;s monthly rent is within the coverage of the Rent Control Act and its extending resolutions (currently <?= clean(money(CONTRACT_RENT_CONTROL_CAP)) ?> or less), the rent may be increased only once every twelve (12) months, and only up to the cap set by the National Human Settlements Board / DHSUD for that year, while the same LESSEE occupies the Unit. If the Unit is not covered, any increase takes effect only upon renewal and as agreed in writing.</p>

<p class="sec"><span class="sec-t">Section 5. Advance Rent.</span> Upon signing, the LESSEE pays <strong>one (1) month advance rent of <?= $m('advance_rent') ?></strong>, which is applied to the first month of the lease.</p>

<p class="sec"><span class="sec-t">Section 6. Security Deposit.</span> Upon signing, the LESSEE pays a <strong>security deposit equal to two (2) months&rsquo; rent, or <?= $m('security_deposit') ?></strong>, under these conditions:</p>
<ol class="sub">
  <li><strong>Application to last two months.</strong> The security deposit shall be applied as payment of rent for the <strong>last two (2) months</strong> of the lease. These two months coincide with the two-month notice period in Section 18. The LESSEE shall not pay rent in cash for those two months.</li>
  <li><strong>Conditions for application.</strong> The deposit is applied to the last two months only if (a) the LESSEE gave written notice to vacate at least two (2) months in advance, (b) the LESSEE has no unpaid rent, penalties or charges at the start of the notice period, and (c) the LESSEE actually vacates at the end of the notice period.</li>
  <li><strong>Early departure or no notice.</strong> If the LESSEE leaves before the end of the term or without the full two-month notice, the deposit shall first answer for unpaid rent, penalties, utilities and the cost of repairs, and the balance shall be forfeited in favor of the LESSOR as liquidated damages for the unserved notice period, without prejudice to the LESSOR&rsquo;s right to collect any remaining amount.</li>
  <li><strong>Rent adjustment.</strong> If the rent is lawfully increased, the LESSEE shall top up the deposit so that it always equals two (2) months of the current rent.</li>
  <li><strong>Bank and interest.</strong> Where the Rent Control Act applies, the LESSOR shall keep the deposit in a bank under the LESSOR&rsquo;s name, and any interest earned shall be returned to the LESSEE at the end of the lease.</li>
  <li><strong>Repairs and damage are separate.</strong> Because the deposit is used up as rent, the LESSEE shall pay in full, before turnover of the keys, the cost of any damage, missing items or restoration found at the move-out inspection under Section 19.</li>
</ol>

<div class="keep">
<p class="sec"><span class="sec-t">Section 7. Utility Deposits.</span> Upon signing, the LESSEE pays a <strong>utility deposit of <?= clean(money($utilTotal)) ?></strong>, consisting of:</p>
<table class="tbl tbl-narrow">
  <thead><tr><th>Utility</th><th class="r">Deposit</th></tr></thead>
  <tbody>
  <?php foreach ($util as $name => $amt): ?>
    <tr><td><?= clean($name) ?></td><td class="r amt"><?= clean(money($amt)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p>The utility deposit answers only for electricity and water bills (including the LESSOR&rsquo;s sub-meter billing, if any) left unpaid when the LESSEE moves out. It may not be applied to rent. Within thirty (30) days after the final bills covering the LESSEE&rsquo;s stay are issued, the LESSOR shall return the balance, net of those bills, with a written breakdown. Any shortfall shall be paid by the LESSEE within seven (7) days of the breakdown.</p>

<div class="keep">
<p class="sec"><span class="sec-t">Section 8. Summary of Move-in Payments.</span></p>
<table class="tbl tbl-narrow">
  <thead><tr><th>Item</th><th class="r">Amount</th></tr></thead>
  <tbody>
    <tr><td>Advance rent (1 month)</td><td class="r"><?= $m('advance_rent') ?></td></tr>
    <tr><td>Security deposit (2 months)</td><td class="r"><?= $m('security_deposit') ?></td></tr>
    <?php foreach ($util as $name => $amt): ?>
    <tr><td><?= clean($name) ?> deposit</td><td class="r amt"><?= clean(money($amt)) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td>Total due on signing</td><td class="r"><?= $m('move_in_total') ?></td></tr></tfoot>
</table>
</div>

<h2>II. Use of the Unit and House Rules</h2>

<p class="sec"><span class="sec-t">Section 9. Residential Use Only.</span> The Unit shall be used only as a dwelling. No business, store, sari-sari store, online-selling warehouse, boarding or bedspace operation, or any activity requiring a business permit shall be conducted in the Unit without the LESSOR&rsquo;s prior written consent and the required permits from the City Government of Caloocan.</p>

<p class="sec"><span class="sec-t">Section 10. No Pets.</span> <strong>No pets or animals of any kind</strong> (including dogs, cats, birds, fowl, reptiles, fish tanks, or livestock) may be kept, fed or housed in the Unit or on the premises, even temporarily. On first violation, the LESSEE shall remove the animal within forty-eight (48) hours of written notice and pay for cleaning, sanitizing and any damage. A second violation is a ground for termination.</p>

<p class="sec"><span class="sec-t">Section 11. No Modifications.</span> <strong>All modifications of the Unit are prohibited.</strong> Without limitation, the LESSEE shall not:</p>
<ol class="sub">
  <li>Build, demolish, or move walls, partitions, ceilings, doors, windows or grills;</li>
  <li>Repaint, wallpaper, tile, or change floor or wall finishes;</li>
  <li>Drill, nail or bolt into walls, ceilings or floors, or install shelves, cabinets, or wall-mounted fixtures;</li>
  <li>Alter electrical wiring, outlets, breakers, plumbing, or install additional meters;</li>
  <li>Install air-conditioning units, water heaters, satellite dishes, antennas, or CCTV that require cutting or mounting;</li>
  <li>Replace or add locks, or change the door lock cylinder.</li>
</ol>
<p>Any change made in violation of this Section shall, at the LESSOR&rsquo;s option, either be removed by the LESSEE at the LESSEE&rsquo;s cost with the Unit restored to its original condition, or be kept by the LESSOR as part of the Unit without any reimbursement to the LESSEE. Ordinary freestanding furniture and appliances that do not require installation are allowed.</p>

<p class="sec"><span class="sec-t">Section 12. No Sublease or Assignment.</span> The LESSEE shall <strong>not sublease, sublet, assign, transfer, lend, or allow any other person to occupy</strong> the Unit or any part of it, with or without payment, including through short-term rental platforms. This is an express prohibition under Art. 1650 of the Civil Code. Any sublease or assignment is void as against the LESSOR and is a ground for immediate termination and ejectment under Section 9 of the Rent Control Act.</p>

<p class="sec"><span class="sec-t">Section 13. Authorized Occupants and Visitors.</span></p>
<ol class="sub">
  <li>Only the LESSEE and the Authorized Occupants listed in Annex &ldquo;B&rdquo; may reside in the Unit. Any additional occupant requires the prior written approval of the Admin/Management and an amended Annex &ldquo;B&rdquo;.</li>
  <li>Visitors are allowed, but <strong>no visitor may stay in the Unit for more than one (1) month</strong>, whether continuously or cumulatively within any three (3)-month period, <strong>unless approved in writing by the Admin/Management</strong>.</li>
  <li>Overnight visitors shall be registered with the Admin/Management with their name and expected length of stay.</li>
  <li>The LESSEE is responsible for the conduct of all occupants and visitors and for any damage they cause.</li>
</ol>

<p class="sec"><span class="sec-t">Section 14. House Rules and Ordinances.</span> The LESSEE, the occupants and visitors shall:</p>
<ol class="sub">
  <li>Observe the building rules in <strong>Annex &ldquo;C&rdquo;</strong> and any reasonable rules the LESSOR issues in writing;</li>
  <li>Comply with the ordinances of Caloocan City and the rules of Barangay <?= $x('barangay_no', '3em') ?>, including those on curfew, noise, smoking in public places, and anti-littering;</li>
  <li>Segregate garbage into biodegradable, recyclable and residual waste and follow the barangay collection schedule, as required by the Ecological Solid Waste Management Act (RA 9003);</li>
  <li>Keep noise down between 10:00 PM and 6:00 AM and avoid any nuisance to neighbors;</li>
  <li>Not store firearms, explosives, fireworks, LPG beyond one (1) household tank, or other flammable or hazardous materials, consistent with the Fire Code of the Philippines (RA 9514);</li>
  <li>Not use or allow the Unit to be used for any illegal or immoral purpose, including illegal drugs or gambling.</li>
</ol>

<p class="sec"><span class="sec-t">Section 15. Common Areas.</span> Common areas (hallways, stairs, rooftop, laundry area) shall be kept clear of personal belongings, and shall not be used for storage, cooking, or drying clothes except in designated areas.</p>

<h2>III. Utilities, Repairs, Notice and Move-out</h2>

<p class="sec"><span class="sec-t">Section 16. Utilities and Other Charges.</span> Electricity, water, internet, cable, LPG and similar charges for the Unit are paid by the LESSEE, on time, directly to Meralco, Maynilad or the provider, or to the LESSOR under its sub-meter billing within five (5) days of the billing notice. The LESSOR may have a service disconnected through the provider for bills more than thirty (30) days overdue, after written notice. Association dues, if any, are for the account of <?= $x('association_dues_payer', '6em') ?>. Real property tax on the building is for the account of the LESSOR.</p>

<p class="sec"><span class="sec-t">Section 17. Repairs, Maintenance and Entry.</span></p>
<ol class="sub">
  <li><strong>LESSOR&rsquo;s repairs.</strong> The LESSOR shall make the necessary repairs to keep the Unit fit for residential use, such as structural defects, roof leaks, and main plumbing and electrical lines, unless the damage was caused by the LESSEE, occupants or visitors (Art. 1654, Civil Code).</li>
  <li><strong>LESSEE&rsquo;s upkeep.</strong> The LESSEE shall keep the Unit clean and sanitary and shall pay for minor repairs from daily use, such as busted bulbs, clogged sinks and toilets, broken faucets, door knobs, screens, and keys, and for any damage caused by the LESSEE, occupants or visitors.</li>
  <li><strong>Duty to report.</strong> The LESSEE shall report any defect or needed repair to the Admin/Management in writing (text or chat is acceptable) as soon as it is discovered. The LESSEE is liable for further damage caused by failure to report (Art. 1663, Civil Code).</li>
  <li><strong>No self-repair of structure or lines.</strong> The LESSEE shall not repair the LESSOR&rsquo;s structural, electrical or plumbing items without written approval; unauthorized repairs are not reimbursable.</li>
  <li><strong>Entry.</strong> The LESSOR or Admin may enter the Unit at reasonable hours, with at least twenty-four (24) hours&rsquo; prior notice, to inspect, make repairs, or show the Unit to prospective tenants during the last two (2) months of the lease. In an emergency (fire, flooding, gas leak, electrical hazard), the LESSOR may enter without prior notice, in the presence of a barangay official or a witness where possible.</li>
</ol>

<p class="sec"><span class="sec-t">Section 18. Two-Month Notice to Vacate.</span></p>
<ol class="sub">
  <li>The LESSEE shall give the LESSOR <strong>written notice of intent to vacate at least two (2) months before the move-out date</strong>, whether at the end of the term or under a month-to-month extension. Notice may be served personally with acknowledgment, or by email or messaging app to the LESSOR&rsquo;s address in Section 26.</li>
  <li>The two-month notice period is paid for by the security deposit under Section 6.</li>
  <li>If the LESSEE vacates without giving the full two-month notice, Section 6(3) applies.</li>
  <li>If the LESSOR does not intend to renew at the end of the term, the LESSOR shall likewise notify the LESSEE in writing at least two (2) months before expiry.</li>
</ol>

<p class="sec"><span class="sec-t">Section 19. Move-out Condition and Inspection.</span></p>
<ol class="sub">
  <li><strong>Original condition.</strong> On move-out, the LESSEE shall return the Unit <strong>in the same condition as described in Annex &ldquo;A&rdquo;</strong>, clean, with all fixtures, furnishings and keys complete, except for deterioration from ordinary wear and tear and the passage of time (Arts. 1665 and 1667, Civil Code). Holes, stains, paint damage, broken fixtures and any unauthorized modification are not ordinary wear and tear.</li>
  <li><strong>Pre-move-out inspection.</strong> Within seven (7) days after receiving the notice to vacate, the Parties shall jointly inspect the Unit against Annex &ldquo;A&rdquo; and list the repairs or replacements needed, with estimated costs.</li>
  <li><strong>Final inspection and turnover.</strong> On the move-out date, the Parties shall do a final walkthrough and sign a Turnover Acknowledgment. The LESSEE shall pay the cost of any listed repairs, cleaning or missing items before the keys are surrendered, or the LESSOR may have the work done and bill the LESSEE, who shall pay within seven (7) days.</li>
  <li><strong>Final readings.</strong> Electricity and water meter readings shall be recorded on the move-out date and applied against the utility deposit under Section 7.</li>
  <li><strong>Items left behind.</strong> Belongings left in the Unit after turnover shall be listed in the presence of a witness and kept for fifteen (15) days, during which the LESSEE may claim them after paying storage costs, if any. Unclaimed items after that period are deemed abandoned and may be disposed of by the LESSOR.</li>
  <li><strong>Holding over.</strong> If the LESSEE stays beyond the move-out date stated in the notice without the LESSOR&rsquo;s written consent, the LESSEE shall pay a daily use fee equal to one-fifteenth (1/15) of the monthly rent until the Unit is surrendered, without prejudice to an ejectment action.</li>
</ol>

<h2>IV. Default, Termination and Disputes</h2>

<p class="sec"><span class="sec-t">Section 20. Risk and Liability.</span> The LESSOR is not liable for loss of or damage to the LESSEE&rsquo;s belongings from theft, fire, flood, or other causes not due to the LESSOR&rsquo;s fault. The LESSEE is encouraged to secure personal-property insurance. The LESSEE shall be liable for any fire or damage to the building caused by the negligence of the LESSEE, occupants or visitors.</p>

<p class="sec"><span class="sec-t">Section 21. Grounds for Termination.</span> The LESSOR may terminate this lease and recover possession of the Unit on any of the following grounds, consistent with Art. 1673 of the Civil Code and Section 9 of the Rent Control Act:</p>
<ol class="sub">
  <li>Rent arrears equivalent to <strong>three (3) months</strong>, or repeated late payment after written warning;</li>
  <li>Subleasing, assignment, or allowing unauthorized occupants, in violation of Sections 12 and 13;</li>
  <li>Keeping pets after a first written warning, in violation of Section 10;</li>
  <li>Unauthorized modification of the Unit not restored within fifteen (15) days of written notice, in violation of Section 11;</li>
  <li>Use of the Unit for business or for any illegal purpose, in violation of Sections 9 and 14;</li>
  <li>Legitimate need of the LESSOR to repossess the Unit for the LESSOR&rsquo;s own use or that of an immediate family member, upon expiration of the lease term and with at least three (3) months&rsquo; written notice, where the Unit is covered by the Rent Control Act;</li>
  <li>Need for necessary repairs that make the Unit unsafe to occupy, or a condemnation or demolition order from the City Building Official, subject to the LESSEE&rsquo;s preferential right to re-lease after repairs where the law grants it;</li>
  <li>Expiration of the lease term without written renewal;</li>
  <li>Any other substantial breach of this Contract not cured within fifteen (15) days after written notice.</li>
</ol>

<p class="sec"><span class="sec-t">Section 22. Effect of Termination for Cause.</span> Upon termination for cause, the LESSEE shall vacate within fifteen (15) days of written demand. The security deposit shall be applied to unpaid rent, penalties, utilities and repairs, and any balance forfeited as liquidated damages, without prejudice to the LESSOR&rsquo;s right to collect further damages.</p>

<p class="sec"><span class="sec-t">Section 23. No Self-Help Eviction.</span> The LESSOR shall not padlock the Unit, remove the LESSEE&rsquo;s belongings, or cut utilities as a means of forcing the LESSEE out. Recovery of possession shall be done only through an ejectment case (unlawful detainer) after the required written demand to pay and/or vacate.</p>

<p class="sec"><span class="sec-t">Section 24. Sale or Mortgage of the Property.</span> A sale, mortgage or transfer of the building does not terminate this lease. The LESSOR shall require any buyer or transferee to respect the remaining term, and shall transfer the deposits to the new owner with written notice to the LESSEE.</p>

<p class="sec"><span class="sec-t">Section 25. Dispute Resolution and Venue.</span></p>
<ol class="sub">
  <li><strong>Barangay conciliation.</strong> Before any case is filed in court, the Parties shall first bring the dispute to the <strong>Lupong Tagapamayapa of Barangay <?= $x('barangay_no', '3em') ?>, Caloocan City</strong>, where the Unit is located, as required by the Katarungang Pambarangay provisions of the Local Government Code (RA 7160, Secs. 399&ndash;422), unless an exception under that law applies.</li>
  <li><strong>Venue.</strong> If conciliation fails, actions arising from this Contract shall be filed exclusively in the proper courts of <strong>Caloocan City</strong>: the Metropolitan Trial Court for ejectment and small claims, and the Regional Trial Court for other actions within its jurisdiction.</li>
  <li><strong>Attorney&rsquo;s fees.</strong> If a Party must go to court to enforce this Contract, the losing Party shall pay attorney&rsquo;s fees of ten percent (10%) of the amount claimed, but not less than <?= $m('attorneys_fee_min') ?>, plus costs of suit.</li>
</ol>

<div class="keep">
<p class="sec"><span class="sec-t">Section 26. Notices.</span> Notices shall be in writing and sent to the addresses below, or to any new address a Party gives in writing. Notices by email or messaging app are valid if the recipient acknowledges receipt or replies.</p>
<table class="tbl">
  <thead><tr><th style="width:16%">Party</th><th>Address</th><th style="width:34%">Email / Mobile</th></tr></thead>
  <tbody>
    <tr><td><strong>LESSOR</strong></td><td><?= $x('lessor_address', '10em') ?></td><td><?= $x('lessor_email', '6em') ?> / <?= $x('lessor_mobile', '6em') ?></td></tr>
    <tr><td><strong>LESSEE</strong></td><td>Unit No. <?= $x('unit_no', '3em') ?>, <?= $x('building_address', '8em') ?>, Barangay <?= $x('barangay_no', '3em') ?>, Caloocan City</td><td><?= $x('lessee_email', '6em') ?> / <?= $x('lessee_mobile', '6em') ?></td></tr>
  </tbody>
</table>
</div>

<p class="sec"><span class="sec-t">Section 27. Data Privacy.</span> The LESSEE consents to the LESSOR&rsquo;s collection and use of the personal information of the LESSEE and Authorized Occupants (names, IDs, contact details, emergency contacts) only for managing this lease, building security, and compliance with barangay or government requests, in accordance with the Data Privacy Act of 2012 (RA 10173).</p>

<p class="sec"><span class="sec-t">Section 28. General Provisions.</span></p>
<ol class="sub">
  <li><strong>Entire agreement.</strong> This Contract and its Annexes are the whole agreement of the Parties and supersede prior discussions. Amendments must be in writing and signed by both Parties.</li>
  <li><strong>Severability.</strong> If any provision is held invalid, the rest remain in force.</li>
  <li><strong>No waiver.</strong> The LESSOR&rsquo;s acceptance of late rent or tolerance of a breach is not a waiver of any right under this Contract.</li>
  <li><strong>Binding effect.</strong> This Contract binds the Parties and their heirs, successors and authorized assigns, subject to Section 12.</li>
  <li><strong>Governing law.</strong> This Contract is governed by the laws of the Republic of the Philippines.</li>
  <li><strong>Taxes and fees.</strong> Documentary stamp tax, if any, shall be <?= $x('dst_payer', '10em') ?>. Income tax on the rent is for the account of the LESSOR.</li>
</ol>

<section class="sign-block">
  <h2>Signatures</h2>
  <p class="note">Both Parties and two witnesses sign every page of this Contract and its Annexes.</p>
  <p><strong>IN WITNESS WHEREOF</strong>, the Parties have signed this Contract of Lease on the date and at the place first written above.</p>

  <div class="sig-grid">
    <div class="sig">
      <div class="sig-role">Lessor</div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= $sig('lessor_name') ?></div>
      <div class="sig-meta">ID: <?= $x('lessor_id', '10em') ?></div>
    </div>
    <div class="sig">
      <div class="sig-role">Lessee</div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= $sig('lessee_name') ?></div>
      <div class="sig-meta">ID: <?= $x('lessee_id', '10em') ?></div>
    </div>
  </div>

  <p class="lead witness-lead"><strong>SIGNED IN THE PRESENCE OF:</strong></p>
  <div class="sig-grid">
    <div class="sig">
      <div class="sig-role">Witness 1</div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= $sig('witness1_name') ?></div>
    </div>
    <div class="sig">
      <div class="sig-role">Witness 2</div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= $sig('witness2_name') ?></div>
    </div>
  </div>
</section>

<section class="annexes">
  <h2>Annexes</h2>
  <dl>
    <dt>Annex &ldquo;A&rdquo; &ndash; Inventory and Condition Report</dt>
    <dd>Room-by-room list of fixtures, furnishings, appliances, keys and meter numbers, with condition notes and dated move-in photos, signed by both Parties. Used again at move-out under Section 19.</dd>
    <dt>Annex &ldquo;B&rdquo; &ndash; Authorized Occupants</dt>
    <dd>Name, relationship to LESSEE, age, and contact number of each person allowed to reside in the Unit, plus an emergency contact.</dd>
    <dt>Annex &ldquo;C&rdquo; &ndash; Building House Rules</dt>
    <dd>Gate hours, garbage schedule, laundry and drying areas, quiet hours, visitor registration, and Admin contact details.</dd>
    <dt>Annex &ldquo;D&rdquo; &ndash; Copies of IDs</dt>
    <dd>Copies of IDs of the Parties and Authorized Occupants.</dd>
  </dl>
</section>
