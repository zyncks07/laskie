<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * chargeDate() decides what date a rent charge should be stamped with.
 * - For a tenant moving in mid-period, the charge date is contract_start
 *   (so the SoA shows the prorated bill at move-in, not at the due day).
 * - Otherwise the charge falls on the unit's due_day.
 * - If the due_day would land on a non-existent day (Feb 30, Apr 31),
 *   it clamps to the last day of the month.
 */
final class ChargeDateTest extends TestCase
{
    #[Test]
    public function uses_due_day_when_no_contract_start(): void
    {
        $this->assertSame('2026-05-05', chargeDate(5, null, 5, 2026));
    }

    #[Test]
    public function uses_due_day_when_contract_started_before_period(): void
    {
        // Contract started March 1, asking about May → due day applies
        $this->assertSame('2026-05-05', chargeDate(5, '2026-03-01', 5, 2026));
    }

    #[Test]
    public function uses_contract_start_when_prorating_within_same_period(): void
    {
        // Contract starts mid-month, AFTER the due day → date stamps as contract_start
        $this->assertSame('2026-05-15', chargeDate(5, '2026-05-15', 5, 2026));
    }

    #[Test]
    public function uses_due_day_when_contract_start_falls_on_due_day(): void
    {
        // Tenant moved in ON the due day → full month rent, billed at due day
        $this->assertSame('2026-05-05', chargeDate(5, '2026-05-05', 5, 2026));
    }

    #[Test]
    public function clamps_due_day_to_last_day_of_short_month(): void
    {
        // due_day=31 in April (30 days) → clamps to 30
        $this->assertSame('2026-04-30', chargeDate(31, null, 4, 2026));
    }

    #[Test]
    public function clamps_due_day_to_february_28_non_leap(): void
    {
        $this->assertSame('2023-02-28', chargeDate(31, null, 2, 2023));
    }

    #[Test]
    public function clamps_due_day_to_february_29_leap(): void
    {
        $this->assertSame('2024-02-29', chargeDate(31, null, 2, 2024));
    }
}
