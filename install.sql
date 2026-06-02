-- ============================================================
-- Laskie Rental Property Management System
-- Database Install Script
-- ============================================================

CREATE DATABASE IF NOT EXISTS laskie_rental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE laskie_rental;

-- Users / Accounts
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    role ENUM('admin','accountant','staff') NOT NULL DEFAULT 'staff',
    email VARCHAR(120),
    phone VARCHAR(30),
    phone2 VARCHAR(30),
    address TEXT,
    -- Web path to uploaded profile picture (NULL → fall back to initials in the avatar circle).
    avatar_path VARCHAR(500) DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Rental Unit Types
CREATE TABLE IF NOT EXISTS unit_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Service / Transaction Types (beyond standard rent)
CREATE TABLE IF NOT EXISTS service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT,
    default_amount DECIMAL(12,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rental Units
CREATE TABLE IF NOT EXISTS rental_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_name VARCHAR(120) NOT NULL,
    unit_type_id INT,
    description TEXT,
    floor_area DECIMAL(10,2),
    monthly_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    due_day TINYINT DEFAULT 5,
    status ENUM('vacant','occupied') DEFAULT 'vacant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_type_id) REFERENCES unit_types(id) ON DELETE SET NULL
);

-- Tenants
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120),
    phone VARCHAR(30),
    phone2 VARCHAR(30),
    facebook VARCHAR(200),
    instagram VARCHAR(200),
    other_social VARCHAR(200),
    address TEXT,
    monthly_rate DECIMAL(12,2),
    contract_start DATE,
    contract_end DATE,
    status ENUM('active','inactive','former') DEFAULT 'active',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE SET NULL
);

-- Tenant Documents
CREATE TABLE IF NOT EXISTS tenant_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    doc_name VARCHAR(200) NOT NULL,
    doc_type VARCHAR(60),
    file_path VARCHAR(500),
    external_url VARCHAR(1000),
    uploaded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Expense Categories
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Payments
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) UNIQUE,
    unit_id INT,
    tenant_id INT,
    payment_type ENUM('rent','service') DEFAULT 'rent',
    service_type_id INT,
    amount DECIMAL(12,2) NOT NULL,
    period_month TINYINT,
    period_year SMALLINT,
    payment_date DATE NOT NULL,
    due_date DATE,
    received_by INT,
    notes TEXT,
    -- Optional proof-of-payment for electronic payments (bank-transfer
    -- screenshot / PDF receipt). Mirrors expenses.receipt_path/receipt_url.
    receipt_path VARCHAR(500) DEFAULT NULL,
    receipt_url VARCHAR(1000) DEFAULT NULL,
    status ENUM('paid','refunded','partially_refunded','voided') NOT NULL DEFAULT 'paid',
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    -- Client-generated UUID to make save_payment INSERT replay-safe.
    -- A duplicate submit (double-click / network retry) returns the existing row instead of inserting again.
    idempotency_key VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idempotency_key (idempotency_key),
    INDEX idx_pay_unit_period (unit_id, period_year, period_month),
    INDEX idx_pay_date (payment_date),
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE SET NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Refunds (one row per refund event against a payment).
-- payments.status moves to 'partially_refunded' / 'refunded' depending
-- on whether SUM(refunds.amount) covers payments.amount.
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
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT,
    category_id INT,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    description VARCHAR(255),
    notes TEXT,
    receipt_path VARCHAR(500),
    receipt_url VARCHAR(1000),
    recorded_by INT,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exp_date (expense_date),
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Unit Charges (line-item billing per unit/period)
-- Rows with payment_id IS NULL are outstanding; source distinguishes
-- pre-issued bills from charges auto-created at payment time.
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
    INDEX idx_uc_unit_period (unit_id, period_year, period_month),
    FOREIGN KEY (unit_id)         REFERENCES rental_units(id)  ON DELETE CASCADE,
    FOREIGN KEY (tenant_id)       REFERENCES tenants(id)       ON DELETE SET NULL,
    FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_id)      REFERENCES payments(id)      ON DELETE SET NULL,
    FOREIGN KEY (created_by)      REFERENCES users(id)         ON DELETE SET NULL
);

-- Cash on Hand Transactions
CREATE TABLE IF NOT EXISTS cash_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_type ENUM('received','remitted','expense','refunded','vault_return') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference_payment_id INT,
    reference_expense_id INT,
    notes TEXT,
    doc_path VARCHAR(500),
    doc_url VARCHAR(1000),
    transaction_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cash_user_date (user_id, transaction_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reference_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (reference_expense_id) REFERENCES expenses(id) ON DELETE SET NULL
);

-- Unit Rate History (tracks rent increases / rate changes per unit)
CREATE TABLE IF NOT EXISTS unit_rate_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    monthly_rate DECIMAL(12,2) NOT NULL,
    effective_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- System Audit Logs
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(60),
    action VARCHAR(120) NOT NULL,
    module VARCHAR(60),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Dividend Recipients
CREATE TABLE IF NOT EXISTS dividend_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Dividend Distributions
CREATE TABLE IF NOT EXISTS dividend_distributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    distribution_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Dividend Returns (money returned to vault by a recipient)
CREATE TABLE IF NOT EXISTS dividend_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    return_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_id) REFERENCES dividend_recipients(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Vault cash requests (staff/accountant → admin approval → auto vault_return)
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

-- In-app notifications (topbar bell; per-user, pull-based)
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

-- System Settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- Seed Data
-- ============================================================

INSERT INTO unit_types (name, description) VALUES
('Room', 'Standard boarding room'),
('Apartment', 'Full apartment unit'),
('Parking Space', 'Vehicle parking slot'),
('Event Space', 'Function/event venue'),
('Commercial Space', 'Business or commercial use');

INSERT INTO service_types (name, description, default_amount) VALUES
('Security Deposit', 'Initial refundable security deposit', 0.00),
('Arrears', 'Unpaid rent carried over from previous periods', 0.00),
('Late Payment Fee', 'Penalty fee for late rental payment', 0.00),
('Prepaid Internet', 'Monthly internet subscription fee', 0.00),
('Motorcycle Parking', 'Monthly motorcycle parking fee', 0.00),
('Car Parking', 'Monthly car/vehicle parking fee', 0.00),
('Advance Rent', 'Advance payment for upcoming period', 0.00),
('Other Services', 'Miscellaneous service charge', 0.00);

INSERT INTO expense_categories (name, description) VALUES
('Maintenance & Repair', 'Property repairs and maintenance costs'),
('Utilities', 'Water, electricity, and other utility expenses'),
('Supplies', 'Office and cleaning supplies'),
('Administrative', 'Administrative and office expenses'),
('Insurance', 'Property and liability insurance premiums'),
('Taxes & Fees', 'Government taxes and regulatory fees'),
('Renovation', 'Unit improvement and renovation costs'),
('Miscellaneous', 'Other uncategorized expenses');

INSERT INTO settings (setting_key, setting_value) VALUES
('default_due_day', '5'),
('app_name', 'Laskie Rental Property Management System'),
('currency_symbol', '₱'),
('currency_code', 'PHP'),
('invoice_prefix', 'INV'),
('company_name', 'Laskie Rental Properties'),
('company_address', ''),
('company_phone', ''),
('company_email', ''),
-- Default master password: Admin@2024 — change in Settings > Security after first login
('master_password', '$2y$12$fwiNYRv3EqojX2QCo.WMaOx4yg3IcVoNg98HUlxrmsDeKPQUEC6xu');

-- Default admin account: username=admin / password=Admin@2024 — CHANGE IMMEDIATELY after first login
INSERT INTO users (username, password_hash, full_name, role, email, status) VALUES
('admin', '$2y$12$t5v4i5Ct9GjShHGfXJUVmOHk3P5it.tksivxNwAQfcHvq40KQWY9e', 'System Administrator', 'admin', 'admin@laskie.local', 'active');
