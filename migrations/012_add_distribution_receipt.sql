-- Migration 012: dividend distribution proof attachment
--
-- Adds an optional uploaded-file path + external URL to each dividend
-- distribution, so the payout slip / bank-transfer screenshot / signed
-- acknowledgement receipt can be tracked against the record. Mirrors the
-- existing expenses.receipt_path/receipt_url and payments.receipt_path/
-- receipt_url columns exactly (migration 010); both nullable so every
-- existing row stays valid.
--
-- Uploaded files land in uploads/dividends/ via handleUpload(), which applies
-- the project whitelist, the 30 MB cap and in-place image compression. They
-- are served through file_gate.php like every other attachment, so they are
-- only readable by a logged-in user.
--
-- Run once against the live DB BEFORE the matching add_distribution /
-- edit_distribution code goes live (the INSERT/UPDATE reference these columns).
-- Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/012_add_distribution_receipt.sql

ALTER TABLE dividend_distributions
    ADD COLUMN IF NOT EXISTS receipt_path VARCHAR(500)  NULL DEFAULT NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS receipt_url  VARCHAR(1000) NULL DEFAULT NULL AFTER receipt_path;
