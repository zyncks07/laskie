<?php
// my_account.php — Self-service profile editor for any logged-in user.
// Lets the current user change their full name, contact info, password, and
// upload an avatar. Username, role, and status are display-only (admins manage
// those via admin/accounts.php).

session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
requireLogin();

$me   = currentUser();
$myId = (int)$me['id'];

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    csrfRequirePost();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Refresh $_SESSION['user'] from DB after any successful change so
    // the topbar/sidebar reflect updated full_name + avatar immediately.
    $refreshSession = function() use ($pdo, $myId) {
        $r = $pdo->prepare("SELECT id, username, full_name, role, email, avatar_path FROM users WHERE id=?");
        $r->execute([$myId]);
        $u = $r->fetch();
        if ($u) {
            $_SESSION['user'] = [
                'id'          => $u['id'],
                'username'    => $u['username'],
                'full_name'   => $u['full_name'],
                'role'        => $u['role'],
                'email'       => $u['email'],
                'avatar_path' => $u['avatar_path'],
            ];
        }
    };

    if ($action === 'save_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = nullOrStr($_POST['email']   ?? '');
        $phone    = nullOrStr($_POST['phone']   ?? '');
        $phone2   = nullOrStr($_POST['phone2']  ?? '');
        $address  = nullOrStr($_POST['address'] ?? '');
        if (!$fullName) jsonErr('Full name is required.');

        $prevRow = $pdo->prepare("SELECT full_name, email, phone, phone2, address FROM users WHERE id=?");
        $prevRow->execute([$myId]);
        $before = $prevRow->fetch();
        if (!$before) jsonErr('Account not found.');
        $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=?,phone2=?,address=? WHERE id=?")
            ->execute([$fullName, $email, $phone, $phone2, $address, $myId]);
        logChange($pdo, 'UPDATE_OWN_PROFILE', 'Accounts', $before,
            ['full_name'=>$fullName,'email'=>$email,'phone'=>$phone,'phone2'=>$phone2,'address'=>$address]);
        $refreshSession();
        jsonOk(['msg' => 'Profile updated.']);
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!$current || !$new || !$confirm)  jsonErr('All password fields are required.');
        if ($new !== $confirm)                jsonErr('New password and confirmation do not match.');
        if (strlen($new) < 8)                 jsonErr('New password must be at least 8 characters.');

        $r = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $r->execute([$myId]);
        $hash = $r->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) jsonErr('Current password is incorrect.');

        $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $myId]);
        logActivity($pdo, 'CHANGE_OWN_PASSWORD', 'Accounts', "Self-changed password (id #$myId)");
        jsonOk(['msg' => 'Password updated successfully.']);
    }

    if ($action === 'upload_avatar') {
        // JPEG only, 3 MB max, content-type verified via getimagesize.
        $up = handleUpload('avatar_file', 'avatars', ['jpg','jpeg'], 3 * 1024 * 1024, true);
        if ($up['error']) jsonErr($up['error']);
        if (!$up['path']) jsonErr('Please choose a file to upload.');

        // Order matters: update the DB FIRST so the new avatar is the source
        // of truth, THEN unlink the old file. The old approach unlinked
        // before the UPDATE — if the UPDATE failed for any reason, the user
        // would lose both the file and the reference to it.
        $prev = $pdo->prepare("SELECT avatar_path FROM users WHERE id=?");
        $prev->execute([$myId]);
        $prevPath = $prev->fetchColumn();

        $pdo->prepare("UPDATE users SET avatar_path=? WHERE id=?")->execute([$up['path'], $myId]);
        if ($prevPath && str_starts_with((string)$prevPath, '/uploads/avatars/')) {
            @unlink(__DIR__ . $prevPath);
        }
        logActivity($pdo, 'UPLOAD_AVATAR', 'Accounts', "Uploaded avatar (id #$myId)");
        $refreshSession();
        jsonOk(['msg' => 'Avatar updated.', 'avatar_path' => $up['path']]);
    }

    if ($action === 'remove_avatar') {
        // Same order as upload: DB first, file unlink second. If the UPDATE
        // fails, the file stays — header.php's is_file() check renders the
        // fallback initials anyway.
        $r = $pdo->prepare("SELECT avatar_path FROM users WHERE id=?");
        $r->execute([$myId]);
        $prevPath = $r->fetchColumn();
        $pdo->prepare("UPDATE users SET avatar_path=NULL WHERE id=?")->execute([$myId]);
        if ($prevPath && str_starts_with((string)$prevPath, '/uploads/avatars/')) {
            @unlink(__DIR__ . $prevPath);
        }
        logActivity($pdo, 'REMOVE_AVATAR', 'Accounts', "Removed avatar (id #$myId)");
        $refreshSession();
        jsonOk(['msg' => 'Avatar removed.']);
    }

    jsonErr('Unknown action.');
}

// ── GET (render) ─────────────────────────────────────────────
$pageTitle = 'My Account';
$depth = '';
$user = $pdo->prepare("SELECT id, username, full_name, role, email, phone, phone2, address, avatar_path, status FROM users WHERE id=?");
$user->execute([$myId]);
$u = $user->fetch();
if (!$u) { http_response_code(404); die('User not found.'); }

logActivity($pdo, 'VIEW_OWN_ACCOUNT', 'Accounts', "Viewed own account page");
include 'includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-user-circle me-2 text-primary-custom"></i>My Account</h1>
  <div class="text-muted" style="font-size:12.5px">
    <?= clean($u['username']) ?> &middot; <span class="badge badge-<?= clean($u['role']) ?>"><?= ucfirst(clean($u['role'])) ?></span>
  </div>
</div>

<div class="row g-3">
  <!-- ── Avatar card ────────────────────────────────────── -->
  <div class="col-12 col-md-4">
    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-image-portrait me-1"></i>Profile Picture</span>
      </div>
      <div class="card-body text-center">
        <div id="avatarPreviewWrap" style="margin:8px auto 16px">
          <?php if (!empty($u['avatar_path'])): ?>
            <img id="avatarPreview" src="<?= clean($u['avatar_path']) ?>" alt="Avatar"
                 style="width:140px;height:140px;border-radius:50%;object-fit:cover;border:3px solid var(--primary-light)">
          <?php else: ?>
            <div id="avatarPreview" style="width:140px;height:140px;margin:0 auto;border-radius:50%;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:44px;font-weight:700">
              <?php
                $initials = implode('', array_map(fn($p) => $p !== '' ? strtoupper($p[0]) : '', array_slice(explode(' ', $u['full_name']), 0, 2)));
                echo clean($initials);
              ?>
            </div>
          <?php endif; ?>
        </div>
        <div id="avatarMsg" class="alert" style="display:none"></div>
        <form id="avatarForm" onsubmit="return false">
          <input type="file" id="avatarFile" accept="image/jpeg" class="form-control form-control-sm mb-2">
          <div class="text-muted" style="font-size:11.5px;margin-bottom:10px">JPEG only &middot; max 3 MB</div>
          <div class="d-flex gap-2 justify-content-center">
            <button type="button" class="btn btn-primary btn-sm" onclick="uploadAvatar()"><i class="fa-solid fa-upload me-1"></i>Upload</button>
            <?php if (!empty($u['avatar_path'])): ?>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAvatar()"><i class="fa-solid fa-trash me-1"></i>Remove</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Profile + Password cards ────────────────────────── -->
  <div class="col-12 col-md-8">
    <div class="card mb-3">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-id-card me-1"></i>Profile Information</span>
      </div>
      <div class="card-body">
        <div id="profileMsg" class="alert" style="display:none"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?= clean($u['username']) ?>" disabled>
            <div class="form-text">Username can only be changed by an admin.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Full Name *</label>
            <input type="text" id="pfFullName" class="form-control" value="<?= clean($u['full_name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" id="pfEmail" class="form-control" value="<?= clean($u['email'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Phone</label>
            <input type="text" id="pfPhone" class="form-control" value="<?= clean($u['phone'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Alt Phone</label>
            <input type="text" id="pfPhone2" class="form-control" value="<?= clean($u['phone2'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <textarea id="pfAddress" class="form-control" rows="2"><?= clean($u['address'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
      <div class="card-footer text-end">
        <button class="btn btn-primary btn-sm" id="pfSaveBtn" onclick="saveProfile()"><i class="fa-solid fa-check me-1"></i>Save Profile</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-header-title"><i class="fa-solid fa-key me-1"></i>Change Password</span>
      </div>
      <div class="card-body">
        <div id="pwMsg" class="alert" style="display:none"></div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Current Password *</label>
            <input type="password" id="pwCurrent" class="form-control" autocomplete="current-password">
          </div>
          <div class="col-md-4">
            <label class="form-label">New Password *</label>
            <input type="password" id="pwNew" class="form-control" autocomplete="new-password">
            <div class="form-text">At least 8 characters.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Confirm New Password *</label>
            <input type="password" id="pwConfirm" class="form-control" autocomplete="new-password">
          </div>
        </div>
      </div>
      <div class="card-footer text-end">
        <button class="btn btn-primary btn-sm" id="pwSaveBtn" onclick="changePassword()"><i class="fa-solid fa-key me-1"></i>Update Password</button>
      </div>
    </div>
  </div>
</div>

<script>
function setMsg(el, text, isErr) {
  el.style.display = '';
  el.className     = isErr ? 'alert alert-danger' : 'alert alert-success';
  el.textContent   = text;
}

function saveProfile() {
  var btn = document.getElementById('pfSaveBtn');
  if (btn.disabled) return;
  btn.disabled = true;
  apiPost('my_account.php', {
    action:    'save_profile',
    full_name: document.getElementById('pfFullName').value,
    email:     document.getElementById('pfEmail').value,
    phone:     document.getElementById('pfPhone').value,
    phone2:    document.getElementById('pfPhone2').value,
    address:   document.getElementById('pfAddress').value
  }, function(err, res) {
    btn.disabled = false;
    var msg = document.getElementById('profileMsg');
    if (!res || !res.success) { setMsg(msg, (res && res.error) || 'Save failed.', true); return; }
    showToast(res.msg, 'success');
    // Reload so the header avatar/initials reflect the new name
    setTimeout(function() { location.reload(); }, 400);
  });
}

function changePassword() {
  var btn = document.getElementById('pwSaveBtn');
  if (btn.disabled) return;
  btn.disabled = true;
  apiPost('my_account.php', {
    action:           'change_password',
    current_password: document.getElementById('pwCurrent').value,
    new_password:     document.getElementById('pwNew').value,
    confirm_password: document.getElementById('pwConfirm').value
  }, function(err, res) {
    btn.disabled = false;
    var msg = document.getElementById('pwMsg');
    if (!res || !res.success) { setMsg(msg, (res && res.error) || 'Password change failed.', true); return; }
    setMsg(msg, res.msg, false);
    document.getElementById('pwCurrent').value = '';
    document.getElementById('pwNew').value     = '';
    document.getElementById('pwConfirm').value = '';
    showToast(res.msg, 'success');
  });
}

function uploadAvatar() {
  var fileInput = document.getElementById('avatarFile');
  if (!fileInput.files.length) {
    setMsg(document.getElementById('avatarMsg'), 'Please choose a JPEG file.', true);
    return;
  }
  var file = fileInput.files[0];
  if (file.size > 3 * 1024 * 1024) {
    setMsg(document.getElementById('avatarMsg'), 'File too large (max 3 MB).', true);
    return;
  }
  if (!/jpe?g$/i.test(file.name)) {
    setMsg(document.getElementById('avatarMsg'), 'JPEG only (filename must end with .jpg or .jpeg).', true);
    return;
  }
  var fd = new FormData();
  fd.append('action', 'upload_avatar');
  fd.append('avatar_file', file);
  apiPost('my_account.php', fd, function(err, res) {
    var msg = document.getElementById('avatarMsg');
    if (!res || !res.success) { setMsg(msg, (res && res.error) || 'Upload failed.', true); return; }
    showToast(res.msg, 'success');
    setTimeout(function() { location.reload(); }, 400);
  });
}

function removeAvatar() {
  confirmDelete('Remove your profile picture?', function() {
    apiPost('my_account.php', {action: 'remove_avatar'}, function(err, res) {
      if (!res || !res.success) { showToast((res && res.error) || 'Failed.', 'error'); return; }
      showToast(res.msg, 'success');
      setTimeout(function() { location.reload(); }, 400);
    });
  });
}
</script>

<?php include 'includes/footer.php'; ?>
