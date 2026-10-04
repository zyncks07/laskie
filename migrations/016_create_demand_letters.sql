-- Migration 016: demand letters for tenants behind on rent
--
-- One row per letter generated from the dashboard Balance Tracker (admin
-- only). A demand letter is a dated legal notice — the date of demand and the
-- amount demanded matter later (barangay conciliation, Rule 70 unlawful
-- detainer) — so each is numbered (DL-YYYY-####, monotonic settings counter,
-- never reissued) and keeps a snapshot of exactly what it demanded so a reprint
-- is identical to what the tenant received.
--
-- Additive only. Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/016_create_demand_letters.sql

USE laskie_rental;

CREATE TABLE IF NOT EXISTS demand_letters (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ref_no         VARCHAR(30)  NOT NULL,
    tenant_id      INT          DEFAULT NULL,
    unit_id        INT          DEFAULT NULL,
    letter_type    ENUM('pay','vacate') NOT NULL,
    amount_due     DECIMAL(12,2) NOT NULL,
    months_behind  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    deadline_days  TINYINT UNSIGNED NOT NULL,
    snapshot_json  LONGTEXT     NOT NULL,
    issued_by      INT          DEFAULT NULL,
    created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dl_ref (ref_no),
    KEY idx_dl_tenant (tenant_id, created_at),
    CONSTRAINT fk_dl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)      ON DELETE SET NULL,
    CONSTRAINT fk_dl_unit   FOREIGN KEY (unit_id)   REFERENCES rental_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_dl_user   FOREIGN KEY (issued_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
