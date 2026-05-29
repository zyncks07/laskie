-- ============================================================
-- Migration 009 — Add vault_requests + notifications
-- ============================================================
-- Two new tables for the "request cash from the Vault" workflow:
--
--   vault_requests  — a staff/accountant request to have cash returned to them
--                     from the Vault (to fund a tenant deposit refund at lease
--                     end, or an unexpected expense after they've remitted all
--                     their cash). An admin approves or rejects. Approving
--                     AUTO-ISSUES the existing vault_return cash row (crediting
--                     the requester's cash-on-hand) and records its id in
--                     cash_tx_id. See api/requests_api.php.
--
--   notifications   — per-user in-app notification feed (the topbar bell).
--                     New requests notify all admins; decisions notify the
--                     requester. Pull-based (page-load + ~15s AJAX poll); the
--                     audit trail (system_logs) is unchanged and separate.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/009_add_vault_requests_notifications.sql
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- ============================================================

USE laskie_rental;

CREATE TABLE IF NOT EXISTS vault_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by INT NOT NULL,
    request_type ENUM('refund_fund','expense_fund','other') NOT NULL DEFAULT 'other',
    amount DECIMAL(12,2) NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    reference_payment_id INT DEFAULT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    decision_note VARCHAR(255) DEFAULT NULL,
    cash_tx_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vr_status (status),
    INDEX idx_vr_requested_by (requested_by),
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reference_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (cash_tx_id) REFERENCES cash_transactions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    vault_request_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_n_user_unread (user_id, is_read),
    INDEX idx_n_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vault_request_id) REFERENCES vault_requests(id) ON DELETE CASCADE
);
