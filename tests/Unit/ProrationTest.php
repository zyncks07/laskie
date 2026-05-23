<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * prorateFirstMonth() is what determines a tenant's first-month bill when
 * the contract starts mid-period. Regressions here become billing disputes,
 * so the edge cases below pin the exact behaviour.
 */
final class ProrationTest extends TestCase
{
    #[Test]
    public function prorates_when_contract_starts_after_due_day(): void
    {
        // ₱10000/month, due day 5, contract starts March 15 (31-day month, 17 days occupied).
        // Single-rounded: round(10000 × 17 / 31, 2) = 5483.87
        $this->assertSame('5483.87', prorateFirstMonth('10000.00', 5, '2024-03-15', 3, 2024));
    }

    #[Test]
    public function accepts_float_input_with_same_result(): void
    {
        $this->assertSame('5483.87', prorateFirstMonth(10000.00, 5, '2024-03-15', 3, 2024));
    }

    #[Test]
    public function start_before_due_day_charges_full_rate(): void
    {
        $this->assertSame('10000.00', prorateFirstMonth('10000.00', 5, '2024-03-03', 3, 2024));
    }

    #[Test]
    public function start_on_due_day_charges_full_rate(): void
    {
        $this->assertSame('10000.00', prorateFirstMonth('10000.00', 5, '2024-03-05', 3, 2024));
    }

    #[Test]
    public function no_contract_start_charges_full_rate(): void
    {
        $this->assertSame('10000.00', prorateFirstMonth('10000.00', 5, null, 3, 2024));
    }

    #[Test]
    public function start_in_different_month_charges_full_rate(): void
    {
        // contract started Feb 15 — for March we bill full month
        $this->assertSame('10000.00', prorateFirstMonth('10000.00', 5, '2024-02-15', 3, 2024));
    }

    #[Test]
    public function zero_rate_returns_zero(): void
    {
        $this->assertSame('0.00', prorateFirstMonth('0.00', 5, '2024-03-15', 3, 2024));
    }

    #[Test]
    public function thirty_day_month_proration_is_correct(): void
    {
        // ₱5000/30 × 16 = 2666.6666… → 2666.67
        $this->assertSame('2666.67', prorateFirstMonth('5000.00', 5, '2024-09-15', 9, 2024));
    }

    #[Test]
    public function february_leap_year_uses_correct_day_count(): void
    {
        // Feb 2024 = 29 days. Start Feb 10, due day 5 → 20 days occupied.
        // (10000 × 20) / 29 = 6896.5517… → 6896.55
        $this->assertSame('6896.55', prorateFirstMonth('10000.00', 5, '2024-02-10', 2, 2024));
    }

    #[Test]
    public function february_non_leap_year_uses_28_days(): void
    {
        // Feb 2023 = 28 days. Start Feb 10, due day 5 → 19 days occupied.
        // (10000 × 19) / 28 = 6785.7142… → 6785.71
        $this->assertSame('6785.71', prorateFirstMonth('10000.00', 5, '2023-02-10', 2, 2023));
    }
}
