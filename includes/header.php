<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$user = currentUser();
$initials = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $user['full_name']), 0, 2)));
$appName = getSetting($pdo, 'app_name', 'Laskie Rental PMS');
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

// Sidebar root path (relative)
$depth = ($currentDir === 'laskie' || $currentDir === 'htdocs' || $currentDir === 'www') ? '' : '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= clean($pageTitle ?? 'Dashboard') ?> — Laskie RMS</title>
<link rel="stylesheet" href="<?= $depth ?>assets/vendor/google-fonts.css">
<link rel="stylesheet" href="<?= $depth ?>assets/vendor/fontawesome.min.css">
<link rel="stylesheet" href="<?= $depth ?>assets/vendor/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?= $depth ?>assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= $depth ?>assets/css/app.css">
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ═══ SIDEBAR ═══ -->
<nav id="sidebar">
  <a href="<?= $depth ?>dashboard.php" class="sidebar-brand">
    <div class="brand-icon"><i class="fa-solid fa-building-columns"></i></div>
    <div>
      <div class="brand-text">Laskie RMS</div>
      <div class="brand-sub">Rental Management</div>
    </div>
  </a>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Overview</div>
    <a href="<?= $depth ?>dashboard.php" class="sidebar-nav-item <?= ($currentPage==='dashboard')?'active':'' ?>">
      <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Payments</div>
    <a href="<?= $depth ?>payments/collection.php" class="sidebar-nav-item <?= ($currentPage==='collection')?'active':'' ?>">
      <i class="fa-solid fa-money-bill-wave"></i> Collection
    </a>
    <a href="<?= $depth ?>payments/history.php" class="sidebar-nav-item <?= ($currentPage==='history')?'active':'' ?>">
      <i class="fa-solid fa-file-invoice"></i> Statement of Account
    </a>
  </div>

  <div class="sidebar-section">
    <div class="sidebar-section-label">Financials</div>
    <a href="<?= $depth ?>expenses.php" class="sidebar-nav-item <?= ($currentPage==='expenses')?'active':'' ?>">
      <i class="fa-solid fa-receipt"></i> Expenses
    </a>
    <a href="<?= $depth ?>cash.php" class="sidebar-nav-item <?= ($currentPage==='cash')?'active':'' ?>">
      <i class="fa-solid fa-hand-holding-dollar"></i> Cash on Hand
    </a>
    <a href="<?= $depth ?>my_summary.php" class="sidebar-nav-item <?= ($currentPage==='my_summary')?'active':'' ?>">
      <i class="fa-solid fa-user-tie"></i> My Summary
    </a>
  </div>

<?php if (isAdmin()): ?>
  <div class="sidebar-section">
    <div class="sidebar-section-label">Administration</div>
    <a href="<?= $depth ?>admin/accounts.php" class="sidebar-nav-item <?= ($currentPage==='accounts')?'active':'' ?>">
      <i class="fa-solid fa-users-gear"></i> Accounts
    </a>
    <a href="<?= $depth ?>admin/tenants.php" class="sidebar-nav-item <?= ($currentPage==='tenants')?'active':'' ?>">
      <i class="fa-solid fa-people-roof"></i> Tenants
    </a>
    <a href="<?= $depth ?>admin/units.php" class="sidebar-nav-item <?= ($currentPage==='units')?'active':'' ?>">
      <i class="fa-solid fa-door-open"></i> Rental Units
    </a>
    <a href="<?= $depth ?>admin/logs.php" class="sidebar-nav-item <?= ($currentPage==='logs')?'active':'' ?>">
      <i class="fa-solid fa-scroll"></i> Audit Logs
    </a>
    <a href="<?= $depth ?>admin/settings.php" class="sidebar-nav-item <?= ($currentPage==='settings')?'active':'' ?>">
      <i class="fa-solid fa-gear"></i> Settings
    </a>
  </div>
<?php endif; ?>

  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar-sm"><?= clean($initials) ?></div>
      <div>
        <div class="user-name-sm"><?= clean($user['full_name']) ?></div>
        <div class="user-role-sm"><?= ucfirst(clean($user['role'])) ?></div>
      </div>
    </div>
    <a href="<?= $depth ?>logout.php" class="sidebar-nav-item text-danger mt-1">
      <i class="fa-solid fa-right-from-bracket"></i> Sign Out
    </a>
  </div>
</nav>

<!-- ═══ TOPBAR ═══ -->
<div id="topbar">
  <button class="btn-toggle-sidebar d-md-none" onclick="openSidebar()">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="topbar-title"><?= clean($pageTitle ?? 'Dashboard') ?></div>
  <div class="topbar-right">
    <div class="topbar-user">
      <div class="user-avatar"><?= clean($initials) ?></div>
      <div class="d-none d-sm-block">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= clean($user['full_name']) ?></div>
        <div style="font-size:11px;color:var(--text-muted)"><?= ucfirst(clean($user['role'])) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MAIN CONTENT ═══ -->
<div id="main">
<div class="page-content">
