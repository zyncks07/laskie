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
    public function next_number_is_strictly_greater_than_existing_max(): void
    {
        // Insert a future-numbered invoice; next call must skip past it.
        $year   = date('Y');
        $prefix = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn() ?: 'INV';
        $highSuffix = 99000;
        $synthetic  = sprintf('%s-%s-%05d', $prefix, $year, $highSuffix);
        $this->insertPayment($synthetic);

        $next       = generateInvoiceNo($this->pdo);
        $nextSuffix = (int) substr($next, strrpos($next, '-') + 1);

        $this->assertSame($highSuffix + 1, $nextSuffix,
            "next invoice should be {$highSuffix}+1, got $nextSuffix from '$next'");
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
