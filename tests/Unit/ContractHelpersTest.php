<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pure helpers behind the lease-contract generator (config/contract_functions.php).
 *
 * What these pin:
 *   - the amount in words always agrees with the figures (a contract that
 *     says one rent in words and another in numbers is a dispute waiting)
 *   - the move-in money is fixed by the template and cents-exact
 *   - the term wording respects an INCLUSIVE end date
 *   - active / expiring / expired are derived from the dates at the edges
 *   - the field whitelist drops anything not in the schema and never trusts a
 *     posted deposit or total
 */
final class ContractHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../config/contract_functions.php';
    }

    // ── Amount in words ──────────────────────────────────────────

    public static function amountWords(): array
    {
        return [
            'round thousands'   => ['8000.00',    'EIGHT THOUSAND'],
            'with centavos'     => ['10500.50',   'TEN THOUSAND FIVE HUNDRED AND 50/100'],
            'one million'       => ['1000000',    'ONE MILLION'],
            'hyphenated tens'   => ['3521',       'THREE THOUSAND FIVE HUNDRED TWENTY-ONE'],
            'teens'             => ['13013',      'THIRTEEN THOUSAND THIRTEEN'],
            'single centavo'    => ['7500.05',    'SEVEN THOUSAND FIVE HUNDRED AND 05/100'],
            'large compound'    => ['1234567.89', 'ONE MILLION TWO HUNDRED THIRTY-FOUR THOUSAND FIVE HUNDRED SIXTY-SEVEN AND 89/100'],
        ];
    }

    #[Test]
    #[DataProvider('amountWords')]
    public function amount_words_match_the_figures(string $amount, string $words): void
    {
        $this->assertSame($words, contractAmountWords($amount));
    }

    // ── Ordinals ─────────────────────────────────────────────────

    #[Test]
    public function ordinals_handle_the_teens_exception(): void
    {
        $got = array_map('ordinalDay', [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 28, 31, 111, 112]);
        $this->assertSame(['1st','2nd','3rd','4th','11th','12th','13th','21st','22nd','23rd','28th','31st','111th','112th'], $got);
    }

    // ── Term wording (end date inclusive) ────────────────────────

    public static function terms(): array
    {
        return [
            'one year'             => ['2026-10-05', '2027-10-04', 'one (1) year'],
            'six months'           => ['2026-10-05', '2027-04-04', 'six (6) months'],
            'year and a half'      => ['2026-10-05', '2028-04-04', 'one (1) year and six (6) months'],
            'two years'            => ['2026-10-05', '2028-10-04', 'two (2) years'],
            'months and days'      => ['2026-10-05', '2026-12-19', 'two (2) months and fifteen (15) days'],
            'year, months, days'   => ['2026-01-01', '2027-03-10', 'one (1) year, two (2) months and ten (10) days'],
            'month-end start'      => ['2026-01-31', '2027-01-30', 'one (1) year'],
            'single day'           => ['2026-10-05', '2026-10-05', 'one (1) day'],
        ];
    }

    #[Test]
    #[DataProvider('terms')]
    public function term_words_count_the_end_date_as_occupied(string $start, string $end, string $words): void
    {
        $this->assertSame($words, contractTermWords($start, $end));
    }

    #[Test]
    public function term_words_are_blank_for_bad_input(): void
    {
        $this->assertSame('', contractTermWords('2027-01-01', '2026-01-01'));
        $this->assertSame('', contractTermWords('', '2026-01-01'));
        $this->assertSame('', contractTermWords('2026-02-30', '2027-01-01'));
    }

    #[Test]
    public function default_end_is_twelve_months_less_a_day(): void
    {
        $this->assertSame('2027-10-04', contractDefaultEnd('2026-10-05'));
        $this->assertSame('2027-01-30', contractDefaultEnd('2026-01-31'));
        $this->assertSame('2027-02-28', contractDefaultEnd('2026-03-01'));
        $this->assertSame('2026-12-31', contractDefaultEnd('2026-01-01'));
    }

    // ── Move-in money ────────────────────────────────────────────

    #[Test]
    public function move_in_money_follows_the_template_version(): void
    {
        // v2 (post-paid, current): no advance — 2 months deposit + ₱3,000.
        $this->assertSame(
            ['advance_rent' => '0.00', 'security_deposit' => '16000.00', 'move_in_total' => '19000.00'],
            contractComputeMoney('8000')
        );
        // v1 (pre-paid, kept for contracts approved on it): + 1 month advance.
        $this->assertSame(
            ['advance_rent' => '8000.00', 'security_deposit' => '16000.00', 'move_in_total' => '27000.00'],
            contractComputeMoney('8000', '1')
        );
        // Cents-exact on an awkward amount: 2 × 3333.33 + 3000 = 9666.66.
        $m = contractComputeMoney('3333.33');
        $this->assertSame('6666.66', $m['security_deposit']);
        $this->assertSame('9666.66', $m['move_in_total']);
    }

    // ── Normalisation ────────────────────────────────────────────

    #[Test]
    public function normalise_drops_unknown_keys_and_recomputes_deposits(): void
    {
        $f = contractNormalizeFields([
            'lessee_name'      => "  Juan   Dela\nCruz ",
            'monthly_rent'     => '₱8,000',
            'security_deposit' => '1.00',         // posted computed value — must be ignored
            'move_in_total'    => '2.00',
            'evil_extra'       => '<script>',
            'start_date'       => '2026-13-01',   // invalid → blank
            'caloocan_side'    => 'East',         // not an option → default
        ]);
        $this->assertArrayNotHasKey('evil_extra', $f);
        $this->assertSame('Juan Dela Cruz', $f['lessee_name']);
        $this->assertSame('8000.00', $f['monthly_rent']);
        $this->assertSame('16000.00', $f['security_deposit']);
        $this->assertSame('19000.00', $f['move_in_total']);
        $this->assertSame('', $f['start_date']);
        $this->assertSame('North', $f['caloocan_side']);
        // Every schema key is present, so the template never hits an undefined index.
        $this->assertSame([], array_diff(array_keys(CONTRACT_FIELDS), array_keys($f)));
    }

    #[Test]
    public function normalise_caps_text_length(): void
    {
        $f = contractNormalizeFields(['unit_no' => str_repeat('x', 500)]);
        $this->assertSame(CONTRACT_FIELDS['unit_no']['max'], mb_strlen($f['unit_no']));
    }

    #[Test]
    public function approval_errors_list_blank_required_fields_and_bad_dates(): void
    {
        $errors = contractApprovalErrors(contractNormalizeFields(['start_date' => '2026-10-05', 'end_date' => '2026-01-01']));
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('Lessee name is required', $joined);
        $this->assertStringContainsString('End date cannot be before the start date', $joined);
        // Optional fields never block approval.
        $this->assertStringNotContainsString('Witness', $joined);
    }

    // ── Auto words ───────────────────────────────────────────────

    #[Test]
    public function auto_words_follow_the_numbers_and_hand_typed_words_are_checked(): void
    {
        $f = contractNormalizeFields(['monthly_rent' => '8500', 'start_date' => '2026-10-05', 'end_date' => '2027-10-04',
                                      'monthly_rent_words' => 'EIGHT THOUSAND', 'term_words' => 'one (1) year']);
        $auto = contractApplyAutoWords($f, true, true);
        $this->assertSame('EIGHT THOUSAND FIVE HUNDRED', $auto['fields']['monthly_rent_words']);
        $this->assertFalse($auto['rent_mismatch']);

        $manual = contractApplyAutoWords($f, false, false);
        $this->assertSame('EIGHT THOUSAND', $manual['fields']['monthly_rent_words'], 'hand-typed words are kept');
        $this->assertTrue($manual['rent_mismatch'], '…but flagged when they disagree with the figures');
        $this->assertFalse($manual['term_mismatch'], 'case/spacing differences are not a mismatch');
    }

    // ── Display state ────────────────────────────────────────────

    public static function states(): array
    {
        $c = fn(string $s, string $e, string $status = 'approved') => ['status' => $status, 'start_date' => $s, 'end_date' => $e];
        return [
            'draft'                 => [$c('2026-01-01', '2026-12-31', 'draft'),     'draft'],
            'voided'                => [$c('2026-01-01', '2026-12-31', 'cancelled'), 'cancelled'],
            'starts tomorrow'       => [$c('2026-10-05', '2027-10-04'), 'upcoming'],
            'starts today'          => [$c('2026-10-04', '2027-10-03'), 'active'],
            'ends today'            => [$c('2025-10-05', '2026-10-04'), 'expiring'],
            'ended yesterday'       => [$c('2025-10-04', '2026-10-03'), 'expired'],
            '60 days left'          => [$c('2026-01-01', '2026-12-03'), 'expiring'],
            '61 days left'          => [$c('2026-01-01', '2026-12-04'), 'active'],
        ];
    }

    #[Test]
    #[DataProvider('states')]
    public function display_state_is_derived_from_the_dates(array $row, string $expected): void
    {
        $this->assertSame($expected, contractDisplayState($row, '2026-10-04'));
    }
}
