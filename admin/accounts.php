<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Account Management';
$depth = '../';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role     = $_POST['role'] ?? 'staff';
        $email    = nullOrStr($_POST['email'] ?? '');
        $phone    = nullOrStr($_POST['phone'] ?? '');
        $phone2   = nullOrStr($_POST['phone2'] ?? '');
        $address  = nullOrStr($_POST['address'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';

        if (!$username || !$fullName) jsonErr('Username and full name are required.');
        if (!in_array($role, ['admin','accountant','staff'])) jsonErr('Invalid role.');

        if ($id) {
            // Update
            $check = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $check->execute([$username, $id]);
            if ($check->fetch()) jsonErr('Username already taken.');
            if ($password) {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $pdo->prepare("UPDATE users SET username=?,full_name=?,role=?,email=?,phone=?,phone2=?,address=?,status=?,password_hash=? WHERE id=?")
                    ->execute([$username,$fullName,$role,$email,$phone,$phone2,$address,$status,$hash,$id]);
            } else {
                $pdo->prepare("UPDATE users SET username=?,full_name=?,role=?,email=?,phone=?,phone2=?,address=?,status=? WHERE id=?")
                    ->execute([$username,$fullName,$role,$email,$phone,$phone2,$address,$status,$id]);
            }
            logActivity($pdo, 'UPDATE_USER', 'Accounts', "Updated user #$id ($username)");
            jsonOk(['msg' => 'Account updated successfully.']);
        } else {
            // Create
            if (!$password) jsonErr('Password is required for new accounts.');
            $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
            $check->execute([$username]);
            if ($check->fetch()) jsonErr('Username already taken.');
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,email,phone,phone2,address,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$username,$hash,$fullName,$role,$email,$phone,$phone2,$address,$status,$_SESSION['user']['id']]);
            logActivity($pdo, 'CREATE_USER', 'Accounts', "Created user $username ($role)");
            jsonOk(['msg' => 'Account created successfully.']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user']['id']) jsonErr('You cannot delete your own account.');
        $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$id]);
        logActivity($pdo, 'DEACTIVATE_USER', 'Accounts', "Deactivated user #$id");
        jsonOk(['msg' => 'Account deactivated.']);
    }

    if ($action === 'get') {
        $row = $pdo->prepare("SELECT id,username,full_name,role,email,phone,phone2,address,status FROM users WHERE id=?");
        $row->execute([(int)$_POST['id']]);
        $data = $row->fetch();
        if (!$data) jsonErr('User not found.');
        jsonOk(['user' => $data]);
    }
    exit;
}

$users = $pdo->query("SELECT u.*, (SELECT username FROM users u2 WHERE u2.id=u.created_by) as created_by_name FROM users u ORDER BY u.role, u.full_name")->fetchAll();
logActivity($pdo, 'VIEW_ACCOUNTS', 'Accounts', 'Viewed account management page');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-users-gear me-2 text-primary-custom"></i>Account Management</h1>
  <button class="btn btn-primary btn-sm" onclick="openModal()"><i class="fa-solid fa-plus me-1"></i>Add Account</button>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-header-title">System Users</span>
    <span class="badge bg-secondary"><?= count($users) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table" id="usersTable">
      <thead><tr>
        <th>Name</th><th>Username</th><th>Role</th>
        <th>Email</th><th>Phone</th><th>Status</th><th class="text-center">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($users as $u): ?>
      <tr>
        <td>
          <div class="fw-600 cell-trunc"><?= clean($u['full_name']) ?></div>
          <?php if($u['address']): ?><div class="text-muted cell-trunc" style="font-size:11px"><?= clean(substr($u['address'],0,50)) ?></div><?php endif; ?>
        </td>
        <td class="mono"><?= clean($u['username']) ?></td>
        <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
        <td class="cell-trunc"><?= clean($u['email'] ?? '—') ?></td>
        <td><?= clean($u['phone'] ?? '—') ?></td>
        <td><span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span></td>
        <td class="text-center">
          <button class="btn-icon" title="Edit" onclick="editUser(<?= $u['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
          <?php if($u['id'] != $_SESSION['user']['id']): ?>
          <button class="btn-icon danger" title="Deactivate" onclick="deactivateUser(<?= $u['id'] ?>, '<?= clean($u['full_name']) ?>')"><i class="fa-solid fa-ban fa-xs"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Add Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="userId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name *</label>
            <input type="text" class="form-control" id="fullName" placeholder="Juan Dela Cruz">
          </div>
          <div class="col-md-6">
            <label class="form-label">Username *</label>
            <input type="text" class="form-control" id="uname" placeholder="jdelacruz" autocomplete="off">
          </div>
          <div class="col-md-4">
            <label class="form-label">Role *</label>
            <select class="form-select" id="urole">
              <option value="staff">Staff / Cashier</option>
              <option value="accountant">Accountant</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" id="ustatus">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" id="uemail" placeholder="email@example.com">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" id="uphone" placeholder="09xx-xxx-xxxx">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone 2</label>
            <input type="text" class="form-control" id="uphone2" placeholder="Alternate number">
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <textarea class="form-control" id="uaddress" rows="2" placeholder="Full address"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password <small class="text-muted">(leave blank to keep current)</small></label>
            <input type="password" class="form-control" id="upassword" autocomplete="new-password" placeholder="••••••••">
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="upassword2" placeholder="••••••••">
          </div>
        </div>
        <div id="modalMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveUser()"><i class="fa-solid fa-save me-1"></i>Save Account</button>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
var modal;
document.addEventListener('DOMContentLoaded', function() {
  modal = new bootstrap.Modal(document.getElementById('userModal'));
});

$(document).ready(function(){
  $('#usersTable').DataTable({ pageLength:25, order:[[2,'asc']], columnDefs:[{orderable:false, targets:6}] });
});

function openModal() {
  document.getElementById('modalTitle').textContent = 'Add Account';
  document.getElementById('userId').value = '';
  ['fullName','uname','uemail','uphone','uphone2','uaddress','upassword','upassword2'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('urole').value = 'staff';
  document.getElementById('ustatus').value = 'active';
  document.getElementById('modalMsg').style.display = 'none';
  modal.show();
}

function editUser(id) {
  apiPost('accounts.php', {action:'get', id}, (err, data) => {
    if (err || !data.success) return showToast('Failed to load user.', 'error');
    const u = data.user;
    document.getElementById('modalTitle').textContent = 'Edit Account';
    document.getElementById('userId').value    = u.id;
    document.getElementById('fullName').value  = u.full_name;
    document.getElementById('uname').value     = u.username;
    document.getElementById('urole').value     = u.role;
    document.getElementById('ustatus').value   = u.status;
    document.getElementById('uemail').value    = u.email || '';
    document.getElementById('uphone').value    = u.phone || '';
    document.getElementById('uphone2').value   = u.phone2 || '';
    document.getElementById('uaddress').value  = u.address || '';
    document.getElementById('upassword').value = '';
    document.getElementById('upassword2').value = '';
    document.getElementById('modalMsg').style.display = 'none';
    modal.show();
  });
}

function saveUser() {
  const pw  = document.getElementById('upassword').value;
  const pw2 = document.getElementById('upassword2').value;
  if (pw && pw !== pw2) { showMsg('Passwords do not match.', 'danger'); return; }

  const data = {
    action:'save',
    id: document.getElementById('userId').value,
    username: document.getElementById('uname').value,
    full_name: document.getElementById('fullName').value,
    role: document.getElementById('urole').value,
    status: document.getElementById('ustatus').value,
    email: document.getElementById('uemail').value,
    phone: document.getElementById('uphone').value,
    phone2: document.getElementById('uphone2').value,
    address: document.getElementById('uaddress').value,
    password: pw
  };

  apiPost('accounts.php', data, (err, res) => {
    if (err || !res.success) { showMsg(res?.error || 'Failed to save.', 'danger'); return; }
    showToast(res.msg, 'success');
    modal.hide();
    setTimeout(() => location.reload(), 800);
  });
}

function deactivateUser(id, name) {
  confirmDelete(`Deactivate account for "${name}"? They will no longer be able to log in.`, () => {
    apiPost('accounts.php', {action:'delete', id}, (err, res) => {
      if (err || !res.success) return showToast(res?.error||'Failed.','error');
      showToast(res.msg,'success');
      setTimeout(() => location.reload(), 800);
    });
  });
}

function showMsg(msg, type) {
  const el = document.getElementById('modalMsg');
  el.style.display = '';
  el.className = 'alert alert-' + type;
  el.innerHTML = msg;
}
</script>
JS;
include '../includes/footer.php'; ?>
