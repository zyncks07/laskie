-- ============================================================
-- Migration 003 — Extend cash_transactions.transaction_type ENUM
-- ============================================================
-- The original install.sql defined transaction_type as
--   ENUM('received','remitted','expense')
-- but payments/api_payment.php::process_refund inserts rows with
-- transaction_type='refunded'. On strict-mode MySQL this raises a
-- truncation error; on non-strict mode it silently stores ''.
--
-- This migration adds 'refunded' to the ENUM. ALTER COLUMN with the
-- full target definition is naturally idempotent — re-running it
-- against a column that already has the new ENUM is a no-op.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/003_extend_cash_tx_enum.sql
-- ============================================================

USE laskie_rental;

ALTER TABLE cash_transactions
    MODIFY COLUMN transaction_type
    ENUM('received','remitted','expense','refunded') NOT NULL;
