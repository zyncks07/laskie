<?php
error_reporting(0);
ini_set("display_errors", 0);
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Master Audit Logs';
$depth = '../';

// ── Filters ───────────────────────────────────────────────────
$filterMonth  = (int)($_GET['month']  ?? date('n'));
$filterYear   = (int)($_GET['year']   ?? date('Y'));
$filterUser   = (int)($_GET['user']   ?? 0);
$filterModule = trim($_GET['module']  ?? '');
$filterAction = trim($_GET['action_type'] ?? '');
$filterIp     = trim($_GET['ip']      ?? '');
$search       = trim($_GET['search']  ?? '');

// Build WHERE
$where  = ['MONTH(sl.created_at)=? AND YEAR(sl.created_at)=?'];
$params = [$filterMonth, $filterYear];

if ($filterUser)   { $where[] = 'sl.user_id=?';     $params[] = $filterUser; }
if ($filterModule) { $where[] = 'sl.module=?';       $params[] = $filterModule; }
if ($filterIp)     { $where[] = 'sl.ip_address=?';   $params[] = $filterIp; }

if ($filterAction) {
    $actionMap = [
        'login_ok'     => "sl.action='LOGIN_SUCCESS'",
        'login_fail'   => "sl.action='LOGIN_FAILED'",
        'logout'       => "sl.action='LOGOUT'",
        'create'       => "sl.action LIKE 'CREATE_%'",
        'update'       => "sl.action LIKE 'UPDATE_%'",
        'delete'       => "sl.action LIKE 'DELETE_%'",
        'view'         => "sl.action LIKE 'VIEW_%'",
        'payment'      => "sl.module='Payments'",
        'settings'     => "sl.module='Settings'",
        'upload'       => "sl.action LIKE '%UPLOAD%'",
    ];
    if (isset($actionMap[$filterAction])) {
        $where[] = $actionMap[$filterAction];
    }
}

if ($search) {
    $where[] = '(sl.action LIKE ? OR sl.details LIKE ? OR sl.username LIKE ? OR sl.ip_address LIKE ?)';
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}

$whereStr = implode(' AND ', $where);

$logs = $pdo->prepare("
    SELECT sl.*, u.full_name, u.role
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id=u.id
    WHERE $whereStr
    ORDER BY sl.created_at DESC
    LIMIT 2000
");
$logs->execute($params);
$logRows = $logs->fetchAll();

// ── Unique IPs this period ────────────────────────────────────
$ipStmt = $pdo->prepare("SELECT DISTINCT ip_address FROM system_logs WHERE MONTH(created_at)=? AND YEAR(created_at)=? AND ip_address IS NOT NULL ORDER BY ip_address");
$ipStmt->execute([$filterMonth, $filterYear]);
$uniqueIps = $ipStmt->fetchAll(PDO::FETCH_COLUMN);

// ── All users for filter dropdown ─────────────────────────────
$allUsers = $pdo->query("SELECT id, full_name, role FROM users ORDER BY full_name")->fetchAll();

// ── Distinct modules ──────────────────────────────────────────
$modules = $pdo->query("SELECT DISTINCT module FROM system_logs WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

// ── Available months/years ────────────────────────────────────
$periods = $pdo->query("SELECT DISTINCT MONTH(created_at) as m, YEAR(created_at) as y FROM system_logs ORDER BY y DESC, m DESC")->fetchAll();
if (empty($periods)) $periods = [['m'=>date('n'),'y'=>date('Y')]];

// ── Action color map ──────────────────────────────────────────
function logActionBadge(string $action): string {
    if (str_starts_with($action,'LOGIN_SUCCESS') || $action==='LOGOUT') return 'badge-received';
    if ($action==='LOGIN_FAILED')                       return 'badge-inactive';
    if (str_starts_with($action,'CREATE_'))             return 'badge-active';
    if (str_starts_with($action,'UPDATE_'))             return 'badge-accountant';
    if (str_starts_with($action,'DELETE_'))             return 'badge-expense';
    if (str_starts_with($action,'VIEW_'))               return 'badge-former';
    if (str_contains($action,'UPLOAD'))                 return 'badge-staff';
    return 'badge-staff';
}

logActivity($pdo,'VIEW_LOGS','Logs',"Viewed audit logs for $filterMonth/$filterYear");
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-scroll me-2 text-primary-custom"></i>Master Audit Logs</h1>
  <div class="d-flex gap-2 align-items-center">
    <!-- Unique IPs badge -->
    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ipModal">
      <i class="fa-solid fa-network-wired me-1"></i>
      <span class="badge bg-primary me-1"><?= count($uniqueIps) ?></span> Unique IP<?= count($uniqueIps)!=1?'s':'' ?>
    </button>
    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
  </div>
</div>

<!-- ── Filter Panel ─────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-filter me-2"></i>Filters</span></div>
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Month</label>
        <select name="month" class="form-select form-select-sm">
          <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?=$m?>" <?=$m==$filterMonth?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Year</label>
        <select name="year" class="form-select form-select-sm">
          <?php
          $ys=array_unique(array_column($periods,'y')); rsort($ys);
          if(!in_array(date('Y'),$ys)) array_unshift($ys,(int)date('Y'));
          foreach($ys as $y): ?>
          <option value="<?=$y?>" <?=$y==$filterYear?'selected':''?>><?=$y?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">User</label>
        <select name="user" class="form-select form-select-sm">
          <option value="">All Users</option>
          <?php foreach($allUsers as $u): ?>
          <option value="<?=$u['id']?>" <?=$u['id']==$filterUser?'selected':''?>><?=clean($u['full_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Module</label>
        <select name="module" class="form-select form-select-sm">
          <option value="">All Modules</option>
          <?php foreach($modules as $mod): ?>
          <option value="<?=clean($mod)?>" <?=$mod===$filterModule?'selected':''?>><?=clean($mod)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Action Type</label>
        <select name="action_type" class="form-select form-select-sm">
          <option value="">All Actions</option>
          <option value="login_ok"   <?=$filterAction==='login_ok'?'selected':''?>>Login OK</option>
          <option value="login_fail" <?=$filterAction==='login_fail'?'selected':''?>>Login Failed</option>
          <option value="logout"     <?=$filterAction==='logout'?'selected':''?>>Logout</option>
          <option value="create"     <?=$filterAction==='create'?'selected':''?>>Create Records</option>
          <option value="update"     <?=$filterAction==='update'?'selected':''?>>Update Records</option>
          <option value="delete"     <?=$filterAction==='delete'?'selected':''?>>Delete Records</option>
          <option value="view"       <?=$filterAction==='view'?'selected':''?>>Page Views</option>
          <option value="payment"    <?=$filterAction==='payment'?'selected':''?>>Payments</option>
          <option value="settings"   <?=$filterAction==='settings'?'selected':''?>>Settings Changes</option>
          <option value="upload"     <?=$filterAction==='upload'?'selected':''?>>File Uploads</option>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label">Filter by IP</label>
        <input type="text" name="ip" class="form-control form-control-sm" placeholder="e.g. 192.168.1.1" value="<?=clean($filterIp)?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Action, details, username..." value="<?=clean($search)?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search me-1"></i>Apply</button>
        <a href="logs.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary Badges ───────────────────────────────────────── -->
<?php
$total      = count($logRows);
$loginOk    = count(array_filter($logRows, fn($r)=>$r['action']==='LOGIN_SUCCESS'));
$loginFail  = count(array_filter($logRows, fn($r)=>$r['action']==='LOGIN_FAILED'));
$creates    = count(array_filter($logRows, fn($r)=>str_starts_with($r['action'],'CREATE_')));
$updates    = count(array_filter($logRows, fn($r)=>str_starts_with($r['action'],'UPDATE_')));
$deletes    = count(array_filter($logRows, fn($r)=>str_starts_with($r['action'],'DELETE_')));
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="badge bg-secondary fs-sm">Total: <?=$total?></span>
  <span class="badge bg-success">Logins OK: <?=$loginOk?></span>
  <span class="badge bg-danger">Login Failed: <?=$loginFail?></span>
  <span class="badge bg-primary">Creates: <?=$creates?></span>
  <span class="badge" style="background:#1d4ed8">Updates: <?=$updates?></span>
  <span class="badge bg-warning text-dark">Deletes: <?=$deletes?></span>
</div>

<!-- ── Logs Table ───────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-header-title">
      <i class="fa-solid fa-list me-2"></i>Log Entries —
      <?= date('F', mktime(0,0,0,$filterMonth,1)) ?> <?= $filterYear ?>
    </span>
    <span class="text-muted" style="font-size:12px"><?= number_format($total) ?> records</span>
  </div>
  <div class="table-responsive">
    <table class="table" id="logsTable">
      <thead><tr>
        <th style="width:150px">Timestamp</th>
        <th>User</th>
        <th>Role</th>
        <th>Action</th>
        <th>Module</th>
        <th>Details</th>
        <th>IP Address</th>
      </tr></thead>
      <tbody>
      <?php foreach($logRows as $log): ?>
      <tr>
        <td data-order="<?= $log['created_at'] ?>" class="mono" style="font-size:11.5px;white-space:nowrap"><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
        <td>
          <div class="fw-600" style="font-size:12.5px"><?= clean($log['full_name'] ?? $log['username'] ?? '—') ?></div>
          <div class="mono" style="font-size:10.5px;color:var(--text-muted)"><?= clean($log['username'] ?? '') ?></div>
        </td>
        <td><?php if($log['role']): ?><span class="badge badge-<?= $log['role'] ?>"><?= ucfirst($log['role'] ?? '') ?></span><?php else: ?>—<?php endif; ?></td>
        <td><span class="badge log-action <?= logActionBadge($log['action']) ?>"><?= clean($log['action']) ?></span></td>
        <td><span class="text-muted" style="font-size:12px"><?= clean($log['module'] ?? '—') ?></span></td>
        <td class="log-details">
          <?php
            $det = $log['details'] ?? '';
            $diffData = null;
            if ($det !== '' && $det[0] === '{') {
                $parsed = json_decode($det, true);
                if ($parsed && isset($parsed['before'], $parsed['after'])) $diffData = $parsed;
            }
            if ($diffData):
              $before  = $diffData['before'];
              $after   = $diffData['after'];
              $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
              sort($allKeys);
              $changed = array_filter($allKeys, fn($k) => (string)($before[$k] ?? '') !== (string)($after[$k] ?? ''));
          ?>
          <button class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:11px" onclick="toggleDiff(this)">
            <i class="fa-solid fa-code-compare fa-xs me-1"></i>Diff (<?= count($changed) ?> change<?= count($changed) !== 1 ? 's' : '' ?>)
          </button>
          <div class="log-diff-table" style="display:none;margin-top:6px;overflow-x:auto;max-width:100%">
            <table style="font-size:11px;border-collapse:collapse;width:100%;min-width:320px">
              <thead><tr>
                <th style="padding:3px 7px;background:#f3f4f6;border:1px solid var(--border)">Field</th>
                <th style="padding:3px 7px;background:#fef2f2;border:1px solid var(--border);color:var(--danger)">Before</th>
                <th style="padding:3px 7px;background:#f0fdf4;border:1px solid var(--border);color:var(--success)">After</th>
              </tr></thead>
              <tbody>
              <?php foreach ($changed as $k): ?>
              <tr>
                <td style="padding:3px 7px;border:1px solid var(--border);font-weight:600;white-space:nowrap"><?= clean($k) ?></td>
                <td style="padding:3px 7px;border:1px solid var(--border);color:var(--danger)"><?= clean((string)($before[$k] ?? '—')) ?></td>
                <td style="padding:3px 7px;border:1px solid var(--border);color:var(--success)"><?= clean((string)($after[$k]  ?? '—')) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <?= clean($det ?: '—') ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if($log['ip_address']): ?>
          <a href="?<?= http_build_query(array_merge($_GET,['ip'=>$log['ip_address']])) ?>" class="ip-badge" title="Filter by this IP"><?= clean($log['ip_address']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($logRows)): ?>
      <tr><td colspan="7" class="text-center py-4 text-muted">No log entries found for this period.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── IP Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="ipModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-network-wired me-2"></i>Unique IPs — <?=date('F',mktime(0,0,0,$filterMonth,1))?> <?=$filterYear?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if(empty($uniqueIps)): ?>
        <p class="text-muted text-center">No IP data for this period.</p>
        <?php else: ?>
        <p class="text-muted mb-2" style="font-size:12px"><?=count($uniqueIps)?> unique IP address<?=count($uniqueIps)!=1?'es':''?> accessed the system.</p>
        <ul class="list-unstyled mb-0">
          <?php foreach($uniqueIps as $ip): ?>
          <li class="mb-1">
            <a href="?month=<?=$filterMonth?>&year=<?=$filterYear?>&ip=<?=urlencode($ip)?>" class="ip-badge text-decoration-none">
              <i class="fa-solid fa-circle-dot me-1" style="font-size:8px;color:var(--primary)"></i><?= clean($ip) ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
  $('#logsTable').DataTable({
    pageLength: 50,
    order: [[0,'desc']],
    columnDefs: [{orderable:false, targets:[]}],
    dom: '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rtip',
    language: {
      search: 'Search:',
      lengthMenu: 'Show _MENU_',
      info: '_START_ to _END_ of _TOTAL_',
      paginate: { previous: '\u2039', next: '\u203a' }
    }
  });
});

function toggleDiff(btn) {
  var table = btn.nextElementSibling;
  var isHidden = table.style.display === 'none';
  table.style.display = isHidden ? '' : 'none';
  btn.innerHTML = isHidden
    ? '<i class="fa-solid fa-chevron-up fa-xs me-1"></i>Hide'
    : btn.innerHTML.replace('Hide', btn.getAttribute('data-label') || 'Diff');
  if (isHidden && !btn.getAttribute('data-label')) {
    btn.setAttribute('data-label', btn.textContent.trim().replace('Hide','').trim() || 'Diff');
  }
}
</script>
<style>
/* Fix DataTable length/filter overlap */
.dataTables_wrapper .dataTables_length { font-size:12.5px; }
.dataTables_wrapper .dataTables_filter { font-size:12.5px; }
.dataTables_wrapper .dataTables_filter input {
  margin-left:6px;
  border:1px solid var(--border);
  border-radius:6px;
  padding:4px 10px;
  font-size:12.5px;
  outline:none;
}
.dataTables_wrapper .dataTables_filter input:focus {
  border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(26,58,143,.1);
}
.dataTables_wrapper .dataTables_length select {
  border:1px solid var(--border);
  border-radius:6px;
  padding:3px 6px;
  font-size:12.5px;
}
.dataTables_wrapper .dataTables_info { font-size:12px; color:var(--text-muted); }
.dataTables_wrapper .dataTables_paginate { font-size:12.5px; }
.dataTables_wrapper .dataTables_paginate .paginate_button {
  padding:3px 9px !important;
  border-radius:5px !important;
  font-size:12.5px !important;
}
</style>
JS;
include '../includes/footer.php'; ?>
