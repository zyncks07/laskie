<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The POST-PAID rent cycle (config/functions.php "Rent Billing Cycle").
 *
 * Charge month M is due on the unit's due day of M and covers the cycle that
 * has just ended: [due(M−1), due(M) − 1]. With due day 5 the January bill
 * (due Jan 5) is for Dec 5 – Jan 4. A tenancy pays for the days of each cycle
 * it occupies, so:
 *   - moving in on the due day → the first bill is a full month, a month later
 *   - moving out the day before a due day → the last bill is a full month
 *   - a handover on any day bills exactly one cycle between the two tenants
 * These replace the old calendar-month ProrationTest / MoveOutProrationTest /
 * ChargeDateTest (pre-paid model, retired 2026-10-04).
 */
final class RentCycleTest extends TestCase
{
    // ── due dates & cycles ───────────────────────────────────────

    public static function dueDates(): array
    {
        return [
            'normal'              => [5,  10, 2026, '2026-10-05'],
            'due 31 in April'     => [31, 4,  2026, '2026-04-30'],
            'due 31 in Feb'       => [31, 2,  2026, '2026-02-28'],
            'due 30 in leap Feb'  => [30, 2,  2028, '2028-02-29'],
            'due day 0 → 1st'     => [0,  3,  2026, '2026-03-01'],
        ];
    }

    #[Test]
    #[DataProvider('dueDates')]
    public function due_date_is_clamped_to_the_month(int $due, int $m, int $y, string $expected): void
    {
        $this->assertSame($expected, rentDueDate($due, $m, $y));
    }

    #[Test]
    public function january_bill_covers_december_fifth_to_january_fourth(): void
    {
        $this->assertSame(['2025-12-05', '2026-01-04'], rentCycle(5, 1, 2026));
        $this->assertSame(['2026-09-05', '2026-10-04'], rentCycle(5, 10, 2026));
        $this->assertSame(['2026-02-28', '2026-03-30'], rentCycle(31, 3, 2026));
    }

    #[Test]
    public function cycles_tile_the_calendar_with_no_gap_or_overlap(): void
    {
        foreach ([1, 5, 15, 28, 29, 30, 31] as $due) {
            for ($k = 0; $k < 30; $k++) {
                $y = 2026 + intdiv($k, 12); $m = $k % 12 + 1;
                $ny = $m === 12 ? $y + 1 : $y; $nm = $m === 12 ? 1 : $m + 1;
                [, $end]    = rentCycle($due, $m, $y);
                [$nextStart] = rentCycle($due, $nm, $ny);
                $this->assertSame(date('Y-m-d', strtotime($end . ' +1 day')), $nextStart, "due $due, $y-$m");
            }
        }
    }

    #[Test]
    public function cycle_month_for_a_date(): void
    {
        $this->assertSame([10, 2026], rentCycleMonthFor(5, '2026-10-04'));
        $this->assertSame([11, 2026], rentCycleMonthFor(5, '2026-10-05'));
        $this->assertSame([1, 2027],  rentCycleMonthFor(5, '2026-12-20'));
        $this->assertSame([1, 2026],  rentCycleMonthFor(5, '2025-12-05'));
    }

    // ── amounts ──────────────────────────────────────────────────

    #[Test]
    public function a_full_cycle_is_exactly_the_rate(): void
    {
        $this->assertSame('6000.00', prorateOccupancyMonth('6000', 5, '2025-12-05', null, 7, 2026));
        $this->assertSame('6000.00', prorateOccupancyMonth(6000.0, 5, null, null, 7, 2026));
    }

    #[Test]
    public function moving_in_on_the_due_day_bills_a_full_month_one_month_later(): void
    {
        $this->assertSame('0.00',    prorateOccupancyMonth('6000', 5, '2026-01-05', null, 1, 2026));
        $this->assertSame('6000.00', prorateOccupancyMonth('6000', 5, '2026-01-05', null, 2, 2026));
        // Migrated tenancies start Dec 5, 2025, so the January bill (Dec 5 – Jan 4) is whole.
        $this->assertSame('6000.00', prorateOccupancyMonth('6000', 5, '2025-12-05', null, 1, 2026));
        $this->assertSame('0.00',    prorateOccupancyMonth('6000', 5, '2025-12-05', null, 12, 2025));
    }

    #[Test]
    public function moving_in_mid_cycle_prorates_the_first_bill_by_cycle_days(): void
    {
        // Jan 20 – Feb 4 = 16 of 31 days (cycle Jan 5 – Feb 4).
        $this->assertSame('3096.77', prorateOccupancyMonth('6000', 5, '2026-01-20', null, 2, 2026));
    }

    #[Test]
    public function leaving_the_day_before_a_due_day_bills_the_whole_last_cycle(): void
    {
        // Out Oct 4 → the October bill (Sep 5 – Oct 4) is the full rate.
        $this->assertSame('6000.00', prorateOccupancyMonth('6000', 5, '2025-12-05', '2026-10-04', 10, 2026));
        $this->assertSame('0.00',    prorateOccupancyMonth('6000', 5, '2025-12-05', '2026-10-04', 11, 2026));
    }

    #[Test]
    public function leaving_mid_cycle_prorates_the_last_bill(): void
    {
        // Out Oct 20: cycle Oct 5 – Nov 4 (31 days), occupied Oct 5–20 = 16 days.
        $this->assertSame('3096.77', prorateOccupancyMonth('6000', 5, '2025-12-05', '2026-10-20', 11, 2026));
    }

    #[Test]
    public function a_handover_on_any_day_bills_exactly_one_cycle(): void
    {
        foreach (['2026-10-05', '2026-10-06', '2026-10-15', '2026-11-04'] as $x) {
            $outEnd = date('Y-m-d', strtotime("$x -1 day"));
            [$m, $y] = rentCycleMonthFor(5, $x);
            $out = prorateOccupancyMonth('8500', 5, '2025-12-05', $outEnd, $m, $y);
            $in  = prorateOccupancyMonth('8500', 5, $x, null, $m, $y);
            $this->assertSame('8500.00', money_add($out, $in), "handover $x");
        }
    }

    #[Test]
    public function a_stay_inside_one_cycle_bills_only_its_days(): void
    {
        // Oct 10–20 inside cycle Oct 5 – Nov 4: 11 of 31 days.
        $this->assertSame('3016.13', prorateOccupancyMonth('8500', 5, '2026-10-10', '2026-10-20', 11, 2026));
    }

    #[Test]
    public function leap_february_cycles_use_their_true_length(): void
    {
        // Cycle Feb 5 – Mar 4, 2028 has 29 days; out Feb 18 → 14 days.
        $this->assertSame('4827.59', prorateOccupancyMonth('10000', 5, '2027-01-05', '2028-02-18', 3, 2028));
        // Same in 2027 (28 days).
        $this->assertSame('5000.00', prorateOccupancyMonth('10000', 5, '2026-01-05', '2027-02-18', 3, 2027));
    }

    #[Test]
    public function zero_rate_stays_zero(): void
    {
        $this->assertSame('0.00', prorateOccupancyMonth('0.00', 5, '2025-12-05', null, 3, 2026));
    }

    // ── charge dates ─────────────────────────────────────────────

    #[Test]
    public function bills_fall_due_on_the_due_date(): void
    {
        $this->assertSame('2026-05-05', chargeDate(5, 5, 2026));
        $this->assertSame('2026-02-28', chargeDate(31, 2, 2026));
        $this->assertSame('2026-10-05', chargeDate(5, 10, 2026, '2026-10-04'), 'end on cycle end → the normal due date');
        $this->assertSame('2026-05-05', chargeDate(5, 5, 2026, '2027-01-04'), 'an end far away changes nothing');
    }

    #[Test]
    public function the_final_partial_cycle_is_billed_at_move_out(): void
    {
        $this->assertSame('2026-10-21', chargeDate(5, 11, 2026, '2026-10-20'));
    }
}
