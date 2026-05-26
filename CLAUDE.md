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
| `handleUpload($field, $subDir)` | File upload with whitelist + 30 MB cap. Auto-compresses image uploads via `compressImage()`. Returns `['path'=>..., 'error'=>...]` |
| `compressImage($absPath, $opts)` | Re-encode JPEG/PNG/WebP in place (long-edge cap + quality). Non-images and GIFs are no-ops. Returns `['compressed'=>bool, 'original_size'=>, 'new_size'=>, 'new_path'=>, 'reason'=>]` |
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
| Audit Logs | ✓ | ✗ | ✗ |
| The Vault (dividend recipients, distributions, returns) | ✓ | ✓ | ✗ |

### Already in place
- Prepared statements only — no string-interpolated SQL anywhere in the codebase
- `clean()` (`htmlspecialchars` ENT_QUOTES) on every echoed user input
- Session: HttpOnly, SameSite=Strict, `use_strict_mode`
- `.htaccess`: blocks `config/`, `includes/`, `*.sql/*.log/*.md/*.sh`; sets `X-Frame-Options=SAMEORIGIN`, `X-Content-Type-Options=nosniff`, `Referrer-Policy=strict-origin-when-cross-origin`; strips `X-Powered-By`
- File upload whitelist (`jpg/jpeg/png/gif/pdf/doc/docx/xls/xlsx/zip`) + 30 MB cap + uniqid-named files
- Failed login attempts logged to `system_logs` with IP
- Master password (`settings.master_password`) gates destructive admin operations

### Gaps (see §11)
- DB credentials committed to `config/db.php` (gitignored, but a `.env` pattern is safer)
- Login lockout exists (5 fails / 15 min) but no global rate-limit at the web-server layer

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
1. ~~**Schema drift: `unit_charges` is used but missing from `install.sql`.**~~ — done. `migrations/001_create_unit_charges.sql` shipped; DDL is also embedded in `install.sql` so fresh installs work.
2. ~~**No DB transactions around multi-table writes.**~~ — done. Every money-moving action in `payments/api_payment.php` (save_payment, void_payment, restore_payment, purge_payment, process_refund, bulk_delete_payments) and `api/expenses_api.php` (save_expense, purge_expense, bulk_delete_expenses) is wrapped in `beginTransaction() … commit() / rollBack()`. `tests/Integration/TransactionAtomicityTest.php` pins the behaviour.

### 🟠 High — accounting integrity & security
3. ~~**Float money → use bcmath or cents.**~~ — done. `to_cents()`, `from_cents()`, `money_add/sub/sum/mul/div/cmp/eq/gt/gte/lt/lte/max/is_zero/is_pos` shipped in `config/functions.php`. Used everywhere PHP combines money values (proration, payment status, dashboard totals, transaction grand totals, vault balance). `tests/Unit/MoneyHelpersTest.php` covers the math.
4. ~~**CSRF tokens.**~~ — done. `csrfToken()`, `csrfField()`, `csrfRequirePost()` shipped; every POST endpoint under `/api/`, `/payments/`, `/admin/` invokes `csrfRequirePost()`. Front-end `apiPost()` auto-adds the `X-CSRF-Token` header. `tests/Unit/CsrfHelpersTest.php` pins token format + validation.
5. ~~**Payment idempotency.**~~ — done. `payments.idempotency_key` column shipped via `migrations/004_add_payment_idempotency.sql` with a UNIQUE index. `save_payment` looks up the key before INSERT and falls back to returning the existing row on the MySQL 1062 race. `tests/Integration/IdempotencyTest.php` covers replay + race.
6. **`.env` config.** Move DB creds out of `config/db.php`. Ship `config/db.php.example`, read real values from `/var/www/laskie/.env` (chmod 600, www-data only).

### 🟡 Medium — performance, observability, robustness
7. ~~**Composite indexes.**~~ — done. `migrations/005_add_composite_indexes.sql` adds the three covering indexes called out here.
8. **Rewrite non-sargable date filters** — _partially done._ `dashboard.php` (rev/exp aggregates), `api/expenses_api.php list_expenses`, `api/cash_api.php list_transactions`, and `api/unit_chart_api.php` all use `monthRange()` / `yearRange()` helpers now. Still using `YEAR()` / `MONTH()` predicates: `my_summary.php`, `admin/logs.php`, `admin/vault.php` (get_logs query + chart queries + year-list UNION), and a few `SELECT DISTINCT YEAR(...)` calls that exist only to populate year dropdowns (low impact). Convert the remaining filter predicates; leave the DISTINCT-year ones — they have no index to hit anyway.
9. **PHPUnit tests for accounting math** — _initial suite shipped (`tests/Unit/MoneyHelpersTest.php`, `ProrationTest.php`, `CsrfHelpersTest.php`, `ChargeDateTest.php`, `DateRangeTest.php`, `UrlHelpersTest.php`, `ImageCompressionTest.php`, plus `tests/Integration/IdempotencyTest.php`, `TransactionAtomicityTest.php`, `RateHistoryTest.php`, `InvoiceNumberTest.php`, `VaultUserReturnTest.php`)._ 59 unit tests, 100% passing. Worth adding next: tests covering void/restore of auto_collected service payments (regression for the phantom-charge fix in §13).
10. ~~**Automated daily DB backup**~~ — local side done. `scripts/backup.sh` mysqldumps the DB + tars `uploads/` into a config-driven backup directory (`LASKIE_BACKUP_DIR`, default `$HOME/laskie-backups`), gzip-compresses, chmod 600, and prunes files older than `LASKIE_BACKUP_RETENTION` days (default 14). Crontab snippet lives in the script header. Off-host 90-day retention is documented as an rsync step you append to the script for your remote store of choice.

### 🟢 Low — polish
11. ~~**SRI hashes** on vendor CSS/JS~~ — done. See `vendorSriMap()` in `config/functions.php` + `scripts/generate-sri.sh`. Tags emitted via `vendorCssTag()` / `vendorJsTag()` in header.php + footer.php + index.php.
12. ~~**`BASE_URL` constant** to replace the fragile `$depth` calculation~~ — done. `BASE_URL` defined in `config/functions.php` (overridable in `config/db.php`); `assetUrl()` / `pageUrl()` helpers shipped; header.php's fragile `currentDir === 'laskie' || 'htdocs' || 'www'` heuristic replaced with a URL-path-based fallback.
13. ~~**Update path for vendor libs**~~ — done. See `VENDOR.md` (pinned versions, upgrade procedure, major-version notes).

---

### Recently fixed (audit-trail of bug-fix sprints)

Most of the items above were addressed in dedicated bug-fix commits. If you're investigating a regression, check that the fix didn't get reverted before redoing it:

- `9e6514f` — 18 fixes: invoice_print fake-receipt banner, soa_pdf voided/deleted filter, edit-payment overwriting refund cash rows, rate edit retroactively rewriting history, void of auto_collected leaving phantom charges, edit non-existent payment silently succeeding, `X-Forwarded-For` spoofing (added `getClientIp()`), factory_reset missing tables + server-side `confirm=RESET`, vault user-return admin-only gate, import_accounts role/status whitelist + valid bcrypt placeholder, admin self-demote / last-admin lockout guard, dashboard late-fee threshold overflow, tenants vacate-unit logic, float-money grand totals, removed `error_reporting(0)` overrides, removed redundant `addslashes()` wrappers, delete_doc orphan files, tenant status whitelist.
- `2aa97c9` — 8 fixes: monthly_summary historical rate lookup, void_payment unit_charges release + transaction wrap, restore_payment cash re-creation, get_unit_payments voided filter, due_day month-overflow capping, cash.php XSS hardening, requireLogin redirect fix, my_summary footer totals via money_sum.
- `10e7888` — voided/deleted filter across dashboard + report queries; dynamic unit chart with period selector.
- `ee46d87` — schema fixes, atomic transactions, CSRF, tests, vault returns.

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

### Database access policy during bug investigation

> ⚠️ **This rule has zero exceptions unless the user explicitly authorizes a specific operation.**

- **Never** run `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `TRUNCATE`, or `DROP` against the live database while investigating or fixing a bug. Investigation is **read-only** (`SELECT` only).
- **Never** run seed scripts, fixture loaders, or any script that inserts sample/test data (e.g., `install.sql`, any `seed_*.sql`, PHPUnit seeders) against the live database.
- **Never** use the Bash tool to pipe SQL or call `php artisan`-style commands that modify data as a side effect of investigation.
- If a bug fix requires a data correction (e.g., removing an erroneous row), **stop and describe the proposed change** to the user first. Only execute it after receiving explicit approval for that specific row/operation.
- When in doubt about whether a query is safe to run, default to showing the query and asking the user to run it themselves.

---

*Last updated: see `git log -- CLAUDE.md` · Project version: see `APP_VERSION` in `config/functions.php`.*

