-- Migration 010: payment proof-of-payment attachment
-- Adds an optional uploaded-file path + external URL to each payment, so an
-- electronic payment (bank-transfer screenshot, PDF bank receipt) can be
-- tracked against its record. Mirrors the existing expenses.receipt_path /
-- expenses.receipt_url columns; both nullable so all existing rows are valid.
--
-- Run once against the live DB BEFORE the matching save_payment code goes live
-- (the INSERT/UPDATE reference these columns).

ALTER TABLE payments
    ADD COLUMN receipt_path VARCHAR(500)  NULL DEFAULT NULL AFTER notes,
    ADD COLUMN receipt_url  VARCHAR(1000) NULL DEFAULT NULL AFTER receipt_path;
