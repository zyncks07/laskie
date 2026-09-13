-- Migration 013: partial settlement of service charges (charge_payments)
--
-- unit_charges modelled a charge as settled by EXACTLY ONE payment: a single
-- payment_id, and save_payment overwrote unit_charges.amount with the payment
-- amount when it linked. There was no way to say "this ₱12,500 charge has
-- received ₱4,000 so far", so a charge paid in instalments broke two ways:
--
--   * charge with a NULL service_type_id — the link lookup keys on
--     `service_type_id = ?`, which never matches NULL, so a matching
--     auto_collected charge was invented beside the payment. Charge and credit
--     cancelled and THE BALANCE NEVER MOVED. Every carried-over arrears row in
--     this database is exactly that shape.
--   * charge with a service_type_id — the link fired and rewrote amount
--     12,500 → 4,000 as paid, DESTROYING ₱8,500 of the receivable.
--
-- charge_payments is the missing allocation row: a charge may receive many
-- payments, each settling a named amount. Outstanding becomes
-- `amount − SUM(allocations whose payment is live)`.
--
-- BALANCE SAFETY. The backfill is exact because the existing data is a perfect
-- 1:1 — every linked charge's amount equals its payment's amount, no charge is
-- voided, no payment_id is shared, no link is orphaned. One allocation per
-- linked charge at its full amount reproduces the old rule case by case:
--   payment_id IS NULL              → no allocation        → outstanding = amount
--   payment_id set, payment live    → allocation = amount  → outstanding = 0
--   payment_id set, payment dead    → allocation excluded  → outstanding = amount
-- (the last case matching the old filtered LEFT JOIN that forced p.id IS NULL).
--
-- Going forward, unit_charges.payment_id is written ONLY for auto_collected
-- rows, where it is genuinely 1:1 and drives the lifecycle invariant that
-- ServiceChargeLifecycleTest pins. pre_billed charges are settled purely
-- through allocations.
--
-- ON DELETE CASCADE both ways: purging a payment or deleting a charge clears
-- its allocations without any handler code.
--
-- Run once against the live DB BEFORE the matching code goes live. This
-- migration is additive and rewrites no existing row, so it is safe to apply
-- ahead of the application change.
-- Idempotent: safe to re-run.
--   mysql -u laskie_db_user -p laskie_rental < migrations/013_create_charge_payments.sql

CREATE TABLE IF NOT EXISTS charge_payments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    charge_id  INT NOT NULL,
    payment_id INT NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,   -- settled against this charge by this payment
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    -- One allocation per (charge, payment): a payment settles a given charge
    -- once, for one amount. Editing the payment updates that row in place.
    UNIQUE KEY uniq_charge_payment (charge_id, payment_id),
    KEY idx_cp_charge  (charge_id),
    KEY idx_cp_payment (payment_id),
    CONSTRAINT fk_cp_charge     FOREIGN KEY (charge_id)  REFERENCES unit_charges(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_payment    FOREIGN KEY (payment_id) REFERENCES payments(id)     ON DELETE CASCADE,
    CONSTRAINT fk_cp_created_by FOREIGN KEY (created_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill every currently-linked charge as one full-amount allocation.
-- INSERT IGNORE + the UNIQUE key make a re-run a no-op.
INSERT IGNORE INTO charge_payments (charge_id, payment_id, amount, created_by, created_at)
SELECT uc.id, uc.payment_id, uc.amount, uc.created_by, uc.created_at
FROM unit_charges uc
JOIN payments p ON p.id = uc.payment_id
WHERE uc.payment_id IS NOT NULL;
