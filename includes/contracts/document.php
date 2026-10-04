<?php
// includes/contracts/document.php — full HTML document around a contract body.
// Included by renderContractDocument(); expects in scope:
//   $fields   normalised contract fields
//   $meta     ['contract_no','status','company_name','template_version','id']
//   $mode     'screen' (paper preview + toolbar) | 'embed' (generator live
//             preview, no toolbar, blanks highlighted) | 'pdf' (chromium)
//   $assetBase  prefix for assets/vendor/ (relative for screen/embed,
//               file:// for pdf)
//
// The document is a print artefact (ink on paper), so it carries its own
// small set of custom properties rather than laskie-tokens.css, which is
// not loaded in the PDF context — same approach as payments/soa_pdf.php.

$status    = (string)($meta['status'] ?? 'draft');
$watermark = $status === 'cancelled' ? 'VOID' : ($status === 'approved' ? '' : 'DRAFT');
$docNo     = (string)($meta['contract_no'] ?? '');
$footNo    = $docNo !== '' ? 'Contract No. ' . $docNo : 'DRAFT — not yet approved';
$title     = 'Contract of Lease' . ($docNo !== '' ? ' — ' . $docNo : '') . (!empty($fields['lessee_name']) ? ' — ' . $fields['lessee_name'] : '');
// CSS string literal for @page content: escape backslashes and quotes.
$cssStr    = fn(string $s) => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= clean($title) ?></title>
<link href="<?= clean($assetBase) ?>google-fonts.css" rel="stylesheet">
<style>
:root{
  --doc-ink:#111111; --doc-ink-2:#3a3a3a; --doc-mute:#6b6b6b; --doc-rule:#b9b9b9;
  --doc-hair:#dddddd; --doc-paper:#ffffff; --doc-desk:#e9e9e9; --doc-fill:#f1f1f1;
  --doc-serif:"Liberation Serif","Times New Roman",Times,Georgia,serif;
  --doc-sans:"DM Sans","Liberation Sans",Arial,sans-serif;
  --doc-mono:"DM Mono","Liberation Mono",monospace;
}
@page{
  size:A4; margin:20mm 19mm 22mm 19mm;
  @bottom-left  { content: <?= $cssStr($footNo) ?>; font:7.5pt var(--doc-sans); color:#6b6b6b; }
  @bottom-center{ content: "Page " counter(page) " of " counter(pages); font:7.5pt var(--doc-sans); color:#6b6b6b; }
  @bottom-right { content: "Initials:  LESSOR ________   LESSEE ________"; font:7.5pt var(--doc-sans); color:#6b6b6b; white-space:pre; }
}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;}
body{
  font-family:var(--doc-serif); font-size:11pt; line-height:1.5; color:var(--doc-ink);
  background:var(--doc-paper); -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.paper{ text-align:justify; hyphens:auto; -webkit-hyphens:auto; }
p{ margin:0 0 7pt; orphans:3; widows:3; }
strong{ font-weight:700; }

/* ── Masthead ─────────────────────────────────────────── */
.doc-head{ border-bottom:3px double var(--doc-ink); padding-bottom:10pt; margin-bottom:14pt; text-align:center; }
.doc-head-top{ display:flex; justify-content:space-between; align-items:flex-start; gap:12pt; text-align:left; }
.doc-company{ font:600 8.5pt var(--doc-sans); letter-spacing:.2em; text-transform:uppercase; color:var(--doc-ink-2); padding-top:2pt; }
.doc-ref{ font:8.5pt var(--doc-sans); color:var(--doc-ink-2); text-align:right; line-height:1.55; }
.doc-ref .lbl{ color:var(--doc-mute); text-transform:uppercase; letter-spacing:.08em; font-size:7.5pt; margin-right:4pt; }
.doc-ref .mono{ font-family:var(--doc-mono); }
.doc-title{ font:700 23pt var(--doc-serif); letter-spacing:.24em; text-transform:uppercase; margin:16pt 0 2pt; }
.doc-sub{ font:italic 10pt var(--doc-serif); color:var(--doc-mute); }

/* ── Body ─────────────────────────────────────────────── */
.lead{ margin-top:10pt; }
.party{ padding-left:14pt; }
.party .name{ font-variant:small-caps; letter-spacing:.02em; font-size:11.5pt; }
.and{ text-align:center; color:var(--doc-mute); font-style:italic; margin:4pt 0 8pt; }
h2{
  font:700 9.5pt var(--doc-sans); letter-spacing:.14em; text-transform:uppercase;
  margin:18pt 0 9pt; padding-bottom:4pt; border-bottom:1px solid var(--doc-ink);
  break-after:avoid; page-break-after:avoid; text-align:left;
}
.sec{ margin-top:8pt; }
.sec-t{ font-weight:700; }
.sec, .sec + ol, .sec + table{ break-inside:auto; }
.sec:has(+ ol), .sec:has(+ table){ break-after:avoid; page-break-after:avoid; }
ol{ margin:0 0 8pt; padding-left:22pt; }
ol li{ margin-bottom:4pt; padding-left:3pt; }
ol.clauses{ padding-left:20pt; }
ol.sub{ list-style:decimal; }
ol.sub li::marker{ font-weight:700; }

/* ── Tables ───────────────────────────────────────────── */
.tbl{ width:100%; border-collapse:collapse; margin:4pt 0 10pt; font-size:10pt; break-inside:avoid; page-break-inside:avoid; text-align:left; }
.tbl-narrow{ width:62%; margin-left:auto; margin-right:auto; }
.tbl th{ font:600 7.5pt var(--doc-sans); letter-spacing:.1em; text-transform:uppercase; color:var(--doc-ink-2);
         border-bottom:1.2px solid var(--doc-ink); padding:4pt 6pt; text-align:left; }
.tbl td{ padding:4.5pt 6pt; border-bottom:.6px solid var(--doc-rule); vertical-align:top; }
.tbl tfoot td{ font-weight:700; border-top:1.2px solid var(--doc-ink); border-bottom:3px double var(--doc-ink); }
.tbl .r{ text-align:right; }
.amt{ font-variant-numeric:tabular-nums; white-space:nowrap; }

/* ── Field values & blanks ────────────────────────────── */
.fill{ display:inline-block; border-bottom:.7pt solid var(--doc-ink); height:1.05em; vertical-align:-2pt; }
.val{ }

/* ── Signatures ───────────────────────────────────────── */
.sign-block{ break-inside:avoid; page-break-inside:avoid; margin-top:8pt; }
.sign-block .note{ font:italic 9.5pt var(--doc-serif); color:var(--doc-mute); }
.sig-grid{ display:grid; grid-template-columns:1fr 1fr; gap:14mm; margin:10pt 0 14pt; }
.sig-role{ font:600 7.5pt var(--doc-sans); letter-spacing:.16em; text-transform:uppercase; color:var(--doc-mute); }
.sig-line{ height:15mm; border-bottom:.9pt solid var(--doc-ink); }
.sig-name{ font-weight:700; font-variant:small-caps; margin-top:4pt; text-align:left; }
.sig-meta{ font-size:9.5pt; color:var(--doc-ink-2); text-align:left; }
.witness-lead{ margin-top:14pt; }
.sig-ph{ font-weight:400; font-variant:normal; font-style:italic; font-size:9pt; color:var(--doc-mute); }
.keep{ break-inside:avoid; page-break-inside:avoid; }

/* ── Annexes ──────────────────────────────────────────── */
.annexes{ break-inside:avoid; page-break-inside:avoid; }
.annexes dl{ margin:0; }
.annexes dt{ font-weight:700; margin-top:6pt; }
.annexes dd{ margin:1pt 0 0 14pt; font-size:10pt; color:var(--doc-ink-2); }

/* ── Watermark (repeats on every printed page: fixed) ─── */
.watermark{
  position:fixed; top:50%; left:0; right:0; text-align:center; transform:translateY(-50%) rotate(-32deg);
  font:700 92pt var(--doc-sans); letter-spacing:.06em; color:rgba(0,0,0,.055);
  pointer-events:none; z-index:0; white-space:nowrap;
}
.paper{ position:relative; z-index:1; }

<?php if ($mode === 'screen' || $mode === 'embed'): ?>
/* ── On-screen paper preview ──────────────────────────── */
@media screen{
  body{ background:var(--doc-desk); }
  .paper{ background:var(--doc-paper); width:210mm; max-width:100%; margin:0 auto 28px; padding:20mm 19mm 22mm;
          box-shadow:0 1px 3px rgba(0,0,0,.12), 0 8px 28px rgba(0,0,0,.08); }
  .watermark{ position:fixed; }
}
@media screen and (max-width:820px){
  .paper{ padding:12mm 7mm 14mm; font-size:10.5pt; }
  .doc-title{ font-size:17pt; letter-spacing:.16em; }
  .tbl-narrow{ width:100%; }
  .sig-grid{ grid-template-columns:1fr; gap:6mm; }
  .doc-head-top{ flex-direction:column; }
  .doc-ref{ text-align:left; }
}
<?php endif; ?>
<?php if ($mode === 'embed'): ?>
@media screen{
  body{ padding:14px 10px; }
  .fill{ background:var(--doc-fill); border-bottom:1px dashed var(--doc-mute); }
  .val{ background:linear-gradient(transparent 62%, rgba(0,0,0,.07) 0); }
}
<?php endif; ?>
<?php if ($mode === 'screen'): ?>
.toolbar{ position:sticky; top:0; z-index:5; display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between;
          padding:10px 16px; margin-bottom:22px; background:var(--doc-paper); border-bottom:1px solid var(--doc-hair); font-family:var(--doc-sans); }
.toolbar .t-title{ font-weight:700; font-size:13px; }
.toolbar .t-sub{ font-size:11.5px; color:var(--doc-mute); }
.toolbar .btns{ display:flex; gap:8px; flex-wrap:wrap; }
.tbtn{ display:inline-flex; align-items:center; gap:6px; min-height:36px; padding:7px 14px; border-radius:8px; border:1px solid var(--doc-ink);
       background:var(--doc-paper); color:var(--doc-ink); font:600 12.5px var(--doc-sans); text-decoration:none; cursor:pointer; }
.tbtn.solid{ background:var(--doc-ink); color:var(--doc-paper); }
@media print{ .toolbar{ display:none !important; } }
<?php endif; ?>
@media print{
  body{ background:var(--doc-paper); }
  .paper{ width:auto; margin:0; padding:0; box-shadow:none; }
}
</style>
</head>
<body>
<?php if ($mode === 'screen'): ?>
<div class="toolbar">
  <div>
    <div class="t-title"><?= clean($docNo !== '' ? $docNo : 'Draft contract') ?> &middot; <?= clean($fields['lessee_name'] ?? '') ?></div>
    <div class="t-sub"><?= clean(($fields['unit_no'] ?? '') !== '' ? 'Unit ' . $fields['unit_no'] : '') ?><?= $watermark ? ' · ' . clean($watermark === 'VOID' ? 'Voided' : 'Draft — not yet approved') : '' ?></div>
  </div>
  <div class="btns">
    <a class="tbtn" href="contracts.php">&larr; Contracts</a>
    <button class="tbtn" type="button" onclick="window.print()">Print</button>
    <?php if (!empty($meta['id'])): ?>
    <a class="tbtn solid" href="contract_pdf_download.php?id=<?= (int)$meta['id'] ?>">Download PDF</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php if ($watermark !== ''): ?><div class="watermark" aria-hidden="true"><?= $watermark ?></div><?php endif; ?>
<main class="paper">
<?= renderContractBody($fields, $meta) ?>
</main>
</body>
</html>
