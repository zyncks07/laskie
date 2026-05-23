<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for getRateForMonth() — rate-history-aware lookup.
 *
 * The contract: for a given unit + month, return the most recent
 * unit_rate_history row whose effective_date <= last day of that month.
 * If no history exists, fall back to the unit's current monthly_rate.
 *
 * These tests insert their own unit + history rows and clean up in tearDown.
 */
final class RateHistoryTest extends IntegrationTestCase
{
    private ?int $unitId = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a throwaway unit so we don't pollute real history.
        $this->pdo->prepare(
            "INSERT INTO rental_units (unit_name, monthly_rate, due_day, status)
             VALUES (?, ?, ?, 'vacant')"
        )->execute(['PHPUNIT-RATE-' . uniqid(), '10000.00', 5]);
        $this->unitId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->unitId !== null) {
            $this->pdo->prepare("DELETE FROM unit_rate_history WHERE unit_id=?")->execute([$this->unitId]);
            $this->pdo->prepare("DELETE FROM rental_units      WHERE id=?")->execute([$this->unitId]);
        }
        parent::tearDown();
    }

    private function addHistory(string $effectiveDate, string $rate): void
    {
        $this->pdo->prepare(
            "INSERT INTO unit_rate_history (unit_id, monthly_rate, effective_date, created_by)
             VALUES (?, ?, ?, 1)"
        )->execute([$this->unitId, $rate, $effectiveDate]);
    }

    #[Test]
    public function no_history_returns_the_base_rate(): void
    {
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(12345.67, $rate, 0.001);
    }

    #[Test]
    public function returns_history_row_when_effective_date_lies_before_period(): void
    {
        // History: ₱8000 effective Jan 1, 2026. Asking about May 2026 → ₱8000.
        $this->addHistory('2026-01-01', '8000.00');
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(8000.00, $rate, 0.001);
    }

    #[Test]
    public function picks_the_most_recent_effective_date_on_or_before_period(): void
    {
        // Two history rows: ₱5000 from Jan, ₱9000 from Mar. Asking about May → ₱9000.
        $this->addHistory('2026-01-01', '5000.00');
        $this->addHistory('2026-03-01', '9000.00');
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(9000.00, $rate, 0.001);
    }

    #[Test]
    public function ignores_history_rows_with_future_effective_dates(): void
    {
        // History: ₱5000 Jan 2026 (in scope), ₱11000 Aug 2026 (future).
        // Asking about May 2026 → ₱5000 (Aug 2026 is in the future).
        $this->addHistory('2026-01-01', '5000.00');
        $this->addHistory('2026-08-01', '11000.00');
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(5000.00, $rate, 0.001);
    }

    #[Test]
    public function effective_date_exactly_on_last_day_of_period_is_included(): void
    {
        // The function compares effective_date <= last day of the period.
        // History row dated May 31, 2026 should be picked up for May 2026.
        $this->addHistory('2026-05-31', '7500.00');
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(7500.00, $rate, 0.001);
    }

    #[Test]
    public function effective_date_in_following_month_is_not_picked_up(): void
    {
        // History row dated June 1, 2026 should NOT apply to May 2026 query.
        $this->addHistory('2026-06-01', '7500.00');
        $rate = getRateForMonth($this->pdo, $this->unitId, 12345.67, 5, 2026);
        $this->assertEqualsWithDelta(12345.67, $rate, 0.001, 'should fall back to baseRate');
    }
}
