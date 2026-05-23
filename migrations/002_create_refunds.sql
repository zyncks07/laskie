-- ============================================================
-- Migration 002 — Create refunds table
-- ============================================================
-- Adds the refunds table referenced by payments/api_payment.php
-- (process_refund / get_payment_refunds actions), payments/history.php,
-- and payments/soa_pdf.php. Was missing from the original install.sql.
--
-- Each row represents one refund event against a payment. The parent
-- payment's status moves to 'partially_refunded' or 'refunded' depending
-- on whether SUM(refunds.amount) covers payments.amount.
--
-- DDL mirrors the live production schema (extracted via SHOW CREATE TABLE).
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/002_create_refunds.sql
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- ============================================================

USE laskie_rental;

CREATE TABLE IF NOT EXISTS refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason TEXT DEFAULT NULL,
    refunded_by INT NOT NULL,
    refunded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY payment_id  (payment_id),
    KEY refunded_by (refunded_by),
    FOREIGN KEY (payment_id)  REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (refunded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
