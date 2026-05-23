-- ============================================================
-- Migration 004 — Add idempotency_key to payments
-- ============================================================
-- Prevents double-recorded payments when a submit form is double-clicked,
-- when a network retry fires after the server already processed the
-- original request, or any other accidental replay.
--
-- The client (payments/collection.php savePayment) generates a fresh
-- UUID per modal-open; the server, on save_payment INSERT, returns the
-- existing payment if the key is already on file. The UNIQUE index also
-- catches concurrent requests at the DB level.
--
-- NULL is allowed (and multiple NULLs are distinct under MySQL UNIQUE)
-- so existing payments without a key and non-JS callers still work.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/004_add_payment_idempotency.sql
-- ============================================================

USE laskie_rental;

-- MariaDB <10.6 lacks IF NOT EXISTS for ADD COLUMN; use a procedure-style
-- guard so the migration is safe to re-run.
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'idempotency_key'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND INDEX_NAME   = 'idempotency_key'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE payments ADD UNIQUE KEY idempotency_key (idempotency_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
