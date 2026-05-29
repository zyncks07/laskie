<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'System Settings';
$depth = '../';

$unlocked = !empty($_SESSION['settings_unlocked']);

$s = [];
if ($unlocked) {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];
}

$currentTz = $s['db_timezone'] ?? 'Asia/Manila';

// Build timezone list: put common Asia timezones first
$tzPriority = [
    'Asia/Manila','Asia/Singapore','Asia/Hong_Kong','Asia/Tokyo','Asia/Kuala_Lumpur',
    'Asia/Jakarta','Asia/Taipei','Asia/Seoul','Asia/Shanghai','Asia/Bangkok',
    'UTC','America/New_York','America/Chicago','America/Los_Angeles','Europe/London','Europe/Paris',
];
$allTz = DateTimeZone::listIdentifiers();

include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-gear me-2 text-primary-custom"></i>System Settings</h1>
  <?php if ($unlocked): ?>
  <button class="btn btn-sm btn-outline-secondary" onclick="lockSettings()">
    <i class="fa-solid fa-lock me-1"></i>Lock Settings
  </button>
  <?php endif; ?>
</div>

<?php if (!$unlocked): ?>
<!-- ── Master Password Gate ──────────────────────────────────── -->
<div class="d-flex justify-content-center mt-5">
  <div class="card" style="width:100%;max-width:400px">
    <div class="card-body p-4 text-center">
      <div style="font-size:48px;margin-bottom:1rem"><i class="fa-solid fa-shield-halved text-primary"></i></div>
      <h5 class="fw-700 mb-1">Settings are locked</h5>
      <p class="text-muted mb-4" style="font-size:13px">Enter the master password to access system settings.</p>
      <div id="lockMsg" class="alert alert-danger mb-3" style="display:none;font-size:13px"></div>
      <div class="input-group mb-3">
        <input type="password" id="masterPass" class="form-control" placeholder="Master password" autofocus>
        <button class="btn btn-primary" onclick="verifyMaster()"><i class="fa-solid fa-unlock me-1"></i>Unlock</button>
      </div>
      <div id="lockSpinner" class="text-muted" style="display:none;font-size:13px"><span class="spinner-border spinner-border-sm me-1"></span>Verifying...</div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('masterPass').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') verifyMaster();
  });
});

function verifyMaster() {
  var pass = document.getElementById('masterPass').value;
  if (!pass) return;
  document.getElementById('lockMsg').style.display = 'none';
  document.getElementById('lockSpinner').style.display = '';

  var fd = new FormData();
  fd.append('action', 'verify_master');
  fd.append('password', pass);
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      document.getElementById('lockSpinner').style.display = 'none';
      if (res.success) {
        location.reload();
      } else {
        var el = document.getElementById('lockMsg');
        el.textContent = res.error || 'Incorrect password.';
        el.style.display = '';
        document.getElementById('masterPass').value = '';
        document.getElementById('masterPass').focus();
      }
    })
    .catch(function() {
      document.getElementById('lockSpinner').style.display = 'none';
      document.getElementById('lockMsg').textContent = 'Network error. Try again.';
      document.getElementById('lockMsg').style.display = '';
    });
}
</script>

<?php else: ?>
<!-- ── Settings Tabs ──────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="settingsTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabGeneral"><i class="fa-solid fa-sliders me-1"></i>General</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabTimezone"><i class="fa-solid fa-clock me-1"></i>Timezone</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSecurity"><i class="fa-solid fa-key me-1"></i>Security</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabExport"><i class="fa-solid fa-download me-1"></i>Export</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabImport"><i class="fa-solid fa-upload me-1"></i>Import</a></li>
  <li class="nav-item ms-auto"><a class="nav-link text-danger" data-bs-toggle="tab" href="#tabDanger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Danger Zone</a></li>
</ul>

<div class="tab-content">

  <!-- ── General ─────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="tabGeneral">
    <div class="card">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-sliders me-2"></i>General Settings</span></div>
      <div class="card-body">
        <div id="genMsg" class="mb-3" style="display:none"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">App Name</label>
            <input type="text" class="form-control" id="app_name" value="<?= clean($s['app_name'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Company / Property Name</label>
            <input type="text" class="form-control" id="company_name" value="<?= clean($s['company_name'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Company Address</label>
            <input type="text" class="form-control" id="company_address" value="<?= clean($s['company_address'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" id="company_phone" value="<?= clean($s['company_phone'] ?? '') ?>">
          </div>
          <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" id="company_email" value="<?= clean($s['company_email'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Invoice Prefix</label>
            <input type="text" class="form-control" id="invoice_prefix" value="<?= clean($s['invoice_prefix'] ?? 'INV') ?>" maxlength="10">
          </div>
          <div class="col-md-3">
            <label class="form-label">Currency Symbol</label>
            <input type="text" class="form-control" id="currency_symbol" value="<?= clean($s['currency_symbol'] ?? '₱') ?>" maxlength="5">
          </div>
          <div class="col-md-3">
            <label class="form-label">Currency Code</label>
            <input type="text" class="form-control" id="currency_code" value="<?= clean($s['currency_code'] ?? 'PHP') ?>" maxlength="5">
          </div>
          <div class="col-md-3">
            <label class="form-label">Default Due Day</label>
            <select class="form-select" id="default_due_day">
              <?php for ($d = 1; $d <= 28; $d++): ?>
              <option value="<?= $d ?>" <?= ($s['default_due_day'] ?? '5') == $d ? 'selected' : '' ?>><?= $d ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="mt-3 text-end">
          <button class="btn btn-primary btn-sm" onclick="saveGeneral()"><i class="fa-solid fa-save me-1"></i>Save Settings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Timezone ─────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabTimezone">
    <div class="card">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-clock me-2"></i>Timezone Configuration</span></div>
      <div class="card-body">
        <div id="tzMsg" class="mb-3" style="display:none"></div>
        <p class="text-muted mb-3" style="font-size:13px">
          Controls the timezone for all dates displayed in the app and stored in the database.
          Current setting: <strong><?= clean($currentTz) ?></strong>
          (<?= (new DateTime('now', new DateTimeZone($currentTz)))->format('P') ?>)
        </p>
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label">Common Timezones</label>
            <select class="form-select" id="tzQuick" onchange="document.getElementById('tzFull').value=this.value">
              <?php foreach ($tzPriority as $tz): ?>
              <option value="<?= $tz ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= $tz ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-7">
            <label class="form-label">Or select any timezone</label>
            <select class="form-select" id="tzFull" onchange="document.getElementById('tzQuick').value=''">
              <?php foreach ($allTz as $tz): ?>
              <option value="<?= $tz ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= $tz ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mt-3 text-end">
          <button class="btn btn-primary btn-sm" onclick="saveTimezone()"><i class="fa-solid fa-save me-1"></i>Save Timezone</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Security ─────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabSecurity">
    <div class="card">
      <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-key me-2"></i>Change Master Password</span></div>
      <div class="card-body" style="max-width:480px">
        <div id="secMsg" class="mb-3" style="display:none"></div>
        <p class="text-muted mb-3" style="font-size:13px">The master password protects this settings page. Keep it separate from your regular login password.</p>
        <div class="mb-3">
          <label class="form-label">Current Master Password</label>
          <input type="password" class="form-control" id="curPass" autocomplete="current-password">
        </div>
        <div class="mb-3">
          <label class="form-label">New Master Password</label>
          <input type="password" class="form-control" id="newPass" autocomplete="new-password">
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm New Password</label>
          <input type="password" class="form-control" id="conPass" autocomplete="new-password">
        </div>
        <div class="text-end">
          <button class="btn btn-primary btn-sm" onclick="changeMaster()"><i class="fa-solid fa-key me-1"></i>Update Password</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Export ─────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabExport">
    <div class="row g-3">

      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-database me-2 text-primary"></i>Full Database Backup</h6>
            <p class="text-muted mb-3" style="font-size:12.5px">Exports the entire database as a <code>.sql</code> file including table structure and all data. Compatible with fresh deployments.</p>
            <a href="../api/settings_api.php?action=export_db" class="btn btn-outline-primary btn-sm" target="_blank">
              <i class="fa-solid fa-download me-1"></i>Download Database (.sql)
            </a>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-folder-open me-2 text-warning"></i>Receipts &amp; Documents</h6>
            <p class="text-muted mb-3" style="font-size:12.5px">Packages all uploaded receipts, remittance slips, tenant documents and other files into a ZIP archive.</p>
            <a href="../api/settings_api.php?action=export_receipts" class="btn btn-outline-warning btn-sm" target="_blank">
              <i class="fa-solid fa-download me-1"></i>Download Files (.zip)
            </a>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-users me-2 text-success"></i>User Accounts</h6>
            <p class="text-muted mb-3" style="font-size:12.5px">Exports all user accounts (without passwords) as JSON. Passwords are preserved on import via ON DUPLICATE KEY UPDATE.</p>
            <a href="../api/settings_api.php?action=export_accounts" class="btn btn-outline-success btn-sm" target="_blank">
              <i class="fa-solid fa-download me-1"></i>Download Accounts (.json)
            </a>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-sliders me-2 text-secondary"></i>App Settings</h6>
            <p class="text-muted mb-3" style="font-size:12.5px">Exports all configuration values (company name, invoice prefix, etc.) as JSON. Does not include the master password.</p>
            <a href="../api/settings_api.php?action=export_settings" class="btn btn-outline-secondary btn-sm" target="_blank">
              <i class="fa-solid fa-download me-1"></i>Download Settings (.json)
            </a>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card" style="border-color:var(--primary);border-width:2px">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-file-pdf me-2 text-danger"></i>Full Transaction Audit Report (PDF)</h6>
            <p class="text-muted mb-3" style="font-size:12.5px">
              Generates a comprehensive, print-ready audit report of all transactions organized by type:
              Rental Payments, Service Payments, Expenses (by category), and Cash Transactions.
              Use <strong>Ctrl+P → Save as PDF</strong> to create the PDF file.
            </p>
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0" style="font-size:13px">Year:</label>
                <select id="auditYear" class="form-select form-select-sm" style="width:100px">
                  <?php
                  $auditYears = $pdo->query("SELECT DISTINCT YEAR(payment_date) y FROM payments UNION SELECT DISTINCT YEAR(expense_date) FROM expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
                  $cy = (int)date('Y');
                  if (!in_array($cy, $auditYears)) array_unshift($auditYears, $cy);
                  foreach ($auditYears as $ay):
                  ?>
                  <option value="<?= $ay ?>" <?= $ay == $cy ? 'selected' : '' ?>><?= $ay ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-danger btn-sm" onclick="openAudit()">
                <i class="fa-solid fa-file-pdf me-1"></i>Open Audit Report
              </button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Import ─────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabImport">
    <div class="alert alert-warning mb-3">
      <i class="fa-solid fa-triangle-exclamation me-2"></i>
      <strong>Warning:</strong> Database import will overwrite all existing data. Other imports merge/update existing records.
      Always export a backup before importing.
    </div>
    <div id="impMsg" class="mb-3" style="display:none"></div>

    <div class="row g-3">

      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-database me-2 text-danger"></i>Import Database</h6>
            <p class="text-muted mb-2" style="font-size:12.5px">Upload a <code>.sql</code> file exported from this app. <strong class="text-danger">This will overwrite all current data.</strong></p>
            <div class="mb-2"><label class="form-label">Check to confirm destructive import:</label>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="confirmDbImport">
                <label class="form-check-label" style="font-size:12.5px">I understand this will erase all current data</label>
              </div>
            </div>
            <input type="file" class="form-control form-control-sm mb-2" id="sqlFile" accept=".sql">
            <button class="btn btn-danger btn-sm" onclick="importDb()"><i class="fa-solid fa-upload me-1"></i>Import Database</button>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-folder-open me-2 text-warning"></i>Import Receipts &amp; Documents</h6>
            <p class="text-muted mb-2" style="font-size:12.5px">Upload a <code>.zip</code> file from a previous receipts export. Files are merged (existing files are overwritten).</p>
            <input type="file" class="form-control form-control-sm mb-2" id="zipFile" accept=".zip">
            <button class="btn btn-warning btn-sm" onclick="importReceipts()"><i class="fa-solid fa-upload me-1"></i>Import Files</button>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-users me-2 text-success"></i>Import User Accounts</h6>
            <p class="text-muted mb-2" style="font-size:12.5px">Upload a <code>.json</code> accounts export. Existing users (by username) will be updated; new ones added. Passwords are not affected.</p>
            <input type="file" class="form-control form-control-sm mb-2" id="accountsFile" accept=".json">
            <button class="btn btn-success btn-sm" onclick="importAccounts()"><i class="fa-solid fa-upload me-1"></i>Import Accounts</button>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <h6 class="fw-700 mb-1"><i class="fa-solid fa-sliders me-2 text-secondary"></i>Import Settings</h6>
            <p class="text-muted mb-2" style="font-size:12.5px">Upload a <code>.json</code> settings export. Existing keys are updated; new keys are added. Master password is never overwritten.</p>
            <input type="file" class="form-control form-control-sm mb-2" id="settingsFile" accept=".json">
            <button class="btn btn-secondary btn-sm" onclick="importSettings()"><i class="fa-solid fa-upload me-1"></i>Import Settings</button>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Danger Zone ────────────────────────────────────────── -->
  <div class="tab-pane fade" id="tabDanger">
    <div class="card border-danger">
      <div class="card-header" style="background:var(--laskie-coral-bg);border-color:var(--laskie-coral-soft)">
        <span class="card-header-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Danger Zone — Factory Reset</span>
      </div>
      <div class="card-body">
        <div class="alert alert-danger mb-4">
          <strong><i class="fa-solid fa-skull-crossbones me-1"></i>This action is irreversible.</strong>
          Factory reset will permanently delete all transactional and operational data from the system.
          User accounts and system settings will be preserved.
        </div>

        <h6 class="fw-700 mb-2">What will be deleted:</h6>
        <ul class="mb-4" style="font-size:13px;line-height:2">
          <li>All <strong>payments</strong> and payment history</li>
          <li>All <strong>expenses</strong> and expense records</li>
          <li>All <strong>cash transactions</strong></li>
          <li>All <strong>tenants</strong> and tenant documents</li>
          <li>All <strong>rental units</strong> and unit types</li>
          <li>All <strong>service types</strong> and expense categories</li>
          <li>All <strong>activity logs</strong></li>
          <li>All <strong>uploaded files</strong> (receipts, documents)</li>
        </ul>
        <h6 class="fw-700 mb-2">What will be kept:</h6>
        <ul class="mb-4" style="font-size:13px;line-height:2">
          <li>All <strong>user accounts</strong> and passwords</li>
          <li>All <strong>system settings</strong> (company info, timezone, master password)</li>
        </ul>

        <hr class="my-4">

        <div id="resetMsg" class="mb-3" style="display:none"></div>

        <div class="mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="resetConfirmCheck" onchange="updateResetBtn()">
            <label class="form-check-label text-danger fw-600" for="resetConfirmCheck">
              I understand this will permanently delete all data and cannot be undone.
            </label>
          </div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-600">Type <code>RESET</code> to confirm:</label>
          <input type="text" class="form-control" id="resetConfirmWord" placeholder="Type RESET here"
                 oninput="updateResetBtn()" style="max-width:280px">
        </div>

        <button class="btn btn-danger" id="resetBtn" onclick="doFactoryReset()" disabled>
          <i class="fa-solid fa-trash-can me-1"></i>Factory Reset
        </button>
      </div>
    </div>
  </div>

</div><!-- .tab-content -->

<script>
function lockSettings() {
  var fd = new FormData();
  fd.append('action', 'lock');
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function() { location.reload(); });
}

function msgBox(elId, type, text) {
  var el = document.getElementById(elId);
  el.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger');
  el.textContent = text;
  el.style.display = '';
  setTimeout(function() { if (el.textContent === text) el.style.display = 'none'; }, 5000);
}

function saveGeneral() {
  var keys = ['app_name','company_name','company_address','company_phone','company_email','invoice_prefix','currency_symbol','currency_code','default_due_day'];
  var fd = new FormData();
  fd.append('action', 'save_settings');
  keys.forEach(function(k) { fd.append(k, document.getElementById(k).value); });
  apiPost('../api/settings_api.php', fd, function(err, res) {
    msgBox('genMsg', res && res.success ? 'success' : 'error', (res && (res.msg || res.error)) || err || 'Error');
  });
}

function saveTimezone() {
  var tz = document.getElementById('tzFull').value || document.getElementById('tzQuick').value;
  apiPost('../api/settings_api.php', {action:'save_timezone', timezone:tz}, function(err, res) {
    msgBox('tzMsg', res && res.success ? 'success' : 'error', (res && (res.msg || res.error)) || err || 'Error');
  });
}

function changeMaster() {
  var data = {
    action: 'change_master',
    current_password:  document.getElementById('curPass').value,
    new_password:      document.getElementById('newPass').value,
    confirm_password:  document.getElementById('conPass').value
  };
  apiPost('../api/settings_api.php', data, function(err, res) {
    if (res && res.success) {
      ['curPass','newPass','conPass'].forEach(function(id) { document.getElementById(id).value = ''; });
    }
    msgBox('secMsg', res && res.success ? 'success' : 'error', (res && (res.msg || res.error)) || err || 'Error');
  });
}

function openAudit() {
  var year = document.getElementById('auditYear').value;
  window.open('../payments/audit_pdf.php?year=' + year, '_blank');
}

function showImpMsg(type, text) { msgBox('impMsg', type, text); }

function importDb() {
  if (!document.getElementById('confirmDbImport').checked) {
    showImpMsg('error', 'Check the confirmation box first.'); return;
  }
  var file = document.getElementById('sqlFile').files[0];
  if (!file) { showImpMsg('error', 'Select a .sql file first.'); return; }
  var fd = new FormData();
  fd.append('action', 'import_db');
  fd.append('sql_file', file);
  showImpMsg('success', 'Importing... this may take a moment.');
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        showImpMsg('success', res.msg);
        if (res.relock) setTimeout(function() { location.reload(); }, 2000);
      } else {
        showImpMsg('error', res.error || 'Import failed.');
      }
    })
    .catch(function(e) { showImpMsg('error', 'Network error: ' + e.message); });
}

function importReceipts() {
  var file = document.getElementById('zipFile').files[0];
  if (!file) { showImpMsg('error', 'Select a .zip file first.'); return; }
  var fd = new FormData(); fd.append('action','import_receipts'); fd.append('zip_file', file);
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) { showImpMsg(res.success ? 'success' : 'error', res.msg || res.error || 'Error'); })
    .catch(function(e) { showImpMsg('error', e.message); });
}

function importAccounts() {
  var file = document.getElementById('accountsFile').files[0];
  if (!file) { showImpMsg('error', 'Select a .json file first.'); return; }
  var fd = new FormData(); fd.append('action','import_accounts'); fd.append('json_file', file);
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) { showImpMsg(res.success ? 'success' : 'error', res.msg || res.error || 'Error'); })
    .catch(function(e) { showImpMsg('error', e.message); });
}

function importSettings() {
  var file = document.getElementById('settingsFile').files[0];
  if (!file) { showImpMsg('error', 'Select a .json file first.'); return; }
  var fd = new FormData(); fd.append('action','import_settings'); fd.append('json_file', file);
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) { showImpMsg(res.success ? 'success' : 'error', res.msg || res.error || 'Error'); })
    .catch(function(e) { showImpMsg('error', e.message); });
}

function updateResetBtn() {
  var checked = document.getElementById('resetConfirmCheck').checked;
  var word    = document.getElementById('resetConfirmWord').value.trim();
  document.getElementById('resetBtn').disabled = !(checked && word === 'RESET');
}

function doFactoryReset() {
  if (!document.getElementById('resetConfirmCheck').checked) return;
  if (document.getElementById('resetConfirmWord').value.trim() !== 'RESET') return;
  if (!confirm('FINAL WARNING: This will permanently erase all data.\n\nAre you absolutely sure you want to factory reset?')) return;

  var btn = document.getElementById('resetBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Resetting...';

  var fd = new FormData();
  fd.append('action', 'factory_reset');
  fd.append('confirm', 'RESET');
  fetch('../api/settings_api.php', {method:'POST', body:fd, credentials:'same-origin', headers: window.csrfHeaders()})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      var el = document.getElementById('resetMsg');
      el.className = 'alert alert-' + (res.success ? 'success' : 'danger');
      el.textContent = res.msg || res.error || 'Unknown error.';
      el.style.display = '';
      btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Factory Reset';
      if (res.success) {
        document.getElementById('resetConfirmCheck').checked = false;
        document.getElementById('resetConfirmWord').value = '';
      }
    })
    .catch(function(e) {
      var el = document.getElementById('resetMsg');
      el.className = 'alert alert-danger';
      el.textContent = 'Network error: ' + e.message;
      el.style.display = '';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i>Factory Reset';
    });
}
</script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
