<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
requireAdmin();
$pageTitle = 'Rental Units Management';
$depth = '../';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    define('JSON_RESPONSE', true);
    csrfRequirePost();
    $action = $_POST['action'] ?? '';

    // ── Rental Units ─────────────────────────────────────────
    if ($action === 'save_unit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['unit_name'] ?? '');
        $typeId   = (int)($_POST['unit_type_id'] ?? 0) ?: null;
        $desc     = nullOrStr($_POST['description'] ?? '');
        $area     = nullOrStr($_POST['floor_area'] ?? '');
        $rate     = trim((string)($_POST['monthly_rate'] ?? '0'));
        $dueDay   = max(1, min(28, (int)($_POST['due_day'] ?? 5)));
        $status   = $_POST['status'] ?? 'vacant';
        if (!$name) jsonErr('Unit name is required.');
        if (!in_array($status, ['vacant', 'occupied'], true)) jsonErr('Invalid unit status.');
        if ($id) {
            $oldRow = $pdo->prepare("SELECT unit_name, monthly_rate, due_day, status FROM rental_units WHERE id=?");
            $oldRow->execute([$id]);
            $before  = $oldRow->fetch();
            if (!$before) jsonErr('Unit not found.');
            $oldRate = $before['monthly_rate'];

            // Atomic: UPDATE rental_units + (possibly) INSERT/UPDATE
            // unit_rate_history must commit together so the cached rate
            // and the history table can't diverge mid-write.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE rental_units SET unit_name=?,unit_type_id=?,description=?,floor_area=?,monthly_rate=?,due_day=?,status=? WHERE id=?")
                    ->execute([$name,$typeId,$desc,$area,$rate,$dueDay,$status,$id]);

                // Rate-history invariant: past months must keep the rate they were
                // billed at. So if the latest history row is in the past, we APPEND
                // a new row effective today; we only UPDATE in place when the latest
                // row is dated today (correcting a same-day typo) or in the future
                // (adjusting a scheduled increase).
                if (money_cmp($rate, $oldRate) !== 0) {
                    $latestHist = $pdo->prepare("SELECT id, effective_date FROM unit_rate_history WHERE unit_id=? ORDER BY effective_date DESC, created_at DESC LIMIT 1");
                    $latestHist->execute([$id]);
                    $latest = $latestHist->fetch();
                    if ($latest === false) {
                        // No history yet — seed an initial entry effective today.
                        $pdo->prepare("INSERT INTO unit_rate_history (unit_id,monthly_rate,effective_date,notes,created_by) VALUES (?,?,CURDATE(),'Initial rate',?)")
                            ->execute([$id, $rate, $_SESSION['user']['id']]);
                    } elseif ($latest['effective_date'] >= date('Y-m-d')) {
                        $pdo->prepare("UPDATE unit_rate_history SET monthly_rate=? WHERE id=?")->execute([$rate, $latest['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO unit_rate_history (unit_id,monthly_rate,effective_date,notes,created_by) VALUES (?,?,CURDATE(),'Rate change',?)")
                            ->execute([$id, $rate, $_SESSION['user']['id']]);
                    }
                    logActivity($pdo,'UPDATE_UNIT_RATE','Units',"Unit #$id rate changed " . money($oldRate) . " → " . money($rate));
                }

                $after = ['unit_name'=>$name,'monthly_rate'=>$rate,'due_day'=>$dueDay,'status'=>$status];
                logChange($pdo,'UPDATE_UNIT','Units',$before,$after);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonOk(['msg'=>'Unit updated.']);
        } else {
            // Atomic: a unit must be born with at least one rate history row so
            // getRateForMonth() can fall back to it. If the history INSERT
            // fails after the unit was created, we'd ship a rate-less unit.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO rental_units (unit_name,unit_type_id,description,floor_area,monthly_rate,due_day,status) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name,$typeId,$desc,$area,$rate,$dueDay,$status]);
                $newUnitId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO unit_rate_history (unit_id,monthly_rate,effective_date,notes,created_by) VALUES (?,?,CURDATE(),'Initial rate',?)")
                    ->execute([$newUnitId, $rate, $_SESSION['user']['id']]);
                logActivity($pdo,'CREATE_UNIT','Units',"Created unit $name");
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            jsonOk(['msg'=>'Unit created.']);
        }
    }

    if ($action === 'get_unit') {
        $row = $pdo->prepare("SELECT * FROM rental_units WHERE id=?");
        $row->execute([(int)$_POST['id']]);
        jsonOk(['unit' => $row->fetch()]);
    }

    if ($action === 'delete_unit') {
        $id = (int)($_POST['id'] ?? 0);
        // Check for active tenants
        $chk = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE unit_id=? AND status='active'");
        $chk->execute([$id]);
        if ($chk->fetchColumn() > 0) jsonErr('Cannot delete: unit has active tenants.');
        $pdo->prepare("DELETE FROM rental_units WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_UNIT','Units',"Deleted unit #$id");
        jsonOk(['msg'=>'Unit deleted.']);
    }

    // ── Unit Types ────────────────────────────────────────────
    if ($action === 'save_type') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = nullOrStr($_POST['description'] ?? '');
        if (!$name) jsonErr('Type name is required.');
        if ($id) {
            $prev = $pdo->prepare("SELECT name, description FROM unit_types WHERE id=?");
            $prev->execute([$id]);
            $before = $prev->fetch();
            if (!$before) jsonErr('Unit type not found.');
            $pdo->prepare("UPDATE unit_types SET name=?,description=? WHERE id=?")->execute([$name,$desc,$id]);
            logChange($pdo,'UPDATE_UNIT_TYPE','Units',$before,['name'=>$name,'description'=>$desc]);
        } else {
            $pdo->prepare("INSERT INTO unit_types (name,description) VALUES (?,?)")->execute([$name,$desc]);
            logActivity($pdo,'CREATE_UNIT_TYPE','Units',"Created unit type $name");
        }
        jsonOk(['msg'=>'Unit type saved.']);
    }

    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM rental_units WHERE unit_type_id=?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() > 0) jsonErr('Cannot delete: unit type is in use by rental units.');
        $pdo->prepare("DELETE FROM unit_types WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_UNIT_TYPE','Units',"Deleted unit type #$id");
        jsonOk(['msg'=>'Unit type deleted.']);
    }

    // ── Service Types ─────────────────────────────────────────
    if ($action === 'save_service') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $desc   = nullOrStr($_POST['description'] ?? '');
        $amount = trim((string)($_POST['default_amount'] ?? '0'));
        $active = (int)($_POST['is_active'] ?? 1);
        if (!$name) jsonErr('Service name is required.');
        if ($id) {
            $prev = $pdo->prepare("SELECT name, description, default_amount, is_active FROM service_types WHERE id=?");
            $prev->execute([$id]);
            $before = $prev->fetch();
            if (!$before) jsonErr('Service type not found.');
            $pdo->prepare("UPDATE service_types SET name=?,description=?,default_amount=?,is_active=? WHERE id=?")->execute([$name,$desc,$amount,$active,$id]);
            logChange($pdo,'UPDATE_SERVICE_TYPE','Units',$before,['name'=>$name,'description'=>$desc,'default_amount'=>$amount,'is_active'=>$active]);
        } else {
            $pdo->prepare("INSERT INTO service_types (name,description,default_amount,is_active) VALUES (?,?,?,?)")->execute([$name,$desc,$amount,$active]);
            logActivity($pdo,'CREATE_SERVICE_TYPE','Units',"Created service type $name");
        }
        jsonOk(['msg'=>'Service type saved.']);
    }

    if ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE service_type_id=?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() > 0) jsonErr('Cannot delete: service type is used in existing payments.');
        // unit_charges uses ON DELETE SET NULL, so deleting would silently orphan
        // pre-billed charge rows, stripping the service label from SOA reports.
        $chkUc = $pdo->prepare("SELECT COUNT(*) FROM unit_charges WHERE service_type_id=?");
        $chkUc->execute([$id]);
        if ($chkUc->fetchColumn() > 0) jsonErr('Cannot delete: service type has outstanding pre-billed charges. Remove those charges first.');
        $pdo->prepare("DELETE FROM service_types WHERE id=?")->execute([$id]);
        logActivity($pdo,'DELETE_SERVICE_TYPE','Units',"Deleted service type #$id");
        jsonOk(['msg'=>'Service type deleted.']);
    }

    // ── Global Due Day ────────────────────────────────────────
    // Atomic: applying the global default touches every unit AND the
    // settings row that records the default. A failure between them
    // would leave the displayed default disagreeing with reality.
    if ($action === 'set_due_day') {
        $day = max(1, min(28, (int)($_POST['due_day'] ?? 5)));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE rental_units SET due_day=?")->execute([$day]);
            $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='default_due_day'")->execute([$day]);
            logActivity($pdo,'SET_GLOBAL_DUE_DAY','Settings',"Set global due day to $day for all units");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg'=>"Due day updated to the {$day}th for all rental units."]);
    }

    // ── Get service for edit ──────────────────────────────────
    if ($action === 'get_service') {
        $row = $pdo->prepare("SELECT * FROM service_types WHERE id=?");
        $row->execute([(int)$_POST['id']]);
        jsonOk(['service' => $row->fetch()]);
    }

    if ($action === 'get_type') {
        $row = $pdo->prepare("SELECT * FROM unit_types WHERE id=?");
        $row->execute([(int)$_POST['id']]);
        jsonOk(['type' => $row->fetch()]);
    }

    // ── Rate History ──────────────────────────────────────────
    if ($action === 'save_rate_increase') {
        $unitId   = (int)($_POST['unit_id']   ?? 0);
        $rate     = trim((string)($_POST['monthly_rate'] ?? '0'));
        $effDate  = trim($_POST['effective_date'] ?? '');
        $notes    = nullOrStr($_POST['notes'] ?? '');
        if (!$unitId) jsonErr('Unit required.');
        if (!money_is_pos($rate)) jsonErr('Rate must be greater than zero.');
        if (!$effDate || !strtotime($effDate)) jsonErr('Valid effective date required.');
        // INSERT history + conditional UPDATE rental_units must be atomic.
        // Otherwise a failure between them leaves the history row committed
        // while rental_units.monthly_rate stays at the old value (or vice
        // versa) and the two reads disagree for everyone using the unit.
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO unit_rate_history (unit_id,monthly_rate,effective_date,notes,created_by) VALUES (?,?,?,?,?)")
                ->execute([$unitId, $rate, $effDate, $notes, $_SESSION['user']['id']]);
            $pdo->prepare("UPDATE rental_units SET monthly_rate=?
                           WHERE id=? AND ? >= (
                               SELECT COALESCE(MAX(effective_date),'1970-01-01')
                               FROM unit_rate_history WHERE unit_id=? AND id != LAST_INSERT_ID()
                           )")
                ->execute([$rate, $unitId, $effDate, $unitId]);
            logActivity($pdo,'RATE_INCREASE','Units',"Unit #$unitId new rate ₱$rate eff. $effDate");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg'=>'Rate change recorded.']);
    }

    if ($action === 'get_rate_history') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $rows = $pdo->prepare("
            SELECT urh.*, u.full_name as created_by_name
            FROM unit_rate_history urh
            LEFT JOIN users u ON urh.created_by = u.id
            WHERE urh.unit_id = ?
            ORDER BY urh.effective_date DESC, urh.created_at DESC
        ");
        $rows->execute([$unitId]);
        jsonOk(['history' => $rows->fetchAll()]);
    }

    if ($action === 'delete_rate_history') {
        $id = (int)($_POST['id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0);
        // Prevent deleting if it's the only history row
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM unit_rate_history WHERE unit_id=?");
        $cnt->execute([$unitId]);
        if ((int)$cnt->fetchColumn() <= 1) jsonErr('Cannot delete the only rate record for this unit.');
        // Atomic: DELETE the row + re-sync rental_units.monthly_rate to the new
        // latest. Without a transaction, the DELETE could commit and the
        // rental_units sync fail, leaving the cached rate ahead of history.
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM unit_rate_history WHERE id=?")->execute([$id]);
            $latest = $pdo->prepare("SELECT monthly_rate FROM unit_rate_history WHERE unit_id=? ORDER BY effective_date DESC, created_at DESC LIMIT 1");
            $latest->execute([$unitId]);
            $latestRate = $latest->fetchColumn();
            if ($latestRate !== false) {
                $pdo->prepare("UPDATE rental_units SET monthly_rate=? WHERE id=?")->execute([$latestRate, $unitId]);
            }
            logActivity($pdo,'DELETE_RATE_HISTORY','Units',"Deleted rate history #$id for unit #$unitId");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg'=>'Rate record deleted.']);
    }

    exit;
}

$units       = $pdo->query("SELECT ru.*, ut.name as type_name FROM rental_units ru LEFT JOIN unit_types ut ON ru.unit_type_id=ut.id ORDER BY ru.unit_name")->fetchAll();
$unitTypes   = $pdo->query("SELECT * FROM unit_types ORDER BY name")->fetchAll();
$serviceTypes= $pdo->query("SELECT * FROM service_types ORDER BY name")->fetchAll();
$defaultDue  = getSetting($pdo,'default_due_day','5');

logActivity($pdo,'VIEW_UNITS','Units','Viewed rental units management page');
include '../includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title"><i class="fa-solid fa-door-open me-2 text-primary-custom"></i>Rental Units Management</h1>
</div>

<!-- Global Due Day Alert -->
<div class="alert alert-info d-flex align-items-center gap-3 mb-3" style="font-size:13px;">
  <i class="fa-solid fa-calendar-day fa-lg"></i>
  <div class="flex-grow-1">
    <strong>Global Rental Due Date:</strong> Currently set to the <strong><?= clean($defaultDue) ?><?= in_array($defaultDue,['1','21','31'])?'st':(in_array($defaultDue,['2','22'])?'nd':(in_array($defaultDue,['3','23'])?'rd':'th')) ?></strong> of every month.
    You can override per-unit or change all at once below.
  </div>
  <button class="btn btn-sm btn-outline-primary no-print" data-bs-toggle="modal" data-bs-target="#dueDayModal">
    <i class="fa-solid fa-edit me-1"></i>Change All
  </button>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="unitsTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-units">
    <i class="fa-solid fa-building me-1"></i>Rental Units <span class="badge bg-secondary ms-1"><?= count($units) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-types">
    <i class="fa-solid fa-tags me-1"></i>Unit Types <span class="badge bg-secondary ms-1"><?= count($unitTypes) ?></span></a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-services">
    <i class="fa-solid fa-wrench me-1"></i>Service & Payment Types <span class="badge bg-secondary ms-1"><?= count($serviceTypes) ?></span></a></li>
</ul>

<div class="tab-content">

  <!-- ── TAB: Rental Units ─────────────────────────────────── -->
  <div class="tab-pane fade show active" id="tab-units">
    <div class="page-header mb-2">
      <span></span>
      <button class="btn btn-primary btn-sm" onclick="openUnitModal()"><i class="fa-solid fa-plus me-1"></i>Add Unit</button>
    </div>
    <div class="card">
      <div class="table-responsive">
        <table class="table" id="unitsTable">
          <thead><tr>
            <th>Unit Name</th><th>Type</th><th>Area (m²)</th>
            <th class="text-end">Monthly Rate</th><th class="text-center">Due Day</th>
            <th>Status</th><th>Description</th><th class="text-center">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach($units as $u): ?>
          <tr>
            <td class="fw-600"><?= clean($u['unit_name']) ?></td>
            <td><?= clean($u['type_name'] ?? '—') ?></td>
            <td><?= $u['floor_area'] ? clean($u['floor_area']) : '—' ?></td>
            <td class="text-end"><?= money((float)$u['monthly_rate']) ?></td>
            <td class="text-center"><?= $u['due_day'] ?><sup><?= in_array($u['due_day'],[1,21,31])?'st':(in_array($u['due_day'],[2,22])?'nd':(in_array($u['due_day'],[3,23])?'rd':'th')) ?></sup></td>
            <td><span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span></td>
            <td class="truncate" style="max-width:180px"><?= clean($u['description'] ?? '—') ?></td>
            <td class="text-center">
              <button class="btn-icon" title="Rate History" data-id="<?= $u['id'] ?>" data-name="<?= clean($u['unit_name']) ?>" data-rate="<?= (float)$u['monthly_rate'] ?>" onclick="openRateHistory(+this.dataset.id, this.dataset.name, +this.dataset.rate)"><i class="fa-solid fa-chart-line fa-xs"></i></button>
              <button class="btn-icon" title="Edit" onclick="editUnit(<?= $u['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" data-id="<?= $u['id'] ?>" data-name="<?= clean($u['unit_name']) ?>" onclick="deleteUnit(+this.dataset.id, this.dataset.name)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TAB: Unit Types ──────────────────────────────────── -->
  <div class="tab-pane fade" id="tab-types">
    <div class="page-header mb-2">
      <span></span>
      <button class="btn btn-primary btn-sm" onclick="openTypeModal()"><i class="fa-solid fa-plus me-1"></i>Add Unit Type</button>
    </div>
    <div class="card">
      <div class="table-responsive">
        <table class="table" id="typesTable">
          <thead><tr><th>Type Name</th><th>Description</th><th class="text-center">Actions</th></tr></thead>
          <tbody>
          <?php foreach($unitTypes as $t): ?>
          <tr>
            <td class="fw-600"><?= clean($t['name']) ?></td>
            <td><?= clean($t['description'] ?? '—') ?></td>
            <td class="text-center">
              <button class="btn-icon" title="Edit" onclick="editType(<?= $t['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" data-id="<?= $t['id'] ?>" data-name="<?= clean($t['name']) ?>" onclick="deleteType(+this.dataset.id, this.dataset.name)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TAB: Service Types ───────────────────────────────── -->
  <div class="tab-pane fade" id="tab-services">
    <div class="page-header mb-2">
      <div style="font-size:13px;color:var(--text-muted)">Define all payment types beyond standard rent (arrears, deposits, fees, etc.)</div>
      <button class="btn btn-primary btn-sm" onclick="openServiceModal()"><i class="fa-solid fa-plus me-1"></i>Add Service Type</button>
    </div>
    <div class="card">
      <div class="table-responsive">
        <table class="table" id="servicesTable">
          <thead><tr><th>Service Name</th><th>Description</th><th class="text-end">Default Amount</th><th>Active</th><th class="text-center">Actions</th></tr></thead>
          <tbody>
          <?php foreach($serviceTypes as $s): ?>
          <tr>
            <td class="fw-600 cell-trunc"><?= clean($s['name']) ?></td>
            <td class="cell-trunc-lg"><?= clean($s['description'] ?? '—') ?></td>
            <td class="text-end"><?= money_is_pos($s['default_amount']) ? money($s['default_amount']) : '<span class="text-muted">Variable</span>' ?></td>
            <td><span class="badge badge-<?= $s['is_active']?'active':'inactive' ?>"><?= $s['is_active']?'Yes':'No' ?></span></td>
            <td class="text-center">
              <button class="btn-icon" title="Edit" onclick="editService(<?= $s['id'] ?>)"><i class="fa-solid fa-pen fa-xs"></i></button>
              <button class="btn-icon danger" title="Delete" data-id="<?= $s['id'] ?>" data-name="<?= clean($s['name']) ?>" onclick="deleteService(+this.dataset.id, this.dataset.name)"><i class="fa-solid fa-trash fa-xs"></i></button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Rental Unit ───────────────────────────────────── -->
<div class="modal fade" id="unitModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="unitModalTitle">Add Rental Unit</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="unitId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Unit Name *</label>
            <input type="text" class="form-control" id="unitName" placeholder="e.g. Room 101, Unit A, Parking #3">
          </div>
          <div class="col-md-6">
            <label class="form-label">Unit Type</label>
            <select class="form-select" id="unitType">
              <option value="">— Select type —</option>
              <?php foreach($unitTypes as $t): ?>
              <option value="<?= $t['id'] ?>"><?= clean($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Monthly Rate (₱) *</label>
            <input type="number" step="0.01" class="form-control" id="unitRate" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Due Day <small class="text-muted">(1–28)</small></label>
            <input type="number" min="1" max="28" class="form-control" id="unitDue" value="<?= clean($defaultDue) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Floor Area (m²)</label>
            <input type="number" step="0.01" class="form-control" id="unitArea" placeholder="optional">
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" id="unitStatus">
              <option value="vacant">Vacant</option>
              <option value="occupied">Occupied</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Description / Notes</label>
            <textarea class="form-control" id="unitDesc" rows="2" placeholder="Floor level, amenities, special notes..."></textarea>
          </div>
        </div>
        <div id="unitMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveUnit()"><i class="fa-solid fa-save me-1"></i>Save Unit</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Unit Type ─────────────────────────────────────── -->
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="typeModalTitle">Add Unit Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="typeId">
        <div class="mb-3"><label class="form-label">Type Name *</label><input type="text" class="form-control" id="typeName" placeholder="e.g. Studio, Apartment, Parking"></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="typeDesc" rows="2"></textarea></div>
        <div id="typeMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveType()"><i class="fa-solid fa-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Service Type ──────────────────────────────────── -->
<div class="modal fade" id="serviceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="svcModalTitle">Add Service Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="svcId">
        <div class="mb-3"><label class="form-label">Service Name *</label><input type="text" class="form-control" id="svcName" placeholder="e.g. Late Payment Fee"></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" id="svcDesc" rows="2"></textarea></div>
        <div class="mb-3"><label class="form-label">Default Amount (₱) <small class="text-muted">— leave 0 if variable</small></label><input type="number" step="0.01" class="form-control" id="svcAmount" value="0"></div>
        <div class="mb-3"><label class="form-label">Active</label>
          <select class="form-select" id="svcActive"><option value="1">Yes</option><option value="0">No</option></select>
        </div>
        <div id="svcMsg" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="saveService()"><i class="fa-solid fa-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Rate History ───────────────────────────────────── -->
<div class="modal fade" id="rateHistoryModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-chart-line me-2"></i>Rate History — <span id="rateUnitName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rateUnitId">
        <!-- History table -->
        <div class="table-responsive mb-4">
          <table class="table table-sm" id="rateHistoryTable">
            <thead>
              <tr>
                <th>Effective Date</th>
                <th class="text-end">Monthly Rate</th>
                <th>Notes</th>
                <th>Recorded By</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody id="rateHistoryBody">
              <tr><td colspan="5" class="text-center text-muted">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <hr>
        <!-- Add new rate change -->
        <div class="fw-600 mb-3"><i class="fa-solid fa-plus-circle me-1 text-primary"></i>Record New Rate Change</div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">New Monthly Rate (₱) *</label>
            <input type="number" step="0.01" min="0.01" class="form-control" id="newRate" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label">Effective Date *</label>
            <input type="date" class="form-control" id="newRateDate">
          </div>
          <div class="col-md-4">
            <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
            <input type="text" class="form-control" id="newRateNotes" placeholder="e.g. Annual increase">
          </div>
        </div>
        <div id="rateMsg" class="mt-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary btn-sm" onclick="saveRateIncrease()"><i class="fa-solid fa-save me-1"></i>Save Rate Change</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Global Due Day ────────────────────────────────── -->
<div class="modal fade" id="dueDayModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Due Day — All Units</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning" style="font-size:12.5px;"><i class="fa-solid fa-triangle-exclamation me-1"></i>This will change the due day for <strong>ALL</strong> rental units.</div>
        <label class="form-label">New Due Day (1–28)</label>
        <input type="number" min="1" max="28" class="form-control" id="globalDueDay" value="<?= clean($defaultDue) ?>">
        <div id="dueMsg" class="mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning btn-sm" onclick="setGlobalDue()"><i class="fa-solid fa-calendar-check me-1"></i>Apply to All Units</button>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
var unitModal, typeModal, serviceModal, rateHistoryModal;
document.addEventListener('DOMContentLoaded', function() {
  unitModal        = new bootstrap.Modal(document.getElementById('unitModal'));
  typeModal        = new bootstrap.Modal(document.getElementById('typeModal'));
  serviceModal     = new bootstrap.Modal(document.getElementById('serviceModal'));
  rateHistoryModal = new bootstrap.Modal(document.getElementById('rateHistoryModal'));
});

$(document).ready(function(){
  $('#unitsTable').DataTable({pageLength:50, columnDefs:[{orderable:false,targets:7}]});
  $('#typesTable').DataTable({pageLength:50, columnDefs:[{orderable:false,targets:2}]});
  $('#servicesTable').DataTable({pageLength:50, columnDefs:[{orderable:false,targets:4}]});
});

// ── Rental Units ─────────────────────────────────────────────
function openUnitModal() {
  document.getElementById('unitModalTitle').textContent = 'Add Rental Unit';
  document.getElementById('unitId').value = '';
  ['unitName','unitArea','unitDesc'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('unitRate').value = '';
  document.getElementById('unitType').value = '';
  document.getElementById('unitStatus').value = 'vacant';
  document.getElementById('unitMsg').style.display='none';
  unitModal.show();
}

function editUnit(id) {
  apiPost('../admin/units.php',{action:'get_unit',id},(err,res)=>{
    if(!res.success) return showToast('Error loading unit','error');
    const u=res.unit;
    document.getElementById('unitModalTitle').textContent='Edit Rental Unit';
    document.getElementById('unitId').value=u.id;
    document.getElementById('unitName').value=u.unit_name||'';
    document.getElementById('unitType').value=u.unit_type_id||'';
    document.getElementById('unitRate').value=u.monthly_rate||'';
    document.getElementById('unitDue').value=u.due_day||5;
    document.getElementById('unitArea').value=u.floor_area||'';
    document.getElementById('unitStatus').value=u.status||'vacant';
    document.getElementById('unitDesc').value=u.description||'';
    document.getElementById('unitMsg').style.display='none';
    unitModal.show();
  });
}

function saveUnit() {
  const data={action:'save_unit',id:document.getElementById('unitId').value,unit_name:document.getElementById('unitName').value,unit_type_id:document.getElementById('unitType').value,monthly_rate:document.getElementById('unitRate').value,due_day:document.getElementById('unitDue').value,floor_area:document.getElementById('unitArea').value,status:document.getElementById('unitStatus').value,description:document.getElementById('unitDesc').value};
  apiPost('../admin/units.php',data,(err,res)=>{
    if(!res.success){const el=document.getElementById('unitMsg');el.style.display='';el.className='alert alert-danger';el.textContent=res.error;return;}
    showToast(res.msg,'success'); unitModal.hide(); setTimeout(()=>location.reload(),800);
  });
}

function deleteUnit(id,name) {
  confirmDelete(`Delete unit "${name}"? This is permanent.`,()=>{
    apiPost('../admin/units.php',{action:'delete_unit',id},(err,res)=>{
      if(!res.success) return showToast(res.error,'error');
      showToast(res.msg,'success'); setTimeout(()=>location.reload(),800);
    });
  });
}

// ── Unit Types ───────────────────────────────────────────────
function openTypeModal() {
  document.getElementById('typeModalTitle').textContent='Add Unit Type';
  document.getElementById('typeId').value='';
  document.getElementById('typeName').value='';
  document.getElementById('typeDesc').value='';
  document.getElementById('typeMsg').style.display='none';
  typeModal.show();
}

function editType(id) {
  apiPost('../admin/units.php',{action:'get_type',id},(err,res)=>{
    if(!res.success) return showToast('Error','error');
    const t=res.type;
    document.getElementById('typeModalTitle').textContent='Edit Unit Type';
    document.getElementById('typeId').value=t.id;
    document.getElementById('typeName').value=t.name;
    document.getElementById('typeDesc').value=t.description||'';
    document.getElementById('typeMsg').style.display='none';
    typeModal.show();
  });
}

function saveType() {
  apiPost('../admin/units.php',{action:'save_type',id:document.getElementById('typeId').value,name:document.getElementById('typeName').value,description:document.getElementById('typeDesc').value},(err,res)=>{
    if(!res.success){const el=document.getElementById('typeMsg');el.style.display='';el.className='alert alert-danger';el.textContent=res.error;return;}
    showToast(res.msg,'success'); typeModal.hide(); setTimeout(()=>location.reload(),800);
  });
}

function deleteType(id,name) {
  confirmDelete(`Delete unit type "${name}"?`,()=>{
    apiPost('../admin/units.php',{action:'delete_type',id},(err,res)=>{
      if(!res.success) return showToast(res.error,'error');
      showToast(res.msg,'success'); setTimeout(()=>location.reload(),800);
    });
  });
}

// ── Service Types ────────────────────────────────────────────
function openServiceModal() {
  document.getElementById('svcModalTitle').textContent='Add Service Type';
  document.getElementById('svcId').value='';
  ['svcName','svcDesc'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('svcAmount').value='0';
  document.getElementById('svcActive').value='1';
  document.getElementById('svcMsg').style.display='none';
  serviceModal.show();
}

function editService(id) {
  apiPost('../admin/units.php',{action:'get_service',id},(err,res)=>{
    if(!res.success) return showToast('Error','error');
    const s=res.service;
    document.getElementById('svcModalTitle').textContent='Edit Service Type';
    document.getElementById('svcId').value=s.id;
    document.getElementById('svcName').value=s.name;
    document.getElementById('svcDesc').value=s.description||'';
    document.getElementById('svcAmount').value=s.default_amount||0;
    document.getElementById('svcActive').value=s.is_active;
    document.getElementById('svcMsg').style.display='none';
    serviceModal.show();
  });
}

function saveService() {
  apiPost('../admin/units.php',{action:'save_service',id:document.getElementById('svcId').value,name:document.getElementById('svcName').value,description:document.getElementById('svcDesc').value,default_amount:document.getElementById('svcAmount').value,is_active:document.getElementById('svcActive').value},(err,res)=>{
    if(!res.success){const el=document.getElementById('svcMsg');el.style.display='';el.className='alert alert-danger';el.textContent=res.error;return;}
    showToast(res.msg,'success'); serviceModal.hide(); setTimeout(()=>location.reload(),800);
  });
}

function deleteService(id,name) {
  confirmDelete(`Delete service type "${name}"?`,()=>{
    apiPost('../admin/units.php',{action:'delete_service',id},(err,res)=>{
      if(!res.success) return showToast(res.error,'error');
      showToast(res.msg,'success'); setTimeout(()=>location.reload(),800);
    });
  });
}

// ── Rate History ─────────────────────────────────────────────
function openRateHistory(unitId, unitName, currentRate) {
  document.getElementById('rateUnitId').value   = unitId;
  document.getElementById('rateUnitName').textContent = unitName;
  document.getElementById('newRate').value      = '';
  document.getElementById('newRateDate').value  = new Date().toISOString().slice(0,10);
  document.getElementById('newRateNotes').value = '';
  document.getElementById('rateMsg').style.display = 'none';
  loadRateHistory(unitId);
  rateHistoryModal.show();
}

// Same esc() pattern used elsewhere — notes and created_by_name flow from
// admin input, so a different admin viewing the history could otherwise be
// XSS'd through them.
function _rhEsc(s) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(s != null ? String(s) : ''));
  return d.innerHTML;
}

function loadRateHistory(unitId) {
  const tbody = document.getElementById('rateHistoryBody');
  tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Loading…</td></tr>';
  apiPost('../admin/units.php', {action:'get_rate_history', unit_id:unitId}, (err, res) => {
    if (!res.success) { tbody.innerHTML = '<tr><td colspan="5" class="text-danger">Failed to load.</td></tr>'; return; }
    if (!res.history.length) { tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No rate history recorded yet.</td></tr>'; return; }
    tbody.innerHTML = res.history.map((h,i) => `
      <tr>
        <td class="fw-600">${_rhEsc(h.effective_date)}</td>
        <td class="text-end fw-600 text-success">₱${parseFloat(h.monthly_rate).toLocaleString('en',{minimumFractionDigits:2})}</td>
        <td class="text-muted" style="font-size:12px">${h.notes ? _rhEsc(h.notes) : '—'}</td>
        <td style="font-size:12px">${h.created_by_name ? _rhEsc(h.created_by_name) : '—'}</td>
        <td class="text-center">
          ${res.history.length > 1
            ? `<button class="btn-icon danger" title="Delete" data-id="${parseInt(h.id)}" data-uid="${parseInt(unitId)}" onclick="deleteRateHistory(+this.dataset.id, +this.dataset.uid)"><i class="fa-solid fa-trash fa-xs"></i></button>`
            : '<span class="text-muted" style="font-size:11px">Initial</span>'}
        </td>
      </tr>`).join('');
  });
}

function saveRateIncrease() {
  const unitId = document.getElementById('rateUnitId').value;
  const rate   = document.getElementById('newRate').value;
  const date   = document.getElementById('newRateDate').value;
  const notes  = document.getElementById('newRateNotes').value;
  const msgEl  = document.getElementById('rateMsg');
  apiPost('../admin/units.php', {action:'save_rate_increase', unit_id:unitId, monthly_rate:rate, effective_date:date, notes}, (err, res) => {
    msgEl.style.display = '';
    if (!res.success) { msgEl.className='alert alert-danger'; msgEl.textContent=res.error; return; }
    msgEl.className='alert alert-success'; msgEl.textContent=res.msg;
    loadRateHistory(unitId);
    document.getElementById('newRate').value = '';
    document.getElementById('newRateNotes').value = '';
    setTimeout(()=>{ msgEl.style.display='none'; }, 3000);
  });
}

function deleteRateHistory(id, unitId) {
  confirmDelete('Delete this rate record? This cannot be undone.', () => {
    apiPost('../admin/units.php', {action:'delete_rate_history', id, unit_id:unitId}, (err, res) => {
      if (!res.success) return showToast(res.error, 'error');
      showToast(res.msg, 'success');
      loadRateHistory(unitId);
    });
  });
}

// ── Global Due Day ───────────────────────────────────────────
function setGlobalDue() {
  const day=document.getElementById('globalDueDay').value;
  apiPost('../admin/units.php',{action:'set_due_day',due_day:day},(err,res)=>{
    const el=document.getElementById('dueMsg');
    el.style.display='';
    if(!res.success){el.className='alert alert-danger';el.textContent=res.error;return;}
    el.className='alert alert-success';el.textContent=res.msg;
    setTimeout(()=>location.reload(),1200);
  });
}
</script>
JS;
include '../includes/footer.php'; ?>
