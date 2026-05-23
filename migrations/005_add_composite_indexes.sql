-- ============================================================
-- Migration 005 — Composite indexes on the hot tables
-- ============================================================
-- Adds indexes that match the actual WHERE patterns in the codebase
-- (audited against payments/api_payment.php, config/functions.php,
-- payments/history.php, api/cash_api.php, api/expenses_api.php).
--
-- Before this migration the optimizer falls back to single-column FK
-- indexes (or full scans) and post-filters in the WHERE clause. With
-- ~125 payment rows and ~185 cash_transactions rows that's tolerable;
-- once history grows past a year or two of activity it stops being.
--
-- Each ADD INDEX is guarded against re-application so this file is
-- safe to re-run.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/005_add_composite_indexes.sql
-- ============================================================

USE laskie_rental;

-- payments(unit_id, period_year, period_month)
-- Covers: getUnitPaymentStatus, monthly_summary, balance calc — the
-- "per-unit per-month rent paid" SUM query that fires once per unit on
-- the collection / dashboard pages.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_unit_period');
SET @sql := IF(@idx=0,
    'ALTER TABLE payments ADD INDEX idx_pay_unit_period (unit_id, period_year, period_month)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- payments(payment_date)
-- Covers: history.php SoA range, dashboard yearly aggregates that already
-- use date columns (and a target for rewriting the NON-sargable
-- YEAR()/MONTH() queries scattered in the codebase).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_date');
SET @sql := IF(@idx=0,
    'ALTER TABLE payments ADD INDEX idx_pay_date (payment_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- expenses(expense_date)
-- Covers: list_expenses date-range filter (currently full-scans).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expenses' AND INDEX_NAME='idx_exp_date');
SET @sql := IF(@idx=0,
    'ALTER TABLE expenses ADD INDEX idx_exp_date (expense_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cash_transactions(user_id, transaction_date)
-- Covers: list_transactions, per-user cash summaries, the cash.php page.
-- Currently falls back to a full table scan because the existing user_id
-- index alone is not selective enough for the optimizer to bother.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_transactions' AND INDEX_NAME='idx_cash_user_date');
SET @sql := IF(@idx=0,
    'ALTER TABLE cash_transactions ADD INDEX idx_cash_user_date (user_id, transaction_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- unit_charges(unit_id, period_year, period_month)
-- Covers: monthly_summary's outstanding_charges subquery, get_unit_payments,
-- and the pre_billed lookup inside save_payment service path.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unit_charges' AND INDEX_NAME='idx_uc_unit_period');
SET @sql := IF(@idx=0,
    'ALTER TABLE unit_charges ADD INDEX idx_uc_unit_period (unit_id, period_year, period_month)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
