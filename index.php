<?php
session_start();
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'config/db.php';
require_once 'config/functions.php';

$error = '';

// ─── Brute-force defence parameters ──────────────────────────
// Lockout fires when ≥THRESHOLD failures from the same IP OR same username
// happened in the last WINDOW_MIN minutes. Reset on any LOGIN_SUCCESS row.
const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_WINDOW_MIN = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = getClientIp();

    // ── Lockout check: count recent failures since the last success ─
    // For an IP/username pair, only failures AFTER the most recent
    // LOGIN_SUCCESS count toward the lockout window.
    $countRecentFailures = function(PDO $pdo, string $col, string $needle): int {
        if ($needle === '') return 0;
        $sql = "SELECT COUNT(*) FROM system_logs
                WHERE action='LOGIN_FAILED' AND $col = ?
                  AND created_at >= NOW() - INTERVAL " . LOGIN_LOCKOUT_WINDOW_MIN . " MINUTE
                  AND created_at > COALESCE(
                      (SELECT MAX(created_at) FROM system_logs
                       WHERE action='LOGIN_SUCCESS' AND $col = ?), '1970-01-01')";
        $s = $pdo->prepare($sql);
        $s->execute([$needle, $needle]);
        return (int)$s->fetchColumn();
    };
    $ipFails   = $countRecentFailures($pdo, 'ip_address', $ip);
    $userFails = $countRecentFailures($pdo, 'username',   $username);
    $maxFails  = max($ipFails, $userFails);

    if ($maxFails >= LOGIN_LOCKOUT_THRESHOLD) {
        // Log the locked-out attempt itself so fail2ban can see it too.
        $pdo->prepare("INSERT INTO system_logs (user_id,username,action,module,details,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([null, $username, 'LOGIN_LOCKED', 'Auth', "Lockout active (ip=$ipFails user=$userFails recent failures)", $ip]);
        $error = 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_WINDOW_MIN . ' minutes.';
    } elseif ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Defence-in-depth: fresh session id + fresh CSRF token on every successful login.
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['user'] = [
                'id'          => $user['id'],
                'username'    => $user['username'],
                'full_name'   => $user['full_name'],
                'role'        => $user['role'],
                'email'       => $user['email'],
                'avatar_path' => $user['avatar_path'] ?? null,
            ];
            // Log success — also resets the lockout window for this IP + username.
            $pdo->prepare("INSERT INTO system_logs (user_id,username,action,module,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$user['id'], $user['username'], 'LOGIN_SUCCESS', 'Auth', 'Successful login', $ip]);
            header('Location: dashboard.php');
            exit;
        } else {
            // Progressive delay: each consecutive failure adds 250 ms,
            // capped at 1.25 s. Slows scripted bursts; near-invisible to
            // a human on the first or second attempt.
            $delaySteps = min($maxFails + 1, 5);
            usleep($delaySteps * 250_000);

            $error = 'Invalid username or password.';
            $uid = $user['id'] ?? null;
            $pdo->prepare("INSERT INTO system_logs (user_id,username,action,module,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$uid, $username, 'LOGIN_FAILED', 'Auth', 'Failed login attempt', $ip]);
        }
    } else {
        $error = 'Please enter username and password.';
    }
}

$err = $_GET['err'] ?? '';
if ($err === 'session') $error = 'Your session has expired. Please log in again.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Laskie Rental Property Management System</title>
<?= vendorCssTag('', 'google-fonts.css') ?>
<?= vendorCssTag('', 'fontawesome.min.css') ?>
<?= vendorCssTag('', 'bootstrap.min.css') ?>
<link rel="stylesheet" href="assets/css/app.css">
<style>
  body { font-family: 'DM Sans', sans-serif; }
  .login-bg-pattern {
    position: fixed; inset: 0; pointer-events: none; overflow: hidden;
    background: linear-gradient(135deg, #0f172a 0%, #1a3a8f 55%, #1e3a8a 100%);
  }
  .login-bg-pattern::before {
    content: ''; position: absolute;
    width: 600px; height: 600px; border-radius: 50%;
    background: rgba(59,91,219,.25);
    top: -200px; right: -200px;
  }
  .login-bg-pattern::after {
    content: ''; position: absolute;
    width: 400px; height: 400px; border-radius: 50%;
    background: rgba(14,165,233,.12);
    bottom: -100px; left: -100px;
  }
  .login-page { position: relative; z-index: 1; }
  .input-group-text { border-color: #e5e7eb; background: #f9fafb; color: #9ca3af; }
  .form-control { border-left: none; }
  .form-control:focus { border-color: #1a3a8f; box-shadow: none; }
  .form-control:focus + .input-group-text,
  .input-group:focus-within .input-group-text {
    border-color: #1a3a8f;
  }
  .system-info { text-align: center; margin-top: 20px; }
  .system-info a { color: rgba(255,255,255,.5); font-size: 12px; text-decoration: none; }
  .system-info a:hover { color: rgba(255,255,255,.8); }
</style>
</head>
<body>
<div class="login-bg-pattern"></div>
<div class="login-page d-flex align-items-center justify-content-center min-vh-100 p-3">
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">
        <i class="fa-solid fa-building-columns"></i>
      </div>
      <h1 class="login-title">Laskie Rental Property<br>Management System</h1>
      <p class="login-sub">Property & Financial Management Portal<br>Authorized personnel only</p>

      <?php if ($error): ?>
      <div class="alert alert-danger alert-sm d-flex align-items-center gap-2 mb-4" style="font-size:13px;">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= clean($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-user fa-sm"></i></span>
            <input type="text" name="username" class="form-control"
              placeholder="Enter username"
              value="<?= clean($_POST['username'] ?? '') ?>"
              autofocus required>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-lock fa-sm"></i></span>
            <input type="password" name="password" id="pwdField" class="form-control"
              placeholder="Enter password" required>
            <button type="button" class="input-group-text" onclick="togglePwd()" style="cursor:pointer;" id="pwdToggle">
              <i class="fa-solid fa-eye fa-sm" id="eyeIcon"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100" style="padding:10px;font-size:14px;font-weight:600;">
          <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
        </button>
      </form>
    </div>

    <div class="system-info">
      <a href="#">Laskie RMS v<?= APP_VERSION ?> &nbsp;·&nbsp; <?= date('Y') ?></a>
    </div>
  </div>
</div>

<script>
function togglePwd() {
  const f = document.getElementById('pwdField');
  const i = document.getElementById('eyeIcon');
  if (f.type === 'password') {
    f.type = 'text';
    i.className = 'fa-solid fa-eye-slash fa-sm';
  } else {
    f.type = 'password';
    i.className = 'fa-solid fa-eye fa-sm';
  }
}
</script>
</body>
</html>
