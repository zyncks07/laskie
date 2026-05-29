-- Migration 008: schema consistency fixes
-- Idempotent: safe to re-run (uses IF EXISTS / IF NOT EXISTS where needed)

-- 1. Fix payments.period_month / period_year nullability + signedness to match unit_charges
ALTER TABLE payments
    MODIFY COLUMN period_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
    MODIFY COLUMN period_year  SMALLINT UNSIGNED NOT NULL DEFAULT 2024;

-- 2. Add missing ON DELETE clause to refunds.refunded_by FK
--    (all other user FKs use ON DELETE SET NULL)
--    Column must be nullable for ON DELETE SET NULL; users are never hard-deleted
--    in practice but consistency with the rest of the schema requires it.
ALTER TABLE refunds
    MODIFY COLUMN refunded_by INT DEFAULT NULL,
    DROP FOREIGN KEY IF EXISTS `refunds_ibfk_2`,
    ADD CONSTRAINT `fk_refunds_refunded_by`
        FOREIGN KEY (refunded_by) REFERENCES users(id) ON DELETE SET NULL;

-- 3. Add missing ON DELETE to dividend_distributions.recipient_id FK
ALTER TABLE dividend_distributions
    DROP FOREIGN KEY IF EXISTS `dividend_distributions_ibfk_1`,
    ADD CONSTRAINT `fk_div_dist_recipient`
        FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id) ON DELETE CASCADE;

-- 4. Add missing ON DELETE to dividend_returns.recipient_id FK
ALTER TABLE dividend_returns
    DROP FOREIGN KEY IF EXISTS `dividend_returns_ibfk_1`,
    ADD CONSTRAINT `fk_div_ret_recipient`
        FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id) ON DELETE CASCADE;

-- 5 & 6. Indexes on payments.received_by and expenses.recorded_by
-- Both columns already have indexes (named 'received_by' / 'recorded_by') from
-- a prior migration, so no additional index is needed here.
