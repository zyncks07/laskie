# Laskie RMS — Monochrome UI/UX Redesign Plan

> **Authoritative design brief.** Any agent/human executing the redesign follows this file.
> It is a **pure presentation refactor**: no PHP logic, SQL, money math, auth, CSRF, or
> business code may change. The codebase is bug-hardened (5 audit sessions, 59/59 unit tests) —
> keep it that way. Read CLAUDE.md §5/§9/§12/§13 before touching anything money- or auth-adjacent.

---

## 1. Context

**Why.** The app currently runs a half-finished "Magix" warm palette (amber/teal/coral/indigo) in
`assets/css/laskie-tokens.css`, applied fully only to `dashboard.php` + `index.php`; every other
page still shows the original navy `app.css` look. The result is visually inconsistent, and the
warm palette competes for attention on dense accounting screens. Screenshots in
`design/visualBugs/` also show concrete defects: stat-card values clip their descenders
(`₱550,120.0`, `₱400,408.07` cut off), and unit-status cells overflow.

**Goal.** A single, coherent **black-and-white minimalist** design across all ~20 pages:
- **Light mode default**, one-tap **dark mode** (trivial because the theme is just an inverted grayscale ramp).
- **Mobile-first**: intuitive button/input placement, everything reachable and legible at 375 px.
- **Desktop-dense**: use the width for information density (tight tables, multi-column layouts) while keeping controls obvious.
- **Color → contrast**: emphasis that used to be red/green/blue is re-expressed through **fill, weight, iconography, and inversion** (chosen technique below), never hue.
- **Minimalist charts** rendered in grayscale.
- **Zero functional regressions.**

**Locked design decisions (from user):**
1. **Palette:** refined neutral gray ramp (not stark two-tone) — black/white anchors + ~7 grays.
2. **Status emphasis:** *fill + weight + icon* — solid ink pill = needs attention; outline pill = normal; muted/strikethrough = voided; bold + ▲/▼ caret for +/− deltas.
3. **Charts:** purely minimalist black/white/grayscale, intuitive via the same emphasis logic — **ignore both `design/inspiration/*.png` images** (they reflect the abandoned warm palette).

---

## 2. Architecture & strategy

### 2.1 The token-layer flip (makes the whole thing safe + delegable)
`includes/header.php` (and `index.php`) load `app.css` **then** `laskie-tokens.css`. Because
`laskie-tokens.css` already **remaps every legacy semantic var** (`--primary`, `--success`,
`--danger`, …) **and** the Bootstrap `--bs-*-rgb` vars, **rewriting that one file to the grayscale
ramp instantly turns every page monochrome** — including pages no one has touched — with no markup
changes. Page-level tiers then *refine* emphasis (pills, glyphs, deltas); they are polish, not
all-or-nothing. This is the backbone of the delegation: **Opus ships the token layer first; nothing
downstream can render broken because the fallback is always "correct but plain monochrome."**

- **Do all palette work in `laskie-tokens.css`.** Treat `app.css` as the base; only edit it for the
  structural layout bug-fixes in §3.5 (stat-card clip, status-cell overflow) where an override would
  be hacky.
- **Every `var(--laskie-*)` used must be defined** in the tokens file (an undefined `var()` silently
  breaks the rule). Keep the audit habit: 28 used / 30 defined today — keep that invariant green.

### 2.2 Dark mode mechanism
- `<html data-theme="light|dark">`. Tokens live under `:root` (light) with a `[data-theme="dark"]`
  override block in `laskie-tokens.css` that flips the ramp (paper→near-black, ink→near-white, etc.).
- **FOUC guard:** a tiny inline `<script>` in `header.php` **`<head>`** (and `index.php` head) reads
  `localStorage['laskie-theme']` and sets `documentElement.dataset.theme` *before* first paint.
- **Toggle:** a sun/moon `.btn-icon` in the topbar (`header.php`) calling `window.toggleTheme()` in
  `app.js`: flips the attribute, persists to localStorage, swaps the glyph, and dispatches a
  `laskie:themechange` CustomEvent.
- **Charts** subscribe to `laskie:themechange` and re-render with fresh `getComputedStyle` colors
  (see §3.4).

### 2.3 Hard guardrails (every tier obeys)
- **Never** touch PHP logic, SQL, money helpers, role guards, CSRF, idempotency, or the
  `*_api.php` / `api_payment.php` business code. Editable surface = **HTML markup (class/structure),
  inline `style=""` → classes, `<style>` blocks, the three asset files, and presentational JS
  (chart colors + theme toggle) only.**
- **Preserve every `id`/`data-*`/`name` that JS or DataTables reads.** Before renaming any selector,
  `grep -rn "getElementById('X')\|querySelector\|#X\|name=\"X\""`. Known load-bearing IDs:
  `#unitChartPeriod`, `#unitChartStats`, `#notifBell`, `#notifPanel`, `#notifBadge`, `#notifList`,
  `#sidebar`, `#sidebarOverlay`, `#topbar`, `#main`, all DataTable `<table id>`s, all modal/form IDs.
- **Keep the data-attribute event pattern** introduced in the XSS sweeps — do **not** reintroduce
  `onclick="fn('<?=clean(...)?>')"` interpolation. Keep every `clean()` call.
- **Keep** the CSRF `<meta>`, `vendorCssTag/JsTag` (SRI), and the self-hosted-vendor rule (no new
  hot-linked assets; grayscale needs no new libs).
- **The working tree is the live deploy** (Apache DocumentRoot = repo root, port 49200). Land the
  Opus foundation as one coherent change so there is never a half-themed intermediate state; test
  before moving on.
- **Perms:** Edit/Write can flip a file's group to `bulik:bulik`. `config/*` is the only 640-sensitive
  set and we don't touch it, but after editing `includes/*.php` / `assets/*`, verify with
  `ls -la` and, if any group drifted, run `sudo bash /home/bulik/laskie-recompress.sh` to restore
  group + setgid.

---

## 3. Design system specification

### 3.1 Color tokens (define in `laskie-tokens.css`)

**Light (`:root`):**
```
--ink:      #0a0a0a   /* primary text, solid buttons, emphasis fills   */
--gray-900: #1a1a1a   /* strong headings                               */
--gray-800: #2b2b2b   /* headings                                      */
--gray-600: #555555   /* secondary text (AA on white ~7:1)             */
--gray-500: #737373   /* muted labels                                  */
--gray-400: #9b9b9b   /* hints / disabled (non-essential only)         */
--gray-300: #c4c4c4   /* strong borders, chart secondary fill          */
--gray-200: #e4e4e4   /* default borders / dividers                    */
--gray-100: #f4f4f4   /* hover, table zebra, inset fills               */
--gray-50:  #fafafa   /* page background (cards = pure white pop)       */
--paper:    #ffffff   /* card / surface                                */
```
**Dark (`[data-theme="dark"]`)** — inverted, slightly soft to avoid halation:
```
--paper:    #161616  --gray-50: #0f0f0f (page bg #0d0d0d)
--gray-100: #1e1e1e  --gray-200: #2b2b2b  --gray-300: #3f3f3f
--gray-400: #6b6b6b  --gray-500: #8a8a8a  --gray-600: #b0b0b0
--gray-800: #d8d8d8  --gray-900: #ececec  --ink: #f5f5f5
```

**Legacy remap (keep names, swap values so untouched pages go mono instantly):**
| Legacy var | → maps to | Notes |
|---|---|---|
| `--primary`, `--primary-hover` | `--ink`, `#000` | solid-black primary buttons |
| `--primary-light` | `--gray-100` | active sidebar pill bg |
| `--primary-mid`, `--accent` | `--gray-700/600` | |
| `--text-primary` | `--ink` | |
| `--text-secondary` | `--gray-600` | |
| `--text-muted` | `--gray-500` | |
| `--border` / `--border-light` | `--gray-200` / `--gray-100` | |
| `--bg` | `--gray-50` | |
| `--card-bg`,`--sidebar-bg`,`--topbar-bg` | `--paper` | |
| `--success` / `--danger` / `--warning` / `--info` | `--ink` | hue removed; emphasis now via §3.3 |
| `--success-bg`/`--danger-bg`/… | `--gray-100` | |
| `--bs-primary/-rgb`, `--bs-success`, `--bs-danger`, `--bs-warning`, `--bs-info` | ink / grays | remap all Bootstrap utility colors |

Retire the warm `--laskie-amber/teal/coral/indigo*` and `--laskie-card-dark` (navy). Keep the
`--laskie-radius-*`/`--laskie-shadow-*` names (re-tune values, §3.6) so existing references survive.

### 3.2 Typography & density
- Keep **DM Sans** (UI) + **DM Mono** (`.mono`: IDs, invoice #, IP, log codes, money in tables).
- Base 13.5 px stays. Headings via **weight + size**, not color: page-title 19/700, card-header 13.5/700, section-label 10/700 uppercase tracking `.12em` in `--gray-500`.
- **Money is monospaced + tabular** in tables (`font-variant-numeric: tabular-nums`) so columns align — a core ledger feel and a free density win.
- Desktop density: table row padding `6px 12px`; introduce a `.density-compact` table variant
  (`5px 10px`) for the big grids (collection, transactions, logs, vault). Cards tighten to
  `14px 16px`.

### 3.3 Status / emphasis system (fill + weight + icon) — the core rule
Replace every colored status with these monochrome primitives. Define as reusable classes; tiers
apply them.

- **`.attn-pill`** (needs attention: overdue, inactive, expense, voided-reason, failed): **solid
  `--ink` background, `--paper` text, bold**, FontAwesome glyph. Auto-inverts in dark mode.
- **`.ok-pill`** (normal/positive: paid, active, occupied, received): **outline** — `1px solid
  --gray-300`, `--gray-700` text, normal weight, light `✓` glyph.
- **`.muted-pill`** (neutral: vacant, former, remitted): `--gray-100` fill, `--gray-500` text.
- **Voided/deleted rows:** `text-decoration: line-through; color: --gray-400;` + small `✕`.
- **+/− deltas (net income, balances):** never red/green. Positive = normal weight; **negative =
  bold + `▼` caret prefix** (`.delta-neg`); large positive may use `▲` (`.delta-pos`). Build a tiny
  PHP/JS helper-free convention: the tier wraps the value and adds the class based on the existing
  `money_gte(...,'0.00')` boolean that pages already compute (no new logic).
- **Unit-status grid** (`getUnitPaymentStatus()` returns `green/amber/red/gray` — **keep the function
  untouched**; only restyle its output): map to **glyphs**, not dots —
  `green→✓` (outline), `amber→!` (ring/half-fill), `red→✕` (solid ink), `gray→–` (faint). Provide
  `.status-glyph.{green,amber,red,gray}` so the class names still match the returned strings.
- **`.status-dot`** stays for legends but becomes: red = solid ink + ring, amber = ink ring (hollow),
  green = solid gray-700, gray = gray-300.
- **Badges** (`.badge-{admin,accountant,…,expense,received,…}`): collapse to two visual families —
  *attention* badges (`inactive`, `expense`, `overdue`) use `.attn-pill` look; the rest use outline
  or `--gray-100` fill. Differentiate roles by a leading glyph, not hue.

### 3.4 Charts (Chart.js — `dashboard.php`, `admin/vault.php`) — minimalist black/white/grayscale
**No external inspiration images** — design purely minimalist B&W/grayscale that stays *intuitive*,
carrying the same §3.3 emphasis logic (fill + weight + the highlighted element draws the eye, never hue).
- Add a shared **`window.chartTheme()`** in `app.js` returning colors from `getComputedStyle`:
  `ink`, `grid` (`--gray-200`), `tick` (`--gray-500`), and a **grayscale series ramp**
  `[--ink, --gray-400, --gray-300, --gray-600 …]`. Multi-series separated by **lightness + dash
  pattern + point shape**, never hue.
- **Intuitive emphasis carries from §3.3:** the data point that matters reads as the darkest/solid
  one. Bars: rounded top (`borderRadius`), `--gray-300` default; **the highlighted/selected bar =
  solid `--ink`** so it pops the way a `.attn-pill` does, the rest recede in gray. Lines: `--ink`
  2px, faint `--gray-100` area fill, no shadow; comparison series = `--gray-400` dashed with a
  distinct point marker. Gridlines: hairline `--gray-200`, drop vertical grid. Legend: text + small
  grayscale square swatch (paired with the dash/point style so series stay distinguishable in mono).
- Direct value labels where they aid scanning (since color can't encode meaning), keeping it sparse
  so the minimalist feel holds.
- Tooltips: white card, `--gray-200` border, ink text (already mostly themed).
- **Re-render on `laskie:themechange`** so dark mode recolors charts (store chart instances; call
  `.update()` after re-reading `chartTheme()`).
- Replace all hardcoded chart hexes and the `var(--laskie-amber/coral)` chart references in
  `dashboard.php`/`vault.php` with theme tokens.

### 3.5 Known visual bugs to fix (from `design/visualBugs/`)
- **Stat-card value clip:** `.stat-value` uses `line-height:1` + container clipping → descenders cut.
  Fix in `app.css` + tokens: `line-height: 1.15`, remove the clip, `overflow-wrap:anywhere` already
  present; ensure the mobile `!important` font sizes still fit (`@media ≤575.98px`).
- **Unit-status cell overflow:** the status column (amount + "Late fee applies") overruns. Give the
  cell `min-width` + allow vertical stack; move "Late fee applies" to a `.stat-sub`-style second line.

### 3.6 Geometry, shadow, components
- **Radius:** flatten the warm 20 px cards → `--radius-lg: 14px`, `--radius: 8px`, pills stay `999px`.
- **Shadow:** near-flat. `--shadow-sm: 0 1px 2px rgba(0,0,0,.06)`; cards = **hairline border +
  whisper shadow** (the B&W look leans on borders, not elevation). Dark mode: borders do the work,
  shadows nearly invisible.
- **Buttons:** primary = solid `--ink` / `--paper` text; secondary = outline `--gray-300`; pill radius
  retained; `.btn-icon` circular outline.
- **Sidebar:** keep 252 px shell + sections. Active item = `--gray-100` fill + ink text + a 2px ink
  left-marker (replaces amber pill). Icons drop their colored circular tints → uniform `--gray-100`
  circle, ink glyph; active icon = solid ink circle, paper glyph.
- **Cash-hero / dark-card:** the navy `--laskie-card-dark` block becomes **solid `--ink`** (light
  mode) — an inverted panel is the strongest B&W "hero." In dark mode it inverts to a bordered light
  panel.
- **Tables:** zebra `--gray-50`, hover `--gray-100`, header text `--gray-500` uppercase, hairline rows.
- **Forms/modals:** inputs `--gray-300` border, focus ring `0 0 0 3px rgba(0,0,0,.12)` (ink, low
  alpha), label `--gray-700`. Modal = white card, hairline border.
- **Toolbar pattern:** standardize page filters (period selectors, search, "Add" button) into a
  responsive `.page-toolbar` that is a single row on desktop and wraps/stacks at 375 px — this is the
  "intuitive control placement" requirement, applied uniformly.

### 3.7 Mobile & print
- Re-verify every page at **375 px**: sidebar auto-closes, modals scroll internally, toolbars wrap,
  tables horizontally scroll inside a `.table-scroll` wrapper (no page-level overflow), touch targets
  ≥ 44 px. (Most rules exist in `app.css` `@media` blocks — extend, don't replace.)
- **Print/PDF** (`invoice_print`, `soa_pdf`, `audit_pdf`): black-on-white is ideal; ensure the
  `@media print` block + PDF `<style>` use ink/grays only and drop any residual navy/amber.

---

## 4. Tiered task breakdown (most complex → simplest)

> **Dependency:** **OPUS-1 and OPUS-2 ship first and block everything else.** After the foundation
> lands (whole app already monochrome + dark-mode working), Sonnet and Haiku page tasks can run in
> parallel — each only *refines* one page using the shared classes from §3.

### 🔴 Tier OPUS 4.8 — foundation + cross-cutting + heaviest pages
| ID | Task | Files | Why Opus |
|---|---|---|---|
| **O1** | **Token-layer rewrite** → grayscale ramp (light + `[data-theme="dark"]`), full legacy + `--bs-*` remap, new emphasis/badge/chart/glyph classes (§3.1, §3.3, §3.6), retire warm tokens. Fix stat-card clip + status-cell overflow (§3.5) in `app.css`. | `assets/css/laskie-tokens.css`, minimal `app.css` | One file flips the entire app; highest blast radius + cascade reasoning. |
| **O2** | **Dark-mode system**: FOUC inline head script (`header.php`, `index.php`), topbar toggle, `window.toggleTheme()` + `laskie:themechange` in `app.js`, re-theme the notification panel + footer hardcoded hexes (`#1D9E75/#D85A30/#EF9F27`) to tokens. | `includes/header.php`, `includes/footer.php`, `assets/js/app.js`, `index.php` head | Cross-cutting; touches shell + JS contract every page depends on. |
| **O3** | **`dashboard.php`** full migration: inverted hero panel, **Chart.js mono restyle** + `chartTheme()` helper + theme-change re-render, unit-status **glyph** grid, MTD net `▲/▼` deltas, kill all warm `var()`s + 54 inline styles → classes. | `dashboard.php`, `assets/js/app.js` (chartTheme) | Flagship page; charts + grid + densest logic-adjacent markup. |
| **O4** | **`admin/vault.php`** migration: vault **charts mono**, dense distribution/return tables, 87 inline styles → classes, 32 data-attr actions kept intact. | `admin/vault.php` | Largest file (1657 ln); second chart surface; many states. |
| **O5** | **`payments/collection.php`** migration: collection grid statuses → glyph/pill system, service-charge + **refund modal** restyle, tabular money, toolbar. | `payments/collection.php` | 1072 ln, money-status dense, refund cashier flow — high care. |

### 🟡 Tier SONNET 4.6 — standard CRUD pages (pattern application)
Each: swap inline styles → §3 classes, apply pill/delta/glyph emphasis, `.page-toolbar`, dense
tables, 375 px + dark-mode pass. **No logic/handlers changed.**
| ID | Page | Notes |
|---|---|---|
| S1 | `admin/units.php` (823) | rate-history table, unit-type/service modals; keep data-attr actions. |
| S2 | `payments/history.php` (675) | SoA ledger (debit/credit rows), refund modal, print block. |
| S3 | `expenses.php` (680) | expense table + category mgmt modals, 12 actions. |
| S4 | `cash.php` (551) | cash-hero → inverted panel, all-staff table, vault-request modal. |
| S5 | `admin/settings.php` (585) | settings groups + **Danger Zone** (currently warm) → ink emphasis. |
| S6 | `admin/accounts.php` (454) | user table, role/status badges → glyph families. |
| S7 | `admin/transactions.php` (449) | soft-deleted/voided list → strikethrough + restore. |
| S8 | `admin/tenants.php` (443) | tenant table + contract modals. |
| S9 | `my_summary.php` (444) | stat cards (fix clip) + collections/remittance tables. |
| S10 | `index.php` (260) | **login redesign**: drop navy gradient → minimal mono split/centered card; first-impression surface, dark-mode aware. |

### 🟢 Tier HAIKU 4.5 — small / template / print pages (mostly token-driven)
Explicit spec, minimal decisions; verify no warm `var()` remains, apply classes, 375 px check.
| ID | Page | Notes |
|---|---|---|
| H1 | `admin/requests.php` (152) | approve/reject cards → pill emphasis. |
| H2 | `my_account.php` (332) | profile/password/avatar form → form spec. |
| H3 | `admin/logs.php` (376) | log table, `.log-action`/`.ip-badge`/`.mono` to grayscale. |
| H4 | `payments/invoice_print.php` (383) | print template → ink-only; keep `.invoice-doc`. |
| H5 | `payments/soa_pdf.php` (471) | **`<style>` block only** → ink/gray; do not touch queries. |
| H6 | `payments/audit_pdf.php` (499) | same as H5; print-mono. |
| H7 | sweep `payments/*_download.php`, `logout.php` | no UI; confirm nothing to do. |

---

## 5. Acceptance criteria (per task + global)
A task is done when:
1. **No hue** remains — grep the file for color hexes/`rgb(`/named colors and warm `--laskie-*`; all
   resolve to the grayscale ramp via tokens. `grep -nE "#[0-9a-fA-F]{3,6}|rgb\(|amber|teal|coral|indigo|navy" <file>` returns only intentional ink/gray or none.
2. **No undefined tokens:** every `var(--…)` referenced is defined in `laskie-tokens.css`
   (re-run the used-vs-defined check; keep it green like the prior audit).
3. **Light + dark** both render correctly; toggle persists across reloads; no FOUC.
4. **375 px**: no horizontal page scroll, controls reachable, modals scroll internally, touch ≥44 px.
5. **Desktop ≥1280 px**: tables dense, multi-column layout uses the width.
6. **Status emphasis** uses §3.3 primitives (pills/glyphs/deltas), never color.
7. **Logic untouched:** no diff in PHP control flow, SQL, handlers, IDs, `name=`, `clean()`, CSRF.
8. Charts (O3/O4) re-render on theme change.

---

## 6. Verification (end-to-end)
- **Unit tests stay green:** `vendor/bin/phpunit` → expect **59/59** (UI changes must not move this).
  *Do not* run the Integration suite (needs live DB; CLAUDE.md §12 SELECT-only rule — user-gated).
- **Token sanity:** `grep -roh "var(--[a-z0-9-]*)" assets dashboard.php admin payments includes | sort -u`
  vs the `:root`/`[data-theme]` definitions — zero undefined.
- **Visual matrix:** load via `http://192.168.9.18:49200/` (working tree = live) every page in
  {light, dark} × {375 px, 1280 px}. Confirm the two `design/visualBugs/` defects are gone.
- **Print:** open `invoice_print.php` + generate one SoA/audit PDF; confirm clean black-on-white.
- **Perms after edits:** `ls -la includes/*.php assets/css/*.css assets/js/*.js`; if any group is
  `bulik:bulik`, run `sudo bash /home/bulik/laskie-recompress.sh`. Watch for a fresh HTTP 500 after
  a change (first check is group/perms).
- **Regression smoke:** record a payment, void+restore it, file an expense, submit a vault cash
  request, toggle theme mid-flow — all still work (these exercise the protected logic the redesign
  must not disturb).

---

## 7. Execution order
1. Begin **O1** (token-layer rewrite) → **O2** (dark-mode system).
2. Verify the foundation: whole app already monochrome, dark mode toggles + persists, unit tests
   59/59 green.
3. Then fan out **O3–O5**, and the Sonnet/Haiku page tasks in parallel — each refines one page using
   the shared classes from §3.
4. **Ignore both `design/inspiration/*.png` images entirely** — they reflect the abandoned warm
   palette; the target is purely the minimalist black/white/grayscale system specified above.
