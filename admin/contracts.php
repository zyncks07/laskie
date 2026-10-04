<?php
// admin/contracts.php — lease-contract register: active / expired / drafts /
// voided, signed-copy uploads, approve / void / renew. The generator itself
// is admin/contract_edit.php; the document is admin/contract_print.php.
//
// A contract never changes billing (CLAUDE.md §4 contracts): rent keeps being
// generated from the tenant record. Disagreements are flagged, not synced.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/contract_functions.php';
requireAdmin();
$pageTitle = 'Contracts';
$depth = '../';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!defined('JSON_RESPONSE')) define('JSON_RESPONSE', true);
    csrfRequirePost();
    $action = $_POST['action'] ?? '';
    $uid    = (int)$_SESSION['user']['id'];

    $loadContract = function (int $id) use ($pdo): array {
        $q = $pdo->prepare("SELECT * FROM contracts WHERE id=?");
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) jsonErr('Contract not found.', 404);
        return $row;
    };
    $ref = fn(array $r) => $r['contract_no'] ?: ('draft #' . $r['id']);

    if ($action === 'approve') {
        $res = contractApprove($pdo, (int)($_POST['id'] ?? 0));
        if (!$res['ok']) jsonErr($res['error']);
        jsonOk(['msg' => 'Approved as ' . $res['contract_no'] . '.', 'contract_no' => $res['contract_no']]);
    }

    if ($action === 'cancel') {
        $row    = $loadContract((int)($_POST['id'] ?? 0));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($row['status'] !== 'approved') jsonErr('Only an approved contract can be voided.');
        if (mb_strlen($reason) < 3) jsonErr('Give a reason for voiding this contract.');
        $reason = mb_substr($reason, 0, 255);
        $pdo->prepare("UPDATE contracts SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, updated_by=? WHERE id=? AND status='approved'")
            ->execute([$uid, $reason, $uid, (int)$row['id']]);
        logChange($pdo, 'CANCEL_CONTRACT', 'Contracts',
            ['id' => (int)$row['id'], 'contract_no' => $row['contract_no'], 'status' => 'approved'],
            ['id' => (int)$row['id'], 'contract_no' => $row['contract_no'], 'status' => 'cancelled', 'reason' => $reason]);
        jsonOk(['msg' => $row['contract_no'] . ' voided.']);
    }

    if ($action === 'delete_draft') {
        $row = $loadContract((int)($_POST['id'] ?? 0));
        if ($row['status'] !== 'draft') jsonErr('Only drafts can be deleted — void an approved contract instead.');
        // A draft never has signed copies (uploads need approval), but clear
        // any defensively before the cascade removes their rows.
        $f = $pdo->prepare("SELECT file_path FROM contract_files WHERE contract_id=?");
        $f->execute([(int)$row['id']]);
        foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            if ($fp && str_starts_with((string)$fp, '/uploads/')) @unlink(__DIR__ . '/..' . $fp);
        }
        $pdo->prepare("DELETE FROM contracts WHERE id=? AND status='draft'")->execute([(int)$row['id']]);
        logActivity($pdo, 'DELETE_CONTRACT_DRAFT', 'Contracts', 'Deleted draft #' . $row['id'] . ' (' . $row['lessee_name'] . ', ' . $row['unit_label'] . ')');
        jsonOk(['msg' => 'Draft deleted.']);
    }

    // Copy any contract into a new draft. mode=renew rolls the term forward:
    // starts the day after the old one ends, same length, today's signing date.
    if ($action === 'duplicate') {
        $row    = $loadContract((int)($_POST['id'] ?? 0));
        $renew  = ($_POST['mode'] ?? '') === 'renew';
        $fields = contractFields($row);
        $fields['contract_date'] = date('Y-m-d');
        if ($renew && $fields['end_date']) {
            $months = 12;
            if ($fields['start_date']) {
                $a = new DateTimeImmutable($fields['start_date']);
                $b = (new DateTimeImmutable($fields['end_date']))->modify('+1 day');
                $months = max(1, ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + (int)$b->format('n') - (int)$a->format('n'));
            }
            $fields['start_date'] = (new DateTimeImmutable($fields['end_date']))->modify('+1 day')->format('Y-m-d');
            $fields['end_date']   = contractDefaultEnd($fields['start_date'], $months);
            $fields['term_words'] = contractTermWords($fields['start_date'], $fields['end_date']);
        }
        $fields = contractNormalizeFields($fields);
        $pdo->prepare(
            "INSERT INTO contracts (template_key, template_version, tenant_id, unit_id, lessee_name, unit_label,
                                    start_date, end_date, monthly_rent, fields_json, status, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?)"
        )->execute([
            CONTRACT_TEMPLATE_KEY, CONTRACT_TEMPLATE_VERSION, $row['tenant_id'], $row['unit_id'],
            mb_substr($fields['lessee_name'], 0, 120), mb_substr($fields['unit_no'], 0, 120),
            $fields['start_date'] ?: null, $fields['end_date'] ?: null, $fields['monthly_rent'] ?: '0.00',
            json_encode($fields, JSON_UNESCAPED_UNICODE), $uid, $uid,
        ]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $renew ? 'RENEW_CONTRACT' : 'DUPLICATE_CONTRACT', 'Contracts',
            ($renew ? 'Renewal draft #' : 'Copied into draft #') . $newId . ' from ' . $ref($row));
        jsonOk(['msg' => $renew ? 'Renewal draft created.' : 'Copied into a new draft.', 'id' => $newId]);
    }

    if ($action === 'get_files') {
        $row = $loadContract((int)($_POST['contract_id'] ?? 0));
        $q = $pdo->prepare("SELECT cf.id, cf.file_path, cf.doc_name, cf.created_at, u.full_name AS uploader
                            FROM contract_files cf LEFT JOIN users u ON u.id = cf.uploaded_by
                            WHERE cf.contract_id=? ORDER BY cf.created_at, cf.id");
        $q->execute([(int)$row['id']]);
        jsonOk(['files' => $q->fetchAll(), 'cap' => CONTRACT_FILE_CAP, 'can_upload' => $row['status'] === 'approved']);
    }

    // One file per request — the client loops a multi-file selection, same as
    // the tenant Documents modal (post_max_size / max_file_uploads limits).
    if ($action === 'upload_file') {
        $row = $loadContract((int)($_POST['contract_id'] ?? 0));
        if ($row['status'] !== 'approved') {
            jsonErr($row['status'] === 'draft'
                ? 'Approve the contract first — signed copies attach to an approved contract.'
                : 'This contract is voided; no new signed copies can be attached.');
        }
        if (empty($_FILES['file']['name'])) jsonErr('Choose a file to upload.');
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM contract_files WHERE contract_id=?");
        $cnt->execute([(int)$row['id']]);
        if ((int)$cnt->fetchColumn() >= CONTRACT_FILE_CAP) {
            jsonErr('Limit reached — ' . CONTRACT_FILE_CAP . ' files per contract. Remove one first.');
        }
        // handleUpload() compresses JPEG/PNG/WebP in place via compressImage(),
        // here with the sharper document profile so signatures stay legible.
        $up = handleUpload('file', CONTRACT_UPLOAD_SUBDIR, CONTRACT_FILE_EXTS, null, false, CONTRACT_SCAN_COMPRESSION);
        if ($up['error']) jsonErr($up['error']);
        $name = mb_substr(basename((string)$_FILES['file']['name']), 0, 200);
        $pdo->prepare("INSERT INTO contract_files (contract_id, file_path, doc_name, uploaded_by) VALUES (?,?,?,?)")
            ->execute([(int)$row['id'], $up['path'], $name, $uid]);
        logActivity($pdo, 'UPLOAD_SIGNED_CONTRACT', 'Contracts', "Attached signed copy '$name' to " . $ref($row));
        jsonOk(['msg' => 'File uploaded.']);
    }

    if ($action === 'delete_file') {
        $fileId     = (int)($_POST['id'] ?? 0);
        $contractId = (int)($_POST['contract_id'] ?? 0);
        // Scoped to the contract so a guessed file id can't reach another one.
        $q = $pdo->prepare("SELECT cf.file_path, cf.doc_name, c.contract_no, c.id AS cid
                            FROM contract_files cf JOIN contracts c ON c.id = cf.contract_id
                            WHERE cf.id=? AND cf.contract_id=?");
        $q->execute([$fileId, $contractId]);
        $f = $q->fetch();
        if (!$f) jsonErr('File not found.');
        if ($f['file_path'] && str_starts_with((string)$f['file_path'], '/uploads/')) {
            @unlink(__DIR__ . '/..' . $f['file_path']);
        }
        $pdo->prepare("DELETE FROM contract_files WHERE id=? AND contract_id=?")->execute([$fileId, $contractId]);
        logActivity($pdo, 'DELETE_SIGNED_CONTRACT', 'Contracts', "Removed signed copy '{$f['doc_name']}' from " . ($f['contract_no'] ?: 'draft #' . $f['cid']));
        jsonOk(['msg' => 'File removed.']);
    }

    jsonErr('Unknown action.');
}

// ─── Page data ───────────────────────────────────────────────
$today = date('Y-m-d');
$rows = $pdo->query(
    "SELECT c.*, t.full_name AS t_name, t.unit_id AS t_unit_id, t.contract_start AS t_start,
            t.contract_end AS t_end, t.status AS t_status,
            (SELECT COUNT(*) FROM contract_files cf WHERE cf.contract_id = c.id) AS file_count
     FROM contracts c
     LEFT JOIN tenants t ON t.id = c.tenant_id
     ORDER BY (c.status='draft') DESC, c.end_date DESC, c.id DESC"
)->fetchAll();

$stats = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'draft' => 0];
foreach ($rows as &$r) {
    $r['state'] = contractDisplayState($r, $today);
    $r['tab']   = match ($r['state']) {
        'active', 'upcoming', 'expiring' => 'active',
        'expired'   => 'expired',
        'cancelled' => 'voided',
        default     => 'drafts',
    };
    if (in_array($r['state'], ['active', 'upcoming', 'expiring'], true)) $stats['active']++;
    if ($r['state'] === 'expiring') $stats['expiring']++;
    if ($r['state'] === 'expired')  $stats['expired']++;
    if ($r['state'] === 'draft')    $stats['draft']++;
    $r['mismatch'] = ($r['status'] === 'approved' && $r['tab'] === 'active' && $r['tenant_id'])
        ? contractTenantMismatches($r, ['unit_id' => $r['t_unit_id'], 'contract_start' => $r['t_start'],
                                        'contract_end' => $r['t_end'], 'status' => $r['t_status']])
        : [];
}
unset($r);
$tabCounts = array_count_values(array_column($rows, 'tab')) + ['active' => 0, 'expired' => 0, 'drafts' => 0, 'voided' => 0];

// Active tenants without a running (approved, not yet expired) contract.
$uncovered = $pdo->query(
    "SELECT t.id, t.full_name, t.contract_start, t.contract_end, ru.unit_name,
            (SELECT c.id FROM contracts c WHERE c.tenant_id = t.id AND c.status='draft' ORDER BY c.id DESC LIMIT 1) AS draft_id
     FROM tenants t
     LEFT JOIN rental_units ru ON ru.id = t.unit_id
     WHERE t.status = 'active'
       AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.tenant_id = t.id AND c.status='approved' AND c.end_date >= CURDATE())
     ORDER BY ru.unit_name, t.full_name"
)->fetchAll();

logActivity($pdo, 'VIEW_CONTRACTS', 'Contracts', 'Viewed contracts register');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-file-signature me-2 text-primary-custom"></i>Contracts</h1>
  <a class="btn btn-primary btn-sm" href="contract_edit.php"><i class="fa-solid fa-plus me-1"></i>New Contract</a>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['Active',            $stats['active'],   'fa-file-circle-check', 'green', 'Approved & running'],
    ['Expiring ≤ ' . CONTRACT_EXPIRING_DAYS . ' days', $stats['expiring'], 'fa-hourglass-end', 'amber', 'Two-month notice window'],
    ['Expired',           $stats['expired'],  'fa-box-archive',       'red',   'Past their end date'],
    ['Drafts',            $stats['draft'],    'fa-pen-ruler',         'blue',  'Awaiting approval'],
  ] as [$lbl, $val, $ico, $col, $sub]): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon <?= $col ?>"><i class="fa-solid <?= $ico ?>"></i></div>
      <div class="stat-body">
        <div class="stat-label"><?= clean($lbl) ?></div>
        <div class="stat-value"><?= (int)$val ?></div>
        <div class="stat-sub"><?= clean($sub) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($uncovered): ?>
<div class="card mb-3">
  <div class="card-header ct-uncov-head" role="button" tabindex="0" data-bs-toggle="collapse" data-bs-target="#uncovBody" aria-expanded="false">
    <span class="card-header-title"><i class="fa-solid fa-user-clock me-2"></i>Active tenants without a running contract</span>
    <span class="d-flex align-items-center gap-2">
      <span class="attn-pill"><?= count($uncovered) ?></span>
      <i class="fa-solid fa-chevron-down ct-chev"></i>
    </span>
  </div>
  <div class="collapse" id="uncovBody">
    <div class="card-body ct-uncov">
      <?php foreach ($uncovered as $u): ?>
      <div class="ct-uncov-row">
        <div class="ct-uncov-name">
          <div class="fw-600 cell-trunc"><?= clean($u['full_name']) ?></div>
          <div class="ct-sub"><?= clean($u['unit_name'] ?? 'No unit') ?>
            <?php if ($u['contract_start']): ?> · tenant record <?= fmtDate($u['contract_start'], 'M j, Y') ?> – <?= $u['contract_end'] ? fmtDate($u['contract_end'], 'M j, Y') : 'open' ?><?php endif; ?></div>
        </div>
        <?php if ($u['draft_id']): ?>
          <a class="btn btn-secondary btn-sm" href="contract_edit.php?id=<?= (int)$u['draft_id'] ?>"><i class="fa-solid fa-pen me-1"></i>Continue draft</a>
        <?php else: ?>
          <a class="btn btn-primary btn-sm" href="contract_edit.php?tenant_id=<?= (int)$u['id'] ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" id="ctTabs">
  <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'drafts' => 'Drafts', 'voided' => 'Voided', 'all' => 'All'] as $k => $lbl): ?>
  <li class="nav-item"><a class="nav-link <?= $k === 'active' ? 'active' : '' ?>" href="#" data-tab="<?= $k ?>"><?= $lbl ?>
    <span class="muted-pill ms-1"><?= $k === 'all' ? count($rows) : (int)$tabCounts[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table" id="ctTable">
      <thead><tr>
        <th>Contract</th><th>Lessee</th><th>Unit</th><th>Term</th><th class="text-end">Rent</th>
        <th>Status</th><th class="text-center">Signed</th><th class="text-center">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        [$stLbl, $stCls] = contractStateMeta($r['state']);
        $daysLeft = $r['end_date'] ? contractDaysLeft($r['end_date'], $today) : null;
      ?>
      <tr data-tab="<?= $r['tab'] ?>">
        <td data-order="<?= clean($r['contract_no'] ?: 'zz' . str_pad((string)$r['id'], 8, '0', STR_PAD_LEFT)) ?>">
          <?php if ($r['contract_no']): ?>
            <span class="mono fw-600 text-nowrap"><?= clean($r['contract_no']) ?></span>
          <?php else: ?>
            <span class="ct-sub">Draft #<?= (int)$r['id'] ?></span>
          <?php endif; ?>
          <div class="ct-sub"><?= $r['approved_at'] ? 'Approved ' . fmtDate($r['approved_at'], 'M j, Y') : 'Edited ' . fmtDate($r['updated_at'], 'M j, Y') ?></div>
        </td>
        <td>
          <div class="fw-600 cell-trunc"><?= clean($r['lessee_name'] ?: '—') ?></div>
          <?php if ($r['mismatch']): ?>
            <a href="tenants.php" class="ct-warn" title="<?= clean(implode("\n", $r['mismatch'])) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Differs from tenant record</a>
          <?php elseif (!$r['tenant_id'] && $r['status'] !== 'cancelled'): ?>
            <div class="ct-sub">Not linked to a tenant</div>
          <?php endif; ?>
        </td>
        <td><?= clean($r['unit_label'] ?: '—') ?></td>
        <td data-order="<?= clean((string)$r['end_date']) ?>" class="text-nowrap">
          <?php if ($r['start_date']): ?>
            <?= fmtDate($r['start_date'], 'M j, Y') ?> – <?= $r['end_date'] ? fmtDate($r['end_date'], 'M j, Y') : '…' ?>
          <?php else: ?>—<?php endif; ?>
          <?php if ($r['state'] === 'expiring' || $r['state'] === 'active'): ?>
            <div class="ct-sub"><?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> left</div>
          <?php elseif ($r['state'] === 'upcoming'): ?>
            <div class="ct-sub">Starts in <?= contractDaysLeft($r['start_date'], $today) ?> days</div>
          <?php elseif ($r['state'] === 'cancelled' && $r['cancel_reason']): ?>
            <div class="ct-sub cell-trunc" title="<?= clean($r['cancel_reason']) ?>">Void: <?= clean($r['cancel_reason']) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap" data-order="<?= clean((string)$r['monthly_rent']) ?>"><?= money($r['monthly_rent']) ?></td>
        <td><span class="<?= $stCls ?>"><?= clean($stLbl) ?></span></td>
        <td class="text-center">
          <?php if ($r['status'] !== 'draft'): ?>
          <button class="btn-icon ct-files-btn" title="Signed copies" data-id="<?= (int)$r['id'] ?>"
                  data-label="<?= clean(($r['contract_no'] ?: 'Draft') . ' — ' . $r['lessee_name']) ?>"
                  onclick="openFiles(+this.dataset.id, this.dataset.label)">
            <i class="fa-solid <?= (int)$r['file_count'] ? 'fa-file-image' : 'fa-upload' ?> fa-xs"></i><?php if ((int)$r['file_count']): ?><span class="doc-count"><?= (int)$r['file_count'] ?></span><?php endif; ?>
          </button>
          <?php else: ?><span class="ct-sub">—</span><?php endif; ?>
        </td>
        <td class="text-center text-nowrap">
          <?php if ($r['status'] === 'draft'): ?>
            <a class="btn-icon" title="Edit draft" href="contract_edit.php?id=<?= (int)$r['id'] ?>"><i class="fa-solid fa-pen fa-xs"></i></a>
            <a class="btn-icon" title="Preview" href="contract_print.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye fa-xs"></i></a>
            <button class="btn-icon" title="Approve" onclick="approveContract(<?= (int)$r['id'] ?>)"><i class="fa-solid fa-circle-check fa-xs"></i></button>
            <button class="btn-icon danger" title="Delete draft" onclick="deleteDraft(<?= (int)$r['id'] ?>)"><i class="fa-solid fa-trash fa-xs"></i></button>
          <?php else: ?>
            <a class="btn-icon" title="View" href="contract_print.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye fa-xs"></i></a>
            <a class="btn-icon" title="Download PDF" href="contract_pdf_download.php?id=<?= (int)$r['id'] ?>" data-pdf-download="Generating contract PDF"><i class="fa-solid fa-file-pdf fa-xs"></i></a>
            <?php if ($r['status'] === 'approved'): ?>
              <button class="btn-icon" title="Renew — new draft starting <?= $r['end_date'] ? clean(fmtDate(date('Y-m-d', strtotime($r['end_date'] . ' +1 day')), 'M j, Y')) : 'after this one' ?>" onclick="duplicateContract(<?= (int)$r['id'] ?>,'renew')"><i class="fa-solid fa-rotate fa-xs"></i></button>
              <button class="btn-icon danger" title="Void" data-id="<?= (int)$r['id'] ?>" data-no="<?= clean($r['contract_no']) ?>" onclick="openVoid(+this.dataset.id, this.dataset.no)"><i class="fa-solid fa-ban fa-xs"></i></button>
            <?php else: ?>
              <button class="btn-icon" title="Copy into a new draft" onclick="duplicateContract(<?= (int)$r['id'] ?>,'copy')"><i class="fa-solid fa-copy fa-xs"></i></button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Signed copies modal — same upload pattern as the tenant Documents modal -->
<div class="modal fade" id="filesModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filesTitle">Signed copies</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="filesContractId">
        <div id="filesUploadBox">
          <div class="doc-ctl">
            <input type="file" class="form-control form-control-sm" id="ctFile" multiple
                   accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="validateFileSize(this)">
            <button class="btn btn-primary btn-sm doc-btn" id="ctUpBtn" onclick="uploadFiles()"><i class="fa-solid fa-upload me-1"></i>Upload</button>
          </div>
          <div class="form-text">Photos or scans of every signed page — pick several at once. JPG/PNG/WebP/PDF, max 30&nbsp;MB each; photos are auto-compressed (kept sharp enough to read signatures).</div>
          <div class="doc-prog" id="ctProg" style="display:none" aria-live="polite">
            <div class="doc-prog-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ctProgTrack"><span id="ctProgFill"></span></div>
            <div class="doc-prog-txt" id="ctProgTxt"></div>
          </div>
        </div>
        <div class="ct-note" id="filesLocked" style="display:none"></div>
        <div class="ct-gallery" id="filesList"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="ct-sub" id="filesCount"></span>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Void modal -->
<div class="modal fade" id="voidModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="voidTitle">Void contract</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="voidId">
        <p class="mb-2" style="font-size:13px">Voiding keeps the contract and its number on record, marked VOID. It does not change the tenant record or billing. To correct a contract, void it and use <em>Copy into a new draft</em>.</p>
        <label class="form-label">Reason *</label>
        <textarea class="form-control" id="voidReason" rows="2" maxlength="255" placeholder="e.g. Wrong unit number — reissued"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger btn-sm" id="voidBtn" onclick="confirmVoid()"><i class="fa-solid fa-ban me-1"></i>Void contract</button>
      </div>
    </div>
  </div>
</div>

<style>
.ct-sub { font-size: 11.5px; color: var(--gray-500); }
.ct-note { font-size: 12.5px; padding: 8px 12px; border-radius: var(--radius); background: var(--gray-100); border: 1px solid var(--gray-200); color: var(--ink); }
.ct-warn { display: inline-block; font-size: 11px; font-weight: 600; color: var(--ink); text-decoration: none; border-bottom: 1px dashed var(--gray-400); }
.ct-uncov-head { cursor: pointer; user-select: none; }
.ct-chev { font-size: 11px; color: var(--gray-500); transition: transform .18s ease; }
.ct-uncov-head[aria-expanded="true"] .ct-chev { transform: rotate(180deg); }
.ct-uncov { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; }
.ct-uncov-row { display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 8px 10px; border: 1px solid var(--gray-200); border-radius: var(--radius); min-width: 0; }
.ct-uncov-name { min-width: 0; }
#ctTabs .nav-link { display: inline-flex; align-items: center; }
#ctTable .btn-icon { position: relative; }
/* Shared with admin/tenants.php's Documents modal */
.doc-ctl { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.doc-ctl .form-control { flex: 1 1 180px; min-width: 0; }
.doc-btn { flex: 0 0 auto; white-space: nowrap; }
.doc-prog { margin-top: 10px; }
.doc-prog-track { height: 6px; border-radius: 999px; overflow: hidden; background: var(--gray-200); }
.doc-prog-track > span { display: block; height: 100%; width: 0; border-radius: 999px; background: var(--ink); transition: width .25s ease; }
.doc-prog-txt { margin-top: 5px; font-size: 11px; color: var(--gray-500); }
.doc-count { position: absolute; top: -4px; right: -4px; min-width: 15px; height: 15px; padding: 0 3px;
  border-radius: 8px; background: var(--ink); color: var(--paper); font-size: 9.5px; line-height: 15px; font-weight: 700; text-align: center; }
/* Signed-copy gallery: thumbnails for images, a tile for PDFs */
.ct-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; margin-top: 14px; }
.ct-tile { border: 1px solid var(--gray-200); border-radius: var(--radius); overflow: hidden; background: var(--paper); display: flex; flex-direction: column; }
.ct-thumb { display: flex; align-items: center; justify-content: center; aspect-ratio: 3 / 4; background: var(--gray-100); color: var(--gray-500); font-size: 26px; text-decoration: none; }
.ct-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ct-tile-foot { display: flex; align-items: center; gap: 6px; padding: 5px 6px; }
.ct-tile-name { flex: 1 1 auto; min-width: 0; font-size: 11px; font-weight: 600; }
.ct-tile-meta { font-size: 10px; color: var(--gray-500); padding: 0 6px 6px; }
.ct-empty { grid-column: 1 / -1; font-size: 12px; color: var(--gray-500); padding: 6px 2px; }
@media (max-width: 576px) {
  .doc-ctl .form-control, .doc-btn { flex: 1 1 100%; }
  .ct-uncov { grid-template-columns: 1fr; }
  .ct-uncov-row { flex-wrap: wrap; }
  .ct-gallery { grid-template-columns: repeat(2, 1fr); }
}
</style>

<?php $extraJs = <<<'JS'
<script>
var fModal, vModal, ctTable, ctTab = 'active';
document.addEventListener('DOMContentLoaded', function () {
  fModal = new bootstrap.Modal(document.getElementById('filesModal'));
  vModal = new bootstrap.Modal(document.getElementById('voidModal'));
  // Signed-copy counts change while the modal is open; refresh the row
  // bubble from the server on close rather than reloading the page.
  document.getElementById('filesModal').addEventListener('hidden.bs.modal', function () {
    if (_ctDirty) location.reload();
  });
  var head = document.querySelector('.ct-uncov-head');
  if (head) head.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); head.click(); }
  });
});

$(document).ready(function () {
  // Tab filter as a DataTables search plugin so paging/counts stay correct.
  $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'ctTable' || ctTab === 'all') return true;
    var tr = settings.aoData[dataIndex].nTr;
    return tr && tr.getAttribute('data-tab') === ctTab;
  });
  ctTable = $('#ctTable').DataTable(Object.assign({}, dtDefaults, {
    order: [[3, 'asc']],
    columnDefs: [{ orderable: false, targets: [6, 7] }],
    language: Object.assign({}, dtDefaults.language, { emptyTable: 'No contracts yet — use New Contract or Generate.', zeroRecords: 'Nothing in this list.' })
  }));
  document.querySelectorAll('#ctTabs .nav-link').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      document.querySelectorAll('#ctTabs .nav-link').forEach(function (x) { x.classList.remove('active'); });
      a.classList.add('active');
      ctTab = a.dataset.tab;
      // Expired / voided read best newest-first; active soonest-ending first.
      ctTable.order([[3, (ctTab === 'active' || ctTab === 'drafts') ? 'asc' : 'desc']]).draw();
    });
  });
  ctTable.draw();
});

function approveContract(id) {
  confirmDelete('Approve this contract?\n\nIt will be locked against edits and given a contract number. It does NOT change the tenant record or billing.', function () {
    apiPost('contracts.php', { action: 'approve', id: id }, function (err, res) {
      if (!res.success) return showToast(res.error, 'error');
      showToast(res.msg, 'success'); setTimeout(function () { location.reload(); }, 700);
    });
  });
}

function deleteDraft(id) {
  confirmDelete('Delete this draft? This cannot be undone.', function () {
    apiPost('contracts.php', { action: 'delete_draft', id: id }, function (err, res) {
      if (!res.success) return showToast(res.error, 'error');
      showToast(res.msg, 'success'); setTimeout(function () { location.reload(); }, 600);
    });
  });
}

function duplicateContract(id, mode) {
  apiPost('contracts.php', { action: 'duplicate', id: id, mode: mode }, function (err, res) {
    if (!res.success) return showToast(res.error, 'error');
    showToast(res.msg, 'success');
    location.href = 'contract_edit.php?id=' + parseInt(res.id, 10);
  });
}

function openVoid(id, no) {
  document.getElementById('voidId').value = id;
  document.getElementById('voidTitle').textContent = 'Void ' + no;
  document.getElementById('voidReason').value = '';
  vModal.show();
}
var _voidBusy = false;
function confirmVoid() {
  if (_voidBusy) return;
  var reason = document.getElementById('voidReason').value.trim();
  if (reason.length < 3) return showToast('Give a reason for voiding.', 'error');
  _voidBusy = true; document.getElementById('voidBtn').disabled = true;
  apiPost('contracts.php', { action: 'cancel', id: document.getElementById('voidId').value, reason: reason }, function (err, res) {
    _voidBusy = false; document.getElementById('voidBtn').disabled = false;
    if (!res.success) return showToast(res.error, 'error');
    vModal.hide(); showToast(res.msg, 'success'); setTimeout(function () { location.reload(); }, 600);
  });
}

// ─── Signed copies ───────────────────────────────────────────
var CT_CAP = 30, _ctCount = 0, _ctBusy = false, _ctDirty = false, MIN_BUSY_MS = 1000;

function _esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s != null ? String(s) : '')); return d.innerHTML; }
// file_path is server-generated (/uploads/…), but keep the scheme guard the
// other attachment lists use so a bad row can never become a script URL.
function _safeUrl(u) {
  if (!u) return '';
  var s = String(u).trim();
  if (/^\s*(javascript|data|vbscript|file):/i.test(s)) return '';
  return _esc(s);
}

function openFiles(id, label) {
  document.getElementById('filesContractId').value = id;
  document.getElementById('filesTitle').textContent = 'Signed copies — ' + label;
  document.getElementById('ctFile').value = '';
  if (!_ctBusy) document.getElementById('ctProg').style.display = 'none';
  document.getElementById('filesList').innerHTML = '<div class="ct-empty"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading…</div>';
  fModal.show();
  loadFiles(id);
}

function loadFiles(id) {
  apiPost('contracts.php', { action: 'get_files', contract_id: id }, function (err, res) {
    if (String(document.getElementById('filesContractId').value) !== String(id)) return;
    var list = document.getElementById('filesList');
    if (!res.success) { list.innerHTML = '<div class="ct-empty">Could not load files.</div>'; return showToast(res.error || 'Failed to load files.', 'error'); }
    CT_CAP = parseInt(res.cap, 10) || CT_CAP;
    var files = res.files || [];
    _ctCount = files.length;
    document.getElementById('filesCount').textContent = files.length + ' / ' + CT_CAP + ' files';
    var box = document.getElementById('filesUploadBox'), lock = document.getElementById('filesLocked');
    box.style.display = res.can_upload ? '' : 'none';
    lock.style.display = res.can_upload ? 'none' : '';
    lock.textContent = res.can_upload ? '' : 'This contract is voided — existing signed copies stay viewable, but no new ones can be attached.';
    var full = files.length >= CT_CAP || _ctBusy;
    document.getElementById('ctFile').disabled = full;
    document.getElementById('ctUpBtn').disabled = full;
    if (!files.length) { list.innerHTML = '<div class="ct-empty">No signed copies yet. Upload a photo or scan of each signed page.</div>'; return; }
    var html = '';
    files.forEach(function (f, i) {
      var href = _safeUrl(f.file_path);
      var ext = String(f.file_path || '').split('.').pop().toLowerCase();
      var isImg = ['jpg', 'jpeg', 'png', 'webp', 'gif'].indexOf(ext) !== -1;
      html += '<div class="ct-tile">'
        + '<a class="ct-thumb" href="' + href + '" target="_blank" rel="noopener noreferrer" title="Open">'
        + (isImg && href
            ? '<img src="' + href + '" alt="Page ' + (i + 1) + '" loading="lazy" onerror="this.outerHTML=\'<i class=&quot;fa-solid fa-image&quot;></i>\'">'
            : '<i class="fa-solid fa-file-pdf"></i>')
        + '</a>'
        + '<div class="ct-tile-foot"><span class="ct-tile-name cell-trunc" title="' + _esc(f.doc_name) + '">' + _esc(f.doc_name || ('Page ' + (i + 1))) + '</span>'
        + '<button class="btn-icon danger" title="Remove" data-id="' + parseInt(f.id, 10) + '" onclick="deleteFile(+this.dataset.id)"><i class="fa-solid fa-trash fa-xs"></i></button></div>'
        + '<div class="ct-tile-meta">' + _esc(f.uploader || '—') + ' · ' + _esc(f.created_at ? fmtDateTime(f.created_at) : '') + '</div>'
        + '</div>';
    });
    list.innerHTML = html;
  });
}

function _ctProgress(pct, text) {
  var p = Math.max(0, Math.min(100, Math.round(pct)));
  document.getElementById('ctProgFill').style.width = p + '%';
  document.getElementById('ctProgTrack').setAttribute('aria-valuenow', p);
  document.getElementById('ctProgTxt').textContent = text || '';
}
var _ctSleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

// Sequential, one request per file — same reasoning and double-submit guard as
// the tenant Documents modal: re-entrancy flag first, controls disabled, and
// the bar held for MIN_BUSY_MS so a double tap can't land on a "ready" button.
async function uploadFiles() {
  if (_ctBusy) return;
  var id = document.getElementById('filesContractId').value;
  var input = document.getElementById('ctFile');
  var files = Array.from(input.files || []);
  if (!files.length) return showToast('Choose one or more files first.', 'error');
  if (!validateFileSize(input)) return;
  if (_ctCount + files.length > CT_CAP) {
    return showToast('That would exceed ' + CT_CAP + ' files (' + _ctCount + ' saved, ' + files.length + ' selected).', 'error');
  }
  _ctBusy = true;
  var startedAt = Date.now(), btn = document.getElementById('ctUpBtn');
  input.disabled = true; btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Uploading…';
  document.getElementById('ctProg').style.display = '';
  _ctProgress(0, 'Preparing ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '…');

  var totalBytes = files.reduce(function (s, f) { return s + f.size; }, 0) || 1;
  var sent = 0, done = 0, failed = null;
  for (var i = 0; i < files.length; i++) {
    var file = files[i];
    var nth = 'File ' + (done + 1) + ' of ' + files.length + ' — ' + file.name;
    var fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('contract_id', id);
    fd.append('file', file);
    var res = await new Promise(function (resolve) {
      apiPost('contracts.php', fd, function (e, r) { resolve(r); }, function (loaded) {
        _ctProgress(Math.min(99, (sent + loaded) / totalBytes * 100), nth);
      });
    });
    if (!res || !res.success) { failed = (res && res.error) || 'Upload failed'; break; }
    done++; sent += file.size; _ctDirty = true;
    _ctProgress(Math.min(99, sent / totalBytes * 100), nth);
  }
  _ctProgress(failed ? sent / totalBytes * 100 : 100, done + ' of ' + files.length + ' uploaded' + (failed ? ' — stopped on an error' : ''));
  await _ctSleep(Math.max(0, MIN_BUSY_MS - (Date.now() - startedAt)));
  document.getElementById('ctProg').style.display = 'none';
  input.value = ''; input.disabled = false; btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Upload';
  _ctBusy = false;
  if (failed) showToast(files[done].name + ': ' + failed, 'error');
  if (done) showToast(done + ' file' + (done === 1 ? '' : 's') + ' uploaded.', 'success');
  loadFiles(id);
}

function deleteFile(fileId) {
  var id = document.getElementById('filesContractId').value;
  confirmDelete('Remove this signed copy? The file is deleted.', function () {
    apiPost('contracts.php', { action: 'delete_file', id: fileId, contract_id: id }, function (err, res) {
      if (!res.success) return showToast(res.error, 'error');
      _ctDirty = true; showToast(res.msg, 'success'); loadFiles(id);
    });
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
