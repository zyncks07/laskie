<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * prorateLastMonth() / prorateOccupancyMonth() decide a tenant's FINAL bill.
 *
 * Before these existed, a tenant who vacated mid-month was billed the whole
 * month — and when the successor moved in during the same month, the unit
 * billed well over one month's rent for it. The cases below pin the exact
 * behaviour, including that the two ends compose over a single day span when a
 * tenancy both starts and ends inside one month.
 *
 * contract_end is INCLUSIVE — the last day of occupancy — matching how
 * buildRentChargeRows() and the SoA occupant query have always read it.
 */
final class MoveOutProrationTest extends TestCase
{
    #[Test]
    public function prorates_when_contract_ends_mid_month(): void
    {
        // ₱8500/month, July = 31 days, leaves on the 15th → 15 days occupied.
        // (8500 × 15) / 31 = 4112.903… → 4112.90
        $this->assertSame('4112.90', prorateLastMonth('8500.00', '2026-07-15', 7, 2026));
    }

    #[Test]
    public function last_day_of_month_bills_the_full_rate(): void
    {
        // Occupied every day of the month — nothing to prorate.
        $this->assertSame('8500.00', prorateLastMonth('8500.00', '2026-07-31', 7, 2026));
    }

    #[Test]
    public function single_day_of_occupancy_bills_one_day(): void
    {
        // The live 359-D case: contract_end 2026-09-01, September = 30 days.
        // 8500 / 30 = 283.333… → 283.33
        $this->assertSame('283.33', prorateLastMonth('8500.00', '2026-09-01', 9, 2026));
    }

    #[Test]
    public function months_before_the_final_one_are_untouched(): void
    {
        // contract_end is in September, so August bills in full.
        $this->assertSame('8500.00', prorateLastMonth('8500.00', '2026-09-01', 8, 2026));
    }

    #[Test]
    public function open_ended_contract_bills_the_full_rate(): void
    {
        $this->assertSame('8500.00', prorateLastMonth('8500.00', null, 7, 2026));
    }

    #[Test]
    public function february_leap_year_uses_correct_day_count(): void
    {
        // Feb 2024 = 29 days, leaves on the 10th.
        // (10000 × 10) / 29 = 3448.2758… → 3448.28
        $this->assertSame('3448.28', prorateLastMonth('10000.00', '2024-02-10', 2, 2024));
    }

    #[Test]
    public function february_non_leap_year_uses_28_days(): void
    {
        // Feb 2023 = 28 days, leaves on the 10th.
        // (10000 × 10) / 28 = 3571.4285… → 3571.43
        $this->assertSame('3571.43', prorateLastMonth('10000.00', '2023-02-10', 2, 2023));
    }

    #[Test]
    public function zero_rate_stays_zero(): void
    {
        $this->assertSame('0.00', prorateLastMonth('0.00', '2026-07-15', 7, 2026));
    }

    // ── prorateOccupancyMonth: composition of both ends ──────────────

    #[Test]
    public function start_month_alone_matches_prorate_first_month(): void
    {
        $this->assertSame(
            prorateFirstMonth('8500.00', 5, '2026-07-16', 7, 2026),
            prorateOccupancyMonth('8500.00', 5, '2026-07-16', '2026-12-31', 7, 2026)
        );
    }

    #[Test]
    public function end_month_alone_matches_prorate_last_month(): void
    {
        $this->assertSame(
            prorateLastMonth('8500.00', '2026-07-15', 7, 2026),
            prorateOccupancyMonth('8500.00', 5, '2026-01-01', '2026-07-15', 7, 2026)
        );
    }

    #[Test]
    public function middle_month_bills_the_full_rate(): void
    {
        $this->assertSame('8500.00', prorateOccupancyMonth('8500.00', 5, '2026-01-01', '2026-12-31', 7, 2026));
    }

    #[Test]
    public function same_month_start_and_end_compose_over_one_span(): void
    {
        // Moves in Jul 10, out Jul 20 → 11 days of a 31-day month, NOT the
        // product of two independent factors (which would charge for days twice).
        // (8500 × 11) / 31 = 3016.129… → 3016.13
        $this->assertSame('3016.13', prorateOccupancyMonth('8500.00', 5, '2026-07-10', '2026-07-20', 7, 2026));
    }

    #[Test]
    public function same_month_start_on_or_before_due_day_runs_from_day_one(): void
    {
        // Moves in Jul 3 with due day 5 → the whole month is owed from day 1,
        // same rule prorateFirstMonth() applies. Out on Jul 20 → 20 of 31 days.
        // (8500 × 20) / 31 = 5483.87096… → 5483.87
        $this->assertSame('5483.87', prorateOccupancyMonth('8500.00', 5, '2026-07-03', '2026-07-20', 7, 2026));
    }

    #[Test]
    public function mid_month_handover_bills_exactly_one_month_across_both_tenants(): void
    {
        // The whole point: outgoing leaves Jul 15, incoming starts Jul 16.
        // The two prorated charges must add up to exactly one month's rent.
        $out = prorateOccupancyMonth('8500.00', 5, '2026-01-01', '2026-07-15', 7, 2026);
        $in  = prorateOccupancyMonth('8500.00', 5, '2026-07-16', null,         7, 2026);
        $this->assertSame('8500.00', money_add($out, $in));
    }
}
