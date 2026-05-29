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
<link rel="stylesheet" href="assets/css/laskie-tokens.css">
<style>
  body { font-family: 'DM Sans', sans-serif; }
  .login-page {
    background: var(--laskie-page-bg);
    position: relative;
    z-index: 1;
  }
  .login-bg-pattern { display: none; }

  .login-wrap { max-width: 380px; }
  .login-card {
    background: var(--laskie-card-bg);
    border-radius: var(--laskie-radius-card);
    padding: 36px 32px;
    box-shadow: var(--laskie-shadow-card);
    border: 1px solid var(--laskie-divider);
  }
  .login-logo {
    width: 56px; height: 56px;
    background: var(--laskie-card-dark);
    border-radius: 16px;
    color: #fff; font-size: 22px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 18px;
    box-shadow: var(--laskie-shadow-hero);
  }
  .login-title { font-size: 18px; font-weight: 800; color: var(--laskie-ink); text-align: center; line-height: 1.35; }
  .login-sub   { font-size: 12.5px; color: var(--laskie-ink-mute); text-align: center; margin-top: 6px; margin-bottom: 26px; line-height: 1.4; }

  /* Treat .input-group as a single soft rounded field */
  .input-group {
    border: 1px solid var(--laskie-divider);
    border-radius: var(--laskie-radius-input);
    overflow: hidden;
    background: #fff;
    transition: border-color .14s, box-shadow .14s;
  }
  .input-group:focus-within {
    border-color: var(--laskie-amber);
    box-shadow: 0 0 0 3px rgba(239,159,39,.18);
  }
  .input-group-text {
    background: #fff;
    border: none;
    color: var(--laskie-ink-mute);
  }
  .input-group .form-control {
    border: none;
    background: transparent;
    padding-left: 8px;
  }
  .input-group .form-control:focus {
    border: none;
    box-shadow: none;
  }
  #pwdToggle { cursor: pointer; background: #fff; }

  .alert-danger {
    background: var(--laskie-coral-bg);
    color: var(--laskie-coral-ink);
    border: none;
    border-radius: var(--laskie-radius-input);
  }

  /* Dark navy pill submit (Magix "Create Invoice" style) */
  .btn.btn-primary {
    background: var(--laskie-card-dark);
    border-color: var(--laskie-card-dark);
    color: #fff;
    border-radius: var(--laskie-radius-pill);
    font-weight: 600;
  }
  .btn.btn-primary:hover {
    background: #18143d;
    border-color: #18143d;
    color: #fff;
  }

  .system-info { text-align: center; margin-top: 22px; }
  .system-info a {
    color: var(--laskie-ink-mute);
    font-size: 11.5px;
    text-decoration: none;
  }
  .system-info a:hover { color: var(--laskie-ink); }
</style>
</head>
<body>
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
