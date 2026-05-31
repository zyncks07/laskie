<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$user = currentUser();
$initials = implode('', array_map(fn($p) => $p !== '' ? strtoupper($p[0]) : '', array_slice(explode(' ', $user['full_name'] ?? ''), 0, 2)));
// Lazy-load avatar_path into the session for older logins that pre-date the avatar column.
if (!array_key_exists('avatar_path', $user)) {
    try {
        $av = $pdo->prepare("SELECT avatar_path FROM users WHERE id=?");
        $av->execute([$user['id']]);
        $_SESSION['user']['avatar_path'] = $av->fetchColumn() ?: null;
        $user['avatar_path'] = $_SESSION['user']['avatar_path'];
    } catch (Throwable $_) { $user['avatar_path'] = null; }
}
$userAvatar = $user['avatar_path'] ?? null;
// If the avatar file was deleted from disk, fall back to initials gracefully.
if ($userAvatar && !is_file(__DIR__ . '/..' . $userAvatar)) {
    $userAvatar = null;
}
$appName = getSetting($pdo, 'app_name', 'Laskie Rental PMS');
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Pending vault-cash requests, for the admin sidebar badge. Guarded so the app
// keeps working before migration 009 creates the table.
$pendingReqCount = 0;
if (isAdmin()) {
    try { $pendingReqCount = (int)$pdo->query("SELECT COUNT(*) FROM vault_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $_) { $pendingReqCount = 0; }
}

// $depth is provided by the including page (root pages: ''; subdir pages: '../').
// Fall back to a URL-path heuristic only if the caller forgot to set it.
// Note: assetUrl() / pageUrl() (driven by BASE_URL) are the preferred way to
// build links — use them in new code.
if (!isset($depth)) {
    $depth = preg_match('#/(admin|payments|api)/[^/]+\.php$#', $_SERVER['PHP_SELF'] ?? '') ? '../' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>/* FOUC guard: set the theme before first paint */(function(){try{var t=localStorage.getItem('laskie-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<title><?= clean($pageTitle ?? 'Dashboard') ?> — Laskie RMS</title>
<?= vendorCssTag($depth, 'google-fonts.css') ?>
<?= vendorCssTag($depth, 'fontawesome.min.css') ?>
<?= vendorCssTag($depth, 'dataTables.bootstrap5.min.css') ?>
<?= vendorCssTag($depth, 'bootstrap.min.css') ?>
<link rel="stylesheet" href="<?= $depth ?>assets/css/app.css">
<link rel="stylesheet" href="<?= $depth ?>assets/css/laskie-tokens.css">
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

  <div class="sidebar-section" data-section="overview">
    <div class="sidebar-section-label">Overview</div>
    <a href="<?= $depth ?>dashboard.php" class="sidebar-nav-item <?= ($currentPage==='dashboard')?'active':'' ?>">
      <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>
  </div>

  <div class="sidebar-section" data-section="payments">
    <div class="sidebar-section-label">Payments</div>
    <a href="<?= $depth ?>payments/collection.php" class="sidebar-nav-item <?= ($currentPage==='collection')?'active':'' ?>">
      <i class="fa-solid fa-money-bill-wave"></i> Collection
    </a>
    <a href="<?= $depth ?>payments/history.php" class="sidebar-nav-item <?= ($currentPage==='history')?'active':'' ?>">
      <i class="fa-solid fa-file-invoice"></i> Statement of Account
    </a>
  </div>

  <div class="sidebar-section" data-section="financials">
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
    <a href="<?= $depth ?>my_account.php" class="sidebar-nav-item <?= ($currentPage==='my_account')?'active':'' ?>">
      <i class="fa-solid fa-user-circle"></i> My Account
    </a>
    <a href="<?= $depth ?>logout.php" class="sidebar-nav-item text-danger">
      <i class="fa-solid fa-right-from-bracket"></i> Sign Out
    </a>
  </div>

<?php if (isAccountant() && !isAdmin()): ?>
  <div class="sidebar-section" data-section="accounting">
    <div class="sidebar-section-label">Accounting</div>
    <a href="<?= $depth ?>admin/vault.php" class="sidebar-nav-item <?= ($currentPage==='vault')?'active':'' ?>">
      <i class="fa-solid fa-vault"></i> The Vault
    </a>
  </div>
<?php endif; ?>

<?php if (isAdmin()): ?>
  <div class="sidebar-section" data-section="admin">
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
    <a href="<?= $depth ?>admin/vault.php" class="sidebar-nav-item <?= ($currentPage==='vault')?'active':'' ?>">
      <i class="fa-solid fa-vault"></i> The Vault
    </a>
    <a href="<?= $depth ?>admin/logs.php" class="sidebar-nav-item <?= ($currentPage==='logs')?'active':'' ?>">
      <i class="fa-solid fa-scroll"></i> Audit Logs
    </a>
    <a href="<?= $depth ?>admin/transactions.php" class="sidebar-nav-item <?= ($currentPage==='transactions')?'active':'' ?>">
      <i class="fa-solid fa-rectangle-list"></i> Transactions
    </a>
    <a href="<?= $depth ?>admin/requests.php" class="sidebar-nav-item <?= ($currentPage==='requests')?'active':'' ?>">
      <i class="fa-solid fa-hand-holding-dollar"></i> Cash Requests
      <?php if ($pendingReqCount > 0): ?><span class="badge ms-auto" style="background:var(--ink);color:var(--paper);font-size:10px"><?= $pendingReqCount ?></span><?php endif; ?>
    </a>
    <a href="<?= $depth ?>admin/settings.php" class="sidebar-nav-item <?= ($currentPage==='settings')?'active':'' ?>">
      <i class="fa-solid fa-gear"></i> Settings
    </a>
  </div>
<?php endif; ?>

  <div class="sidebar-footer">
    <a href="<?= $depth ?>my_account.php" class="user-info text-decoration-none" style="color:inherit">
      <?php if ($userAvatar): ?>
        <img src="<?= clean($userAvatar) ?>" alt="" style="width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0">
      <?php else: ?>
        <div class="user-avatar-sm"><?= clean($initials) ?></div>
      <?php endif; ?>
      <div>
        <div class="user-name-sm"><?= clean($user['full_name']) ?></div>
        <div class="user-role-sm"><?= ucfirst(clean($user['role'])) ?></div>
      </div>
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
    <button class="btn-icon" data-theme-toggle title="Switch to dark mode" aria-label="Toggle dark mode" onclick="toggleTheme()">
      <i class="fa-solid fa-moon"></i>
    </button>
    <div class="topbar-notif" style="position:relative">
      <button id="notifBell" class="btn-icon" title="Notifications" aria-label="Notifications" onclick="toggleNotifPanel(event)" style="position:relative">
        <i class="fa-solid fa-bell"></i>
        <span id="notifBadge" style="display:none;position:absolute;top:-3px;right:-3px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:var(--ink);color:var(--paper);font-size:10px;line-height:16px;font-weight:700;text-align:center"></span>
      </button>
      <div id="notifPanel" style="display:none;position:absolute;right:0;top:calc(100% + 8px);width:320px;max-height:420px;overflow-y:auto;background:var(--paper);border:1px solid var(--gray-200);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:1080">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--gray-200)">
          <strong style="font-size:13px;color:var(--ink)">Notifications</strong>
          <a href="#" onclick="markAllNotifs(event)" style="font-size:11.5px;text-decoration:none;color:var(--gray-600)">Mark all read</a>
        </div>
        <div id="notifList" style="padding:2px 0"><div style="padding:14px;color:var(--gray-500);font-size:12.5px">No notifications.</div></div>
      </div>
    </div>
    <a href="<?= $depth ?>my_account.php" class="topbar-user text-decoration-none" style="color:inherit" title="My Account">
      <?php if ($userAvatar): ?>
        <img src="<?= clean($userAvatar) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
      <?php else: ?>
        <div class="user-avatar"><?= clean($initials) ?></div>
      <?php endif; ?>
      <div class="d-none d-sm-block">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= clean($user['full_name']) ?></div>
        <div style="font-size:11px;color:var(--text-muted)"><?= ucfirst(clean($user['role'])) ?></div>
      </div>
    </a>
    <a href="<?= $depth ?>logout.php" class="btn-icon danger ms-1" title="Sign Out" aria-label="Sign Out">
      <i class="fa-solid fa-right-from-bracket"></i>
    </a>
  </div>
</div>

<!-- ═══ MAIN CONTENT ═══ -->
<div id="main">
<div class="page-content">
