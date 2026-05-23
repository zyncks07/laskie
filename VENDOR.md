# Vendor Assets

All third-party CSS/JS/font assets are **self-hosted** under `assets/vendor/`.
The app does not hot-link to any CDN — vendor files are pinned, served from
the same origin, and protected by SRI hashes (see `vendorAssetTag()` in
`config/functions.php`).

## Pinned versions

| Asset | Version | File(s) |
|---|---|---|
| Bootstrap | 5.3.2 | `bootstrap.bundle.min.js`, `bootstrap.min.css` |
| jQuery | 3.7.1 | `jquery.min.js` |
| DataTables | 1.13.7 | `jquery.dataTables.min.js` |
| DataTables Bootstrap 5 integration | matched to 1.13 | `dataTables.bootstrap5.min.js`, `dataTables.bootstrap5.min.css` |
| Chart.js (UMD build) | 4.4.1 | `chart.umd.min.js` |
| FontAwesome Free | 6.5.0 | `fontawesome.min.css` + `webfonts/` |
| Google Fonts — DM Sans + DM Mono | (self-hosted, latest at pin) | `google-fonts.css` + `fonts/` |

## Upgrading

When updating a vendor file:

1. Download the new version from its official source (Bootstrap, jsDelivr, CDNJS, FontAwesome, etc.). **Verify the upstream hash** if the source publishes one.
2. Drop the new file into `assets/vendor/` (or `assets/vendor/fonts/` / `webfonts/` for fonts).
3. Regenerate the SRI hash:
   ```bash
   ./scripts/generate-sri.sh
   ```
   (or manually: `openssl dgst -sha384 -binary <file> | openssl base64 -A`)
4. Update the corresponding entry in `config/functions.php::VENDOR_SRI` and the version row in this file.
5. Bump the relevant **major-version notes** below if upgrading across a major (e.g. Bootstrap 5 → 6).
6. `composer test` and load the affected pages in a browser to confirm nothing visually regressed.

## Major-version notes

- **Bootstrap 5.3.x**: the project's design tokens (in `assets/css/app.css`) override Bootstrap's CSS variables. Upgrading to 6.x will likely change variable names — audit `:root{ --primary…` overrides first.
- **DataTables 1.13.x**: the project uses the legacy DataTables 1.x API style (`$('#table').DataTable({...})`, `dtDefaults` in `app.js`). DataTables 2.x ships breaking config changes — would be a bigger migration.
- **Chart.js 4.x**: the dashboard's chart configs (in `dashboard.php` inline JS) use the v4 API. v5 (when it lands) will rename several options.
- **FontAwesome 6.x**: icon class prefixes are `fa-solid` / `fa-regular`. Earlier versions used `fas` / `far` — many templates still reference the `fa-solid` style, so 5.x is no longer a fallback.

## Why self-hosted?

- **No third-party network dependency.** The app runs in a small-LAN context (sometimes air-gapped); CDN failures would kill the UI.
- **Reproducible deploys.** Vendor files are committed to git and rsynced via `deploy.sh` — the same bytes everywhere.
- **CSP simplicity.** All scripts/styles are first-party so a strict CSP needs no `unsafe-eval` or third-party origins.

## Where to find the references

| File | Tags it emits |
|---|---|
| `includes/header.php` | All `<link>` tags (CSS) |
| `includes/footer.php` | All `<script>` tags (JS) |
| `index.php` | A bespoke set (login page, no header.php) |
| `payments/soa_pdf.php`, `payments/audit_pdf.php` | Vendor refs for chromium PDF rendering — these are NOT served to browsers, so SRI doesn't apply |
| `payments/invoice_print.php` | Same — print-only, no SRI needed |
