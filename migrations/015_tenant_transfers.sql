-- Migration 015: tenant transfers (move an existing tenant to another unit)
--
-- A tenants row IS a tenancy (one unit, one start→end window) and rent is
-- generated virtually from that window, so a transfer closes the old row and
-- creates a linked successor row (tenants.transferred_from_id). The Tenants
-- page shows the person once.
--
-- Money moves between the two tenancies through primitives every report
-- already understands:
--   * prepaid credit / deposit credit: a NON-CASH pair — a refund with
--     non_cash=1 on the old tenancy and a payment with transfer_id set on the
--     new one. Neither gets a cash_transactions row, so nobody's cash-on-hand
--     moves, and income nets out because reports subtract refunds.
--   * unpaid rent: rent_charge_voids rows of kind='transfer' on the old
--     tenancy, re-billed as one pre-billed Arrears charge on the new one.
--   * deposit top-up: a pre-billed Security Deposit charge on the new unit.
-- Every row a transfer creates carries its transfer_id so it can be locked
-- against piecemeal edits and undone as a whole.
--
-- Additive only. Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/015_tenant_transfers.sql

USE laskie_rental;

CREATE TABLE IF NOT EXISTS tenant_transfers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    from_tenant_id   INT          DEFAULT NULL,
    to_tenant_id     INT          DEFAULT NULL,
    from_unit_id     INT          DEFAULT NULL,
    to_unit_id       INT          DEFAULT NULL,
    move_date        DATE         NOT NULL,
    old_end_date     DATE         NOT NULL,
    new_start_date   DATE         NOT NULL,
    mode             ENUM('cycle','split') NOT NULL,
    old_rent         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    new_rent         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    carried_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_held     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_required DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_action   ENUM('none','topup','credit','refund','keep') NOT NULL DEFAULT 'none',
    deposit_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cashier_id       INT          DEFAULT NULL,
    snapshot_json    LONGTEXT     NOT NULL,
    idempotency_key  VARCHAR(64)  DEFAULT NULL,
    status           ENUM('done','undone') NOT NULL DEFAULT 'done',
    created_by       INT          DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    undone_by        INT          DEFAULT NULL,
    undone_at        DATETIME     DEFAULT NULL,
    undo_reason      VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uniq_tt_idem (idempotency_key),
    KEY idx_tt_from (from_tenant_id),
    KEY idx_tt_to   (to_tenant_id),
    CONSTRAINT fk_tt_from_tenant FOREIGN KEY (from_tenant_id) REFERENCES tenants(id)      ON DELETE SET NULL,
    CONSTRAINT fk_tt_to_tenant   FOREIGN KEY (to_tenant_id)   REFERENCES tenants(id)      ON DELETE SET NULL,
    CONSTRAINT fk_tt_from_unit   FOREIGN KEY (from_unit_id)   REFERENCES rental_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tt_to_unit     FOREIGN KEY (to_unit_id)     REFERENCES rental_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tt_cashier     FOREIGN KEY (cashier_id)     REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_tt_created     FOREIGN KEY (created_by)     REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_tt_undone      FOREIGN KEY (undone_by)      REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tenants           ADD COLUMN IF NOT EXISTS transferred_from_id INT DEFAULT NULL;
ALTER TABLE payments          ADD COLUMN IF NOT EXISTS transfer_id INT DEFAULT NULL;
ALTER TABLE refunds           ADD COLUMN IF NOT EXISTS transfer_id INT DEFAULT NULL;
ALTER TABLE refunds           ADD COLUMN IF NOT EXISTS non_cash TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE unit_charges      ADD COLUMN IF NOT EXISTS transfer_id INT DEFAULT NULL;
ALTER TABLE rent_charge_voids ADD COLUMN IF NOT EXISTS transfer_id INT DEFAULT NULL;
ALTER TABLE rent_charge_voids ADD COLUMN IF NOT EXISTS kind ENUM('waiver','transfer') NOT NULL DEFAULT 'waiver';

ALTER TABLE tenants           ADD INDEX IF NOT EXISTS idx_t_transferred_from (transferred_from_id);
ALTER TABLE payments          ADD INDEX IF NOT EXISTS idx_pay_transfer (transfer_id);
ALTER TABLE refunds           ADD INDEX IF NOT EXISTS idx_ref_transfer (transfer_id);
ALTER TABLE unit_charges      ADD INDEX IF NOT EXISTS idx_uc_transfer (transfer_id);
ALTER TABLE rent_charge_voids ADD INDEX IF NOT EXISTS idx_rcv_transfer (transfer_id);
