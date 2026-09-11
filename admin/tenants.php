<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Tenant Management';
$depth = '../';

// ─── Tenant document categories ──────────────────────────────
// The category IS the tenant_docs.doc_type column — there are exactly three
// upload sections in the Documents modal, and each maps to its own uploads/
// subdirectory. Anything unrecognised (a stale client, a hand-crafted POST)
// buckets into 'other' rather than inventing a fourth section.
const TENANT_DOC_CATS = ['contract' => 'contracts', 'id' => 'ids', 'other' => 'docs'];
// Hard ceiling per category, per tenant. Typical use is 2–5 contract pages,
// ~4 IDs and a couple of supporting docs, so 20 is generous headroom.
const TENANT_DOC_CAP  = 20;
// Viewable/printable types only — the default handleUpload() whitelist omits
// webp (common on Android screenshots) and allows zip, which is not a document.
const TENANT_DOC_EXTS = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    csrfRequirePost();
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
        if (!in_array($status, ['active','inactive','former'], true)) jsonErr('Invalid tenant status.');
        if ($start && $end && strtotime($start) > strtotime($end)) jsonErr('Contract start date cannot be after contract end date.');

        if ($id) {
            // Capture before-state: old unit for occupancy resync + full field
            // set for the audit diff.
            $oldRow = $pdo->prepare("SELECT unit_id, full_name, status, contract_start, contract_end FROM tenants WHERE id=?");
            $oldRow->execute([$id]);
            $beforeRow = $oldRow->fetch();
            if (!$beforeRow) jsonErr('Tenant not found.');
            $oldUnitId = !empty($beforeRow['unit_id']) ? (int)$beforeRow['unit_id'] : null;

            // Atomic: UPDATE tenants + up-to-two UPDATE rental_units commit
            // together. Without this, a failure between the tenant update and
            // the occupancy resync leaves rental_units.status disagreeing
            // with the tenants table.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE tenants SET unit_id=?,full_name=?,email=?,phone=?,phone2=?,facebook=?,instagram=?,other_social=?,address=?,contract_start=?,contract_end=?,status=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$unitId,$fullName,$email,$phone,$phone2,$fb,$ig,$other_s,$address,$start,$end,$status,$notes,$id]);
                // Recompute new unit's occupancy from the truth of the tenants table.
                // Previously this flipped to 'vacant' whenever the edited tenant went
                // inactive/former, ignoring any other active tenants still assigned
                // to the unit. Counting again here keeps the unit 'occupied' if
                // someone else is still active in it.
                if ($unitId) {
                    $stillActive = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE unit_id=? AND status='active'");
                    $stillActive->execute([$unitId]);
                    $occ = ((int)$stillActive->fetchColumn() > 0) ? 'occupied' : 'vacant';
                    $pdo->prepare("UPDATE rental_units SET status=? WHERE id=?")->execute([$occ,$unitId]);
                }
                // If the tenant moved out of the old unit, recompute occupancy there too.
                if ($oldUnitId && $oldUnitId !== $unitId) {
                    $stillOccupied = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE unit_id=? AND status='active' AND id!=?");
                    $stillOccupied->execute([$oldUnitId, $id]);
                    if ((int)$stillOccupied->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE rental_units SET status='vacant' WHERE id=?")->execute([$oldUnitId]);
                    }
                }
                $after = ['unit_id'=>$unitId,'full_name'=>$fullName,'status'=>$status,'contract_start'=>$start,'contract_end'=>$end];
                logChange($pdo, 'UPDATE_TENANT', 'Tenants', $beforeRow, $after);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonOk(['msg'=>'Tenant updated.']);
        } else {
            // Atomic: tenant creation may bump the unit to 'occupied'.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO tenants (unit_id,full_name,email,phone,phone2,facebook,instagram,other_social,address,contract_start,contract_end,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$unitId,$fullName,$email,$phone,$phone2,$fb,$ig,$other_s,$address,$start,$end,$status,$notes,$_SESSION['user']['id']]);
                if ($unitId && $status === 'active') $pdo->prepare("UPDATE rental_units SET status='occupied' WHERE id=?")->execute([$unitId]);
                logActivity($pdo, 'CREATE_TENANT', 'Tenants', "Created tenant $fullName");
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonOk(['msg'=>'Tenant added.']);
        }
    }

    if ($action === 'get_tenant') {
        $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
        $t->execute([(int)$_POST['id']]);
        jsonOk(['tenant' => $t->fetch()]);
    }

    // One file (or one external link) per request. The client loops a multi-file
    // selection sequentially: .htaccess caps post_max_size at 35M and PHP's
    // max_file_uploads defaults to 20, so a single 20-file POST would be
    // truncated or rejected outright. Per-file requests also let the UI report
    // exactly which file failed.
    if ($action === 'upload_doc') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if (!$tenantId) jsonErr('Tenant ID required.');
        // Confirm the tenant exists before touching the filesystem — a bogus id
        // used to fall through to the FK and surface as a 500.
        $tq = $pdo->prepare("SELECT full_name FROM tenants WHERE id=?");
        $tq->execute([$tenantId]);
        $tenantName = $tq->fetchColumn();
        if ($tenantName === false) jsonErr('Tenant not found.');

        $cat = (string)($_POST['doc_type'] ?? '');
        if (!isset(TENANT_DOC_CATS[$cat])) $cat = 'other';

        $extUrl  = nullOrStr($_POST['external_url'] ?? '');
        $hasFile = !empty($_FILES['doc_file']['name']);
        if (!$hasFile && !$extUrl) jsonErr('Choose a file to upload or paste an external link.');
        // Reject javascript:/data:/file: on the way in, not just on the way out —
        // the client's _docSafeUrl() only protects the render, not the stored row.
        if ($extUrl && !preg_match('#^https?://#i', $extUrl)) {
            jsonErr('External links must start with http:// or https://');
        }

        // Per-category cap, enforced server-side so two open tabs cannot race
        // past it. The client pre-check is UX only.
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM tenant_docs WHERE tenant_id=? AND doc_type=?");
        $cnt->execute([$tenantId, $cat]);
        if ((int)$cnt->fetchColumn() >= TENANT_DOC_CAP) {
            jsonErr('Limit reached — ' . TENANT_DOC_CAP . ' items in this category. Remove one first.');
        }

        $filePath = null;
        $docName  = '';
        if ($hasFile) {
            // handleUpload() compresses JPEG/PNG/WebP in place (same pipeline as
            // payment and expense receipts) and leaves PDFs/office docs alone.
            $up = handleUpload('doc_file', TENANT_DOC_CATS[$cat], TENANT_DOC_EXTS);
            if ($up['error']) jsonErr($up['error']);
            $filePath = $up['path'];
            // The on-disk name is random hex — keep the name the user recognises.
            $docName = mb_substr(basename((string)$_FILES['doc_file']['name']), 0, 200);
        }
        if ($docName === '') {
            $docName = nullOrStr($_POST['label'] ?? '')
                ?? (parse_url((string)$extUrl, PHP_URL_HOST) ?: 'External link');
            $docName = mb_substr($docName, 0, 200);
        }

        $pdo->prepare("INSERT INTO tenant_docs (tenant_id,doc_name,doc_type,file_path,external_url,uploaded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$tenantId,$docName,$cat,$filePath,$extUrl,$_SESSION['user']['id']]);
        logActivity($pdo,'UPLOAD_DOC','Tenants',"Added $cat doc '$docName' for tenant #$tenantId ($tenantName)");
        jsonOk(['msg' => $hasFile ? 'File uploaded.' : 'Link added.']);
    }

    if ($action === 'get_docs') {
        $docs = $pdo->prepare("SELECT td.*, u.full_name as uploader FROM tenant_docs td LEFT JOIN users u ON td.uploaded_by=u.id WHERE td.tenant_id=? ORDER BY td.created_at DESC");
        $docs->execute([(int)$_POST['tenant_id']]);
        jsonOk(['docs' => $docs->fetchAll(), 'cap' => TENANT_DOC_CAP]);
    }

    if ($action === 'delete_doc') {
        $docId    = (int)($_POST['id']        ?? 0);
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if (!$docId || !$tenantId) jsonErr('Invalid request.');
        // Scope to tenant so an admin cannot delete another tenant's document
        // by guessing a doc ID. Both conditions must match.
        $row = $pdo->prepare("SELECT file_path FROM tenant_docs WHERE id=? AND tenant_id=?");
        $row->execute([$docId, $tenantId]);
        $fp = $row->fetchColumn();
        if ($fp === false) jsonErr('Document not found.');
        if ($fp && str_starts_with((string)$fp, '/uploads/')) {
            @unlink(__DIR__ . '/..' . $fp);
        }
        $pdo->prepare("DELETE FROM tenant_docs WHERE id=? AND tenant_id=?")->execute([$docId, $tenantId]);
        logActivity($pdo,'DELETE_DOC','Tenants',"Deleted tenant doc #$docId");
        jsonOk(['msg'=>'Document removed.']);
    }
    exit;
}

$tenants = $pdo->query("SELECT t.*, ru.unit_name FROM tenants t LEFT JOIN rental_units ru ON t.unit_id=ru.id ORDER BY t.status, t.full_name")->fetchAll();
$units   = $pdo->query("SELECT id, unit_name, status FROM rental_units ORDER BY unit_name")->fetchAll();
// One grouped query feeds the document-count badge on every row's folder button.
$docCounts = $pdo->query("SELECT tenant_id, COUNT(*) FROM tenant_docs GROUP BY tenant_id")->fetchAll(PDO::FETCH_KEY_PAIR);
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
          <?php $dc = (int)($docCounts[$t['id']] ?? 0); ?>
          <button class="btn-icon" title="Documents<?= $dc ? " ($dc)" : '' ?>" data-id="<?= $t['id'] ?>" data-name="<?= clean($t['full_name']) ?>" onclick="openDocs(+this.dataset.id, this.dataset.name)"><i class="fa-solid fa-folder-open fa-xs"></i><?php if ($dc): ?><span class="doc-count"><?= $dc ?></span><?php endif; ?></button>
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

<!-- Docs Modal — three fixed sections, each capped at TENANT_DOC_CAP items -->
<div class="modal fade" id="docsModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="docsTitle">Documents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="docTenantId">
        <?php foreach ([
          ['contract', 'Contract',             'fa-file-signature', 'Signed contract, addendums, renewal pages'],
          ['id',       'IDs',                  'fa-id-card',        'Government or company IDs, front and back'],
          ['other',    'Supporting Documents', 'fa-folder-tree',    'Proof of billing, payslips, references, anything else'],
        ] as [$cat, $secLabel, $secIcon, $secHint]): ?>
        <div class="card mb-3" data-cat="<?= $cat ?>">
          <div class="card-header doc-sec-head" role="button" tabindex="0"
               data-bs-toggle="collapse" data-bs-target="#docSec-<?= $cat ?>" aria-expanded="true">
            <span class="card-header-title"><i class="fa-solid <?= $secIcon ?> me-2"></i><?= $secLabel ?></span>
            <span class="d-flex align-items-center gap-2">
              <span class="muted-pill" id="docCount-<?= $cat ?>">0 / <?= TENANT_DOC_CAP ?></span>
              <i class="fa-solid fa-chevron-up doc-chev"></i>
            </span>
          </div>
          <div class="collapse show" id="docSec-<?= $cat ?>">
            <div class="card-body">
              <div class="doc-ctl">
                <input type="file" class="form-control form-control-sm" id="docFile-<?= $cat ?>" multiple
                       accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                       onchange="validateFileSize(this)">
                <button class="btn btn-primary btn-sm doc-btn" id="docUpBtn-<?= $cat ?>"
                        onclick="uploadDocs('<?= $cat ?>')"><i class="fa-solid fa-upload me-1"></i>Upload</button>
              </div>
              <div class="form-text"><?= $secHint ?> — pick several files at once. JPG/PNG/WebP/PDF/DOC/XLS, max 30&nbsp;MB each; images are auto-compressed.</div>
              <div class="doc-ctl mt-2">
                <input type="url" class="form-control form-control-sm" id="docUrl-<?= $cat ?>" placeholder="https://drive.google.com/...">
                <input type="text" class="form-control form-control-sm doc-label-input" id="docLabel-<?= $cat ?>" placeholder="Link label (optional)">
                <button class="btn btn-secondary btn-sm doc-btn" id="docLinkBtn-<?= $cat ?>"
                        onclick="addDocLink('<?= $cat ?>')"><i class="fa-solid fa-link me-1"></i>Add link</button>
              </div>
              <div class="doc-prog" id="docProg-<?= $cat ?>" style="display:none" aria-live="polite">
                <div class="doc-prog-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="0" id="docProgTrack-<?= $cat ?>"><span id="docProgFill-<?= $cat ?>"></span></div>
                <div class="doc-prog-txt" id="docProgTxt-<?= $cat ?>"></div>
              </div>
              <div class="doc-list" id="docList-<?= $cat ?>"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<style>
/* Documents modal — compact rows instead of a table so nothing scrolls
   sideways at 375px. Colors come from the token layer only. */
.doc-sec-head { cursor: pointer; user-select: none; }
.doc-chev { font-size: 11px; color: var(--gray-500); transition: transform .18s ease; }
.doc-sec-head.collapsed .doc-chev { transform: rotate(180deg); }
.doc-ctl { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.doc-ctl .form-control { flex: 1 1 180px; min-width: 0; }
.doc-ctl .doc-label-input { flex: 1 1 140px; }
.doc-btn { flex: 0 0 auto; white-space: nowrap; }
/* Upload progress — real bytes-sent for files, so a 20 MB photo on a slow
   connection shows movement instead of a frozen button. */
.doc-prog { margin-top: 10px; }
.doc-prog-track {
  height: 6px; border-radius: 999px; overflow: hidden;
  background: var(--gray-200);
}
.doc-prog-track > span {
  display: block; height: 100%; width: 0;
  border-radius: 999px; background: var(--ink);
  transition: width .25s ease;
}
.doc-prog-txt { margin-top: 5px; font-size: 11px; color: var(--gray-500); }
.doc-list { margin-top: 12px; display: flex; flex-direction: column; gap: 2px; }
.doc-row {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 8px; border-radius: var(--radius);
  border: 1px solid transparent;
}
.doc-row + .doc-row { border-top: 1px solid var(--gray-200); border-radius: 0; }
.doc-row:hover { background: var(--gray-100); }
.doc-row .doc-ico { flex: 0 0 16px; color: var(--gray-500); font-size: 13px; text-align: center; }
.doc-row .doc-name { flex: 1 1 auto; min-width: 0; font-weight: 600; font-size: 12.5px; }
.doc-row .doc-meta { flex: 0 0 auto; font-size: 11px; color: var(--gray-500); }
.doc-row .doc-open { flex: 0 0 auto; font-size: 11.5px; font-weight: 600; text-decoration: none; }
.doc-row .btn-icon { flex: 0 0 auto; }
.doc-empty { font-size: 12px; color: var(--gray-500); padding: 6px 2px; }
/* Count bubble on the row's folder button */
.doc-count {
  position: absolute; top: -4px; right: -4px;
  min-width: 15px; height: 15px; padding: 0 3px;
  border-radius: 8px; background: var(--ink); color: var(--paper);
  font-size: 9.5px; line-height: 15px; font-weight: 700; text-align: center;
}
#tenantTable .btn-icon { position: relative; }
@media (max-width: 576px) {
  .doc-ctl .form-control, .doc-btn { flex: 1 1 100%; }
  .doc-row { flex-wrap: wrap; row-gap: 2px; }
  .doc-row .doc-name { flex: 1 1 100%; order: 1; }
  .doc-row .doc-ico  { order: 0; }
  .doc-row .doc-meta { order: 2; }
  .doc-row .doc-open { order: 3; margin-left: auto; }
  .doc-row .btn-icon { order: 4; }
}
</style>

<?php $extraJs = <<<'JS'
<script>
var tModal, dModal;
document.addEventListener('DOMContentLoaded', function() {
  tModal = new bootstrap.Modal(document.getElementById('tenantModal'));
  dModal = new bootstrap.Modal(document.getElementById('docsModal'));
  // Bootstrap's collapse toggles on click only; the section headers are divs
  // (a <button> would fight the .card-header flex layout), so wire Enter/Space
  // for keyboard users.
  document.querySelectorAll('.doc-sec-head').forEach(function(h) {
    h.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); h.click(); }
    });
  });
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

// The three sections mirror the PHP TENANT_DOC_CATS map; anything else the
// server ever returns (a doc_type from an older build) buckets into 'other'.
var DOC_CATS = ['contract','id','other'];
var DOC_CAP  = 20;

function openDocs(tenantId, name) {
  document.getElementById('docTenantId').value = tenantId;
  document.getElementById('docsTitle').textContent = 'Documents — ' + name;
  DOC_CATS.forEach(function(c){
    document.getElementById('docFile-'  + c).value = '';
    document.getElementById('docUrl-'   + c).value = '';
    document.getElementById('docLabel-' + c).value = '';
    // A section can still be mid-upload if the modal was closed and reopened
    // while a batch was in flight: leave its bar up and its controls locked.
    if (!_docBusy[c]) document.getElementById('docProg-' + c).style.display = 'none';
    // Otherwise clear any at-cap disabling left over from the previously opened
    // tenant — loadDocs() reapplies it for this one.
    ['docFile-','docUpBtn-','docLinkBtn-','docUrl-','docLabel-']
      .forEach(p => document.getElementById(p + c).disabled = _docBusy[c]);
    document.getElementById('docList-'  + c).innerHTML =
      '<div class="doc-empty"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…</div>';
  });
  dModal.show();
  loadDocs(tenantId);
}

// Same esc()/safeUrl() pair used in cash.php / expenses.php — keeps DB-stored
// doc names/types/URLs from breaking out of the rendered cell or executing as
// HTML. external_url is user-supplied so the URL-scheme check is load-bearing.
function _docEsc(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}
function _docSafeUrl(u) {
  if (!u) return '';
  var s = String(u).trim();
  if (/^\s*(javascript|data|vbscript|file):/i.test(s)) return '';
  return _docEsc(s);
}

// File-type icon from the stored path (or the link flag). Purely cosmetic —
// the server whitelist is what actually restricts what lands on disk.
function _docIcon(d) {
  if (!d.file_path) return 'fa-link';
  const ext = String(d.file_path).split('.').pop().toLowerCase();
  if (['jpg','jpeg','png','webp','gif'].includes(ext)) return 'fa-file-image';
  if (ext === 'pdf')                                   return 'fa-file-pdf';
  if (['doc','docx'].includes(ext))                    return 'fa-file-word';
  if (['xls','xlsx'].includes(ext))                    return 'fa-file-excel';
  return 'fa-file';
}

// Counts per category, kept in sync with the pills so the client can pre-check
// the cap before starting a multi-file upload.
var _docCounts = {contract:0, id:0, other:0};
// Which sections have a request in flight. Read by loadDocs() so a refresh can
// never re-enable a control mid-upload; see the double-submit guard below.
var _docBusy = {contract:false, id:false, other:false};
var MIN_BUSY_MS = 1000;

function loadDocs(tenantId) {
  apiPost('tenants.php', {action:'get_docs', tenant_id:tenantId}, (err, res) => {
    if (!res.success) {
      DOC_CATS.forEach(c => document.getElementById('docList-'+c).innerHTML =
        '<div class="doc-empty">Could not load documents.</div>');
      return showToast(res.error || 'Failed to load documents.', 'error');
    }
    if (res.cap) DOC_CAP = parseInt(res.cap, 10) || DOC_CAP;
    // An upload that finishes after the modal was closed and reopened on a
    // different tenant must not repaint this tenant's lists — keep the row
    // badge honest and stop there.
    if (String(document.getElementById('docTenantId').value) !== String(tenantId)) {
      _syncDocBadge(tenantId, (res.docs || []).length);
      return;
    }
    const buckets = {contract:[], id:[], other:[]};
    (res.docs || []).forEach(d => {
      (buckets[d.doc_type] !== undefined ? buckets[d.doc_type] : buckets.other).push(d);
    });
    DOC_CATS.forEach(cat => {
      const rows = buckets[cat];
      _docCounts[cat] = rows.length;
      document.getElementById('docCount-' + cat).textContent = rows.length + ' / ' + DOC_CAP;
      // Never re-enable a section that is mid-upload — _docBusy owns it until done.
      const full = (rows.length >= DOC_CAP) || _docBusy[cat];
      ['docFile-','docUpBtn-','docLinkBtn-','docUrl-','docLabel-']
        .forEach(p => document.getElementById(p + cat).disabled = full);

      const list = document.getElementById('docList-' + cat);
      if (!rows.length) { list.innerHTML = '<div class="doc-empty">Nothing here yet.</div>'; return; }
      let html = '';
      rows.forEach(d => {
        const isFile = !!d.file_path;
        const href   = _docSafeUrl(isFile ? d.file_path : d.external_url);
        const open   = href
          ? '<a class="doc-open" href="' + href + '" target="_blank" rel="noopener noreferrer">'
            + (isFile ? 'View' : 'Open') + ' <i class="fa-solid fa-arrow-up-right-from-square fa-2xs"></i></a>'
          : '<span class="doc-meta">unavailable</span>';
        html += '<div class="doc-row">'
          + '<i class="fa-solid ' + _docIcon(d) + ' doc-ico"></i>'
          + '<span class="doc-name cell-trunc" title="' + _docEsc(d.doc_name) + '">' + _docEsc(d.doc_name) + '</span>'
          + '<span class="doc-meta">' + (d.uploader ? _docEsc(d.uploader) : '&#8212;') + '</span>'
          + '<span class="doc-meta">' + (d.created_at ? _docEsc(fmtDateTime(d.created_at)) : '&#8212;') + '</span>'
          + open
          + '<button class="btn-icon danger" title="Remove" data-id="' + parseInt(d.id, 10) + '"'
          + ' onclick="deleteDoc(+this.dataset.id)"><i class="fa-solid fa-trash fa-xs"></i></button>'
          + '</div>';
      });
      list.innerHTML = html;
    });
    _syncDocBadge(tenantId, (res.docs || []).length);
  });
}

// Keep the folder-button bubble honest without a page reload.
function _syncDocBadge(tenantId, total) {
  const btn = document.querySelector('#tenantTable button[data-id="' + parseInt(tenantId, 10) + '"]');
  if (!btn) return;
  let bubble = btn.querySelector('.doc-count');
  if (!total) { if (bubble) bubble.remove(); btn.title = 'Documents'; return; }
  if (!bubble) {
    bubble = document.createElement('span');
    bubble.className = 'doc-count';
    btn.appendChild(bubble);
  }
  bubble.textContent = total;
  btn.title = 'Documents (' + total + ')';
}

// ─── Double-submit guard ─────────────────────────────────────
// Three layers, because a disabled attribute alone is not enough on a slow
// phone: (1) an in-flight flag checked at function entry and set before the
// first `await`, so a second tap that lands in the same tick still bails;
// (2) every control in the section disabled for the duration; (3) the progress
// bar is held on screen for at least MIN_BUSY_MS even when the request returns
// instantly, so there is never a window where the button looks ready but the
// list has not refreshed yet.
const _docSleep = ms => new Promise(r => setTimeout(r, ms));

function _docSetBusy(cat, busy, btnId, busyHtml, idleHtml) {
  ['docFile-','docUpBtn-','docLinkBtn-','docUrl-','docLabel-'].forEach(p => {
    const el = document.getElementById(p + cat);
    if (el) el.disabled = busy;
  });
  const btn = document.getElementById(btnId + cat);
  if (btn) btn.innerHTML = busy ? busyHtml : idleHtml;
}

function _docProgress(cat, pct, text) {
  const p = Math.max(0, Math.min(100, Math.round(pct)));
  document.getElementById('docProgFill-'  + cat).style.width = p + '%';
  document.getElementById('docProgTrack-' + cat).setAttribute('aria-valuenow', p);
  document.getElementById('docProgTxt-'   + cat).textContent = text || '';
}

function _docShowProg(cat, text) {
  _docProgress(cat, 0, text);
  document.getElementById('docProg-' + cat).style.display = '';
}

// One request per file, run sequentially: post_max_size is 35M and PHP's
// max_file_uploads defaults to 20, so a single batched POST would be truncated
// or rejected. Sequential also means a failure names the exact file, and the
// bar can report real bytes sent across the whole batch.
async function uploadDocs(cat) {
  if (_docBusy[cat]) return;                       // re-entrancy guard — first line, no awaits above it
  const tenantId = document.getElementById('docTenantId').value;
  const input    = document.getElementById('docFile-' + cat);
  const files    = Array.from(input.files || []);
  if (!files.length) return showToast('Choose one or more files first.', 'error');
  if (!validateFileSize(input)) return;
  if (_docCounts[cat] + files.length > DOC_CAP) {
    return showToast('That would exceed ' + DOC_CAP + ' items in this category ('
      + _docCounts[cat] + ' already saved, ' + files.length + ' selected).', 'error');
  }

  _docBusy[cat] = true;
  const startedAt  = Date.now();
  const idleHtml   = '<i class="fa-solid fa-upload me-1"></i>Upload';
  _docSetBusy(cat, true, 'docUpBtn-', '<i class="fa-solid fa-spinner fa-spin me-1"></i>Uploading…', idleHtml);
  _docShowProg(cat, 'Preparing ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '…');

  const totalBytes = files.reduce((s, f) => s + f.size, 0) || 1;
  let sentBytes = 0, done = 0, failed = null;

  for (const file of files) {
    const nth = 'File ' + (done + 1) + ' of ' + files.length + ' — ' + file.name;
    const fd = new FormData();
    fd.append('action', 'upload_doc');
    fd.append('tenant_id', tenantId);
    fd.append('doc_type', cat);
    fd.append('doc_file', file);
    const res = await new Promise(resolve => apiPost('tenants.php', fd,
      (e, r) => resolve(r),
      // Cap the in-flight bar at 99% — 100% is reserved for "server said OK",
      // otherwise a slow server response looks finished while it isn't.
      loaded => _docProgress(cat, Math.min(99, (sentBytes + loaded) / totalBytes * 100), nth)
    ));
    if (!res || !res.success) { failed = (res && res.error) || 'Upload failed'; break; }
    done++;
    sentBytes += file.size;
    _docProgress(cat, Math.min(99, sentBytes / totalBytes * 100), nth);
  }

  _docProgress(cat, failed ? (sentBytes / totalBytes * 100) : 100,
    done + ' of ' + files.length + ' uploaded' + (failed ? ' — stopped on an error' : ''));

  // Hold the finished bar for a beat. On a fast connection the whole batch can
  // land in under 100 ms; without this the button would flick back to "Upload"
  // before a double-tap has even landed.
  await _docSleep(Math.max(0, MIN_BUSY_MS - (Date.now() - startedAt)));

  document.getElementById('docProg-' + cat).style.display = 'none';
  input.value = '';
  _docSetBusy(cat, false, 'docUpBtn-', '', idleHtml);
  _docBusy[cat] = false;
  if (failed) showToast(files[done].name + ': ' + failed, 'error');
  if (done)   showToast(done + ' file' + (done === 1 ? '' : 's') + ' uploaded.', 'success');
  loadDocs(tenantId);
}

async function addDocLink(cat) {
  if (_docBusy[cat]) return;                       // same guard — a link row is one INSERT, so a double-tap would duplicate it
  const tenantId = document.getElementById('docTenantId').value;
  const urlEl    = document.getElementById('docUrl-' + cat);
  const labelEl  = document.getElementById('docLabel-' + cat);
  const url      = urlEl.value.trim();
  if (!url) return showToast('Paste a link first.', 'error');

  _docBusy[cat] = true;
  const startedAt = Date.now();
  const idleHtml  = '<i class="fa-solid fa-link me-1"></i>Add link';
  _docSetBusy(cat, true, 'docLinkBtn-', '<i class="fa-solid fa-spinner fa-spin me-1"></i>Adding…', idleHtml);
  _docShowProg(cat, 'Saving link…');
  _docProgress(cat, 35, 'Saving link…');           // no bytes to measure — a short determinate sweep

  const res = await new Promise(resolve => apiPost('tenants.php',
    {action:'upload_doc', tenant_id:tenantId, doc_type:cat, external_url:url, label:labelEl.value},
    (e, r) => resolve(r)));

  _docProgress(cat, 100, (res && res.success) ? 'Link saved.' : 'Could not save the link.');
  await _docSleep(Math.max(0, MIN_BUSY_MS - (Date.now() - startedAt)));

  document.getElementById('docProg-' + cat).style.display = 'none';
  _docSetBusy(cat, false, 'docLinkBtn-', '', idleHtml);
  _docBusy[cat] = false;
  if (!res || !res.success) return showToast((res && res.error) || 'Could not save the link.', 'error');
  urlEl.value = ''; labelEl.value = '';
  showToast(res.msg, 'success');
  loadDocs(tenantId);
}

function deleteDoc(id) {
  const tenantId = document.getElementById('docTenantId').value;
  confirmDelete('Remove this document?', ()=>{
    apiPost('tenants.php', {action:'delete_doc', id, tenant_id: tenantId}, (err,res) => {
      if (!res.success) return showToast(res.error,'error');
      showToast(res.msg,'success');
      loadDocs(tenantId);
    });
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
