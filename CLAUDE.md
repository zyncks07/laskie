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
  - **Exception — `tests/Integration/PaymentReceiptTest.php`** runs against an **isolated `laskie_test` database** (never the live DB; it hard-aborts unless `SELECT DATABASE()` is `laskie_test`). One-time setup: `CREATE DATABASE laskie_test …; GRANT ALL ON laskie_test.* TO 'laskie_db_user'@'localhost';`. It rebuilds the schema from `install.sql` each run and **self-skips** if `laskie_test` is absent. Run it directly: `vendor/bin/phpunit tests/Integration/PaymentReceiptTest.php`.

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
├── my_account.php         # Self-service profile/password/avatar editor (any logged-in user)
├── install.sql            # Schema + seed (run once)
├── .htaccess              # PHP ini values, security headers, mime caching
│
├── admin/                 # Admin-only pages (requireAdmin())
│   ├── accounts.php       # User CRUD
│   ├── tenants.php
│   ├── units.php          # Units + unit_types + service_types + rate history
│   ├── transactions.php   # Soft-deleted / voided payments — restore UI
│   ├── requests.php       # Review/approve vault cash requests (approve auto-issues vault_return)
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
│   ├── settings_api.php   # System settings CRUD
│   ├── unit_chart_api.php # Dashboard "Revenue vs Expenses by Unit" chart data (JSON)
│   └── requests_api.php   # Vault cash requests + in-app notifications (create/approve/reject + bell feed)
│
├── config/
│   ├── db.php             # PDO connection (thin template; calls env.php + connects)
│   ├── env.php            # .env loader → define()s DB_* constants (see §11)
│   └── functions.php      # Helpers — see §6
│
├── includes/
│   ├── header.php         # Sidebar + topbar layout, session boot
│   └── footer.php         # JS includes + shared inline helpers
│
├── assets/
│   ├── css/app.css            # Base design system (see §7)
│   ├── css/laskie-tokens.css  # Magix redesign token layer — loaded after app.css (see §7)
│   ├── js/app.js              # Global JS helpers
│   └── vendor/                # All third-party CSS/JS (self-hosted)
│
└── uploads/               # User-uploaded files — writable by www-data
    ├── contracts/   receipts/   docs/   remittance/
```

---

## 4. Database schema (accounting-critical)

Read [`install.sql`](install.sql) for the authoritative DDL. The live DB is current through **migration 010** (`migrations/` holds the incremental DDL; fresh installs get the same via `install.sql`). Highlights:

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
  - `receipt_path` / `receipt_url` — optional proof-of-payment (bank-transfer screenshot / PDF receipt) uploaded on the collection page, or an external link. Mirrors `expenses.receipt_path/receipt_url`; added in `migrations/010_add_payment_receipt.sql`.
  - `status` ∈ {`paid`, `refunded`, `partially_refunded`, `voided`} — **never** delete; always status-flip
  - `deleted_at` — **soft delete**, restorable from `admin/transactions.php`
- `expenses` — amount, expense_date, unit_id, category_id, recorded_by, receipt_path, **soft-delete via `deleted_at`**
- `cash_transactions` — per-user ledger; `transaction_type` ∈ {`received`, `remitted`, `expense`}; FKs back to `payments.id` / `expenses.id`
- **`unit_charges`** — pre-billed line items per unit/period; rows with `payment_id IS NULL` are outstanding. `source` ∈ {`pre_billed`, `auto_collected`}. **`voided_at`/`voided_by`/`void_reason`** = admin write-off (migration 011); a voided charge is never outstanding and is never hard-deleted.
  > DDL shipped in `migrations/001_create_unit_charges.sql` and embedded in `install.sql`, so fresh installs work.
- **`rent_charge_voids`** — admin write-offs of the **virtual** monthly rent charge (migration 011). Keyed on `(unit_id, period_month, period_year)` + optional `tenant_id`; several rows may waive one period (partials accumulate). Only rows with `restored_at IS NULL` are in effect. Waivers move receivables only — no cash, no income.

### History & audit
- `unit_rate_history` — every rent increase. **Read via `getRateForMonth()` — never read `rental_units.monthly_rate` directly when computing historical balances.**
- `system_logs` — append-only audit. Use `logActivity()` for free-text, `logChange()` for before/after JSON diffs.

### Dividends ("The Vault")
- `dividend_recipients`, `dividend_distributions`, `dividend_returns` — money flowing out to (and back from) owners/investors.

### Vault cash requests & notifications (migration 009)
- `vault_requests` — a staff/accountant request to have cash returned from the Vault (deposit refunds, unexpected expenses after remitting). `status` ∈ {`pending`,`approved`,`rejected`,`cancelled`}. **Approving (admin) auto-issues a `vault_return` `cash_transactions` row** crediting the requester and stores its id in `cash_tx_id`. See `api/requests_api.php`.
- `notifications` — per-user in-app feed for the topbar bell. New requests notify all admins; decisions notify the requester. Pull-based (page-load + ~15s poll); **separate from `system_logs`** (which stays the immutable audit trail).

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
6. **Voided payments are still in the table; refunds must be netted.** Always filter `WHERE deleted_at IS NULL AND status != 'voided'` when summing payments for reports, AND subtract each payment's refunds so a refunded receipt doesn't count as income. The canonical pattern is `SUM(p.amount - COALESCE(r.refsum,0))` via `LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r` (see `dashboard.php`, `api_payment.php` `monthly_summary` / `get_unit_payments`). The SoA ledger (`history.php`) instead lists refunds as separate debit rows — already net. Cash-on-hand already nets `refunded` cash rows (see invariant for `getUserCashOnHand()`).
7. **Rent charges are VIRTUAL — there is no rent-charge table.** Every rent line is recomputed per render from `tenants.contract_start` × `rental_units.due_day` × `unit_rate_history`. Generate them through **`buildRentChargeRows()`** (SoA screen + PDF) and always net active `rent_charge_voids` off the expected amount anywhere a balance/"amount due" is shown (`dashboard.php`, `api_payment.php` `monthly_summary`, `getUnitPaymentStatus()`). A waiver is capped at the **unpaid** portion — `waivableRent(gross, netPaid, alreadyVoided)` — so a paid month can never be waived into a phantom credit; reverse a payment with a refund instead. Waivers render as an offsetting **credit line**, never by hiding the charge.
8. **Invoice numbers are sequential per year.** `generateInvoiceNo()` uses `MAX(...) + 1` on `INV-YYYY-%`. If you change the format, write a migration that backfills.
9. **All money columns are `DECIMAL(12,2)`.** PHP-side they are read as float — be aware of float drift; sum in SQL when possible.
10. **All audit-relevant mutations call `logActivity()` or `logChange()`** with module, action, and details. Don't skip this for "small" admin edits.
11. **Soft-delete restore goes through `admin/transactions.php`** — do not expose restore links elsewhere.

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
| `buildRentChargeRows($pdo, $unitId, $occupants, $dueDay, $baseRate, $from, $to, $voidMap)` | **The** rent-charge generator (SoA screen + PDF). Returns per-month `gross`/`voided`/`net` + attached waivers |
| `getRentVoidMap($pdo, $unitId, $from, $to)` | Active rent waivers for a unit over a range, keyed `"Y-n"` |
| `getRentVoidTotals($pdo, $m, $y)` | **Batched** waiver totals for one period keyed by `unit_id` — use in per-unit loops (dashboard, collection grid) |
| `getRentVoidedForPeriod($pdo, $unitId, $m, $y)` | Waiver total for one unit/period |
| `getRentPaidForPeriod($pdo, $unitId, $m, $y)` / `getRentPaidByPeriod($pdo, $unitId)` | Net rent paid (payments − refunds), single period / all periods keyed `"Y-n"` |
| `getGrossRentCharge($pdo, $unitId, $m, $y, $tenantId?)` | Server-side recompute of a period's full rent charge — never trust a posted amount |
| `waivableRent($gross, $netPaid, $alreadyVoided)` | Cap rule for a void: `max(0, gross − paid − waived)` |
| `getUnitPaymentStatus($pdo, $unitId, $m, $y)` | Returns `'green'`/`'amber'`/`'red'`/`'gray'` for the unit-status grid |
| `getUserCashOnHand($pdo, $userId)` | Authoritative per-user cash on hand (`received + vault_return − remitted − expenses − refunded`). Use for any "enough cash?" gate (e.g. refund cashier check) |
| `notifyUser($pdo, $userId, $type, $msg, $link?, $reqId?)` / `notifyAdmins(...)` | Insert in-app notification(s) for the topbar bell. Best-effort (try/catch); store **raw** text (render escapes) |
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

### Redesign token layer — `assets/css/laskie-tokens.css`
The **monochrome "Magix" redesign** drives the entire UI. `laskie-tokens.css` is loaded by `header.php` and `index.php` **after** `app.css`; all pages use it.

- **Palette:** black/white/refined-grayscale. `--ink` (`#0a0a0a`) ↔ `--paper` (`#ffffff`) with a 9-stop gray ramp. Dark mode via `[data-theme="dark"]` on `<html>` (FOUC guard in `<head>`, toggle in topbar + login page).
- **Namespace:** `--laskie-*` for radius/shadow/card tokens; `--ink`, `--paper`, `--gray-*` for color. The old warm `--laskie-amber/teal/coral/indigo` names are kept as gray aliases so no markup breaks — do **not** add new refs to those names.
- **Rule for all agents:** always use `--laskie-*` / `--ink` / `--paper` / `--gray-*` tokens. Never hard-code a hex. Never reference the old warm palette names for new work. Every token used must be defined in `laskie-tokens.css` (an undefined `var()` silently falls back to nothing).
- **Dark mode:** `[data-theme="dark"]` block in `laskie-tokens.css` flips the gray ramp. Bootstrap utility variables (`--bs-secondary-color`, `--bs-secondary-rgb`, `--bs-pagination-*`, `--bs-tertiary-bg`, `--bs-secondary-bg`) are overridden in that block so Bootstrap components (`.text-muted`, pagination, file inputs, modals) adapt automatically.

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
| View all staff cash-on-hand balances (`cash.php` All Staff table + cross-user filter) | ✓ | ✓ | ✗ |
| Edit/Delete payments | ✓ | ✗ | ✗ |
| Edit/Delete expenses | ✓ | ✗ | ✗ |
| Edit/Delete cash transactions (own or others) | ✓ | ✗ | ✗ |
| Manage Accounts, Tenants, Units, Categories, Settings | ✓ | ✗ | ✗ |
| Audit Logs | ✓ | ✗ | ✗ |
| The Vault (dividend recipients, distributions, returns) | ✓ | ✓ | ✗ |
| Vault → User cash returns (issue/edit/delete cash from the Vault back to a user, within Vault page) | ✓ | ✓ | ✗ |
| Request cash from the Vault (`cash.php` → `requests_api.php create_request`) | ✓ | ✓ | ✓ |
| Approve/reject cash requests (`admin/requests.php`; approve auto-issues the `vault_return`) | ✓ | ✗ | ✗ |
| Process a payment refund — admin picks the cashier; refund is hard-gated on that cashier's cash-on-hand | ✓ | ✗ | ✗ |

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

### ⚠️ This dev host IS the production host
On `192.168.9.18:49200`, Apache's `DocumentRoot` points at **`/home/bulik/apps/laskie/`** (this source repo), not `/var/www/laskie/` — the working copy IS the live deployment. Every Edit/Write here is visible to live traffic on the next HTTP request. There is no "deploy" step; `git pull` (or just editing) is the deploy. The stale `/var/www/laskie/` directory exists but Apache no longer reads from it.

Implications for file work:
- File permissions matter. Apache runs as `www-data`. Config files (`config/db.php`, `config/env.php`, `config/functions.php`, `.env`) are owned `bulik:www-data` mode 640. `bulik` is in the `www-data` group; `www-data` is NOT in the `bulik` group. A file that drifts to `bulik:bulik` 640 is unreadable by Apache → HTTP 500.
- Edit/Write tools do NOT preserve file group on rewrite. After editing any `config/*.php`, run `sudo chown bulik:www-data <file>` (or the consolidated `sudo bash /home/bulik/laskie-recompress.sh` which restores perms across the tree).
- Directories have setgid (`chmod g+s`) so new files inherit group `www-data`. If a directory ever loses setgid, future files there will silently 500 the site.

### Troubleshooting quick-refs
- Blank page → `tail -f /var/log/apache2/laskie_error.log`
- Upload fails → `chown -R www-data:www-data uploads/ && chmod -R 775 uploads/`
- Login broken → verify `users` table seeded; check `system_logs` for `LOGIN_FAILED`

---

## 11. Recommended improvements (ranked)

Items #1–7 and #10–13 (schema drift, atomic transactions, cents-based money,
CSRF, idempotency, `.env` config, composite indexes, daily backup, SRI hashes,
`BASE_URL`, vendor-update path) are **done** — see `git log` for the commits and
the files/helpers each one shipped. Still open:

8. **Rewrite non-sargable date filters** — _partially done._ `dashboard.php` (rev/exp aggregates), `api/expenses_api.php list_expenses`, `api/cash_api.php list_transactions`, and `api/unit_chart_api.php` all use `monthRange()` / `yearRange()` helpers now. Still using `YEAR()` / `MONTH()` predicates: `my_summary.php`, `admin/logs.php`, `admin/vault.php` (get_logs query + chart queries + year-list UNION), and a few `SELECT DISTINCT YEAR(...)` calls that exist only to populate year dropdowns (low impact). Convert the remaining filter predicates; leave the DISTINCT-year ones — they have no index to hit anyway.
9. **PHPUnit tests for accounting math** — _suite shipped (59 unit + integration; see `tests/`)._ Worth adding next: tests covering void/restore of `auto_collected` service payments (regression for the phantom-charge fix in §13).

> For a record of past bug-fix sprints, feature work, and audit sessions, read
> `git log` and the project memory files (`bug_audit_coverage`,
> `deferred_bug_findings`, `monochrome_redesign`, `vault_request_feature`).
> Durable "don't re-fix this" guidance lives in §13.

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

## 13. Intentional design decisions — apparent-bug exceptions

These look like bugs but are **deliberate choices**. Do not "fix" them without a design discussion.

### `admin/vault.php` — Accountants can delete vault remittances (no `isAdmin()` on `delete_remittance`)
`delete_remittance` in `admin/vault.php` intentionally has **no `isAdmin()` guard**, so accountants can delete any `transaction_type='remitted'` cash transaction via the Vault page. The Vault workflow expects accountants to have full CRUD over remittances they record on behalf of staff. The role-matrix entry "Edit/Delete cash transactions (own or others) — admin only" refers specifically to `cash_api.php::delete_cash_tx` (the general-purpose endpoint); vault-context remittance management is a separate, deliberately accountant-accessible sub-flow. Do **not** add `requireAdmin()` to this action.

### `admin/vault.php` — Accountants can issue/edit/delete Vault → User cash returns (no `isAdmin()` on `add/edit/delete/get_user_return`)
The four `*_user_return` actions and their UI (the "Return to User" button, the "Returns to Users" card, and the user_return Edit/Delete buttons in the transaction log) intentionally carry **no `isAdmin()` guard** beyond the page-level `requireRole(['admin','accountant'])` (changed 2026-06-03, by user request). Accountants can issue cash from the Vault directly back to a user without going through the admin request→approval flow — the whole Vault page is symmetric for both roles. This reverses the admin-only gate originally added in commit `9e6514f`; do **not** re-add `isAdmin()` to these actions. Note the separate request/approval flow (`admin/requests.php` + `api/requests_api.php` `approve_request`/`reject_request`) stays **admin-only** — only direct issuance was opened up. There is deliberately **no vault-balance gate** on issuance (matches admin behavior; the vault balance can go negative by admin/accountant action).

### `payments/api_payment.php` — `save_charge` has no `requireAdmin()` (any logged-in user can create/edit pre-billed charges)
The `save_charge` action intentionally has **no role guard** beyond `requireLogin()`, and the "Add Service Charge" button in `payments/collection.php` is shown to all roles. This is a **deliberate workflow choice** (confirmed 2026-05-30): staff/accountants pre-bill service charges during collection. Note the asymmetry — `delete_charge` and editing an already-PAID charge *are* admin-only (you can add/edit an unpaid charge but only an admin can delete one or touch a paid one). Do **not** add `requireAdmin()` to `save_charge`.

### `payments/api_payment.php` — `delete_charge` is a **soft void**, not a delete
Despite the action name, `delete_charge` sets `unit_charges.voided_at/voided_by/void_reason` instead of running a `DELETE` (the name is kept so existing callers in `payments/collection.php` keep working; `void_charge` is an alias). It requires a reason and is reversible via `restore_charge` from the SoA. Do **not** "fix" it back to a hard delete — that was the audit-trail hole this replaced.

### `api/settings_api.php` — Master password hashed at bcrypt cost 10, not the app-standard 12
`change_master` at line 111 uses `password_hash($new, PASSWORD_BCRYPT)` (default cost 10), while all user passwords use `['cost' => 12]`. This is **intentional**: the master password is re-verified on every Settings page unlock — potentially several times per admin session — so the lower cost avoids noticeable latency in the UI. The placeholder hash at line 285 (`import_accounts`) also uses default cost intentionally; it is a throwaway credential that admins must reset immediately via the Accounts page. Do **not** unify these to cost 12.

---

*Last updated: see `git log -- CLAUDE.md` · Project version: see `APP_VERSION` in `config/functions.php`.*


