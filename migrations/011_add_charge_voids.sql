-- Migration 011: admin charge waivers (void rent + service charges)
--
-- Rent charges are VIRTUAL — they are recomputed on every render from
-- tenants.contract_start x rental_units.due_day x unit_rate_history, so there is
-- no row to flag when an admin waives one. rent_charge_voids is that missing row:
-- one waiver per (unit, period), several allowed so a period can be waived
-- partially and repeatedly. Only rows with restored_at IS NULL count.
--
-- Service charges ARE rows (unit_charges), so they get soft-void columns in the
-- same idiom as payments.deleted_at. This also replaces the hard DELETE that
-- payments/api_payment.php delete_charge used to run (audit-trail preserving).
--
-- Waivers move receivables only: rent charges are never revenue in this app
-- (dashboard/P&L sum payments), so nothing here touches cash_transactions.
--
-- Run once against the live DB BEFORE the matching code goes live.
-- Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/011_add_charge_voids.sql

CREATE TABLE IF NOT EXISTS rent_charge_voids (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    unit_id      INT NOT NULL,
    tenant_id    INT DEFAULT NULL,           -- occupant the waived charge belonged to
    period_month TINYINT UNSIGNED NOT NULL,
    period_year  SMALLINT UNSIGNED NOT NULL,
    amount       DECIMAL(12,2) NOT NULL,     -- amount waived (may be partial)
    reason       VARCHAR(255) NOT NULL,
    voided_by    INT DEFAULT NULL,
    voided_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    restored_at  DATETIME DEFAULT NULL,      -- soft un-void; the row is never deleted
    restored_by  INT DEFAULT NULL,
    KEY idx_rcv_unit_period (unit_id, period_year, period_month, restored_at),
    KEY idx_rcv_period      (period_year, period_month, restored_at),
    KEY tenant_id           (tenant_id),
    CONSTRAINT fk_rcv_unit        FOREIGN KEY (unit_id)     REFERENCES rental_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_rcv_tenant      FOREIGN KEY (tenant_id)   REFERENCES tenants(id)      ON DELETE SET NULL,
    CONSTRAINT fk_rcv_voided_by   FOREIGN KEY (voided_by)   REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_rcv_restored_by FOREIGN KEY (restored_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service-charge soft void. MariaDB 10.6+ / MySQL 8 both accept IF NOT EXISTS here.
ALTER TABLE unit_charges
    ADD COLUMN IF NOT EXISTS voided_at   DATETIME     DEFAULT NULL AFTER source,
    ADD COLUMN IF NOT EXISTS voided_by   INT          DEFAULT NULL AFTER voided_at,
    ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) DEFAULT NULL AFTER voided_by;

ALTER TABLE unit_charges
    ADD KEY IF NOT EXISTS idx_uc_voided (voided_at);

ALTER TABLE unit_charges
    DROP FOREIGN KEY IF EXISTS fk_uc_voided_by,
    ADD CONSTRAINT fk_uc_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL;
