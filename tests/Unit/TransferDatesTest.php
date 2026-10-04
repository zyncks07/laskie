<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The move dates of a tenant transfer (transferDates()), under POST-PAID rent.
 *
 * The old tenancy always ends the day before the move and the new one starts
 * on it. The billing cycle holding the move splits by days, so the two
 * tenancies bill exactly one cycle between them; moving on the due day is the
 * clean case where the old unit's last cycle is whole and the new unit starts
 * a fresh one.
 */
final class TransferDatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../config/transfer_functions.php';
    }

    public static function moves(): array
    {
        return [
            'on the due day'         => ['2026-10-05', 5,  '2025-12-05', '2026-10-04', 'cycle'],
            'before the due day'     => ['2026-10-02', 5,  '2025-12-05', '2026-10-01', 'split'],
            'after the due day'      => ['2026-10-15', 5,  '2025-12-05', '2026-10-14', 'split'],
            'January due day'        => ['2027-01-05', 5,  '2025-12-05', '2027-01-04', 'cycle'],
            'due 31 clamped in Feb'  => ['2026-02-28', 31, '2025-12-05', '2026-02-27', 'cycle'],
            'moved in this month'    => ['2026-10-20', 5,  '2026-10-10', '2026-10-19', 'split'],
        ];
    }

    #[Test]
    #[DataProvider('moves')]
    public function old_ends_the_day_before_and_new_starts_on_the_move(string $move, int $due, string $start, string $oldEnd, string $mode): void
    {
        $d = transferDates($move, $due, $start);
        $this->assertSame([$oldEnd, $move, $mode], [$d['old_end'] ?? null, $d['new_start'] ?? null, $d['mode'] ?? null], json_encode($d));
    }

    #[Test]
    public function a_move_on_or_before_the_start_is_refused(): void
    {
        $this->assertArrayHasKey('error', transferDates('2025-12-05', 5, '2025-12-05'));
        $this->assertArrayHasKey('error', transferDates('2025-11-01', 5, '2025-12-05'));
    }

    #[Test]
    public function moving_on_the_due_day_pays_a_full_last_cycle_and_starts_fresh(): void
    {
        $d = transferDates('2026-10-05', 5, '2025-12-05');
        // The old unit's October bill (Sep 5 – Oct 4) is whole; the new unit owes nothing for October…
        $this->assertSame('6000.00', prorateOccupancyMonth('6000', 5, '2025-12-05', $d['old_end'], 10, 2026));
        $this->assertSame('0.00',    prorateOccupancyMonth('8500', 5, $d['new_start'], null, 10, 2026));
        // …and its first bill is a full month, due Nov 5.
        $this->assertSame('8500.00', prorateOccupancyMonth('8500', 5, $d['new_start'], null, 11, 2026));
        $this->assertSame('2026-11-05', chargeDate(5, 11, 2026));
    }

    #[Test]
    public function a_mid_cycle_move_splits_the_cycle_by_days(): void
    {
        $d = transferDates('2026-10-15', 5, '2025-12-05');
        // Cycle Oct 5 – Nov 4 (31 days): 10 days at the old rate, 21 at the new.
        $old = prorateOccupancyMonth('6000', 5, '2025-12-05', $d['old_end'], 11, 2026);
        $new = prorateOccupancyMonth('6000', 5, $d['new_start'], null, 11, 2026);
        $this->assertSame('6000.00', money_add($old, $new));
        $this->assertSame('1935.48', $old);
        $this->assertSame('2026-10-15', chargeDate(5, 11, 2026, $d['old_end']), 'old unit billed at move-out');
    }
}
