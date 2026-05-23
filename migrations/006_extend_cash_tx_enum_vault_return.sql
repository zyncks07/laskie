-- ============================================================
-- Migration 006 — Add 'vault_return' to cash_transactions.transaction_type
-- ============================================================
-- New ENUM value supports the admin-only "Return to User" flow on
-- The Vault page: cash issued from the vault back to an admin / accountant /
-- staff user when a remittance was excessive, or to fund a planned expense.
--
-- Semantics:
--   vault_return INCREASES the user's cash_on_hand (the inverse of 'remitted')
--   vault_return DECREASES the vault balance
--
-- ALTER TABLE ... MODIFY COLUMN is naturally idempotent against the target
-- ENUM definition, so re-running is safe.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/006_extend_cash_tx_enum_vault_return.sql
-- ============================================================

USE laskie_rental;

ALTER TABLE cash_transactions
    MODIFY COLUMN transaction_type
    ENUM('received','remitted','expense','refunded','vault_return') NOT NULL;
