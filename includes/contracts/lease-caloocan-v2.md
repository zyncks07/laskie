---
template: contract-of-lease-caloocan
version: 2.0
jurisdiction: Caloocan City, Metro Manila, Philippines
source: "Contract of Lease – Caloocan City (Draft)", reviewed 2026-10-04; v2 = post-paid rent, no advance (2026-10-04)
notarized: false
parking: none
---

<!--
=====================================================================
DEVELOPER NOTES — DO NOT RENDER ANYTHING ABOVE THE "BEGIN CONTRACT" LINE
=====================================================================

Placeholder syntax: {{variable_name}}
Everything outside {{ }} is fixed contract text. The non-negotiables
(no pets, no modifications, no sublease, visitor rule, 2-month notice,
deposit applied to last 2 months, PHP 2,000 / PHP 1,000 utility deposits)
are hard-coded on purpose — do not expose them as editable fields.

-------------------------------------------------
VARIABLES
-------------------------------------------------
Contract
  contract_day              e.g. "4th"
  contract_month            e.g. "October"
  contract_year             e.g. "2026"

Lessor
  lessor_name               person or company name
  lessor_civil_status       e.g. "single" (omit/blank if corporation)
  lessor_address
  lessor_rep_clause         optional; for corporations, e.g.
                            "represented by Juan Dela Cruz per Board Resolution dated 1 October 2026"
                            render "" for individuals
  lessor_email
  lessor_mobile
  lessor_id                 ID type and number (signature block)

Lessee
  lessee_name
  lessee_nationality        e.g. "Filipino"
  lessee_civil_status
  lessee_address            present address before move-in
  lessee_id                 ID type and number
  lessee_email
  lessee_mobile

Property / unit
  building_address          street and house/building no.
  barangay_no               e.g. "176"
  caloocan_side             "North" | "South"
  title_ref                 TCT / Tax Declaration no.
  unit_no
  floor_area_sqm
  bedrooms
  toilet_baths
  max_occupants

Term
  term_words                e.g. "one (1) year"
  start_date
  end_date

Money (store as numbers, format at render time as "PHP 1,234.00" with the peso sign ₱)
  monthly_rent              input
  monthly_rent_words        e.g. "EIGHT THOUSAND"
  rent_due_day              e.g. "5th"
  payment_methods           e.g. "GCash no. 09xx / bank deposit to BDO acct ..."
  late_penalty_pct          e.g. "one percent (1%)"
  bounced_check_fee
  security_deposit          = monthly_rent * 2       (computed)
  move_in_total             = monthly_rent * 2 + 3000 (computed; v2 takes no advance rent)

Optional house-rule values (defaults shown in the text, override if needed)
  association_dues_payer    "the LESSOR" | "the LESSEE"
  dst_payer                 "shared equally by the Parties" | "paid by the LESSEE" | "paid by the LESSOR"
  attorneys_fee_min

Witnesses
  witness1_name
  witness2_name

-------------------------------------------------
BUSINESS RULES FOR THE GENERATOR
-------------------------------------------------
- Rent control: if monthly_rent <= 10000, RA 9653 applies. Advance max 1 month,
  deposit max 2 months (this template already complies). Annual increase for a
  continuing tenant capped by NHSB resolution (1% for 2026, valid to 2026-12-31).
  Re-check for a 2027 resolution before renewals.
- Deposit timeline: security deposit covers the last 2 months of rent; this
  coincides with the 2-month notice. Generator/app should flag the last 2 months
  as "paid by deposit" once a notice to vacate is logged.
- Utility deposit (3,000) is never applied to rent; refunded net of final bills
  within 30 days.
- Annexes A–D are separate documents (inventory, occupants, house rules, IDs);
  the app should attach or generate them.
- Not legal advice; have a Caloocan lawyer review the template before production use.
=====================================================================
-->

<!-- ======================= BEGIN CONTRACT ======================= -->

# CONTRACT OF LEASE

**KNOW ALL MEN BY THESE PRESENTS:**

This Contract of Lease is made and entered into this {{contract_day}} day of {{contract_month}}, {{contract_year}}, in Caloocan City, Metro Manila, Philippines, by and between:

**{{lessor_name}}**, of legal age, Filipino, {{lessor_civil_status}}, with address at {{lessor_address}}, Caloocan City{{lessor_rep_clause}}, hereinafter called the **"LESSOR"**;

— and —

**{{lessee_name}}**, of legal age, {{lessee_nationality}}, {{lessee_civil_status}}, with present address at {{lessee_address}} and valid ID {{lessee_id}}, hereinafter called the **"LESSEE"**.

The LESSOR and LESSEE are each a "Party" and together the "Parties." Where this Contract refers to "Admin" or "Management," it means the LESSOR or the building administrator the LESSOR names in writing.

**WITNESSETH:**

1. The LESSOR is the registered owner / lawful administrator of the residential building located at {{building_address}}, Barangay {{barangay_no}}, {{caloocan_side}} Caloocan, Caloocan City, Metro Manila, covered by TCT / Tax Declaration No. {{title_ref}}.
2. The LESSOR agrees to lease to the LESSEE, and the LESSEE agrees to lease from the LESSOR, **Unit No. {{unit_no}}** of said building (the "Unit"), with a floor area of approximately {{floor_area_sqm}} sq. m., consisting of {{bedrooms}} bedroom(s), {{toilet_baths}} toilet and bath, and the fixtures and furnishings listed in the **Inventory and Condition Report (Annex "A")**.
3. The Unit shall be used **solely as a private residence** of the LESSEE and the persons listed in **Annex "B" (Authorized Occupants)**, not exceeding {{max_occupants}} persons in total.

NOW, THEREFORE, the Parties agree to the following terms and conditions:

## I. Term, Rent and Deposits

**Section 1. Term.** This lease is for a period of **{{term_words}}**, starting **{{start_date}}** and ending **{{end_date}}**. It may be renewed only by a new written contract or a written renewal signed by both Parties at least thirty (30) days before expiry. If the LESSEE stays on after expiry without a written renewal and the LESSOR accepts rent, the lease continues on a month-to-month basis (Arts. 1670 and 1687, Civil Code) under the same terms, including the two-month notice rule in Section 18.

**Section 2. Monthly Rent.** The monthly rent is **{{monthly_rent_words}} PESOS (₱{{monthly_rent}})**, **payable after each month of occupancy**. Each billing period runs from the **{{rent_due_day}}** day of one month to the day before the same day of the following month, and the rent for a billing period is due on or before the {{rent_due_day}} day that immediately follows it, without need of demand, through {{payment_methods}}. The LESSOR shall issue an official receipt for every payment.

**Section 3. Late Payment.** Rent not paid within five (5) days after its due date earns a penalty of {{late_penalty_pct}} of the unpaid amount per month of delay. Dishonored checks are treated as non-payment and earn a ₱{{bounced_check_fee}} handling fee, without prejudice to remedies under Batas Pambansa Blg. 22.

**Section 4. Rent Increase.** If the Unit's monthly rent is within the coverage of the Rent Control Act and its extending resolutions (currently ₱10,000 or less), the rent may be increased only once every twelve (12) months, and only up to the cap set by the National Human Settlements Board / DHSUD for that year, while the same LESSEE occupies the Unit. If the Unit is not covered, any increase takes effect only upon renewal and as agreed in writing.

**Section 5. Billing Periods, First and Final Bill.** No advance rent is collected. The first billing period begins on the start date in Section 1, and its rent is due on the next due day under Section 2. Where the lease begins or ends on a day other than the due day, the rent for that partial billing period is computed by the number of days the Unit was occupied over the number of days in that billing period. The rent for the final billing period, including any partial period, is due on the day the LESSEE vacates the Unit, subject to the application of the security deposit under Section 6.

**Section 6. Security Deposit.** Upon signing, the LESSEE pays a **security deposit equal to two (2) months' rent, or ₱{{security_deposit}}**, under these conditions:

1. **Application to the last two billing periods.** The security deposit shall be applied as payment of the rent for the **last two (2) billing periods** of the lease. These coincide with the two-month notice period in Section 18. The LESSEE shall not pay rent in cash for those two billing periods.
2. **Conditions for application.** The deposit is applied to the last two months only if (a) the LESSEE gave written notice to vacate at least two (2) months in advance, (b) the LESSEE has no unpaid rent, penalties or charges at the start of the notice period, and (c) the LESSEE actually vacates at the end of the notice period.
3. **Early departure or no notice.** If the LESSEE leaves before the end of the term or without the full two-month notice, the deposit shall first answer for unpaid rent, penalties, utilities and the cost of repairs, and the balance shall be forfeited in favor of the LESSOR as liquidated damages for the unserved notice period, without prejudice to the LESSOR's right to collect any remaining amount.
4. **Rent adjustment.** If the rent is lawfully increased, the LESSEE shall top up the deposit so that it always equals two (2) months of the current rent.
5. **Bank and interest.** Where the Rent Control Act applies, the LESSOR shall keep the deposit in a bank under the LESSOR's name, and any interest earned shall be returned to the LESSEE at the end of the lease.
6. **Repairs and damage are separate.** Because the deposit is used up as rent, the LESSEE shall pay in full, before turnover of the keys, the cost of any damage, missing items or restoration found at the move-out inspection under Section 19.

**Section 7. Utility Deposits.** Upon signing, the LESSEE pays a **utility deposit of ₱3,000.00**, consisting of:

| Utility | Deposit |
| --- | --- |
| Electricity | ₱2,000.00 |
| Water | ₱1,000.00 |

The utility deposit answers only for electricity and water bills (including the LESSOR's sub-meter billing, if any) left unpaid when the LESSEE moves out. It may not be applied to rent. Within thirty (30) days after the final bills covering the LESSEE's stay are issued, the LESSOR shall return the balance, net of those bills, with a written breakdown. Any shortfall shall be paid by the LESSEE within seven (7) days of the breakdown.

**Section 8. Summary of Move-in Payments.**

| Item | Amount |
| --- | --- |
| Security deposit (2 months) | ₱{{security_deposit}} |
| Electricity deposit | ₱2,000.00 |
| Water deposit | ₱1,000.00 |
| **Total due on signing** | **₱{{move_in_total}}** |

## II. Use of the Unit and House Rules

**Section 9. Residential Use Only.** The Unit shall be used only as a dwelling. No business, store, sari-sari store, online-selling warehouse, boarding or bedspace operation, or any activity requiring a business permit shall be conducted in the Unit without the LESSOR's prior written consent and the required permits from the City Government of Caloocan.

**Section 10. No Pets.** **No pets or animals of any kind** (including dogs, cats, birds, fowl, reptiles, fish tanks, or livestock) may be kept, fed or housed in the Unit or on the premises, even temporarily. On first violation, the LESSEE shall remove the animal within forty-eight (48) hours of written notice and pay for cleaning, sanitizing and any damage. A second violation is a ground for termination.

**Section 11. No Modifications.** **All modifications of the Unit are prohibited.** Without limitation, the LESSEE shall not:

1. Build, demolish, or move walls, partitions, ceilings, doors, windows or grills;
2. Repaint, wallpaper, tile, or change floor or wall finishes;
3. Drill, nail or bolt into walls, ceilings or floors, or install shelves, cabinets, or wall-mounted fixtures;
4. Alter electrical wiring, outlets, breakers, plumbing, or install additional meters;
5. Install air-conditioning units, water heaters, satellite dishes, antennas, or CCTV that require cutting or mounting;
6. Replace or add locks, or change the door lock cylinder.

Any change made in violation of this Section shall, at the LESSOR's option, either be removed by the LESSEE at the LESSEE's cost with the Unit restored to its original condition, or be kept by the LESSOR as part of the Unit without any reimbursement to the LESSEE. Ordinary freestanding furniture and appliances that do not require installation are allowed.

**Section 12. No Sublease or Assignment.** The LESSEE shall **not sublease, sublet, assign, transfer, lend, or allow any other person to occupy** the Unit or any part of it, with or without payment, including through short-term rental platforms. This is an express prohibition under Art. 1650 of the Civil Code. Any sublease or assignment is void as against the LESSOR and is a ground for immediate termination and ejectment under Section 9 of the Rent Control Act.

**Section 13. Authorized Occupants and Visitors.**

1. Only the LESSEE and the Authorized Occupants listed in Annex "B" may reside in the Unit. Any additional occupant requires the prior written approval of the Admin/Management and an amended Annex "B".
2. Visitors are allowed, but **no visitor may stay in the Unit for more than one (1) month**, whether continuously or cumulatively within any three (3)-month period, **unless approved in writing by the Admin/Management**.
3. Overnight visitors shall be registered with the Admin/Management with their name and expected length of stay.
4. The LESSEE is responsible for the conduct of all occupants and visitors and for any damage they cause.

**Section 14. House Rules and Ordinances.** The LESSEE, the occupants and visitors shall:

1. Observe the building rules in **Annex "C"** and any reasonable rules the LESSOR issues in writing;
2. Comply with the ordinances of Caloocan City and the rules of Barangay {{barangay_no}}, including those on curfew, noise, smoking in public places, and anti-littering;
3. Segregate garbage into biodegradable, recyclable and residual waste and follow the barangay collection schedule, as required by the Ecological Solid Waste Management Act (RA 9003);
4. Keep noise down between 10:00 PM and 6:00 AM and avoid any nuisance to neighbors;
5. Not store firearms, explosives, fireworks, LPG beyond one (1) household tank, or other flammable or hazardous materials, consistent with the Fire Code of the Philippines (RA 9514);
6. Not use or allow the Unit to be used for any illegal or immoral purpose, including illegal drugs or gambling.

**Section 15. Common Areas.** Common areas (hallways, stairs, rooftop, laundry area) shall be kept clear of personal belongings, and shall not be used for storage, cooking, or drying clothes except in designated areas.

## III. Utilities, Repairs, Notice and Move-out

**Section 16. Utilities and Other Charges.** Electricity, water, internet, cable, LPG and similar charges for the Unit are paid by the LESSEE, on time, directly to Meralco, Maynilad or the provider, or to the LESSOR under its sub-meter billing within five (5) days of the billing notice. The LESSOR may have a service disconnected through the provider for bills more than thirty (30) days overdue, after written notice. Association dues, if any, are for the account of {{association_dues_payer}}. Real property tax on the building is for the account of the LESSOR.

**Section 17. Repairs, Maintenance and Entry.**

1. **LESSOR's repairs.** The LESSOR shall make the necessary repairs to keep the Unit fit for residential use, such as structural defects, roof leaks, and main plumbing and electrical lines, unless the damage was caused by the LESSEE, occupants or visitors (Art. 1654, Civil Code).
2. **LESSEE's upkeep.** The LESSEE shall keep the Unit clean and sanitary and shall pay for minor repairs from daily use, such as busted bulbs, clogged sinks and toilets, broken faucets, door knobs, screens, and keys, and for any damage caused by the LESSEE, occupants or visitors.
3. **Duty to report.** The LESSEE shall report any defect or needed repair to the Admin/Management in writing (text or chat is acceptable) as soon as it is discovered. The LESSEE is liable for further damage caused by failure to report (Art. 1663, Civil Code).
4. **No self-repair of structure or lines.** The LESSEE shall not repair the LESSOR's structural, electrical or plumbing items without written approval; unauthorized repairs are not reimbursable.
5. **Entry.** The LESSOR or Admin may enter the Unit at reasonable hours, with at least twenty-four (24) hours' prior notice, to inspect, make repairs, or show the Unit to prospective tenants during the last two (2) months of the lease. In an emergency (fire, flooding, gas leak, electrical hazard), the LESSOR may enter without prior notice, in the presence of a barangay official or a witness where possible.

**Section 18. Two-Month Notice to Vacate.**

1. The LESSEE shall give the LESSOR **written notice of intent to vacate at least two (2) months before the move-out date**, whether at the end of the term or under a month-to-month extension. Notice may be served personally with acknowledgment, or by email or messaging app to the LESSOR's address in Section 26.
2. The two-month notice period is paid for by the security deposit under Section 6.
3. If the LESSEE vacates without giving the full two-month notice, Section 6(3) applies.
4. If the LESSOR does not intend to renew at the end of the term, the LESSOR shall likewise notify the LESSEE in writing at least two (2) months before expiry.

**Section 19. Move-out Condition and Inspection.**

1. **Original condition.** On move-out, the LESSEE shall return the Unit **in the same condition as described in Annex "A"**, clean, with all fixtures, furnishings and keys complete, except for deterioration from ordinary wear and tear and the passage of time (Arts. 1665 and 1667, Civil Code). Holes, stains, paint damage, broken fixtures and any unauthorized modification are not ordinary wear and tear.
2. **Pre-move-out inspection.** Within seven (7) days after receiving the notice to vacate, the Parties shall jointly inspect the Unit against Annex "A" and list the repairs or replacements needed, with estimated costs.
3. **Final inspection and turnover.** On the move-out date, the Parties shall do a final walkthrough and sign a Turnover Acknowledgment. The LESSEE shall pay the cost of any listed repairs, cleaning or missing items before the keys are surrendered, or the LESSOR may have the work done and bill the LESSEE, who shall pay within seven (7) days.
4. **Final readings.** Electricity and water meter readings shall be recorded on the move-out date and applied against the utility deposit under Section 7.
5. **Items left behind.** Belongings left in the Unit after turnover shall be listed in the presence of a witness and kept for fifteen (15) days, during which the LESSEE may claim them after paying storage costs, if any. Unclaimed items after that period are deemed abandoned and may be disposed of by the LESSOR.
6. **Holding over.** If the LESSEE stays beyond the move-out date stated in the notice without the LESSOR's written consent, the LESSEE shall pay a daily use fee equal to one-fifteenth (1/15) of the monthly rent until the Unit is surrendered, without prejudice to an ejectment action.

## IV. Default, Termination and Disputes

**Section 20. Risk and Liability.** The LESSOR is not liable for loss of or damage to the LESSEE's belongings from theft, fire, flood, or other causes not due to the LESSOR's fault. The LESSEE is encouraged to secure personal-property insurance. The LESSEE shall be liable for any fire or damage to the building caused by the negligence of the LESSEE, occupants or visitors.

**Section 21. Grounds for Termination.** The LESSOR may terminate this lease and recover possession of the Unit on any of the following grounds, consistent with Art. 1673 of the Civil Code and Section 9 of the Rent Control Act:

1. Rent arrears equivalent to **three (3) months**, or repeated late payment after written warning;
2. Subleasing, assignment, or allowing unauthorized occupants, in violation of Sections 12 and 13;
3. Keeping pets after a first written warning, in violation of Section 10;
4. Unauthorized modification of the Unit not restored within fifteen (15) days of written notice, in violation of Section 11;
5. Use of the Unit for business or for any illegal purpose, in violation of Sections 9 and 14;
6. Legitimate need of the LESSOR to repossess the Unit for the LESSOR's own use or that of an immediate family member, upon expiration of the lease term and with at least three (3) months' written notice, where the Unit is covered by the Rent Control Act;
7. Need for necessary repairs that make the Unit unsafe to occupy, or a condemnation or demolition order from the City Building Official, subject to the LESSEE's preferential right to re-lease after repairs where the law grants it;
8. Expiration of the lease term without written renewal;
9. Any other substantial breach of this Contract not cured within fifteen (15) days after written notice.

**Section 22. Effect of Termination for Cause.** Upon termination for cause, the LESSEE shall vacate within fifteen (15) days of written demand. The security deposit shall be applied to unpaid rent, penalties, utilities and repairs, and any balance forfeited as liquidated damages, without prejudice to the LESSOR's right to collect further damages.

**Section 23. No Self-Help Eviction.** The LESSOR shall not padlock the Unit, remove the LESSEE's belongings, or cut utilities as a means of forcing the LESSEE out. Recovery of possession shall be done only through an ejectment case (unlawful detainer) after the required written demand to pay and/or vacate.

**Section 24. Sale or Mortgage of the Property.** A sale, mortgage or transfer of the building does not terminate this lease. The LESSOR shall require any buyer or transferee to respect the remaining term, and shall transfer the deposits to the new owner with written notice to the LESSEE.

**Section 25. Dispute Resolution and Venue.**

1. **Barangay conciliation.** Before any case is filed in court, the Parties shall first bring the dispute to the **Lupong Tagapamayapa of Barangay {{barangay_no}}, Caloocan City**, where the Unit is located, as required by the Katarungang Pambarangay provisions of the Local Government Code (RA 7160, Secs. 399–422), unless an exception under that law applies.
2. **Venue.** If conciliation fails, actions arising from this Contract shall be filed exclusively in the proper courts of **Caloocan City**: the Metropolitan Trial Court for ejectment and small claims, and the Regional Trial Court for other actions within its jurisdiction.
3. **Attorney's fees.** If a Party must go to court to enforce this Contract, the losing Party shall pay attorney's fees of ten percent (10%) of the amount claimed, but not less than ₱{{attorneys_fee_min}}, plus costs of suit.

**Section 26. Notices.** Notices shall be in writing and sent to the addresses below, or to any new address a Party gives in writing. Notices by email or messaging app are valid if the recipient acknowledges receipt or replies.

| Party | Address | Email / Mobile |
| --- | --- | --- |
| LESSOR | {{lessor_address}} | {{lessor_email}} / {{lessor_mobile}} |
| LESSEE | Unit No. {{unit_no}}, {{building_address}}, Barangay {{barangay_no}}, Caloocan City | {{lessee_email}} / {{lessee_mobile}} |

**Section 27. Data Privacy.** The LESSEE consents to the LESSOR's collection and use of the personal information of the LESSEE and Authorized Occupants (names, IDs, contact details, emergency contacts) only for managing this lease, building security, and compliance with barangay or government requests, in accordance with the Data Privacy Act of 2012 (RA 10173).

**Section 28. General Provisions.**

1. **Entire agreement.** This Contract and its Annexes are the whole agreement of the Parties and supersede prior discussions. Amendments must be in writing and signed by both Parties.
2. **Severability.** If any provision is held invalid, the rest remain in force.
3. **No waiver.** The LESSOR's acceptance of late rent or tolerance of a breach is not a waiver of any right under this Contract.
4. **Binding effect.** This Contract binds the Parties and their heirs, successors and authorized assigns, subject to Section 12.
5. **Governing law.** This Contract is governed by the laws of the Republic of the Philippines.
6. **Taxes and fees.** Documentary stamp tax, if any, shall be {{dst_payer}}. Income tax on the rent is for the account of the LESSOR.

## Signatures

Both Parties and two witnesses sign every page of this Contract and its Annexes.

**IN WITNESS WHEREOF**, the Parties have signed this Contract of Lease on the date and at the place first written above.

| LESSOR | LESSEE |
| --- | --- |
| {{lessor_name}} | {{lessee_name}} |
| Signature: ______________________ | Signature: ______________________ |
| ID: {{lessor_id}} | ID: {{lessee_id}} |

**SIGNED IN THE PRESENCE OF:**

| Witness 1 | Witness 2 |
| --- | --- |
| {{witness1_name}} | {{witness2_name}} |
| Signature: ______________________ | Signature: ______________________ |

## Annexes

- **Annex "A" – Inventory and Condition Report.** Room-by-room list of fixtures, furnishings, appliances, keys and meter numbers, with condition notes and dated move-in photos, signed by both Parties. Used again at move-out under Section 19.
- **Annex "B" – Authorized Occupants.** Name, relationship to LESSEE, age, and contact number of each person allowed to reside in the Unit, plus an emergency contact.
- **Annex "C" – Building House Rules.** Gate hours, garbage schedule, laundry and drying areas, quiet hours, visitor registration, and Admin contact details.
- **Annex "D" – Copies of IDs** of the Parties and Authorized Occupants.

<!-- ======================== END CONTRACT ======================== -->
