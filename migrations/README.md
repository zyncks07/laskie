# Migrations

Numbered, append-only schema changes. Newer than `install.sql` (which represents the bootstrap schema for fresh installs).

## Conventions

- Files named `NNN_short_description.sql` where `NNN` is a zero-padded, sequential integer.
- **Never edit a committed migration.** Add a new one that fixes it.
- Each migration starts with `USE laskie_rental;` and **must be idempotent** (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, etc.) so re-running is safe.
- When a migration adds a new table or column, also append the same DDL to `install.sql` so fresh installs get it without replaying every migration.

## Applying

```bash
# Apply a single migration
mysql -u laskie_db_user -p laskie_rental < migrations/001_create_unit_charges.sql

# Apply all (in order)
for f in migrations/*.sql; do
  echo "Applying $f..."
  mysql -u laskie_db_user -p laskie_rental < "$f" || break
done
```

## Index

| # | File | Purpose |
|---|---|---|
| 001 | `001_create_unit_charges.sql` | Add `unit_charges` table (referenced by code but missing from original `install.sql`) |
| 002 | `002_create_refunds.sql` | Add `refunds` table (referenced by `process_refund` / SoA / history; was missing from `install.sql`) |
| 003 | `003_extend_cash_tx_enum.sql` | Add `'refunded'` to `cash_transactions.transaction_type` ENUM (process_refund writes this value) |
| 004 | `004_add_payment_idempotency.sql` | Add `payments.idempotency_key` (UUID, UNIQUE) so double-clicks / network retries don't double-record |
| 005 | `005_add_composite_indexes.sql` | Composite indexes on `payments`, `expenses`, `cash_transactions`, `unit_charges` matching real WHERE patterns (audited, not guessed) |
| 006 | `006_extend_cash_tx_enum_vault_return.sql` | Add `'vault_return'` to `cash_transactions.transaction_type` for the Vault → User return flow |
| 007 | `007_add_user_avatar.sql` | Add `users.avatar_path` for uploaded profile pictures |
