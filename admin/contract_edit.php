<?php
// admin/contract_edit.php — contract generator.
//   ?id=N         edit draft N (approved/voided contracts open read-only in
//                 contract_print.php instead — an approved contract is locked)
//   ?tenant_id=N  start a new draft pre-filled for tenant N
//   (none)        start a blank draft (company defaults only)
//
// Every field is pre-filled from the records (tenant, unit, rate history, the
// unit's last contract, saved defaults) and stays editable. The right-hand
// pane is a live preview rendered by the SAME template the PDF uses.

session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/contract_functions.php';
requireAdmin();
$pageTitle = 'Contract Generator';
$depth = '../';

// Fields from a POST: f[key] => value, plus the auto-words flags.
function contractPostedFields(): array {
    $raw    = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $fields = contractNormalizeFields($raw);
    return contractApplyAutoWords($fields, ($_POST['auto_rent_words'] ?? '1') === '1', ($_POST['auto_term_words'] ?? '1') === '1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!defined('JSON_RESPONSE')) define('JSON_RESPONSE', true);
    csrfRequirePost();
    $action = $_POST['action'] ?? '';
    $uid    = (int)$_SESSION['user']['id'];

    if ($action === 'prefill') {
        $p = contractPrefill($pdo, (int)($_POST['tenant_id'] ?? 0) ?: null, (int)($_POST['unit_id'] ?? 0) ?: null);
        jsonOk($p);
    }

    if ($action === 'preview') {
        $a = contractPostedFields();
        $html = renderContractDocument($a['fields'], [
            'contract_no' => '', 'status' => 'draft', 'template_version' => CONTRACT_TEMPLATE_VERSION,
            'company_name' => getSetting($pdo, 'company_name', ''),
        ], 'embed');
        jsonOk([
            'html' => $html, 'computed' => array_intersect_key($a['fields'], array_flip(['advance_rent','security_deposit','move_in_total'])),
            'rent_words' => $a['rent_words'], 'term_words' => $a['term_words'],
            'rent_mismatch' => $a['rent_mismatch'], 'term_mismatch' => $a['term_mismatch'],
            'missing' => contractApprovalErrors($a['fields']),
        ]);
    }

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $tenantId = (int)($_POST['tenant_id'] ?? 0) ?: null;
        $unitId   = (int)($_POST['unit_id'] ?? 0) ?: null;
        $fields   = contractPostedFields()['fields'];

        if ($tenantId) {
            $q = $pdo->prepare("SELECT id FROM tenants WHERE id=?"); $q->execute([$tenantId]);
            if (!$q->fetchColumn()) jsonErr('Selected tenant not found.');
        }
        if ($unitId) {
            $q = $pdo->prepare("SELECT id FROM rental_units WHERE id=?"); $q->execute([$unitId]);
            if (!$q->fetchColumn()) jsonErr('Selected unit not found.');
        }
        if ($fields['lessee_name'] === '') jsonErr('Lessee name is required, even for a draft.');
        if ($fields['start_date'] && $fields['end_date'] && $fields['end_date'] < $fields['start_date']) {
            jsonErr('End date cannot be before the start date.');
        }

        $cols = [
            'tenant_id' => $tenantId, 'unit_id' => $unitId,
            'lessee_name' => mb_substr($fields['lessee_name'], 0, 120), 'unit_label' => mb_substr($fields['unit_no'], 0, 120),
            'start_date' => $fields['start_date'] ?: null, 'end_date' => $fields['end_date'] ?: null,
            'monthly_rent' => $fields['monthly_rent'] ?: '0.00',
            'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ];

        if ($id) {
            $q = $pdo->prepare("SELECT * FROM contracts WHERE id=?");
            $q->execute([$id]);
            $before = $q->fetch();
            if (!$before) jsonErr('Contract not found.', 404);
            // Approved contracts are an immutable snapshot of what was signed.
            if ($before['status'] !== 'draft') {
                jsonErr('This contract is ' . ($before['status'] === 'approved' ? 'approved' : 'voided')
                      . ' and locked. Void it and use "Copy into a new draft" to change it.');
            }
            $pdo->prepare(
                "UPDATE contracts SET tenant_id=?, unit_id=?, lessee_name=?, unit_label=?, start_date=?, end_date=?,
                                      monthly_rent=?, fields_json=?, updated_by=?, template_version=?
                 WHERE id=? AND status='draft'"
            )->execute([...array_values($cols), $uid, CONTRACT_TEMPLATE_VERSION, $id]);
            $beforeFields = json_decode((string)$before['fields_json'], true) ?: [];
            $changed = array_keys(array_diff_assoc(array_map('strval', $fields), array_map('strval', $beforeFields)));
            logChange($pdo, 'UPDATE_CONTRACT_DRAFT', 'Contracts',
                ['id' => $id] + array_intersect_key($beforeFields, array_flip($changed)),
                ['id' => $id] + array_intersect_key($fields, array_flip($changed)));
        } else {
            $pdo->prepare(
                "INSERT INTO contracts (template_key, template_version, tenant_id, unit_id, lessee_name, unit_label,
                                        start_date, end_date, monthly_rent, fields_json, status, created_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?)"
            )->execute([CONTRACT_TEMPLATE_KEY, CONTRACT_TEMPLATE_VERSION, ...array_values($cols), $uid, $uid]);
            $id = (int)$pdo->lastInsertId();
            logActivity($pdo, 'CREATE_CONTRACT_DRAFT', 'Contracts',
                "Draft #$id for {$fields['lessee_name']} (unit {$fields['unit_no']}, {$fields['start_date']} → {$fields['end_date']})");
        }

        if (($_POST['save_defaults'] ?? '') === '1') {
            contractSaveDefaults($pdo, $fields);
            logActivity($pdo, 'SAVE_CONTRACT_DEFAULTS', 'Contracts', 'Saved lessor/building/witness values as contract defaults');
        }

        // The draft is committed BEFORE approval is attempted, so a failed
        // approval (missing fields, overlap) never loses the admin's edits.
        if (($_POST['approve'] ?? '') === '1') {
            $res = contractApprove($pdo, $id);
            if (!$res['ok']) jsonOk(['id' => $id, 'saved' => true, 'approved' => false, 'approve_error' => $res['error'], 'msg' => 'Draft saved.']);
            jsonOk(['id' => $id, 'saved' => true, 'approved' => true, 'contract_no' => $res['contract_no'],
                    'msg' => 'Approved as ' . $res['contract_no'] . '.']);
        }
        jsonOk(['id' => $id, 'saved' => true, 'approved' => false, 'msg' => 'Draft saved.']);
    }

    jsonErr('Unknown action.');
}

// ─── Page data ───────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
$contract = null;
if ($id) {
    $q = $pdo->prepare("SELECT * FROM contracts WHERE id=?");
    $q->execute([$id]);
    $contract = $q->fetch();
    if (!$contract) { header('Location: contracts.php'); exit; }
    if ($contract['status'] !== 'draft') { header('Location: contract_print.php?id=' . $id); exit; }
    $fields   = contractFields($contract);
    $sources  = [];
    $tenantId = $contract['tenant_id'] ? (int)$contract['tenant_id'] : null;
    $unitId   = $contract['unit_id'] ? (int)$contract['unit_id'] : null;
} else {
    $p = contractPrefill($pdo, (int)($_GET['tenant_id'] ?? 0) ?: null, (int)($_GET['unit_id'] ?? 0) ?: null);
    $fields = $p['fields']; $sources = $p['sources'];
    $tenantId = $p['tenant_id']; $unitId = $p['unit_id'];
}
// The words are "automatic" until they differ from what the numbers say.
$autoRent = $fields['monthly_rent_words'] === '' || $fields['monthly_rent_words'] === contractAmountWords($fields['monthly_rent'] ?: '0');
$autoTerm = $fields['term_words'] === '' || $fields['term_words'] === contractTermWords($fields['start_date'], $fields['end_date']);

$tenants = $pdo->query(
    "SELECT t.id, t.full_name, t.status, t.unit_id, ru.unit_name
     FROM tenants t LEFT JOIN rental_units ru ON ru.id = t.unit_id
     ORDER BY (t.status='active') DESC, ru.unit_name, t.full_name"
)->fetchAll();
$units = $pdo->query("SELECT id, unit_name, status FROM rental_units ORDER BY unit_name")->fetchAll();

$bySection = [];
foreach (CONTRACT_FIELDS as $k => $f) $bySection[$f['section']][$k] = $f;

include '../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary-custom"></i><?= $contract ? 'Edit Draft #' . (int)$contract['id'] : 'New Contract' ?></h1>
    <div class="ct-sub">Contract of Lease — Metro Manila · template v<?= clean(CONTRACT_TEMPLATE_VERSION) ?></div>
  </div>
  <a class="btn btn-secondary btn-sm" href="contracts.php"><i class="fa-solid fa-arrow-left me-1"></i>Contracts</a>
</div>

<div class="ct-switch" role="tablist">
  <button type="button" class="active" data-pane="form" onclick="showPane('form')"><i class="fa-solid fa-pen me-1"></i>Details</button>
  <button type="button" data-pane="preview" onclick="showPane('preview')"><i class="fa-solid fa-eye me-1"></i>Preview</button>
</div>

<div class="ct-gen" id="ctGen" data-pane="form">
  <div class="ct-form-pane">
    <form id="ctForm" onsubmit="return false" autocomplete="off">
      <input type="hidden" id="ctId" value="<?= $contract ? (int)$contract['id'] : '' ?>">

      <div class="card mb-3">
        <div class="card-header"><span class="card-header-title"><i class="fa-solid fa-link me-2"></i>Tenant & Unit</span></div>
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-md-6">
              <label class="form-label" for="ctTenant">Tenant</label>
              <select class="form-select" id="ctTenant">
                <option value="">— Not linked to a tenant —</option>
                <?php foreach ($tenants as $t): ?>
                <option value="<?= (int)$t['id'] ?>" data-unit="<?= (int)$t['unit_id'] ?>" <?= $tenantId === (int)$t['id'] ? 'selected' : '' ?>>
                  <?= clean($t['full_name']) ?><?= $t['unit_name'] ? ' — ' . clean($t['unit_name']) : '' ?><?= $t['status'] !== 'active' ? ' (' . clean($t['status']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 col-8">
              <label class="form-label" for="ctUnit">Unit</label>
              <select class="form-select" id="ctUnit">
                <option value="">— None —</option>
                <?php foreach ($units as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $unitId === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['unit_name']) ?> (<?= clean($u['status']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2 col-4">
              <button type="button" class="btn btn-secondary btn-sm w-100 ct-refill" onclick="refill(true)" title="Re-fill every field from the tenant, unit and saved defaults">
                <i class="fa-solid fa-rotate me-1"></i>Re-fill
              </button>
            </div>
          </div>
          <div class="form-text">Picking a tenant fills in their details, unit, rent (from rate history), term and your saved defaults. Every field below stays editable.</div>
        </div>
      </div>

      <div class="ct-note mb-3" id="ctRentControl" style="display:none">
        <i class="fa-solid fa-scale-balanced me-1"></i>
        <strong>Rent-controlled unit.</strong> At <?= clean(money(CONTRACT_RENT_CONTROL_CAP)) ?>/month or less, RA 9653 applies: max 1 month advance + 2 months deposit (this template complies), and increases for a continuing tenant are capped once a year by the NHSB resolution (1% for 2026, valid to Dec 31, 2026 — re-check before renewing in 2027).
      </div>

      <?php foreach (CONTRACT_SECTIONS as $sec => [$secLabel, $secIcon]): if (empty($bySection[$sec])) continue; ?>
      <div class="card mb-3 ct-sec">
        <div class="card-header ct-sec-head" role="button" tabindex="0" data-bs-toggle="collapse" data-bs-target="#sec-<?= $sec ?>" aria-expanded="true">
          <span class="card-header-title"><i class="fa-solid <?= $secIcon ?> me-2"></i><?= clean($secLabel) ?></span>
          <span class="d-flex align-items-center gap-2">
            <span class="ct-sec-missing muted-pill" id="miss-<?= $sec ?>" style="display:none"></span>
            <i class="fa-solid fa-chevron-up ct-chev"></i>
          </span>
        </div>
        <div class="collapse show" id="sec-<?= $sec ?>">
          <div class="card-body">
            <div class="row g-2">
            <?php foreach ($bySection[$sec] as $key => $f):
              // Post-paid templates (v2+) take no advance rent — don't show the field.
              if ($key === 'advance_rent' && !(CONTRACT_ADVANCE_MONTHS[CONTRACT_TEMPLATE_VERSION] ?? 0)) continue;
              $val  = (string)($fields[$key] ?? '');
              $wide = $f['type'] === 'textarea' || in_array($key, ['monthly_rent_words', 'term_words', 'lessor_name', 'lessee_name', 'building_address'], true);
            ?>
              <div class="<?= $wide ? 'col-12' : 'col-sm-6' ?>">
                <label class="form-label ct-label" for="f_<?= $key ?>">
                  <span><?= clean($f['label']) ?><?= !empty($f['req']) ? ' <span class="ct-req">*</span>' : '' ?></span>
                  <span class="ct-chip" id="src_<?= $key ?>"><?= isset($sources[$key]) ? clean($sources[$key]) : '' ?></span>
                </label>
                <?php if ($f['type'] === 'computed'): ?>
                  <div class="form-control ct-computed" id="f_<?= $key ?>" data-key="<?= $key ?>"><?= $val !== '' ? money($val) : '—' ?></div>
                <?php elseif ($f['type'] === 'textarea'): ?>
                  <textarea class="form-control ct-in" id="f_<?= $key ?>" name="f[<?= $key ?>]" data-key="<?= $key ?>" rows="2" maxlength="<?= (int)($f['max'] ?? 300) ?>"><?= clean($val) ?></textarea>
                <?php elseif ($f['type'] === 'select'): ?>
                  <select class="form-select ct-in" id="f_<?= $key ?>" name="f[<?= $key ?>]" data-key="<?= $key ?>">
                    <?php foreach ($f['opts'] as $opt): ?>
                    <option value="<?= clean($opt) ?>" <?= $val === $opt ? 'selected' : '' ?>><?= clean($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($key === 'monthly_rent_words' || $key === 'term_words'): ?>
                  <div class="input-group">
                    <input type="text" class="form-control ct-in" id="f_<?= $key ?>" name="f[<?= $key ?>]" data-key="<?= $key ?>"
                           value="<?= clean($val) ?>" maxlength="<?= (int)($f['max'] ?? 200) ?>"
                           data-auto="<?= ($key === 'monthly_rent_words' ? $autoRent : $autoTerm) ? '1' : '0' ?>">
                    <button type="button" class="btn btn-secondary" title="Regenerate from the <?= $key === 'term_words' ? 'dates' : 'amount' ?>" onclick="resetWords('<?= $key ?>')"><i class="fa-solid fa-rotate"></i></button>
                  </div>
                  <div class="ct-mismatch" id="mm_<?= $key ?>" style="display:none"><i class="fa-solid fa-triangle-exclamation me-1"></i><span></span></div>
                <?php else: ?>
                  <?php
                    $type = ['date' => 'date', 'money' => 'text', 'int' => 'number'][$f['type']] ?? 'text';
                    $mode = $f['type'] === 'money' ? ' inputmode="decimal"' : '';
                  ?>
                  <?php if ($f['type'] === 'money'): ?><div class="input-group"><span class="input-group-text"><?= clean(getSetting($pdo, 'currency_symbol', '₱')) ?></span><?php endif; ?>
                  <input type="<?= $type ?>" class="form-control ct-in<?= $f['type'] === 'money' ? ' mono' : '' ?>" id="f_<?= $key ?>" name="f[<?= $key ?>]" data-key="<?= $key ?>"
                         value="<?= clean($f['type'] === 'money' && $val !== '' ? number_format((float)$val, 2, '.', '') : $val) ?>"<?= $mode ?>
                         <?= isset($f['max']) ? 'maxlength="' . (int)$f['max'] . '"' : '' ?>>
                  <?php if ($f['type'] === 'money'): ?></div><?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($f['hint'])): ?><div class="form-text"><?= clean($f['hint']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="ct-actions">
        <label class="form-check ct-defaults mb-0">
          <input class="form-check-input" type="checkbox" id="ctSaveDefaults">
          <span class="form-check-label">Save lessor, building, payment &amp; witness values as defaults for future contracts</span>
        </label>
        <div class="ct-missing" id="ctMissing"></div>
        <div class="ct-btns">
          <button type="button" class="btn btn-secondary btn-sm" id="btnSave" onclick="saveContract(false)"><i class="fa-solid fa-floppy-disk me-1"></i>Save draft</button>
          <button type="button" class="btn btn-secondary btn-sm" id="btnPdf" onclick="savePdf()"><i class="fa-solid fa-file-pdf me-1"></i>Save &amp; PDF</button>
          <button type="button" class="btn btn-primary btn-sm" id="btnApprove" onclick="saveContract(true)"><i class="fa-solid fa-circle-check me-1"></i>Approve</button>
        </div>
      </div>
    </form>
  </div>

  <div class="ct-preview-pane">
    <div class="ct-preview-bar">
      <span><i class="fa-solid fa-eye me-1"></i>Live preview</span>
      <span class="ct-sub" id="ctPreviewState">Rendering…</span>
    </div>
    <!-- sandbox without allow-scripts: the preview document is inert HTML -->
    <iframe id="ctPreview" title="Contract preview" sandbox="allow-same-origin"></iframe>
  </div>
</div>

<style>
.ct-sub { font-size: 11.5px; color: var(--gray-500); }
.ct-gen { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
.ct-preview-pane { display: none; }
.ct-gen[data-pane="preview"] .ct-form-pane { display: none; }
.ct-gen[data-pane="preview"] .ct-preview-pane { display: flex; }
.ct-preview-pane { flex-direction: column; border: 1px solid var(--gray-200); border-radius: var(--radius-lg);
  overflow: hidden; background: var(--gray-100); height: 78vh; min-height: 420px; }
.ct-preview-bar { display: flex; justify-content: space-between; align-items: center; gap: 8px;
  padding: 8px 12px; background: var(--paper); border-bottom: 1px solid var(--gray-200); font-size: 12.5px; font-weight: 600; }
#ctPreview { flex: 1 1 auto; width: 100%; border: 0; background: var(--gray-100); }
.ct-switch { display: inline-flex; gap: 0; margin-bottom: 12px; border: 1px solid var(--gray-300); border-radius: var(--radius); overflow: hidden; }
.ct-switch button { border: 0; background: var(--paper); color: var(--gray-600); font-size: 12.5px; font-weight: 600; padding: 8px 16px; min-height: 40px; }
.ct-switch button.active { background: var(--ink); color: var(--paper); }
@media (min-width: 1200px) {
  .ct-switch { display: none; }
  .ct-gen, .ct-gen[data-pane] { grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); }
  .ct-gen[data-pane] .ct-form-pane { display: block; }
  .ct-gen[data-pane] .ct-preview-pane { display: flex; position: sticky; top: 72px; height: calc(100vh - 92px); }
}
.ct-sec-head { cursor: pointer; user-select: none; }
.ct-chev { font-size: 11px; color: var(--gray-500); transition: transform .18s ease; }
.ct-sec-head.collapsed .ct-chev { transform: rotate(180deg); }
.ct-label { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; width: 100%; }
.ct-req { color: var(--gray-500); }
.ct-chip { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--gray-500);
  background: var(--gray-100); border-radius: var(--laskie-radius-pill); padding: 1px 7px; white-space: nowrap; }
.ct-chip:empty { display: none; }
.ct-chip.edited { background: transparent; border: 1px solid var(--gray-300); }
.ct-computed { background: var(--gray-100); font-family: 'DM Mono', monospace; font-weight: 600; }
.ct-mismatch { margin-top: 4px; font-size: 11.5px; font-weight: 600; color: var(--ink); }
.ct-note { font-size: 12.5px; line-height: 1.5; padding: 10px 14px; border-radius: var(--radius-lg);
  background: var(--gray-100); border: 1px solid var(--gray-200); color: var(--ink); }
.ct-actions { position: sticky; bottom: 0; z-index: 3; background: var(--paper); border: 1px solid var(--gray-200);
  border-radius: var(--radius-lg); padding: 10px 12px; margin-bottom: 8px; box-shadow: var(--laskie-shadow-card-hover);
  display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; justify-content: space-between; }
.ct-defaults { font-size: 12px; color: var(--gray-600); flex: 1 1 260px; }
.ct-missing { flex: 1 1 100%; font-size: 11.5px; color: var(--gray-500); display: none; }
.ct-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
.ct-btns .btn { min-height: 38px; }
@media (max-width: 576px) {
  .ct-btns { width: 100%; }
  .ct-btns .btn { flex: 1 1 0; min-height: 44px; }
  .ct-refill { min-height: 38px; }
}
</style>

<?php
$jsConf = json_encode([
    'rentCap'  => (float)CONTRACT_RENT_CONTROL_CAP,
    'advanceMonths' => (int)(CONTRACT_ADVANCE_MONTHS[CONTRACT_TEMPLATE_VERSION] ?? 0),
    'utility'  => (float)array_sum(array_map('floatval', CONTRACT_UTILITY_DEPOSITS)),
    'sections' => array_map(fn($f) => $f['section'], CONTRACT_FIELDS),
    'required' => array_keys(array_filter(CONTRACT_FIELDS, fn($f) => !empty($f['req']))),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$extraJs = '<script>var CT = ' . $jsConf . ';</script>' . <<<'JS'
<script>
var _dirty = false, _busy = false, _pvTimer = null, _pvSeq = 0;

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.ct-sec-head').forEach(function (h) {
    h.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); h.click(); } });
  });
  document.querySelectorAll('.ct-in').forEach(function (el) {
    var onEdit = function () {
      _dirty = true;
      var chip = document.getElementById('src_' + el.dataset.key);
      if (chip && chip.textContent !== 'edited') { chip.textContent = 'edited'; chip.classList.add('edited'); }
      // Typing over the words takes them off automatic; ↻ puts them back.
      if (el.dataset.auto !== undefined) el.dataset.auto = '0';
      onValueChange(el.dataset.key);
    };
    el.addEventListener('input', onEdit);
    el.addEventListener('change', onEdit);
  });
  document.getElementById('ctTenant').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.unit && opt.dataset.unit !== '0') document.getElementById('ctUnit').value = opt.dataset.unit;
    if (this.value) refill(false);
  });
  document.getElementById('ctUnit').addEventListener('change', function () { _dirty = true; refill(false, true); });
  window.addEventListener('beforeunload', function (e) { if (_dirty) { e.preventDefault(); e.returnValue = ''; } });
  updateRentControl();
  schedulePreview(0);
});

function showPane(p) {
  document.getElementById('ctGen').dataset.pane = p;
  document.querySelectorAll('.ct-switch button').forEach(function (b) { b.classList.toggle('active', b.dataset.pane === p); });
  if (p === 'preview') schedulePreview(0);
  window.scrollTo({ top: 0 });
}

function onValueChange(key) {
  if (key === 'monthly_rent') { updateComputed(); updateRentControl(); }
  schedulePreview(600);
}

function _cents(v) { var n = parseFloat(String(v || '').replace(/[^0-9.]/g, '')); return isNaN(n) ? 0 : Math.round(n * 100); }
function updateComputed() {
  var r = _cents(document.getElementById('f_monthly_rent').value);
  var u = Math.round(CT.utility * 100);
  var set = function (k, c) { var el = document.getElementById('f_' + k); if (el) el.textContent = r ? fmt(c / 100) : '—'; };
  var adv = r * CT.advanceMonths;
  set('advance_rent', adv); set('security_deposit', r * 2); set('move_in_total', adv + r * 2 + u);
}
function updateRentControl() {
  var r = _cents(document.getElementById('f_monthly_rent').value);
  document.getElementById('ctRentControl').style.display = (r > 0 && r <= Math.round(CT.rentCap * 100)) ? '' : 'none';
}

function collect() {
  var d = {};
  document.querySelectorAll('.ct-in').forEach(function (el) { d['f[' + el.dataset.key + ']'] = el.value; });
  d.auto_rent_words = document.getElementById('f_monthly_rent_words').dataset.auto === '1' ? '1' : '0';
  d.auto_term_words = document.getElementById('f_term_words').dataset.auto === '1' ? '1' : '0';
  return d;
}

function resetWords(key) {
  var el = document.getElementById('f_' + key);
  el.dataset.auto = '1';
  var chip = document.getElementById('src_' + key);
  if (chip) { chip.textContent = 'auto'; chip.classList.remove('edited'); }
  _dirty = true;
  schedulePreview(0);
}

// ─── Live preview ────────────────────────────────────────────
// Server-rendered by the same template as the PDF, so what you see is what
// prints. Responses are sequence-numbered so a slow one never overwrites a
// newer render.
function schedulePreview(ms) {
  clearTimeout(_pvTimer);
  document.getElementById('ctPreviewState').textContent = 'Updating…';
  _pvTimer = setTimeout(renderPreview, ms);
}
function renderPreview() {
  var seq = ++_pvSeq;
  var data = collect(); data.action = 'preview';
  apiPost('contract_edit.php', data, function (err, res) {
    if (seq !== _pvSeq) return;
    if (!res.success) { document.getElementById('ctPreviewState').textContent = res.error || 'Preview failed'; return; }
    var frame = document.getElementById('ctPreview');
    var y = 0;
    try { y = frame.contentWindow.scrollY; } catch (e) {}
    frame.onload = function () { try { frame.contentWindow.scrollTo(0, y); } catch (e) {} };
    frame.srcdoc = res.html;
    applyWords('monthly_rent_words', res.rent_words, res.rent_mismatch, 'Does not match the amount — should read "' + res.rent_words + '".');
    applyWords('term_words', res.term_words, res.term_mismatch, 'Does not match the dates — should read "' + res.term_words + '".');
    showMissing(res.missing || []);
    document.getElementById('ctPreviewState').textContent = (res.missing && res.missing.length)
      ? res.missing.length + ' field' + (res.missing.length === 1 ? '' : 's') + ' still blank'
      : 'Ready to approve';
  });
}
function applyWords(key, suggestion, mismatch, msg) {
  var el = document.getElementById('f_' + key), mm = document.getElementById('mm_' + key);
  if (el.dataset.auto === '1') { el.value = suggestion || ''; mismatch = false; }
  mm.style.display = mismatch ? '' : 'none';
  mm.querySelector('span').textContent = mismatch ? msg : '';
}
// Per-section "N missing" pills + the list above the buttons.
function showMissing(list) {
  var counts = {};
  CT.required.forEach(function (k) {
    var el = document.getElementById('f_' + k);
    if (el && el.classList.contains('ct-in') && !String(el.value).trim()) counts[CT.sections[k]] = (counts[CT.sections[k]] || 0) + 1;
  });
  document.querySelectorAll('.ct-sec-missing').forEach(function (p) {
    var n = counts[p.id.replace('miss-', '')] || 0;
    p.style.display = n ? '' : 'none';
    p.textContent = n + ' to fill';
  });
  var box = document.getElementById('ctMissing');
  box.style.display = list.length ? '' : 'none';
  box.textContent = list.length ? 'Needed before approval: ' + list.map(function (s) { return s.split(' → ').pop().replace(' is required.', ''); }).join(', ') : '';
}

// ─── Pre-fill ────────────────────────────────────────────────
// full=true  → every field (asks first if anything was edited)
// unitOnly   → only fields that come from the unit / its last contract
function refill(full, unitOnly) {
  var go = function () {
    apiPost('contract_edit.php', {
      action: 'prefill',
      tenant_id: document.getElementById('ctTenant').value,
      unit_id: document.getElementById('ctUnit').value
    }, function (err, res) {
      if (!res.success) return showToast(res.error || 'Could not load records.', 'error');
      if (!unitOnly && res.unit_id) document.getElementById('ctUnit').value = res.unit_id;
      Object.keys(res.fields).forEach(function (k) {
        var src = res.sources[k] || '';
        if (unitOnly && ['unit', 'last contract'].indexOf(src) === -1 && ['monthly_rent_words'].indexOf(k) === -1) return;
        var el = document.getElementById('f_' + k);
        if (!el) return;
        if (el.classList.contains('ct-computed')) return;
        el.value = (el.tagName === 'INPUT' && el.classList.contains('mono') && res.fields[k] !== '') ? parseFloat(res.fields[k]).toFixed(2) : res.fields[k];
        if (el.dataset.auto !== undefined) el.dataset.auto = '1';
        var chip = document.getElementById('src_' + k);
        if (chip) { chip.textContent = src; chip.classList.remove('edited'); }
      });
      _dirty = true;
      updateComputed(); updateRentControl(); schedulePreview(0);
      showToast(unitOnly ? 'Unit details filled in.' : 'Filled in from records.', 'success');
    });
  };
  if (full && _dirty) {
    confirmDelete('Replace the current values with fresh ones from the tenant, unit and saved defaults? Your edits will be lost.', go);
  } else go();
}

// ─── Save / approve ──────────────────────────────────────────
function _setBusy(b) {
  _busy = b;
  ['btnSave', 'btnPdf', 'btnApprove'].forEach(function (id) { document.getElementById(id).disabled = b; });
}
function _save(approve, cb) {
  if (_busy) return;
  _setBusy(true);
  var data = collect();
  data.action = 'save';
  data.id = document.getElementById('ctId').value;
  data.tenant_id = document.getElementById('ctTenant').value;
  data.unit_id = document.getElementById('ctUnit').value;
  data.save_defaults = document.getElementById('ctSaveDefaults').checked ? '1' : '0';
  data.approve = approve ? '1' : '0';
  apiPost('contract_edit.php', data, function (err, res) {
    _setBusy(false);
    if (!res.success) return showToast(res.error, 'error');
    document.getElementById('ctId').value = res.id;
    if (!/[?&]id=/.test(location.search)) history.replaceState(null, '', 'contract_edit.php?id=' + parseInt(res.id, 10));
    _dirty = false;
    document.getElementById('ctSaveDefaults').checked = false;
    cb(res);
  });
}
function saveContract(approve) {
  var run = function () {
    _save(approve, function (res) {
      if (approve && res.approved) {
        showToast(res.msg, 'success');
        setTimeout(function () { location.href = 'contracts.php'; }, 700);
      } else if (approve) {
        showToast('Draft saved, but it could not be approved yet.', 'error');
        var box = document.getElementById('ctMissing');
        box.style.display = ''; box.textContent = res.approve_error;
        box.style.whiteSpace = 'pre-line';
      } else {
        showToast(res.msg, 'success');
      }
    });
  };
  if (approve) {
    confirmDelete('Approve this contract?\n\nIt will be saved, locked against edits and given a contract number, then listed under Active. It does NOT change the tenant record or billing.', run);
  } else run();
}
function savePdf() {
  _save(false, function (res) {
    downloadPdf('contract_pdf_download.php?id=' + parseInt(res.id, 10), 'Draft saved \u2014 generating PDF');
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
