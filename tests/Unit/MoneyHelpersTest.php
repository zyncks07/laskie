<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the cents-based money helpers in config/functions.php.
 * Ported from /tmp/test_money.php that we used during issue #3.
 *
 * Why these matter: every accounting calculation in the app routes through
 * these helpers. A regression here is a silent money-drift bug.
 */
final class MoneyHelpersTest extends TestCase
{
    // ─── to_cents / from_cents ───────────────────────────────────

    #[Test]
    public function to_cents_handles_integers(): void
    {
        $this->assertSame(0,   to_cents(0));
        $this->assertSame(100, to_cents(1));
    }

    #[Test]
    public function to_cents_handles_decimal_strings_and_floats(): void
    {
        $this->assertSame(123,  to_cents('1.23'));
        $this->assertSame(123,  to_cents(1.23));
        $this->assertSame(-123, to_cents('-1.23'));
    }

    #[Test]
    public function to_cents_uses_half_up_rounding(): void
    {
        $this->assertSame(1, to_cents(0.005));  // half rounds up
        $this->assertSame(0, to_cents(0.004));
    }

    #[Test]
    public function to_cents_handles_max_decimal_12_2(): void
    {
        // DECIMAL(12,2) max is 999,999,999,999.99 — must fit in int with room
        $this->assertSame(99999999999900, to_cents('999999999999.00'));
    }

    #[Test]
    public function from_cents_emits_canonical_two_decimal_form(): void
    {
        $this->assertSame('0.00',    from_cents(0));
        $this->assertSame('1.23',    from_cents(123));
        $this->assertSame('-1.23',   from_cents(-123));
        $this->assertSame('1000.00', from_cents(100000));
    }

    // ─── money_add / money_sub ───────────────────────────────────

    #[Test]
    public function money_add_basic_cases(): void
    {
        $this->assertSame('3.00', money_add('1.00', '2.00'));
        $this->assertSame('5.55', money_add('1.11', '4.44'));
    }

    #[Test]
    public function money_add_kills_the_classic_float_drift(): void
    {
        // 0.10 + 0.20 == 0.300000000000000044 in float; we want exactly 0.30.
        $this->assertSame('0.30', money_add('0.10', '0.20'));
        $this->assertSame('0.30', money_add(0.10, 0.20));
    }

    #[Test]
    public function money_sub_basic_and_self_cancel(): void
    {
        $this->assertSame('-0.50', money_sub('1.00', '1.50'));
        $this->assertSame('0.00',  money_sub('1.23', '1.23'));
    }

    // ─── money_sum ───────────────────────────────────────────────

    #[Test]
    public function money_sum_empty_is_zero(): void
    {
        $this->assertSame('0.00', money_sum([]));
    }

    #[Test]
    public function money_sum_no_drift_over_many_values(): void
    {
        $this->assertSame('0.30', money_sum(['0.10', '0.10', '0.10']));
        $this->assertSame('0.30', money_sum([0.1, 0.1, 0.1]));
        $this->assertSame('6.66', money_sum(['1.11', '2.22', '3.33']));
    }

    #[Test]
    public function money_sum_one_thousand_cents_equals_ten_pesos(): void
    {
        $this->assertSame('10.00', money_sum(array_fill(0, 1000, '0.01')));
    }

    // ─── money_mul / money_div ───────────────────────────────────

    #[Test]
    public function money_mul_basic(): void
    {
        $this->assertSame('10.00', money_mul('5.00', 2.0));
        $this->assertSame('1.50',  money_mul('3.00', 0.5));
    }

    #[Test]
    public function money_div_rounds_half_up(): void
    {
        $this->assertSame('2.50',  money_div('5.00', 2.0));
        $this->assertSame('33.33', money_div('100.00', 3.0));
        $this->assertSame('33.34', money_div('100.01', 3.0));
    }

    #[Test]
    public function money_div_zero_throws(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        money_div('5.00', 0.0);
    }

    // ─── Comparators ─────────────────────────────────────────────

    public static function cmpProvider(): array
    {
        return [
            'less than'    => [-1, '1.00', '2.00'],
            'equal'        => [ 0, '1.00', '1.00'],
            'greater than' => [ 1, '2.00', '1.00'],
        ];
    }

    #[Test]
    #[DataProvider('cmpProvider')]
    public function money_cmp_returns_expected(int $expected, string $a, string $b): void
    {
        $this->assertSame($expected, money_cmp($a, $b));
    }

    #[Test]
    public function comparators_handle_mixed_types(): void
    {
        $this->assertTrue(money_eq(1.00, '1.00'));
        $this->assertTrue(money_gt('2.00', '1.00'));
        $this->assertTrue(money_gte('1.00', '1.00'));
        $this->assertTrue(money_lt('1.00', '2.00'));
        $this->assertFalse(money_lt('1.00', '1.00'));
        $this->assertTrue(money_lte('1.00', '1.00'));
    }

    #[Test]
    public function money_max_picks_larger_handles_negatives(): void
    {
        $this->assertSame('2.00', money_max('1.00', '2.00'));
        $this->assertSame('0.00', money_max('0.00', '-5.00'));
    }

    #[Test]
    public function is_zero_and_is_pos_semantics(): void
    {
        $this->assertTrue(money_is_zero('0.00'));
        $this->assertFalse(money_is_zero('0.01'));

        $this->assertTrue(money_is_pos('0.01'));
        $this->assertFalse(money_is_pos('0.00'),  'zero is not positive');
        $this->assertFalse(money_is_pos('-0.01'), 'negative is not positive');
    }
}
