<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * generateInvoiceNo() produces the next sequential invoice number per year,
 * with zero-padded suffix and the configured prefix. Format: PREFIX-YYYY-NNNNN.
 *
 * These tests do NOT mutate the payments table — they only INSERT a synthetic
 * row to claim a particular suffix, then DELETE in tearDown.
 */
final class InvoiceNumberTest extends IntegrationTestCase
{
    private array $insertedIds = [];

    protected function tearDown(): void
    {
        if ($this->insertedIds) {
            $in = implode(',', array_fill(0, count($this->insertedIds), '?'));
            $this->pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id IN ($in)")->execute($this->insertedIds);
            $this->pdo->prepare("DELETE FROM payments              WHERE id                  IN ($in)")->execute($this->insertedIds);
        }
        parent::tearDown();
    }

    private function insertPayment(string $invoiceNo): int
    {
        $this->pdo->prepare(
            "INSERT INTO payments
             (invoice_no, unit_id, payment_type, amount, period_month, period_year, payment_date, received_by, notes)
             VALUES (?, 1, 'rent', '0.01', ?, ?, ?, 1, ?)"
        )->execute([
            $invoiceNo,
            (int) date('n'),
            (int) date('Y'),
            date('Y-m-d'),
            self::PHPUNIT_MARKER . ' — invoice no test',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->insertedIds[] = $id;
        return $id;
    }

    #[Test]
    public function a_deleted_payment_never_gives_its_number_back(): void
    {
        // The regression: generateInvoiceNo() used MAX(invoice_no)+1, so deleting
        // the newest payment freed its number and the next payment reused it.
        // Two different payments could then hold the same invoice_no over time,
        // making a printed receipt ambiguous.
        $first = generateInvoiceNo($this->pdo);
        $id    = $this->insertPayment($first);

        $this->pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
        array_pop($this->insertedIds);

        $second = generateInvoiceNo($this->pdo);
        $this->assertNotSame($first, $second, 'a retired invoice number must never be reissued');
        $this->assertGreaterThan($this->suffixOf($first), $this->suffixOf($second));
    }

    #[Test]
    public function a_soft_deleted_payment_also_keeps_its_number_retired(): void
    {
        $first = generateInvoiceNo($this->pdo);
        $id    = $this->insertPayment($first);
        $this->pdo->prepare("UPDATE payments SET deleted_at=NOW() WHERE id=?")->execute([$id]);

        $second = generateInvoiceNo($this->pdo);
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function numbers_are_issued_strictly_increasing(): void
    {
        $seen = [];
        for ($i = 0; $i < 5; $i++) $seen[] = $this->suffixOf(generateInvoiceNo($this->pdo));
        $sorted = $seen;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $seen, 'numbers must come out in ascending order');
        $this->assertSame(count($seen), count(array_unique($seen)), 'no number may repeat');
    }

    #[Test]
    public function the_counter_seeds_above_the_highest_number_already_used(): void
    {
        // Fresh schema with an existing payment but no counter row yet: the
        // first issued number must clear the existing one, not collide with it.
        $prefix = $this->prefix();
        $year   = date('Y');
        $this->pdo->prepare("DELETE FROM settings WHERE setting_key=?")
                  ->execute(["invoice_seq_{$prefix}_{$year}"]);
        $this->insertPayment("{$prefix}-{$year}-04242");

        $next = generateInvoiceNo($this->pdo);
        $this->assertSame(4243, $this->suffixOf($next));
    }

    #[Test]
    public function the_counter_is_kept_per_prefix_and_year(): void
    {
        $prefix = $this->prefix();
        $year   = date('Y');
        generateInvoiceNo($this->pdo);
        $row = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $row->execute(["invoice_seq_{$prefix}_{$year}"]);
        $this->assertNotFalse($row->fetchColumn(), 'a counter row for this prefix+year must exist');
    }

    private function suffixOf(string $invoiceNo): int
    {
        return (int) substr($invoiceNo, strrpos($invoiceNo, '-') + 1);
    }

    private function prefix(): string
    {
        $p = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn();
        return $p ?: 'INV';
    }

    #[Test]
    public function format_is_prefix_dash_year_dash_zero_padded_5(): void
    {
        $no = generateInvoiceNo($this->pdo);
        $year = date('Y');
        $this->assertMatchesRegularExpression(
            '/^[A-Z]+-' . $year . '-\d{5}$/',
            $no
        );
    }

    #[Test]
    public function uses_invoice_prefix_setting_when_set(): void
    {
        $original = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn();
        $this->pdo->exec("UPDATE settings SET setting_value='TEST' WHERE setting_key='invoice_prefix'");

        try {
            $no = generateInvoiceNo($this->pdo);
            $this->assertStringStartsWith('TEST-' . date('Y') . '-', $no);
        } finally {
            $this->pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='invoice_prefix'")
                ->execute([$original ?: 'INV']);
        }
    }

    #[Test]
    public function a_seeded_counter_is_authoritative_over_rows_inserted_behind_it(): void
    {
        // Contract change: numbering is a monotonic counter in `settings`, not
        // MAX(invoice_no)+1. Once the counter exists it is the sole source of
        // truth, so a row inserted out of band (as this test does, bypassing
        // generateInvoiceNo) does NOT drag it forward. That is the property that
        // stops a deleted payment's number from being handed out again — see
        // a_deleted_payment_never_gives_its_number_back. The UNIQUE index on
        // invoice_no plus save_payment's retry loop cover the rare drift.
        $year   = date('Y');
        $prefix = $this->prefix();
        generateInvoiceNo($this->pdo);                       // ensure the counter exists
        $this->insertPayment(sprintf('%s-%s-%05d', $prefix, $year, 99000));

        $next = $this->suffixOf(generateInvoiceNo($this->pdo));
        $this->assertLessThan(99000, $next, 'the counter, not the table, decides the next number');

        $row = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $row->execute(["invoice_seq_{$prefix}_{$year}"]);
        $this->assertSame($next, (int) $row->fetchColumn(), 'issued number must match the stored counter');
    }

    #[Test]
    public function suffix_is_5_digit_zero_padded(): void
    {
        $no     = generateInvoiceNo($this->pdo);
        $suffix = substr($no, strrpos($no, '-') + 1);
        $this->assertSame(5, strlen($suffix), "suffix '$suffix' is not 5 chars");
        $this->assertMatchesRegularExpression('/^\d{5}$/', $suffix);
    }
}
