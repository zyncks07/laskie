<?php
// file_gate.php — authenticated reverse-proxy for uploaded files.
//
// Apache internally rewrites every /uploads/<path> request to this script (see
// the root .htaccess). Static serving let anyone holding a URL fetch receipts,
// signed contracts and remittance proofs with no session (audit finding W5-1);
// this gate requires a logged-in user first. Images/PDFs are served inline so
// in-app previews keep working; office docs are forced to download.
//
// The on-disk no-exec hardening in uploads/.htaccess stays as defence in depth.

session_start();
require_once __DIR__ . '/config/functions.php';
requireLogin(); // 302 → index.php?err=session for anonymous requests

$rel  = (string)($_GET['p'] ?? '');
$base = realpath(__DIR__ . '/uploads');

// realpath() collapses ../ and symlinks; anything resolving outside uploads/,
// missing, a directory, or a dotfile (e.g. the protective .htaccess) is 404.
$full = ($base !== false && $rel !== '') ? realpath($base . '/' . $rel) : false;
if ($full === false
    || !str_starts_with($full, $base . '/')
    || !is_file($full)
    || basename($full)[0] === '.') {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif',  'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx'=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip',
];
$ct = $types[$ext] ?? 'application/octet-stream';
// Inline for things browsers render safely; everything else downloads. SVG/HTML
// are not in the map, so they fall to octet-stream + attachment — no rendering
// of attacker markup as this origin.
$inline = in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true);

header('Content-Type: ' . $ct);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
       . '; filename="' . basename($full) . '"');
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Referrer-Policy: no-referrer');

readfile($full);
