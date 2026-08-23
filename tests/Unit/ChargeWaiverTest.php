<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * waivableRent() is the cap rule behind the admin "void charge" flow: an admin
 * may write off what a tenant still owes for a month, never more. Getting this
 * wrong either blocks legitimate write-offs or lets a paid month be waived into
 * a phantom credit balance, so the boundaries are pinned here.
 *
 * Contract: waivable = max(0, gross − net rent paid − already waived).
 */
final class ChargeWaiverTest extends TestCase
{
    #[Test]
    public function whole_charge_is_waivable_when_nothing_was_paid(): void
    {
        $this->assertSame('10000.00', waivableRent('10000.00', '0.00', '0.00'));
    }

    #[Test]
    public function only_the_unpaid_part_is_waivable(): void
    {
        // ₱10,000 charged, ₱6,000 collected → ₱4,000 may be written off.
        $this->assertSame('4000.00', waivableRent('10000.00', '6000.00', '0.00'));
    }

    #[Test]
    public function a_fully_paid_month_is_not_waivable(): void
    {
        $this->assertSame('0.00', waivableRent('10000.00', '10000.00', '0.00'));
    }

    #[Test]
    public function overpaid_month_never_returns_a_negative(): void
    {
        // An overpayment must not turn into "negative waivable" and be treated
        // as room to waive; it clamps at zero.
        $this->assertSame('0.00', waivableRent('10000.00', '12500.00', '0.00'));
    }

    #[Test]
    public function earlier_waivers_reduce_what_is_left(): void
    {
        // Partial waivers accumulate: ₱3,000 already written off leaves ₱7,000.
        $this->assertSame('7000.00', waivableRent('10000.00', '0.00', '3000.00'));
    }

    #[Test]
    public function payments_and_waivers_both_count_against_the_cap(): void
    {
        $this->assertSame('1500.00', waivableRent('10000.00', '5500.00', '3000.00'));
    }

    #[Test]
    public function a_fully_waived_month_has_nothing_left(): void
    {
        $this->assertSame('0.00', waivableRent('10000.00', '0.00', '10000.00'));
    }

    #[Test]
    public function prorated_centavos_survive_the_cap_math(): void
    {
        // Prorated first month (₱5,483.87) part-paid ₱1,000.01 → ₱4,483.86 left.
        // Cents math, so no float drift on the trailing centavo.
        $this->assertSame('4483.86', waivableRent('5483.87', '1000.01', '0.00'));
    }

    #[Test]
    public function accepts_float_and_numeric_string_input_alike(): void
    {
        $this->assertSame('4000.00', waivableRent(10000.00, 6000.00, 0.00));
        $this->assertSame('4000.00', waivableRent('10000', '6000', '0'));
    }

    #[Test]
    public function a_zero_rate_month_is_never_waivable(): void
    {
        $this->assertSame('0.00', waivableRent('0.00', '0.00', '0.00'));
    }
}
