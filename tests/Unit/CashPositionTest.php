<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * splitCashPosition() decomposes the signed per-user ledger balance into the
 * two different things a single account was carrying:
 *   - on_hand: company cash the user physically holds (never negative)
 *   - owed:    expenses they shouldered past their float, not yet recovered
 *
 * The invariant that matters for the books is balance = on_hand - owed, so the
 * split reports the same rows two ways without inventing or losing a centavo.
 */
final class CashPositionTest extends TestCase
{
    #[Test]
    public function positive_balance_is_all_cash_on_hand(): void
    {
        $p = splitCashPosition('32023.50');
        $this->assertSame('32023.50', $p['on_hand']);
        $this->assertSame('0.00',     $p['owed']);
        $this->assertSame('32023.50', $p['balance']);
    }

    #[Test]
    public function negative_balance_becomes_zero_cash_and_a_debt(): void
    {
        // Paul's real position on 2026-09-19.
        $p = splitCashPosition('-4770.62');
        $this->assertSame('0.00',    $p['on_hand']);
        $this->assertSame('4770.62', $p['owed']);
        $this->assertSame('-4770.62', $p['balance']);
    }

    #[Test]
    public function zero_balance_is_square_on_both_sides(): void
    {
        $p = splitCashPosition('0.00');
        $this->assertSame('0.00', $p['on_hand']);
        $this->assertSame('0.00', $p['owed']);
    }

    #[Test]
    public function one_centavo_either_side_of_zero(): void
    {
        $pos = splitCashPosition('0.01');
        $this->assertSame('0.01', $pos['on_hand']);
        $this->assertSame('0.00', $pos['owed']);

        $neg = splitCashPosition('-0.01');
        $this->assertSame('0.00', $neg['on_hand']);
        $this->assertSame('0.01', $neg['owed']);
    }

    #[Test]
    public function balance_always_equals_on_hand_minus_owed(): void
    {
        foreach (['0.00', '0.01', '-0.01', '5000.00', '-46770.62', '928420.00', '-0.99'] as $b) {
            $p = splitCashPosition($b);
            $this->assertSame(
                from_cents(to_cents($b)),
                money_sub($p['on_hand'], $p['owed']),
                "balance != on_hand - owed for $b"
            );
        }
    }

    #[Test]
    public function accepts_floats_and_ints_like_the_other_money_helpers(): void
    {
        $this->assertSame('1500.00', splitCashPosition(1500)['on_hand']);
        $this->assertSame('4770.62', splitCashPosition(-4770.62)['owed']);
    }

    #[Test]
    public function halves_are_summed_per_user_not_split_from_the_aggregate(): void
    {
        // One staffer owed 5,000 while another holds 5,000 is 5,000 of company
        // cash AND a 5,000 liability — not a tidy zero. Splitting the aggregate
        // (-5000 + 5000 = 0) would erase both; summing the halves keeps them.
        $balances = ['-5000.00', '5000.00'];
        $splits   = array_map('splitCashPosition', $balances);

        $this->assertSame('5000.00', money_sum(array_column($splits, 'on_hand')));
        $this->assertSame('5000.00', money_sum(array_column($splits, 'owed')));
        $this->assertSame('0.00',    money_sum($balances));

        // And the footer still reconciles: sum(balance) + sum(owed) = sum(on_hand)
        $this->assertSame(
            money_sum(array_column($splits, 'on_hand')),
            money_add(money_sum($balances), money_sum(array_column($splits, 'owed')))
        );
    }

    #[Test]
    public function money_is_neg_mirrors_money_is_pos(): void
    {
        $this->assertTrue(money_is_neg('-0.01'));
        $this->assertFalse(money_is_neg('0.00'));
        $this->assertFalse(money_is_neg('0.01'));
        $this->assertTrue(money_is_neg(-1));
    }
}
