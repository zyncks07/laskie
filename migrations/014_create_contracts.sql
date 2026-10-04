-- Migration 014: lease contracts (generator + register + signed scans)
--
-- A contract is a DOCUMENT, not a billing input. Rent stays virtual and keeps
-- being generated from tenants.contract_start / contract_end (CLAUDE.md §5
-- inv. 7 and 12). Approving a contract never touches the tenants row. The
-- Contracts page only warns when the two disagree.
--
-- contracts
--   Lifecycle: draft -> approved -> (optionally) cancelled.
--   * draft      editable, hard-deletable, no contract_no yet
--   * approved   immutable snapshot of fields_json, holds a contract_no drawn
--                from a monotonic settings counter (contract_seq_YYYY), never
--                reissued, same reasoning as invoice numbers (§5 inv. 8)
--   * cancelled  voided with a reason, kept for the audit trail
--   Active / expiring / expired are DERIVED from start_date / end_date at
--   render time, so no cron is needed to move a contract between lists.
--   fields_json holds every template variable exactly as approved. The
--   snapshot columns (lessee_name, unit_label, dates, rent) exist only so the
--   register can sort, filter and search without decoding JSON.
--
-- contract_files
--   Photos / scans of the signed paper copy. Uploaded through handleUpload()
--   so images are auto-compressed. Hard-deleted on removal (with the file
--   unlinked), like tenant_docs: an attachment list, not a ledger.
--
-- Additive only. Creates two tables and rewrites no existing row.
-- Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/014_create_contracts.sql

USE laskie_rental;

CREATE TABLE IF NOT EXISTS contracts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    contract_no      VARCHAR(30)  DEFAULT NULL,
    template_key     VARCHAR(60)  NOT NULL DEFAULT 'lease-caloocan',
    template_version VARCHAR(10)  NOT NULL DEFAULT '1',
    tenant_id        INT          DEFAULT NULL,
    unit_id          INT          DEFAULT NULL,
    lessee_name      VARCHAR(120) NOT NULL DEFAULT '',
    unit_label       VARCHAR(120) NOT NULL DEFAULT '',
    start_date       DATE         DEFAULT NULL,
    end_date         DATE         DEFAULT NULL,
    monthly_rent     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fields_json      LONGTEXT     NOT NULL,
    status           ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
    created_by       INT          DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_by       INT          DEFAULT NULL,
    updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    approved_by      INT          DEFAULT NULL,
    approved_at      DATETIME     DEFAULT NULL,
    cancelled_by     INT          DEFAULT NULL,
    cancelled_at     DATETIME     DEFAULT NULL,
    cancel_reason    VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uniq_contract_no (contract_no),
    KEY idx_ct_status_end (status, end_date),
    KEY idx_ct_tenant (tenant_id),
    KEY idx_ct_unit   (unit_id),
    CONSTRAINT fk_ct_tenant    FOREIGN KEY (tenant_id)    REFERENCES tenants(id)      ON DELETE SET NULL,
    CONSTRAINT fk_ct_unit      FOREIGN KEY (unit_id)      REFERENCES rental_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_ct_created   FOREIGN KEY (created_by)   REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_ct_updated   FOREIGN KEY (updated_by)   REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_ct_approved  FOREIGN KEY (approved_by)  REFERENCES users(id)        ON DELETE SET NULL,
    CONSTRAINT fk_ct_cancelled FOREIGN KEY (cancelled_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_files (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT          NOT NULL,
    file_path   VARCHAR(500) NOT NULL,
    doc_name    VARCHAR(200) NOT NULL DEFAULT '',
    uploaded_by INT          DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cf_contract (contract_id),
    CONSTRAINT fk_cf_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cf_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
