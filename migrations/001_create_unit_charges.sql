-- ============================================================
-- Migration 001 — Create unit_charges table
-- ============================================================
-- Adds the unit_charges table that is referenced throughout
-- payments/api_payment.php, payments/history.php, payments/soa_pdf.php,
-- and seed_spreadsheet.php but was missing from install.sql.
--
-- Rows represent line-item charges per unit/period:
--   - source='pre_billed'    → bill issued ahead of payment (payment_id IS NULL)
--   - source='auto_collected' → row created at the time a service payment was recorded
--   - payment_id IS NULL      → outstanding charge (appears in SoA balance)
--   - payment_id IS NOT NULL  → settled by that payment
--
-- This DDL mirrors the schema that has been running in production
-- (extracted via SHOW CREATE TABLE). Future schema changes should be
-- added as further numbered migrations rather than editing this one.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/001_create_unit_charges.sql
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- ============================================================

USE laskie_rental;

CREATE TABLE IF NOT EXISTS unit_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    tenant_id INT DEFAULT NULL,
    service_type_id INT DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    charge_date DATE NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    payment_id INT DEFAULT NULL,
    source ENUM('pre_billed','auto_collected') NOT NULL DEFAULT 'auto_collected',
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY unit_id         (unit_id),
    KEY tenant_id       (tenant_id),
    KEY service_type_id (service_type_id),
    KEY payment_id      (payment_id),
    KEY created_by      (created_by),
    FOREIGN KEY (unit_id)         REFERENCES rental_units(id)  ON DELETE CASCADE,
    FOREIGN KEY (tenant_id)       REFERENCES tenants(id)       ON DELETE SET NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_id)      REFERENCES payments(id)      ON DELETE SET NULL,
    FOREIGN KEY (created_by)      REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
