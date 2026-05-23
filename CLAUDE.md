# CLAUDE.md — Laskie Rental Property Management System

> Guide for Claude (and any AI/human collaborator) working in this repo.
> Read this file in full before making changes that touch money, schema, or auth.

---

## 1. Project at a glance

**Laskie RMS** is a server-rendered, multi-user web application for managing rental properties — units, tenants, rent collection, expenses, cash-on-hand, dividends, and audit. It is deployed to a single Apache + MySQL host (`/var/www/laskie/`), used by 1–3 staff in a Philippine context (₱ PHP, Asia/Manila).

- **Authoritative install guide:** [`INSTALL.md`](INSTALL.md)
- **Default app version:** `APP_VERSION` constant in `config/functions.php`
- **Currency:** PHP Peso (₱), 2-decimal `DECIMAL(12,2)` everywhere
- **Time zone:** `Asia/Manila` (set from `settings.db_timezone`, falls back to Manila)

---

## 2. Stack

### Backend
| Layer | Tech | Notes |
|---|---|---|
| Language | PHP 8.1+ | procedural, no framework |
| DB | MySQL 8 / MariaDB 10.6+ | `utf8mb4_unicode_ci` |
| DB driver | PDO | `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, prepared statements only |
| Web server | Apache 2.4 | `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate` |
| Sessions | native PHP | `cookie_httponly=1`, `samesite=Strict`, `use_strict_mode=1` (from `.htaccess`) |
| Password hash | `password_hash` (bcrypt cost 12) | login + master password share the same scheme |

### Frontend (all self-hosted under `assets/vendor/`)
| Tool | Version locked to vendor file |
|---|---|
| Bootstrap | 5.x (`bootstrap.min.css`, `bootstrap.bundle.min.js`) |
| jQuery | 3.x (`jquery.min.js`) |
| DataTables | jQuery DT + Bootstrap 5 styling |
| Chart.js | UMD build (`chart.umd.min.js`) |
| FontAwesome | 6 (`fontawesome.min.css` + `webfonts/`) |
| Google Fonts | DM Sans + DM Mono (self-hosted, **never** hot-linked) |

### Tooling
- `deploy.sh` — bash deploy via SSH/rsync (writes `config/db.php` on the server)
- `install.sql` — one-shot schema + seed data
- **Composer** installed for the PHPUnit dev dependency. **No** npm, **no** build pipeline.
- **Test runner**: PHPUnit 11. `vendor/bin/phpunit` runs the Unit suite (pure PHP, no DB needed). `vendor/bin/phpunit --testsuite Integration` runs DB-dependent tests against the live DB (rows tagged with `PHPUNIT_INTEGRATION_TEST` and cleaned in tearDown). See `tests/` directory.

---

## 3. Directory layout

```
/var/www/laskie/
├── index.php              # Login (sign-in form, session bootstrap, audit log)
├── logout.php
├── dashboard.php          # Annual P&L, unit status grid, charts
├── expenses.php           # Expense entry + listing
├── cash.php               # Cash on hand per user
├── my_summary.php         # Per-user activity summary
├── install.sql            # Schema + seed (run once)
├── .htaccess              # PHP ini values, security headers, mime caching
│
├── admin/                 # Admin-only pages (requireAdmin())
│   ├── accounts.php       # User CRUD
│   ├── tenants.php
│   ├── units.php          # Units + unit_types + service_types + rate history
│   ├── transactions.php   # Soft-deleted / voided payments — restore UI
│   ├── vault.php          # Dividend recipients, distributions, returns
│   ├── logs.php           # system_logs audit viewer
│   └── settings.php       # Settings + master password change
│
├── payments/
│   ├── collection.php     # Monthly rent collection grid
│   ├── history.php        # Statement of Account (per unit, date range)
│   ├── invoice_print.php  # Printable single-invoice
│   ├── api_payment.php    # Payment + service-charge CRUD API (JSON)
│   ├── soa_pdf.php        # SoA HTML (rendered to PDF by *_download.php)
│   ├── soa_pdf_download.php  # Includes soa_pdf.php under ob_*, pipes HTML → chromium → PDF
│   ├── audit_pdf.php      # Audit-trail HTML (same pattern)
│   └── audit_pdf_download.php
│
├── api/
│   ├── cash_api.php       # Cash transactions CRUD
│   ├── expenses_api.php   # Expense CRUD
│   └── settings_api.php   # System settings CRUD
│
├── config/
│   ├── db.php             # PDO connection (credentials baked in by deploy.sh)
│   └── functions.php      # Helpers — see §6
│
├── includes/
│   ├── header.php         # Sidebar + topbar layout, session boot
│   └── footer.php         # JS includes + shared inline helpers
│
├── assets/
│   ├── css/app.css        # Design system (see §7)
│   ├── js/app.js          # Global JS helpers
│   └── vendor/            # All third-party CSS/JS (self-hosted)
│
└── uploads/               # User-uploaded files — writable by www-data
    ├── contracts/   receipts/   docs/   remittance/
```

---

## 4. Database schema (accounting-critical)

Read [`install.sql`](install.sql) for the authoritative DDL. Highlights:

### Core entities
- `users` — id, username, password_hash, full_name, **role** (`admin`/`accountant`/`staff`), status, email/phone
- `rental_units` — unit_name, unit_type_id, monthly_rate, **due_day**, status (`vacant`/`occupied`)
- `tenants` — full_name, unit_id, monthly_rate, **contract_start / contract_end**, status (`active`/`inactive`/`former`)
- `unit_types`, `service_types`, `expense_categories` — lookup tables

### Money-movement tables
- `payments`
  - `invoice_no` (UNIQUE, format `INV-YYYY-#####`, see `generateInvoiceNo()`)
  - `payment_type` ∈ {`rent`, `service`}, `service_type_id` FK when `service`
  - `amount`, `period_month`, `period_year`, `payment_date`, `due_date`
  - `received_by` — user who collected
  - `status` ∈ {`paid`, `refunded`, `partially_refunded`, `voided`} — **never** delete; always status-flip
  - `deleted_at` — **soft delete**, restorable from `admin/transactions.php`
- `expenses` — amount, expense_date, unit_id, category_id, recorded_by, receipt_path, **soft-delete via `deleted_at`**
- `cash_transactions` — per-user ledger; `transaction_type` ∈ {`received`, `remitted`, `expense`}; FKs back to `payments.id` / `expenses.id`
- **`unit_charges`** — pre-billed line items per unit/period; rows with `payment_id IS NULL` are outstanding. `source` ∈ {`pre_billed`, `auto_collected`}.
  > ⚠️ **This table is referenced in code but is currently missing from `install.sql`.** See §11 finding #1. New installs will fail until migration #001 is applied.

### History & audit
- `unit_rate_history` — every rent increase. **Read via `getRateForMonth()` — never read `rental_units.monthly_rate` directly when computing historical balances.**
- `system_logs` — append-only audit. Use `logActivity()` for free-text, `logChange()` for before/after JSON diffs.

### Dividends ("The Vault")
- `dividend_recipients`, `dividend_distributions`, `dividend_returns` — money flowing out to (and back from) owners/investors.

### Settings & FX
- `settings` — key/value; sensitive keys: `master_password` (bcrypt hash), `currency_symbol`, `invoice_prefix`, `company_*`, `db_timezone`

---

## 5. Money & accounting invariants — DO NOT VIOLATE

These are the rules that keep the books consistent. Any change in this area requires extra care.

1. **Never read `rental_units.monthly_rate` for past months.** Use `getRateForMonth($pdo, $unitId, $base, $month, $year)` so rate increases don't retroactively re-bill paid months.
2. **Prorate first month when contract starts after `due_day`.** Use `prorateFirstMonth()`. The formula is `rate / days_in_month × days_occupied`.
3. **Every collected payment creates a `cash_transactions` row** with `transaction_type='received'` and `reference_payment_id` set. If you record a payment without also creating its cash entry, the cash-on-hand report will drift.
4. **Service payments must touch `unit_charges`.** Either link to an existing pre-billed row (`UPDATE`, attach `payment_id`) or create a new `auto_collected` row. See `payments/api_payment.php` action `save_payment`.
5. **Soft delete, never hard delete** for `payments` and `expenses`. Hard deletes break the audit trail and unbalance cash reports.
6. **Voided payments are still in the table.** Always filter `WHERE deleted_at IS NULL AND status != 'voided'` when summing for reports.
7. **Invoice numbers are sequential per year.** `generateInvoiceNo()` uses `MAX(...) + 1` on `INV-YYYY-%`. If you change the format, write a migration that backfills.
8. **All money columns are `DECIMAL(12,2)`.** PHP-side they are read as float — be aware of float drift; sum in SQL when possible.
9. **All audit-relevant mutations call `logActivity()` or `logChange()`** with module, action, and details. Don't skip this for "small" admin edits.
10. **Soft-delete restore goes through `admin/transactions.php`** — do not expose restore links elsewhere.

---

## 6. `config/functions.php` cheat-sheet

Helpers Claude should reuse rather than re-implement:

| Function | Purpose |
|---|---|
| `money($amount)` | Format peso amount with current currency symbol |
| `fmtDate($iso, $fmt = 'M j, Y')` | Safe date formatter (returns `—` for null) |
| `currentUser()` / `isAdmin()` / `isAccountant()` | Session-user accessors |
| `requireLogin()` / `requireAdmin()` / `requireRole([...])` | Page/API guards — call at the top of every protected file |
| `logActivity($pdo, $action, $module, $details)` | Append free-text audit row |
| `logChange($pdo, $action, $module, $before, $after)` | Append JSON before/after diff |
| `generateInvoiceNo($pdo)` | Get next `INV-YYYY-#####` |
| `handleUpload($field, $subDir)` | File upload with whitelist + 10 MB cap, returns `['path'=>..., 'error'=>...]` |
| `getRateForMonth($pdo, $unitId, $base, $m, $y)` | **Use this**, not `rental_units.monthly_rate`, for historical billing |
| `prorateFirstMonth($rate, $dueDay, $contractStart, $m, $y)` | First-month proration |
| `chargeDate($dueDay, $contractStart, $m, $y)` | Returns correct charge date (proration-aware) |
| `getUnitPaymentStatus($pdo, $unitId, $m, $y)` | Returns `'green'`/`'amber'`/`'red'`/`'gray'` for the unit-status grid |
| `jsonOk([...])` / `jsonErr($msg, $code=400)` | JSON response shorthand for `/api/` and `/payments/api_payment.php` |
| `clean($v)` | `htmlspecialchars` wrapper — **use in every PHP echo into HTML** |
| `nullOrStr($v)` | Trim → null-if-empty (for nullable VARCHAR columns) |
| `getSetting($pdo, $key, $default = '')` | Read from `settings` table |

---

## 7. Design system & UI conventions

### Visual language
- **Typography:** DM Sans (UI), DM Mono (for IDs, IP addresses, log codes — use `.mono`)
- **Base font size:** 13.5 px (compact, spreadsheet-feel)
- **Color tokens** (from `assets/css/app.css` `:root`):
  - Primary `#1a3a8f` (navy) · Primary-mid `#3b5bdb` · Accent `#0ea5e9`
  - Semantic: `--success`/`--danger`/`--warning`/`--info` each paired with a `-bg` token
- **Radius:** 8 px (`--radius`) for inputs/buttons, 12 px (`--radius-lg`) for cards
- **Shadow:** very subtle — `var(--shadow-sm)` is the default; cards never use Bootstrap default shadow

### Component library (already in `app.css`)
- `.card`, `.card-header`, `.card-body`, `.card-footer`
- `.stat-card` + `.stat-icon.{blue,green,red,amber,purple,teal}`
- `.status-dot.{green,red,amber,gray}` (with halo for active dots)
- `.badge-{admin,accountant,staff,active,inactive,former,vacant,occupied,rent,service,received,remitted,expense}`
- `.btn-icon` for compact icon-only actions
- `.cell-trunc{,-sm,-lg}` for safe table-cell truncation
- `.cash-hero` — gradient hero block used on `cash.php`
- `.empty-state` for "no records" panels
- `.invoice-doc` print styles
- Responsive breakpoints: 992 px (tablet), 768 px (mobile), 576 px (small)
- `@media print` already hides sidebar/topbar/buttons

### Layout
- Sidebar (`#sidebar`, 252 px wide) + topbar (`#topbar`, 58 px high), main scroll in `#main`
- Sidebar collapses below 768 px and toggles via `openSidebar()`/`closeSidebar()`
- All pages: `include 'includes/header.php'` (after setting `$pageTitle`) and `include 'includes/footer.php'`

### When adding UI
- **Reuse tokens.** Never hard-code a color/shadow/radius — go via `var(--...)`.
- **Reuse classes.** New status? Add a `.status-dot.{color}` rule, don't inline-style.
- **Mobile first.** Test at 375 px width; sidebar must auto-close, modals must scroll inside, DataTable filters must wrap.
- **Print second.** SoA, invoices, and audit reports are printed; keep them inside the `@media print` rules.
- **Touch targets ≥ 44 px** on mobile (already enforced for `.sidebar-nav-item`).

---

## 8. JS conventions (`assets/js/app.js`)

Global helpers attached to `window`: `showToast`, `apiPost`, `confirmDelete`, `fmt`, `fmtDate`, `serializeForm`, `printSection`, `statusDot`, `updateQueryParam`, `copyText`, `showLoading`/`hideLoading`, `markFormDirty`, `syncPeriodSelectors`, `validateFileSize`, `dtDefaults`.

**Patterns to follow:**
- All API calls go through `apiPost(url, data, cb)`. Don't write raw `fetch` calls in page scripts.
- All delete/destructive UI flows go through `confirmDelete(msg, cb)`.
- All currency rendering on the client uses `fmt(n)` (`en-PH` locale, ₱ prefix).
- All DataTables use `dtDefaults` as the base config.
- Toasts via `showToast(msg, type)` — never `alert()`.

---

## 9. Auth, roles, and security

### Role matrix (enforced by `requireRole()`)
| Capability | admin | accountant | staff |
|---|:-:|:-:|:-:|
| Dashboard, Collection, SoA, Expenses, Cash, My Summary | ✓ | ✓ | ✓ |
| Edit/Delete payments | ✓ | ✗ | ✗ |
| Edit/Delete expenses | ✓ | ✗ | ✗ |
| Manage Accounts, Tenants, Units, Categories, Settings | ✓ | ✗ | ✗ |
| Audit Logs, The Vault | ✓ | ✗ | ✗ |

### Already in place
- Prepared statements only — no string-interpolated SQL anywhere in the codebase
- `clean()` (`htmlspecialchars` ENT_QUOTES) on every echoed user input
- Session: HttpOnly, SameSite=Strict, `use_strict_mode`
- `.htaccess`: blocks `config/`, `includes/`, `*.sql/*.log/*.md/*.sh`; sets `X-Frame-Options=SAMEORIGIN`, `X-Content-Type-Options=nosniff`, `Referrer-Policy=strict-origin-when-cross-origin`; strips `X-Powered-By`
- File upload whitelist (`jpg/jpeg/png/gif/pdf/doc/docx/xls/xlsx/zip`) + 10 MB cap + uniqid-named files
- Failed login attempts logged to `system_logs` with IP
- Master password (`settings.master_password`) gates destructive admin operations

### Gaps (see §11)
- No CSRF tokens
- No rate-limit on login
- DB credentials committed to `config/db.php` (gitignored, but a `.env` pattern is safer)

---

## 10. Local development & deployment

### Local stack (Debian 13 + Apache + MySQL)
1. `mysql -u root -p < install.sql`
2. Edit `config/db.php` with local credentials
3. Apache vhost → `DocumentRoot /var/www/laskie`
4. `chown -R www-data:www-data /var/www/laskie && chmod -R 775 /var/www/laskie/uploads`
5. Default login: `admin` / `Admin@2024` — **change immediately**

### Deployment
- `./deploy.sh` rsyncs project to the configured server (uses `.deploy_credentials`, gitignored)
- `config/db.php` is regenerated on the server with deployment credentials — **never commit a real-credentials version**
- `.htaccess` is shipped as-is; ensure `mod_rewrite` is enabled

### Troubleshooting quick-refs
- Blank page → `tail -f /var/log/apache2/laskie_error.log`
- Upload fails → `chown -R www-data:www-data uploads/ && chmod -R 775 uploads/`
- Login broken → verify `users` table seeded; check `system_logs` for `LOGIN_FAILED`

---

## 11. Recommended improvements (ranked)

These are known gaps. Address top items before adding features.

### 🔴 Critical — block new installs / risk data corruption
1. **Schema drift: `unit_charges` is used but missing from `install.sql`.**
   Fix: add `migrations/001_create_unit_charges.sql` and append its DDL to `install.sql`.
   ```sql
   CREATE TABLE unit_charges (
     id INT AUTO_INCREMENT PRIMARY KEY,
     unit_id INT NOT NULL,
     tenant_id INT,
     service_type_id INT,
     amount DECIMAL(12,2) NOT NULL,
     description VARCHAR(255),
     charge_date DATE NOT NULL,
     period_month TINYINT NOT NULL,
     period_year SMALLINT NOT NULL,
     payment_id INT,
     source ENUM('pre_billed','auto_collected') NOT NULL DEFAULT 'pre_billed',
     created_by INT,
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     FOREIGN KEY (unit_id) REFERENCES rental_units(id) ON DELETE CASCADE,
     FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
     FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
     FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
     INDEX idx_uc_unit_period (unit_id, period_year, period_month),
     INDEX idx_uc_payment (payment_id)
   );
   ```
2. **No DB transactions around multi-table writes.** A payment write touches `payments` + `cash_transactions` + `unit_charges`. If any step fails mid-way, the books drift. Wrap every multi-statement API action in `$pdo->beginTransaction() … commit() / rollBack()`.

### 🟠 High — accounting integrity & security
3. **Float money → use bcmath or cents.** Add `money_add/sub/cmp` helpers in `functions.php` and use them anywhere PHP touches money outside of SQL aggregates.
4. **CSRF tokens.** Issue a token in `header.php`, validate in every POST endpoint under `/api/` and `/payments/api_payment.php`.
5. **Payment idempotency.** Add an `idempotency_key` UUID column on `payments` + a debounce wrapper in `apiPost` for `save_payment`.
6. **`.env` config.** Move DB creds out of `config/db.php`. Ship `config/db.php.example`, read real values from `/var/www/laskie/.env` (chmod 600, www-data only).

### 🟡 Medium — performance, observability, robustness
7. **Composite indexes.** `payments(unit_id, period_year, period_month, deleted_at)`, `expenses(unit_id, expense_date, deleted_at)`, `cash_transactions(user_id, transaction_date)`.
8. **Rewrite non-sargable date filters.** `dashboard.php` / `my_summary.php` / `cash_api.php` use `WHERE YEAR(payment_date)=?` and `WHERE MONTH(...)=? AND YEAR(...)=?` — these can't hit `idx_pay_date` / `idx_exp_date`. Rewrite to `payment_date >= ? AND payment_date < ?` so the indexes activate.
9. **PHPUnit tests for accounting math** — _initial suite shipped (`tests/Unit/MoneyHelpersTest.php`, `ProrationTest.php`, `CsrfHelpersTest.php`, plus `tests/Integration/IdempotencyTest.php` and `TransactionAtomicityTest.php`)._ Worth adding next: `getRateForMonth`, `chargeDate`, `generateInvoiceNo` — all pure (or near-pure) and worth pinning behavior on.
10. **Automated daily DB backup** — ship `scripts/backup.sh` + crontab snippet, retain 14 days locally + 90 days off-host.

### 🟢 Low — polish
11. ~~**SRI hashes** on vendor CSS/JS~~ — done. See `vendorSriMap()` in `config/functions.php` + `scripts/generate-sri.sh`. Tags emitted via `vendorCssTag()` / `vendorJsTag()` in header.php + footer.php + index.php.
12. ~~**`BASE_URL` constant** to replace the fragile `$depth` calculation~~ — done. `BASE_URL` defined in `config/functions.php` (overridable in `config/db.php`); `assetUrl()` / `pageUrl()` helpers shipped; header.php's fragile `currentDir === 'laskie' || 'htdocs' || 'www'` heuristic replaced with a URL-path-based fallback.
13. ~~**Update path for vendor libs**~~ — done. See `VENDOR.md` (pinned versions, upgrade procedure, major-version notes).

---

## 12. Working agreements for Claude

When editing this codebase:

- **Never** introduce new SQL string interpolation. Always use prepared statements.
- **Never** read `rental_units.monthly_rate` for past-month math — use `getRateForMonth()`.
- **Never** hard-delete from `payments`, `expenses`, `cash_transactions` — soft-delete, mark voided, or refund.
- **Never** add a new vendor JS/CSS file by hot-linking. Self-host under `assets/vendor/`.
- **Never** hardcode `₱` — use `money()` (PHP) or `fmt()` (JS).
- **Never** commit a `config/db.php` with real credentials. (The file is `.gitignore`'d but a template should exist.)
- **Always** call `requireLogin()` (or stronger) at the top of every PHP entry point that isn't `index.php`.
- **Always** `logActivity()` for user-visible state changes; `logChange()` for edits where before/after matters.
- **Always** use design-system tokens — no ad-hoc colors, shadows, or radii.
- **Always** test at 375 px width before declaring UI done.
- **Always** sum money in SQL when possible; if you must sum in PHP, use bcmath.

---

*Last updated: see `git log -- CLAUDE.md` · Project version: see `APP_VERSION` in `config/functions.php`.*

