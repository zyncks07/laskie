<?php
// config/contract_functions.php — lease-contract helpers.
//
// A contract is a DOCUMENT, not a billing input. Rent stays virtual and keeps
// being generated from tenants.contract_start / contract_end (CLAUDE.md §5 inv.
// 7 and 12): nothing here writes to tenants, payments or unit_charges. The
// register only *warns* when a contract and the tenant record disagree.
//
// The pure helpers (words, ordinals, term, money, state, field normalisation)
// take no PDO so tests/Unit/ContractHelpersTest.php can pin them without a DB.
// Requires config/functions.php (money_* helpers) to be loaded first.

require_once __DIR__ . '/transfer_functions.php';   // transferChainIds() for prefill

const CONTRACT_TEMPLATE_KEY     = 'lease-caloocan';
// v2 (2026-10-04): post-paid rent, no advance. v1 stays renderable for the record.
const CONTRACT_TEMPLATE_VERSION = '2';
// Months of advance rent each template version collects on signing.
const CONTRACT_ADVANCE_MONTHS = ['1' => 1, '2' => 0];
const CONTRACT_NO_PREFIX        = 'LSE';
// "Expiring" window. Matches the template's two-month notice to vacate
// (Section 18), so a contract turns amber exactly when notice is due.
const CONTRACT_EXPIRING_DAYS    = 60;
// Signed-copy scans: cap per contract, accepted types, and a sharper
// compressImage() profile than receipts (1600px/q62) so signatures and the
// contract's fine print stay legible.
const CONTRACT_FILE_CAP         = 30;
const CONTRACT_FILE_EXTS        = ['jpg','jpeg','png','webp','pdf'];
const CONTRACT_SCAN_COMPRESSION = ['max_dimension' => 2400, 'quality' => 75];
const CONTRACT_UPLOAD_SUBDIR    = 'contracts';
// Fixed by the template (Section 7) and deliberately NOT editable fields.
const CONTRACT_UTILITY_DEPOSITS = ['Electricity' => '2000.00', 'Water' => '1000.00'];
// RA 9653 rent-control ceiling the template refers to (Section 4).
const CONTRACT_RENT_CONTROL_CAP = '10000.00';

/**
 * The single field schema. It drives the generator form, the server-side
 * whitelist (unknown keys are dropped), approval validation and the template.
 *
 *   label    form label
 *   section  form section key (see CONTRACT_SECTIONS)
 *   type     text | textarea | date | money | int | select | computed
 *   max      max length for text values
 *   req      required before APPROVAL (drafts may be incomplete)
 *   def      true → value can be saved as a company-wide default
 *            (settings key "contract_default_<field>")
 *   opts     select options
 *   hint     helper text under the input
 */
const CONTRACT_SECTIONS = [
    'agreement' => ['Agreement',          'fa-calendar-day'],
    'lessor'    => ['Lessor',             'fa-user-tie'],
    'lessee'    => ['Lessee',             'fa-user'],
    'property'  => ['Property & Unit',    'fa-building'],
    'term'      => ['Term',               'fa-hourglass-half'],
    'money'     => ['Rent & Deposits',    'fa-peso-sign'],
    'rules'     => ['House-rule Options', 'fa-scale-balanced'],
    'witnesses' => ['Witnesses',          'fa-signature'],
];

const CONTRACT_FIELDS = [
    // Agreement
    'contract_date'      => ['label' => 'Date of signing',     'section' => 'agreement', 'type' => 'date', 'req' => true,
                             'hint' => 'Rendered as "4th day of October, 2026".'],
    // Lessor
    'lessor_name'        => ['label' => 'Lessor name',         'section' => 'lessor', 'type' => 'text', 'max' => 160, 'req' => true, 'def' => true],
    'lessor_civil_status'=> ['label' => 'Civil status',        'section' => 'lessor', 'type' => 'text', 'max' => 40, 'def' => true,
                             'hint' => 'Leave blank for a corporation.'],
    'lessor_address'     => ['label' => 'Address',             'section' => 'lessor', 'type' => 'textarea', 'max' => 300, 'req' => true, 'def' => true,
                             'hint' => 'Street / barangay only — the contract appends "Caloocan City".'],
    'lessor_rep_clause'  => ['label' => 'Representative clause','section' => 'lessor', 'type' => 'textarea', 'max' => 300, 'def' => true,
                             'hint' => 'Corporations only, e.g. "represented by Juan Dela Cruz per Board Resolution dated 1 October 2026".'],
    'lessor_email'       => ['label' => 'Email',               'section' => 'lessor', 'type' => 'text', 'max' => 120, 'def' => true],
    'lessor_mobile'      => ['label' => 'Mobile',              'section' => 'lessor', 'type' => 'text', 'max' => 40,  'def' => true],
    'lessor_id'          => ['label' => 'ID type & number',    'section' => 'lessor', 'type' => 'text', 'max' => 120, 'def' => true],
    // Lessee
    'lessee_name'        => ['label' => 'Lessee name',         'section' => 'lessee', 'type' => 'text', 'max' => 120, 'req' => true],
    'lessee_nationality' => ['label' => 'Nationality',         'section' => 'lessee', 'type' => 'text', 'max' => 60,  'req' => true],
    'lessee_civil_status'=> ['label' => 'Civil status',        'section' => 'lessee', 'type' => 'text', 'max' => 40,  'req' => true],
    'lessee_address'     => ['label' => 'Present address',     'section' => 'lessee', 'type' => 'textarea', 'max' => 300, 'req' => true,
                             'hint' => 'Address before move-in.'],
    'lessee_id'          => ['label' => 'ID type & number',    'section' => 'lessee', 'type' => 'text', 'max' => 120, 'req' => true],
    'lessee_email'       => ['label' => 'Email',               'section' => 'lessee', 'type' => 'text', 'max' => 120],
    'lessee_mobile'      => ['label' => 'Mobile',              'section' => 'lessee', 'type' => 'text', 'max' => 40],
    // Property
    'building_address'   => ['label' => 'Building address',    'section' => 'property', 'type' => 'text', 'max' => 200, 'req' => true, 'def' => true,
                             'hint' => 'Street and house/building no.'],
    'barangay_no'        => ['label' => 'Barangay no.',        'section' => 'property', 'type' => 'text', 'max' => 20,  'req' => true, 'def' => true],
    'caloocan_side'      => ['label' => 'Caloocan side',       'section' => 'property', 'type' => 'select', 'opts' => ['North', 'South'], 'req' => true, 'def' => true],
    'title_ref'          => ['label' => 'TCT / Tax Dec. no.',  'section' => 'property', 'type' => 'text', 'max' => 80,  'req' => true, 'def' => true],
    'unit_no'            => ['label' => 'Unit no.',            'section' => 'property', 'type' => 'text', 'max' => 40,  'req' => true],
    'floor_area_sqm'     => ['label' => 'Floor area (sq. m.)', 'section' => 'property', 'type' => 'text', 'max' => 20,  'req' => true],
    'bedrooms'           => ['label' => 'Bedrooms',            'section' => 'property', 'type' => 'text', 'max' => 10,  'req' => true],
    'toilet_baths'       => ['label' => 'Toilet & bath',       'section' => 'property', 'type' => 'text', 'max' => 10,  'req' => true],
    'max_occupants'      => ['label' => 'Max occupants',       'section' => 'property', 'type' => 'text', 'max' => 10,  'req' => true],
    // Term
    'start_date'         => ['label' => 'Start date',          'section' => 'term', 'type' => 'date', 'req' => true],
    'end_date'           => ['label' => 'End date',            'section' => 'term', 'type' => 'date', 'req' => true,
                             'hint' => 'Last day of the lease (inclusive).'],
    'term_words'         => ['label' => 'Term in words',       'section' => 'term', 'type' => 'text', 'max' => 120, 'req' => true,
                             'hint' => 'Auto-filled from the dates, e.g. "one (1) year".'],
    // Money
    'monthly_rent'       => ['label' => 'Monthly rent',        'section' => 'money', 'type' => 'money', 'req' => true],
    'monthly_rent_words' => ['label' => 'Rent in words',       'section' => 'money', 'type' => 'text', 'max' => 200, 'req' => true,
                             'hint' => 'Auto-filled from the amount. Printed as "… PESOS".'],
    'rent_due_day'       => ['label' => 'Rent due day',        'section' => 'money', 'type' => 'text', 'max' => 10,  'req' => true,
                             'hint' => 'e.g. "5th".'],
    'payment_methods'    => ['label' => 'Payment methods',     'section' => 'money', 'type' => 'textarea', 'max' => 300, 'req' => true, 'def' => true,
                             'hint' => 'e.g. "GCash no. 09xx / bank deposit to BDO acct …".'],
    'late_penalty_pct'   => ['label' => 'Late penalty',        'section' => 'money', 'type' => 'text', 'max' => 60,  'req' => true, 'def' => true,
                             'hint' => 'e.g. "one percent (1%)" — per month of delay.'],
    'bounced_check_fee'  => ['label' => 'Bounced-check fee',   'section' => 'money', 'type' => 'money', 'req' => true, 'def' => true],
    'advance_rent'       => ['label' => 'Advance rent (1 mo.)',      'section' => 'money', 'type' => 'computed'],
    'security_deposit'   => ['label' => 'Security deposit (2 mo.)',  'section' => 'money', 'type' => 'computed'],
    'move_in_total'      => ['label' => 'Total due on signing',      'section' => 'money', 'type' => 'computed'],
    // Rules
    'association_dues_payer' => ['label' => 'Association dues paid by', 'section' => 'rules', 'type' => 'select',
                                 'opts' => ['the LESSOR', 'the LESSEE'], 'req' => true, 'def' => true],
    'dst_payer'          => ['label' => 'Documentary stamp tax', 'section' => 'rules', 'type' => 'select',
                             'opts' => ['shared equally by the Parties', 'paid by the LESSEE', 'paid by the LESSOR'], 'req' => true, 'def' => true],
    'attorneys_fee_min'  => ['label' => "Attorney's fee minimum", 'section' => 'rules', 'type' => 'money', 'req' => true, 'def' => true],
    // Witnesses
    'witness1_name'      => ['label' => 'Witness 1',           'section' => 'witnesses', 'type' => 'text', 'max' => 120, 'def' => true],
    'witness2_name'      => ['label' => 'Witness 2',           'section' => 'witnesses', 'type' => 'text', 'max' => 120, 'def' => true],
];

// Built-in fallbacks used when neither a saved default nor a record supplies a
// value. Business choices, so every one of them stays editable in the form.
const CONTRACT_BUILTIN_DEFAULTS = [
    'lessee_nationality'     => 'Filipino',
    'caloocan_side'          => 'North',
    'late_penalty_pct'       => 'one percent (1%)',
    'bounced_check_fee'      => '500.00',
    'association_dues_payer' => 'the LESSOR',
    'dst_payer'              => 'shared equally by the Parties',
    'attorneys_fee_min'      => '10000.00',
];

// ─── Number words ────────────────────────────────────────────
// Pure PHP (no intl dependency), lowercase, hyphenated tens: 21 → "twenty-one".
function numberToWords(int $n): string {
    static $ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
                    'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    static $tens = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if ($n < 0)  return 'minus ' . numberToWords(-$n);
    if ($n < 20) return $ones[$n];
    if ($n < 100) {
        return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    }
    if ($n < 1000) {
        return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . numberToWords($n % 100) : '');
    }
    foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $scale => $word) {
        if ($n >= $scale) {
            $rest = $n % $scale;
            return numberToWords(intdiv($n, $scale)) . ' ' . $word . ($rest ? ' ' . numberToWords($rest) : '');
        }
    }
    return (string)$n; // unreachable
}

// Peso amount in contract style: '8000.00' → "EIGHT THOUSAND",
// '10500.50' → "TEN THOUSAND FIVE HUNDRED AND 50/100". The template supplies
// the trailing "PESOS (₱…)".
function contractAmountWords($amount): string {
    $cents = to_cents($amount);
    if ($cents < 0) $cents = -$cents;
    $words = strtoupper(numberToWords(intdiv($cents, 100)));
    $c = $cents % 100;
    return $c ? $words . ' AND ' . str_pad((string)$c, 2, '0', STR_PAD_LEFT) . '/100' : $words;
}

// 1 → "1st", 2 → "2nd", 11 → "11th", 22 → "22nd".
function ordinalDay(int $n): string {
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 13) return $n . 'th';
    return $n . (['th','st','nd','rd','th','th','th','th','th','th'][$n % 10]);
}

// "one (1) year", "six (6) months", "one (1) year and six (6) months",
// "two (2) months and fifteen (15) days". contract_end is inclusive, so the
// span is start → end + 1 day. Returns '' for missing/inverted dates.
function contractTermWords(?string $start, ?string $end): string {
    if (!contractIsoDate($start) || !contractIsoDate($end) || $end < $start) return '';
    $from = new DateTimeImmutable($start);
    $stop = (new DateTimeImmutable($end))->modify('+1 day');

    // Whole calendar months that fit, anchored on the start day. Month-ends are
    // clamped (Jan 31 + 1 month = Feb 28) so a lease starting on the 31st still
    // counts whole months.
    // Capped at 100 years so a typo'd year can't spin the loop.
    $months = 0;
    while ($months < 1200 && contractAddMonths($from, $months + 1) <= $stop) {
        $months++;
    }
    $days = (int)contractAddMonths($from, $months)->diff($stop)->days;

    $part = fn(int $n, string $unit) => numberToWords($n) . " ($n) " . $unit . ($n === 1 ? '' : 's');
    $parts = [];
    if (intdiv($months, 12)) $parts[] = $part(intdiv($months, 12), 'year');
    if ($months % 12)        $parts[] = $part($months % 12, 'month');
    if ($days)               $parts[] = $part($days, 'day');
    if (!$parts) return '';
    $last = array_pop($parts);
    return $parts ? implode(', ', $parts) . ' and ' . $last : $last;
}

// Calendar-month add with end-of-month clamping (PHP's +N month overflows).
function contractAddMonths(DateTimeImmutable $d, int $months): DateTimeImmutable {
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n') + $months;
    $y += intdiv($m - 1, 12);
    $m  = (($m - 1) % 12) + 1;
    $day = min((int)$d->format('j'), (int)date('t', mktime(0, 0, 0, $m, 1, $y)));
    return $d->setDate($y, $m, $day);
}

// Default lease end: start + 12 months − 1 day (inclusive end date).
function contractDefaultEnd(string $start, int $months = 12): string {
    return contractAddMonths(new DateTimeImmutable($start), $months)->modify('-1 day')->format('Y-m-d');
}

function contractIsoDate(?string $v): bool {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y);
}

// Move-in money, cents-exact, fixed by the template version: two months'
// security deposit + ₱3,000 utility deposits, plus CONTRACT_ADVANCE_MONTHS of
// advance rent (v1: one month; v2, post-paid: none). The server always
// recomputes these — a posted value is never trusted.
function contractComputeMoney($monthlyRent, string $version = CONTRACT_TEMPLATE_VERSION): array {
    $rent      = from_cents(max(0, to_cents($monthlyRent)));
    $utilities = money_sum(array_values(CONTRACT_UTILITY_DEPOSITS));
    $advance   = money_mul($rent, CONTRACT_ADVANCE_MONTHS[$version] ?? 0);
    $deposit   = money_mul($rent, 2);
    return [
        'advance_rent'     => $advance,
        'security_deposit' => $deposit,
        'move_in_total'    => money_sum([$advance, $deposit, $utilities]),
    ];
}

// ─── Field normalisation / validation ────────────────────────
// Whitelists $input against CONTRACT_FIELDS, trims and length-caps text,
// canonicalises money to "0.00" strings and dates to ISO (invalid → ''),
// clamps selects to their options, then recomputes the derived money.
function contractNormalizeFields(array $input, string $version = CONTRACT_TEMPLATE_VERSION): array {
    $out = [];
    foreach (CONTRACT_FIELDS as $key => $f) {
        if ($f['type'] === 'computed') continue;
        $raw = $input[$key] ?? '';
        $v   = is_scalar($raw) ? trim((string)$raw) : '';
        switch ($f['type']) {
            case 'money':
                $v = str_replace([',', ' ', '₱'], '', $v);
                $v = ($v !== '' && is_numeric($v)) ? from_cents(max(0, to_cents($v))) : '';
                break;
            case 'date':
                $v = contractIsoDate($v) ? $v : '';
                break;
            case 'select':
                if (!in_array($v, $f['opts'], true)) $v = CONTRACT_BUILTIN_DEFAULTS[$key] ?? $f['opts'][0];
                break;
            default:
                // Collapse runs of whitespace in single-line fields; keep newlines
                // out of the document entirely (the template is prose).
                $v = preg_replace('/\s+/u', ' ', $v);
                $v = mb_substr($v, 0, $f['max'] ?? 200);
        }
        $out[$key] = $v;
    }
    return array_merge($out, contractComputeMoney($out['monthly_rent'] ?: '0', $version));
}

// Rent-in-words and term-in-words follow their numbers unless the admin has
// typed over them. $autoRent / $autoTerm say whether each is still automatic;
// when it isn't, the hand-typed words are kept but checked against the
// numbers so a contract can't quietly say one amount in words and another
// in figures. Returns the suggestions and mismatch flags for the UI.
function contractApplyAutoWords(array $fields, bool $autoRent, bool $autoTerm): array {
    $rentWords = ($fields['monthly_rent'] ?? '') !== '' ? contractAmountWords($fields['monthly_rent']) : '';
    $termWords = contractTermWords($fields['start_date'] ?? null, $fields['end_date'] ?? null);
    if ($autoRent) $fields['monthly_rent_words'] = $rentWords;
    if ($autoTerm) $fields['term_words'] = $termWords;
    $norm = fn($s) => preg_replace('/\s+/', ' ', strtoupper(trim((string)$s)));
    return [
        'fields'        => $fields,
        'rent_words'    => $rentWords,
        'term_words'    => $termWords,
        'rent_mismatch' => $rentWords !== '' && $norm($fields['monthly_rent_words'] ?? '') !== $norm($rentWords),
        'term_mismatch' => $termWords !== '' && $norm($fields['term_words'] ?? '') !== $norm($termWords),
    ];
}

// Errors that block APPROVAL. Drafts may be saved incomplete.
function contractApprovalErrors(array $fields): array {
    $errors = [];
    foreach (CONTRACT_FIELDS as $key => $f) {
        if (!empty($f['req']) && trim((string)($fields[$key] ?? '')) === '') {
            $errors[] = CONTRACT_SECTIONS[$f['section']][0] . ' → ' . $f['label'] . ' is required.';
        }
    }
    if (($fields['monthly_rent'] ?? '') !== '' && !money_is_pos($fields['monthly_rent'])) {
        $errors[] = 'Monthly rent must be greater than zero.';
    }
    if (!empty($fields['start_date']) && !empty($fields['end_date']) && $fields['end_date'] < $fields['start_date']) {
        $errors[] = 'End date cannot be before the start date.';
    }
    return $errors;
}

// ─── Display state ───────────────────────────────────────────
// draft / cancelled come from the stored status; for approved contracts the
// state is derived from the dates so nothing needs a cron to "expire" it.
function contractDisplayState(array $row, ?string $today = null): string {
    $today = $today ?? date('Y-m-d');
    $status = $row['status'] ?? 'draft';
    if ($status !== 'approved') return $status === 'cancelled' ? 'cancelled' : 'draft';
    $start = (string)($row['start_date'] ?? '');
    $end   = (string)($row['end_date'] ?? '');
    if ($start !== '' && $start > $today) return 'upcoming';
    if ($end !== '' && $end < $today)     return 'expired';
    if ($end !== '' && contractDaysLeft($end, $today) <= CONTRACT_EXPIRING_DAYS) return 'expiring';
    return 'active';
}

// Days from $today until $end (0 on the last day). Negative once past.
function contractDaysLeft(string $end, ?string $today = null): int {
    $a = new DateTimeImmutable($today ?? date('Y-m-d'));
    $b = new DateTimeImmutable($end);
    return (int)$a->diff($b)->format('%r%a');
}

// [label, pill class] for a display state — mono pills from laskie-tokens.css.
function contractStateMeta(string $state): array {
    return [
        'active'    => ['Active',    'ok-pill'],
        'upcoming'  => ['Upcoming',  'ok-pill'],
        'expiring'  => ['Expiring',  'attn-pill'],
        'expired'   => ['Expired',   'muted-pill'],
        'draft'     => ['Draft',     'muted-pill'],
        'cancelled' => ['Voided',    'muted-pill'],
    ][$state] ?? [ucfirst($state), 'muted-pill'];
}

// ─── DB helpers ──────────────────────────────────────────────

// Next contract number, LSE-YYYY-0001. Same monotonic-counter scheme as
// generateInvoiceNo() (CLAUDE.md §5 inv. 8): drawn atomically from settings
// key contract_seq_LSE_YYYY, self-seeded above the highest number ever issued,
// never handed out twice — a voided contract keeps its number.
function generateContractNo(PDO $pdo): string {
    $year = date('Y');
    $key  = 'contract_seq_' . CONTRACT_NO_PREFIX . '_' . $year;

    $bump = $pdo->prepare("UPDATE settings SET setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1) WHERE setting_key = ?");
    $bump->execute([$key]);
    if ($bump->rowCount() === 0) {
        $max = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(contract_no,'-',-1) AS UNSIGNED)),0) FROM contracts WHERE contract_no LIKE ?");
        $max->execute([CONTRACT_NO_PREFIX . '-' . $year . '-%']);
        $seed = (int)$max->fetchColumn() + 1;
        $ins = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1)"
        );
        $ins->execute([$key, (string)$seed]);
        $next = ($ins->rowCount() === 1) ? $seed : (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    } else {
        $next = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    }
    return CONTRACT_NO_PREFIX . '-' . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// Saved company-wide defaults (settings contract_default_*), keyed by field.
function contractSavedDefaults(PDO $pdo): array {
    $keys = array_keys(array_filter(CONTRACT_FIELDS, fn($f) => !empty($f['def'])));
    if (!$keys) return [];
    $in = implode(',', array_fill(0, count($keys), '?'));
    $q  = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($in)");
    $q->execute(array_map(fn($k) => 'contract_default_' . $k, $keys));
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $out[substr($k, strlen('contract_default_'))] = (string)$v;
    }
    return $out;
}

// Persist the default-able fields of $fields as company-wide defaults.
function contractSaveDefaults(PDO $pdo, array $fields): void {
    $up = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    $uid = currentUser()['id'] ?? null;
    foreach (CONTRACT_FIELDS as $key => $f) {
        if (empty($f['def'])) continue;
        $up->execute(['contract_default_' . $key, (string)($fields[$key] ?? ''), $uid]);
    }
}

/**
 * Pre-fill every field from the records we have. Returns
 *   ['fields' => [key => value], 'sources' => [key => 'tenant'|'unit'|...]]
 * The sources drive the small chips in the generator so the admin can see
 * where each value came from. Everything stays editable.
 *
 * Precedence per field: record (tenant / unit / previous contract) → saved
 * default → company setting → built-in default.
 */
function contractPrefill(PDO $pdo, ?int $tenantId, ?int $unitId): array {
    $f = []; $src = [];
    $set = function (string $k, $v, string $source) use (&$f, &$src) {
        if ($v === null || $v === '' || array_key_exists($k, $f)) return;
        $f[$k] = (string)$v; $src[$k] = $source;
    };

    $tenant = null;
    if ($tenantId) {
        $q = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
        $q->execute([$tenantId]);
        $tenant = $q->fetch() ?: null;
        if ($tenant && !$unitId && $tenant['unit_id']) $unitId = (int)$tenant['unit_id'];
    }
    $unit = null;
    if ($unitId) {
        $q = $pdo->prepare("SELECT * FROM rental_units WHERE id=?");
        $q->execute([$unitId]);
        $unit = $q->fetch() ?: null;
    }

    // ── Agreement date
    $set('contract_date', date('Y-m-d'), 'today');

    // ── Lessee
    if ($tenant) {
        $set('lessee_name',    $tenant['full_name'], 'tenant');
        $set('lessee_address', $tenant['address'],   'tenant');
        $set('lessee_email',   $tenant['email'],     'tenant');
        $set('lessee_mobile',  $tenant['phone'],     'tenant');
    }

    // ── Previous contracts: this tenant's last approved one (renewal) and the
    // unit's last one (physical details rarely change between tenants).
    $prevTenant = null;
    if ($tenant) {
        $q = $pdo->prepare("SELECT * FROM contracts WHERE tenant_id=? AND status='approved' ORDER BY end_date DESC, id DESC LIMIT 1");
        $q->execute([(int)$tenant['id']]);
        $prevTenant = $q->fetch() ?: null;
    }
    $prevUnit = null;
    if ($unit) {
        $q = $pdo->prepare("SELECT * FROM contracts WHERE unit_id=? AND status IN ('approved','draft') ORDER BY (status='approved') DESC, id DESC LIMIT 1");
        $q->execute([(int)$unit['id']]);
        $prevUnit = $q->fetch() ?: null;
    }
    $prevUnitFields   = $prevUnit   ? (json_decode((string)$prevUnit['fields_json'], true)   ?: []) : [];

    // The lessee's own particulars come from their latest signed contract —
    // across a tenant transfer too (the old unit's contract is voided by the
    // move but still names the same person). A draft is never a source.
    $person = null;
    if ($tenant) {
        $chain = function_exists('transferChainIds') ? transferChainIds($pdo, (int)$tenant['id']) : [(int)$tenant['id']];
        $q = $pdo->prepare("SELECT fields_json FROM contracts WHERE tenant_id IN (" . implode(',', array_fill(0, count($chain), '?')) . ")
                            AND status IN ('approved','cancelled') ORDER BY (status='approved') DESC, id DESC LIMIT 1");
        $q->execute($chain);
        $person = $q->fetchColumn() ?: null;
    }
    $prevTenantFields = $person ? (json_decode((string)$person, true) ?: []) : [];

    // A renewal or transfer carries the lessee's own particulars forward.
    foreach (['lessee_nationality','lessee_civil_status','lessee_id','lessee_address','lessee_email','lessee_mobile'] as $k) {
        $set($k, $prevTenantFields[$k] ?? null, 'previous contract');
    }

    // ── Unit
    if ($unit) {
        $set('unit_no', $unit['unit_name'], 'unit');
        if ($unit['floor_area'] !== null && (float)$unit['floor_area'] > 0) {
            $set('floor_area_sqm', rtrim(rtrim(number_format((float)$unit['floor_area'], 2, '.', ''), '0'), '.'), 'unit');
        }
        $set('rent_due_day', ordinalDay((int)($unit['due_day'] ?: 5)), 'unit');
    }
    foreach (['floor_area_sqm','bedrooms','toilet_baths','max_occupants'] as $k) {
        $set($k, $prevUnitFields[$k] ?? null, 'last contract');
    }

    // ── Term. Renewal → the day after the last contract ends. A recent move-in
    // → the tenant's contract_start. Otherwise today.
    $start = null; $startSrc = 'today';
    if ($prevTenant && $prevTenant['end_date']) {
        $start = (new DateTimeImmutable($prevTenant['end_date']))->modify('+1 day')->format('Y-m-d');
        $startSrc = 'previous contract';
    } elseif ($tenant && $tenant['contract_start'] && $tenant['contract_start'] >= date('Y-m-d', strtotime('-60 days'))) {
        $start = $tenant['contract_start'];
        $startSrc = 'tenant';
    }
    $start = $start ?? date('Y-m-d');
    $set('start_date', $start, $startSrc);
    if ($startSrc === 'tenant' && $tenant['contract_end'] && $tenant['contract_end'] > $start) {
        $set('end_date', $tenant['contract_end'], 'tenant');
    }
    $set('end_date', contractDefaultEnd($start), 'computed');
    $set('term_words', contractTermWords($f['start_date'], $f['end_date']), 'computed');

    // ── Rent: the rate in effect for the first month of the lease — read
    // through rate history, never rental_units.monthly_rate directly (§5 inv. 1).
    if ($unit) {
        $sm = (int)substr($start, 5, 2); $sy = (int)substr($start, 0, 4);
        $rate = from_cents(to_cents(getRateForMonth($pdo, (int)$unit['id'], (float)$unit['monthly_rate'], $sm, $sy)));
        if (money_is_pos($rate)) {
            $set('monthly_rent', $rate, 'unit');
            $set('monthly_rent_words', contractAmountWords($rate), 'computed');
        }
    }

    // ── Saved defaults → company settings → built-ins
    foreach (contractSavedDefaults($pdo) as $k => $v) $set($k, $v, 'defaults');
    $set('lessor_name',    getSetting($pdo, 'company_name'),    'company');
    $set('lessor_address', getSetting($pdo, 'company_address'), 'company');
    $set('lessor_email',   getSetting($pdo, 'company_email'),   'company');
    $set('lessor_mobile',  getSetting($pdo, 'company_phone'),   'company');
    foreach (CONTRACT_BUILTIN_DEFAULTS as $k => $v) $set($k, $v, 'built-in');

    $fields = contractNormalizeFields($f);
    foreach (['advance_rent','security_deposit','move_in_total'] as $k) $src[$k] = 'computed';
    return ['fields' => $fields, 'sources' => $src, 'tenant_id' => $tenant ? (int)$tenant['id'] : null, 'unit_id' => $unitId ?: null];
}

// Disagreements between an approved contract and the live tenant record.
// The contract never syncs into billing, so these are surfaced as warnings.
function contractTenantMismatches(array $contract, ?array $tenant): array {
    if (!$tenant) return [];
    $out = [];
    if (($contract['unit_id'] ?? null) && (int)$tenant['unit_id'] !== (int)$contract['unit_id']) {
        $out[] = 'Tenant is no longer assigned to this unit.';
    }
    if ($contract['start_date'] && $tenant['contract_start'] && $tenant['contract_start'] > $contract['start_date']) {
        $out[] = 'Tenant record starts ' . fmtDate($tenant['contract_start']) . ', after this contract begins (' . fmtDate($contract['start_date']) . ').';
    }
    if ($contract['end_date'] && $tenant['contract_end'] && $tenant['contract_end'] !== $contract['end_date']) {
        $out[] = 'Tenant record ends ' . fmtDate($tenant['contract_end']) . '; contract ends ' . fmtDate($contract['end_date']) . '.';
    }
    if (in_array($tenant['status'], ['former','inactive'], true) && contractDisplayState($contract) !== 'expired'
        && ($contract['status'] ?? '') === 'approved') {
        $out[] = 'Tenant is marked ' . $tenant['status'] . ' but this contract is still running.';
    }
    return $out;
}

// Approved contracts on the same unit whose window overlaps [start, end].
function contractFindOverlap(PDO $pdo, int $unitId, string $start, string $end, int $excludeId = 0): ?array {
    $q = $pdo->prepare(
        "SELECT id, contract_no, lessee_name, start_date, end_date FROM contracts
         WHERE unit_id = ? AND id <> ? AND status = 'approved'
           AND start_date <= ? AND end_date >= ?
         ORDER BY start_date LIMIT 1"
    );
    $q->execute([$unitId, $excludeId, $end, $start]);
    return $q->fetch() ?: null;
}

/**
 * Approve a draft: validate, refuse an overlapping approved contract on the
 * same unit, draw a contract number and lock the snapshot. Atomic — the row
 * is locked FOR UPDATE so two tabs cannot both approve (and burn two numbers).
 * Returns ['ok'=>true,'contract_no'=>…] or ['ok'=>false,'error'=>…].
 */
function contractApprove(PDO $pdo, int $id): array {
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM contracts WHERE id=? FOR UPDATE");
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Contract not found.']; }
        if ($row['status'] !== 'draft') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only a draft can be approved (this one is ' . $row['status'] . ').'];
        }
        $fields = contractFields($row);
        if ($errors = contractApprovalErrors($fields)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "Complete these before approving:\n• " . implode("\n• ", $errors)];
        }
        if ($row['unit_id'] && ($clash = contractFindOverlap($pdo, (int)$row['unit_id'], $fields['start_date'], $fields['end_date'], $id))) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This lease (' . fmtDate($fields['start_date']) . ' → ' . fmtDate($fields['end_date'])
                . ') overlaps approved contract ' . $clash['contract_no'] . ' for ' . $clash['lessee_name']
                . ' (' . fmtDate($clash['start_date']) . ' → ' . fmtDate($clash['end_date']) . ') on the same unit. Void that one first, or adjust the dates.'];
        }
        $no  = generateContractNo($pdo);
        $uid = currentUser()['id'] ?? null;
        $pdo->prepare(
            "UPDATE contracts SET status='approved', contract_no=?, approved_by=?, approved_at=NOW(),
                    fields_json=?, lessee_name=?, unit_label=?, start_date=?, end_date=?, monthly_rent=?, updated_by=?
             WHERE id=?"
        )->execute([
            $no, $uid, json_encode($fields, JSON_UNESCAPED_UNICODE),
            mb_substr($fields['lessee_name'], 0, 120), mb_substr($fields['unit_no'], 0, 120),
            $fields['start_date'], $fields['end_date'], $fields['monthly_rent'], $uid, $id,
        ]);
        logChange($pdo, 'APPROVE_CONTRACT', 'Contracts',
            ['id' => $id, 'status' => 'draft'],
            ['id' => $id, 'status' => 'approved', 'contract_no' => $no, 'lessee' => $fields['lessee_name'],
             'unit' => $fields['unit_no'], 'start' => $fields['start_date'], 'end' => $fields['end_date'],
             'monthly_rent' => $fields['monthly_rent']]);
        $pdo->commit();
        return ['ok' => true, 'contract_no' => $no];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Full HTML document for a contract. $mode: 'screen' (paper view with a
// toolbar), 'embed' (generator live preview — blanks highlighted, no toolbar)
// or 'pdf' (chromium; vendor assets resolved to file:// paths).
function renderContractDocument(array $fields, array $meta, string $mode = 'screen'): string {
    if (!in_array($mode, ['screen', 'embed', 'pdf'], true)) $mode = 'screen';
    $assetBase = $mode === 'pdf' ? pdfAssetsBaseUrl() : '../assets/vendor/';
    ob_start();
    include __DIR__ . '/../includes/contracts/document.php';
    return (string)ob_get_clean();
}

// Build the $meta array the document needs from a contracts row.
function contractMeta(PDO $pdo, ?array $row): array {
    return [
        'id'               => $row['id'] ?? null,
        'contract_no'      => $row['contract_no'] ?? '',
        'status'           => $row['status'] ?? 'draft',
        'template_version' => $row['template_version'] ?? CONTRACT_TEMPLATE_VERSION,
        'company_name'     => getSetting($pdo, 'company_name', ''),
    ];
}

// Decoded fields of a stored contract. Every key the template expects is
// guaranteed present (a row from an older build still renders), but stored
// values win over re-normalisation so an APPROVED snapshot is reproduced
// exactly even if the schema's options or defaults change later.
function contractFields(array $row): array {
    $raw = json_decode((string)($row['fields_json'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $out = contractNormalizeFields($raw, (string)($row['template_version'] ?? CONTRACT_TEMPLATE_VERSION));
    foreach ($out as $k => $_) {
        if (isset($raw[$k]) && is_string($raw[$k])) $out[$k] = $raw[$k];
    }
    return $out;
}

// Render the contract document body for a template version. Returns HTML.
// $fields are already normalised; $meta carries contract_no / status / company.
function renderContractBody(array $fields, array $meta): string {
    $file = __DIR__ . '/../includes/contracts/lease_caloocan_v' . preg_replace('/\D/', '', (string)($meta['template_version'] ?? CONTRACT_TEMPLATE_VERSION)) . '.php';
    if (!is_file($file)) $file = __DIR__ . '/../includes/contracts/lease_caloocan_v1.php';
    $v = $fields;
    ob_start();
    include $file;
    return (string)ob_get_clean();
}
