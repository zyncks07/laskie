<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for monthRange() / yearRange() — the sargable date helpers used
 * by dashboard.php, my_summary.php, api/expenses_api.php, api/cash_api.php,
 * payments/audit_pdf.php to keep WHERE clauses index-friendly.
 *
 * The contract: both helpers return [startDate, exclusiveEndDate] so the
 * predicate is `col >= start AND col < end`. NEVER include the end date
 * in the range — a payment on the last day of the period would otherwise
 * be missed or double-counted depending on time component.
 */
final class DateRangeTest extends TestCase
{
    #[Test]
    public function month_range_returns_start_and_exclusive_next_month(): void
    {
        $this->assertSame(['2026-05-01', '2026-06-01'], monthRange(5, 2026));
    }

    #[Test]
    public function month_range_handles_december_year_rollover(): void
    {
        $this->assertSame(['2026-12-01', '2027-01-01'], monthRange(12, 2026));
    }

    #[Test]
    public function month_range_handles_january_no_underflow(): void
    {
        $this->assertSame(['2026-01-01', '2026-02-01'], monthRange(1, 2026));
    }

    #[Test]
    public function month_range_february_leap_year(): void
    {
        // The range is end-exclusive, so leap day handling doesn't affect the range
        // boundaries — but the next-month start should be March 1 regardless.
        $this->assertSame(['2024-02-01', '2024-03-01'], monthRange(2, 2024));
    }

    #[Test]
    public function year_range_returns_jan_to_next_year_jan(): void
    {
        $this->assertSame(['2026-01-01', '2027-01-01'], yearRange(2026));
    }

    #[Test]
    public function range_endpoint_is_exclusive_so_last_day_is_included(): void
    {
        // Regression guard: the exclusive end must not equal the last day of
        // the month, otherwise `col < end` would EXCLUDE that day.
        [$start, $end] = monthRange(5, 2026);
        $this->assertNotSame('2026-05-31', $end, 'end must NOT be the last day of the month');
        $this->assertSame('2026-06-01', $end);
    }
}
