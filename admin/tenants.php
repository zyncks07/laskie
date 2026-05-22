<?php
error_reporting(0);
ini_set("display_errors", 0);
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Tenant Management';
$depth = '../';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_tenant') {
        $id        = (int)($_POST['id'] ?? 0);
        $unitId    = (int)($_POST['unit_id'] ?? 0) ?: null;
        $fullName  = trim($_POST['full_name'] ?? '');
        $email     = nullOrStr($_POST['email'] ?? '');
        $phone     = nullOrStr($_POST['phone'] ?? '');
        $phone2    = nullOrStr($_POST['phone2'] ?? '');
        $fb        = nullOrStr($_POST['facebook'] ?? '');
        $ig        = nullOrStr($_POST['instagram'] ?? '');
        $other_s   = nullOrStr($_POST['other_social'] ?? '');
        $address   = nullOrStr($_POST['address'] ?? '');
        $start     = nullOrStr($_POST['contract_start'] ?? '');
        $end       = nullOrStr($_POST['contract_end'] ?? '');
        $status    = $_POST['status'] ?? 'active';
        $notes     = nullOrStr($_POST['notes'] ?? '');
        if (!$fullName) jsonErr('Tenant full name is required.');

        if ($id) {
            $pdo->prepare("UPDATE tenants SET unit_id=?,full_name=?,email=?,phone=?,phone2=?,facebook=?,instagram=?,other_social=?,address=?,contract_start=?,contract_end=?,status=?,notes=?,updated_at=NOW() WHERE id=?")
                ->execute([$unitId,$fullName,$email,$phone,$phone2,$fb,$ig,$other_s,$address,$start,$end,$status,$notes,$id]);
            // Update unit occupancy
            if ($unitId) {
                $occ = $status === 'active' ? 'occupied' : 'vacant';
                $pdo->prepare("UPDATE rental_units SET status=? WHERE id=?")->execute([$occ,$unitId]);
            }
            logActivity($pdo, 'UPDATE_TENANT', 'Tenants', "Updated tenant #$id ($fullName)");
            jsonOk(['msg'=>'Tenant updated.']);
        } else {
            $pdo->prepare("INSERT INTO tenants (unit_id,full_name,email,phone,phone2,facebook,instagram,other_social,address,contract_start,contract_end,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$unitId,$fullName,$email,$phone,$phone2,$fb,$ig,$other_s,$address,$start,$end,$status,$notes,$_SESSION['user']['id']]);
            if ($unitId && $status === 'active') $pdo->prepare("UPDATE rental_units SET status='occupied' WHERE id=?")->execute([$unitId]);
            logActivity($pdo, 'CREATE_TENANT', 'Tenants', "Created tenant $fullName");
            jsonOk(['msg'=>'Tenant added.']);
        }
    }

    if ($action === 'get_tenant') {
        $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
        $t->execute([(int)$_POST['id']]);
        jsonOk(['tenant' => $t->fetch()]);
    }

    if ($action === 'upload_doc') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if (!$tenantId) jsonErr('Tenant ID required.');
        $docName = trim($_POST['doc_name'] ?? 'Document');
        $docType = nullOrStr($_POST['doc_type'] ?? '');
        $extUrl  = nullOrStr($_POST['external_url'] ?? '');
        $filePath = null;
        if (!empty($_FILES['doc_file']['name'])) {
            $up = handleUpload('doc_file', 'contracts');
            if ($up['error']) jsonErr($up['error']);
            $filePath = $up['path'];
        }
        $pdo->prepare("INSERT INTO tenant_docs (tenant_id,doc_name,doc_type,file_path,external_url,uploaded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$tenantId,$docName,$docType,$filePath,$extUrl,$_SESSION['user']['id']]);
        logActivity($pdo,'UPLOAD_DOC','Tenants',"Uploaded doc '$docName' for tenant #$tenantId");
        jsonOk(['msg'=>'Document saved.']);
    }

    if ($action === 'get_docs') {
        $docs = $pdo->prepare("SELECT td.*, u.full_name as uploader FROM tenant_docs td LEFT JOIN users u ON td.uploaded_by=u.id WHERE td.tenant_id=? ORDER BY td.created_at DESC");
        $docs->execute([(int)$_POST['tenant_id']]);
        jsonOk(['docs' => $docs->fetchAll()]);
    }

    if ($action === 'delete_doc') {
        $pdo->prepare("DELETE FROM tenant_docs WHERE id=?")->execute([(int)$_POST['id']]);
        logActivity($pdo,'DELETE_DOC','Tenants',"Deleted tenant doc #".(int)$_POST['id']);
        jsonOk(['msg'=>'Document removed.']);
    }
    exit;
}

$tenants = $pdo->query("SELECT t.*, ru.unit_name FROM tenants t LEFT JOIN rental_units ru ON t.unit_id=ru.id ORDER BY t.status, t.full_name")->fetchAll();
$units   = $pdo->query("SELECT id, unit_name, status FROM rental_units ORDER BY unit_name")->fetchAll();
logActivity($pdo,'VIEW_TENANTS','Tenants','Viewed tenant management page');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-people-roof me-2 text-primary-custom"></i>Tenant Management</h1>
  <button class="btn btn-primary btn-sm" onclick="openTenantModal()"><i class="fa-solid fa-plus me-1"></i>Add Tenant</button>
</div>

<!-- Filter tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active" href="#" onclick="filterTenants('all',this)">All (<?= count($tenants) ?>)</a></li>
  <li class="nav-item"><a class="nav-link" href="#" onclick="filterTenants('active',this)">Active</a></li>
  <li class="nav-item"><a class="nav-link" href="#" onclick="filterTenants('inactive',this)">Inactive</a></li>
  <li class="nav-item"><a class="nav-link" href="#" onclick="filterTenants('former',this)">Former</a></li>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table" id="tenantTable">
      <thead><tr>
        <th>Tenant Name</th><th>Unit</th><th>Contact</th>
        <th>Contract</th><th>Status</th><th class="text-center">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($tenants as $t): ?>
      <tr data-status="<?= $t['status'] ?>">
        <td>
          <div class="fw-600 cell-trunc"><?= clean($t['full_name']) ?></div>
          <?php if($t['email']): ?><div class="cell-trunc" style="font-size:11.5px;color:var(--text-muted)"><?= clean($t['email']) ?></div><?php endif; ?>
        </td>
        <td><?= clean($t['unit_name'] ?? '—') ?></td>
        <td>
          <?= clean($t['phone'] ?? '—') ?>
          <?php if($t['phone2']): ?><br><small><?= clean($t['phone2']) ?></small><?php endif; ?>
        </td>
        <td data-order="<?= $t['contract_start'] ?? '' ?>">
          <?php if($t['contract_start']): ?>
            <?= fmtDate($t['contract_start'],'M Y') ?> – <?= $t['contract_end'] ? fmtDate($t['contract_end'],'M Y') : 'Open' ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
        <td class="text-center">
          <button class="btn-icon" title="Edit" onclick="editTenant(<?= $t['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
          <button class="btn-icon" title="Documents" onclick="openDocs(<?= $t['id'] ?>, '<?= clean(addslashes($t['full_name'])) ?>')"><i class="fa-solid fa-folder-open fa-xs"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tenant Modal -->
<div class="modal fade" id="tenantModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tModalTitle">Add Tenant</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tId">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Full Name *</label><input type="text" class="form-control" id="tName"></div>
          <div class="col-md-4"><label class="form-label">Rental Unit</label>
            <select class="form-select" id="tUnit">
              <option value="">— No unit assigned —</option>
              <?php foreach($units as $u): ?>
              <option value="<?= $u['id'] ?>"><?= clean($u['unit_name']) ?> (<?= $u['status'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" id="tEmail"></div>
          <div class="col-md-4"><label class="form-label">Phone</label><input type="text" class="form-control" id="tPhone"></div>
          <div class="col-md-4"><label class="form-label">Phone 2</label><input type="text" class="form-control" id="tPhone2"></div>
          <div class="col-md-4"><label class="form-label">Facebook</label><input type="text" class="form-control" id="tFb" placeholder="Profile URL or name"></div>
          <div class="col-md-4"><label class="form-label">Instagram</label><input type="text" class="form-control" id="tIg" placeholder="@handle"></div>
          <div class="col-md-4"><label class="form-label">Other Social</label><input type="text" class="form-control" id="tOther"></div>
          <div class="col-md-4"><label class="form-label">Contract Start</label><input type="date" class="form-control" id="tStart"></div>
          <div class="col-md-4"><label class="form-label">Contract End</label><input type="date" class="form-control" id="tEnd"></div>
          <div class="col-md-4"><label class="form-label">Status</label>
            <select class="form-select" id="tStatus">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="former">Former Tenant</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" id="tAddress" rows="2"></textarea></div>
          <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" id="tNotes" rows="2" placeholder="Additional notes or remarks"></textarea></div>
        </div>
        <div id="tMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveTenant()"><i class="fa-solid fa-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Docs Modal -->
<div class="modal fade" id="docsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="docsTitle">Documents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="docTenantId">
        <div class="card mb-3">
          <div class="card-header"><span class="card-header-title">Upload / Add Document</span></div>
          <div class="card-body">
            <div class="row g-2">
              <div class="col-md-4"><label class="form-label">Document Name *</label><input type="text" class="form-control" id="dName" placeholder="e.g. Signed Contract"></div>
              <div class="col-md-4"><label class="form-label">Type</label>
                <select class="form-select" id="dType">
                  <option value="contract">Signed Contract</option>
                  <option value="id">Government ID</option>
                  <option value="permit">Permit</option>
                  <option value="receipt">Receipt</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-4"><label class="form-label">Upload File</label><input type="file" class="form-control" id="dFile" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></div>
              <div class="col-12"><label class="form-label">Or External URL</label><input type="url" class="form-control" id="dUrl" placeholder="https://drive.google.com/..."></div>
              <div class="col-12"><button class="btn btn-primary btn-sm" onclick="uploadDoc()"><i class="fa-solid fa-upload me-1"></i>Save Document</button></div>
            </div>
          </div>
        </div>
        <div id="docsList"><div class="text-center py-3 text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
var tModal, dModal;
document.addEventListener('DOMContentLoaded', function() {
  tModal = new bootstrap.Modal(document.getElementById('tenantModal'));
  dModal = new bootstrap.Modal(document.getElementById('docsModal'));
});

$(document).ready(function(){
  $('#tenantTable').DataTable({pageLength:50, order:[[4,'asc'],[0,'asc']], columnDefs:[{orderable:false,targets:5}]});
});

function filterTenants(status, el) {
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('#tenantTable tbody tr').forEach(tr => {
    tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
  });
}

function openTenantModal() {
  document.getElementById('tModalTitle').textContent = 'Add Tenant';
  document.getElementById('tId').value = '';
  ['tName','tEmail','tPhone','tPhone2','tFb','tIg','tOther','tAddress','tNotes'].forEach(id => document.getElementById(id).value='');
  document.getElementById('tUnit').value  = '';
  document.getElementById('tStart').value = '';
  document.getElementById('tEnd').value = '';
  document.getElementById('tStatus').value = 'active';
  document.getElementById('tMsg').style.display = 'none';
  tModal.show();
}

function editTenant(id) {
  apiPost('tenants.php', {action:'get_tenant', id}, (err, res) => {
    if (!res.success) return showToast('Failed to load tenant.','error');
    const t = res.tenant;
    document.getElementById('tModalTitle').textContent = 'Edit Tenant';
    document.getElementById('tId').value     = t.id;
    document.getElementById('tName').value   = t.full_name || '';
    document.getElementById('tUnit').value   = t.unit_id || '';
    document.getElementById('tEmail').value  = t.email || '';
    document.getElementById('tPhone').value  = t.phone || '';
    document.getElementById('tPhone2').value = t.phone2 || '';
    document.getElementById('tFb').value     = t.facebook || '';
    document.getElementById('tIg').value     = t.instagram || '';
    document.getElementById('tOther').value  = t.other_social || '';
    document.getElementById('tStart').value  = t.contract_start || '';
    document.getElementById('tEnd').value    = t.contract_end || '';
    document.getElementById('tStatus').value = t.status || 'active';
    document.getElementById('tAddress').value = t.address || '';
    document.getElementById('tNotes').value  = t.notes || '';
    document.getElementById('tMsg').style.display = 'none';
    tModal.show();
  });
}

function saveTenant() {
  const data = {
    action:'save_tenant',
    id: document.getElementById('tId').value,
    unit_id: document.getElementById('tUnit').value,
    full_name: document.getElementById('tName').value,
    email: document.getElementById('tEmail').value,
    phone: document.getElementById('tPhone').value,
    phone2: document.getElementById('tPhone2').value,
    facebook: document.getElementById('tFb').value,
    instagram: document.getElementById('tIg').value,
    other_social: document.getElementById('tOther').value,
    address: document.getElementById('tAddress').value,
    contract_start: document.getElementById('tStart').value,
    contract_end: document.getElementById('tEnd').value,
    status: document.getElementById('tStatus').value,
    notes: document.getElementById('tNotes').value
  };
  apiPost('tenants.php', data, (err, res) => {
    if (!res.success) { const el=document.getElementById('tMsg'); el.style.display=''; el.className='alert alert-danger'; el.textContent=res.error; return; }
    showToast(res.msg,'success'); tModal.hide(); setTimeout(()=>location.reload(),800);
  });
}

function openDocs(tenantId, name) {
  document.getElementById('docTenantId').value = tenantId;
  document.getElementById('docsTitle').textContent = 'Documents — ' + name;
  document.getElementById('dName').value = '';
  document.getElementById('dUrl').value = '';
  document.getElementById('dFile').value = '';
  dModal.show();
  loadDocs(tenantId);
}

function loadDocs(tenantId) {
  apiPost('tenants.php', {action:'get_docs', tenant_id:tenantId}, (err, res) => {
    const container = document.getElementById('docsList');
    if (!res.success || !res.docs.length) { container.innerHTML = '<p class="text-muted small">No documents yet.</p>'; return; }
    let html = '<div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Type</th><th>File/URL</th><th>Uploaded By</th><th>Date</th><th></th></tr></thead><tbody>';
    res.docs.forEach(d => {
      let link = '—';
      if (d.file_path) link = '<a href="' + d.file_path + '" target="_blank" class="text-primary"><i class="fa-solid fa-file me-1"></i>View</a>';
      else if (d.external_url) link = '<a href="' + d.external_url + '" target="_blank" class="text-primary"><i class="fa-solid fa-link me-1"></i>Open URL</a>';
      html += '<tr><td>' + d.doc_name + '</td><td><span class="badge bg-secondary">' + (d.doc_type||'&#8212;') + '</span></td><td>' + link + '</td><td>' + (d.uploader||'&#8212;') + '</td><td style="font-size:11px">' + d.created_at.split(' ')[0] + '</td>' +
        '<td><button class="btn-icon danger" onclick="deleteDoc(' + d.id + ')"><i class="fa-solid fa-trash fa-xs"></i></button></td></tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
  });
}

function uploadDoc() {
  const tenantId = document.getElementById('docTenantId').value;
  const fd = new FormData();
  fd.append('action','upload_doc');
  fd.append('tenant_id', tenantId);
  fd.append('doc_name', document.getElementById('dName').value);
  fd.append('doc_type', document.getElementById('dType').value);
  fd.append('external_url', document.getElementById('dUrl').value);
  const fileInput = document.getElementById('dFile');
  if (fileInput.files[0]) fd.append('doc_file', fileInput.files[0]);
  fetch('tenants.php', {method:'POST', body:fd, credentials:'same-origin'})
    .then(r=>r.json())
    .then(res => {
      if (!res.success) { showToast(res.error,'error'); return; }
      showToast(res.msg,'success');
      document.getElementById('dName').value=''; document.getElementById('dUrl').value=''; document.getElementById('dFile').value='';
      loadDocs(tenantId);
    });
}

function deleteDoc(id) {
  confirmDelete('Remove this document?', ()=>{
    apiPost('tenants.php', {action:'delete_doc', id}, (err,res) => {
      if (!res.success) return showToast(res.error,'error');
      showToast(res.msg,'success');
      loadDocs(document.getElementById('docTenantId').value);
    });
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
