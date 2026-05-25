<?php
// payments/api_payment.php — Payment CRUD API
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
csrfRequirePost();

header('Content-Type: application/json');
// Global JSON exception handler + transaction rollback are installed
// by config/functions.php when JSON_RESPONSE is defined.
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Record Payment ────────────────────────────────────────────
if ($action === 'save_payment') {
    $id          = (int)($_POST['id'] ?? 0);
    $unitId      = (int)($_POST['unit_id'] ?? 0);
    $tenantId    = (int)($_POST['tenant_id'] ?? 0) ?: null;
    $type        = $_POST['payment_type'] ?? 'rent';
    $serviceId   = (int)($_POST['service_type_id'] ?? 0) ?: null;
    $amount      = (float)($_POST['amount'] ?? 0);
    $payDate     = $_POST['payment_date'] ?? date('Y-m-d');
    $dueDate     = nullOrStr($_POST['due_date'] ?? '');
    $periodMonth = (int)($_POST['period_month'] ?? date('n'));
    $periodYear  = (int)($_POST['period_year']  ?? date('Y'));
    $notes       = nullOrStr($_POST['notes'] ?? '');

    if (!$unitId)    jsonErr('Rental unit is required.');
    if ($amount <= 0) jsonErr('Amount must be greater than zero.');
    if (!in_array($type, ['rent','service'])) jsonErr('Invalid payment type.');

    if ($id) {
        requireAdmin(); // Only admins may edit existing payments
        // Fetch full record for before/after audit trail + validation
        $chk = $pdo->prepare("SELECT * FROM payments WHERE id=?");
        $chk->execute([$id]);
        $chkRow = $chk->fetch();
        if (!$chkRow)                          jsonErr('Payment not found.');
        if ($chkRow['deleted_at'])             jsonErr('Cannot edit a deleted payment. Restore it from the trash first.');
        if ($chkRow['status'] === 'voided')    jsonErr('Cannot edit a voided payment. Restore it first.');
        $before = array_intersect_key($chkRow, array_flip(['unit_id','tenant_id','payment_type','service_type_id','amount','payment_date','due_date','period_month','period_year','notes']));

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE payments SET unit_id=?,tenant_id=?,payment_type=?,service_type_id=?,amount=?,payment_date=?,due_date=?,period_month=?,period_year=?,received_by=?,notes=? WHERE id=?")
                ->execute([$unitId,$tenantId,$type,$serviceId,$amount,$payDate,$dueDate,$periodMonth,$periodYear,$_SESSION['user']['id'],$notes,$id]);

            // Only sync the 'received' cash row that mirrors this payment. Refunds
            // also store reference_payment_id, and we must not overwrite their
            // amount/date with the payment's new values.
            $pdo->prepare("UPDATE cash_transactions SET amount=?,transaction_date=? WHERE reference_payment_id=? AND transaction_type='received'")
                ->execute([$amount, $payDate, $id]);

            if ($type === 'service') {
                $pdo->prepare("UPDATE unit_charges SET amount=?, charge_date=? WHERE payment_id=?")
                    ->execute([$amount, $payDate, $id]);
            }

            $after = ['unit_id'=>$unitId,'tenant_id'=>$tenantId,'payment_type'=>$type,'service_type_id'=>$serviceId,'amount'=>$amount,'payment_date'=>$payDate,'due_date'=>$dueDate,'period_month'=>$periodMonth,'period_year'=>$periodYear,'notes'=>$notes];
            logChange($pdo,'UPDATE_PAYMENT','Payments',$before,$after);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg' => 'Payment updated successfully.', 'id' => $id]);
    } else {
        // Idempotency: client sends a per-modal-open UUID. A duplicate submit
        // (double-click / network retry) returns the original payment instead
        // of inserting a second row. The DB UNIQUE index on idempotency_key
        // is the atomic guard against races.
        $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? '')) ?: null;
        if ($idempotencyKey !== null) {
            $look = $pdo->prepare("SELECT id, invoice_no FROM payments WHERE idempotency_key=?");
            $look->execute([$idempotencyKey]);
            $hit = $look->fetch();
            if ($hit) {
                jsonOk(['msg' => 'Payment already recorded.', 'id' => (int)$hit['id'], 'invoice_no' => $hit['invoice_no'], 'idempotent_replay' => true]);
            }
        }

        // New payment — wrap payments + cash_transactions + unit_charges as one atomic unit
        $pdo->beginTransaction();
        try {
            $invoiceNo = generateInvoiceNo($pdo);
            $pdo->prepare("INSERT INTO payments (invoice_no,unit_id,tenant_id,payment_type,service_type_id,amount,period_month,period_year,payment_date,due_date,received_by,notes,idempotency_key)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$invoiceNo,$unitId,$tenantId,$type,$serviceId,$amount,$periodMonth,$periodYear,$payDate,$dueDate,$_SESSION['user']['id'],$notes,$idempotencyKey]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
                ->execute([$_SESSION['user']['id'],'received',$amount,$newId,"Payment received: $invoiceNo",$payDate]);

            if ($type === 'service') {
                $stRow = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
                $stRow->execute([$serviceId]);
                $stName  = $stRow->fetchColumn() ?: 'Service';
                $chgDesc = $notes ?: $stName;
                $exist = $pdo->prepare("SELECT id FROM unit_charges WHERE unit_id=? AND service_type_id=? AND period_month=? AND period_year=? AND payment_id IS NULL AND source='pre_billed' LIMIT 1");
                $exist->execute([$unitId, $serviceId, $periodMonth, $periodYear]);
                $existChargeId = $exist->fetchColumn();
                if ($existChargeId) {
                    $pdo->prepare("UPDATE unit_charges SET payment_id=?, amount=?, charge_date=? WHERE id=?")
                        ->execute([$newId, $amount, $payDate, $existChargeId]);
                } else {
                    $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,payment_id,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$unitId,$tenantId,$serviceId,$amount,$chgDesc,$payDate,$periodMonth,$periodYear,$newId,'auto_collected',$_SESSION['user']['id']]);
                }
            }

            logActivity($pdo,'RECORD_PAYMENT','Payments',"Recorded payment $invoiceNo for unit #$unitId, ₱$amount");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Race-condition recovery: if a concurrent request inserted the same key first,
            // MySQL fires error 1062 (duplicate-entry). Fall back to returning the existing row.
            if ($idempotencyKey !== null && $e instanceof PDOException && (int)$e->errorInfo[1] === 1062) {
                $look = $pdo->prepare("SELECT id, invoice_no FROM payments WHERE idempotency_key=?");
                $look->execute([$idempotencyKey]);
                $hit = $look->fetch();
                if ($hit) {
                    jsonOk(['msg' => 'Payment already recorded.', 'id' => (int)$hit['id'], 'invoice_no' => $hit['invoice_no'], 'idempotent_replay' => true]);
                }
            }
            throw $e;
        }
        jsonOk(['msg' => 'Payment recorded successfully.', 'id' => $newId, 'invoice_no' => $invoiceNo]);
    }
}

// ── Delete Payment (soft) ─────────────────────────────────────
// Same side-effects as void: remove the 'received' cash entry and release /
// delete the linked unit_charges. Without these, cash_api list_transactions
// and monthly_summary outstanding_charges keep counting the trashed payment.
if ($action === 'delete_payment') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');

    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=? AND deleted_at IS NULL");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found or already deleted.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'")->execute([$id]);
        if ($p['payment_type'] === 'service') {
            $pdo->prepare("UPDATE unit_charges SET payment_id=NULL WHERE payment_id=? AND source='pre_billed'")->execute([$id]);
            $pdo->prepare("DELETE FROM unit_charges WHERE payment_id=? AND source='auto_collected'")->execute([$id]);
        }
        logActivity($pdo,'DELETE_PAYMENT','Payments',"Soft-deleted payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Payment moved to trash. It can be restored from the Transaction Manager.']);
}

// ── Restore Deleted Payment (from trash) ──────────────────────
// Mirror of delete_payment side-effects: recreate the cash entry and re-link
// (or recreate) the unit_charges row. Symmetric with restore_payment for voids.
if ($action === 'restore_deleted_payment') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');

    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=? AND deleted_at IS NOT NULL");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found in trash.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET deleted_at=NULL WHERE id=?")->execute([$id]);
        // Only recreate the cash row if it isn't already there — covers the
        // historical case where an older delete_payment left it in place.
        $existing = $pdo->prepare("SELECT id FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received' LIMIT 1");
        $existing->execute([$id]);
        if (!$existing->fetchColumn()) {
            $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
                ->execute([$p['received_by'],'received',$p['amount'],$id,"Payment received: {$p['invoice_no']}",$p['payment_date']]);
        }
        if ($p['payment_type'] === 'service') {
            $look = $pdo->prepare("SELECT id FROM unit_charges WHERE unit_id=? AND service_type_id=? AND period_month=? AND period_year=? AND payment_id IS NULL AND source='pre_billed' LIMIT 1");
            $look->execute([$p['unit_id'], $p['service_type_id'], $p['period_month'], $p['period_year']]);
            $existChargeId = $look->fetchColumn();
            $alreadyLinked = $pdo->prepare("SELECT id FROM unit_charges WHERE payment_id=? LIMIT 1");
            $alreadyLinked->execute([$id]);
            if ($alreadyLinked->fetchColumn()) {
                // legacy state: charge survived a previous (broken) delete and is still linked
            } elseif ($existChargeId) {
                $pdo->prepare("UPDATE unit_charges SET payment_id=?, amount=?, charge_date=? WHERE id=?")
                    ->execute([$id, $p['amount'], $p['payment_date'], $existChargeId]);
            } else {
                $stRow = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
                $stRow->execute([$p['service_type_id']]);
                $stName  = $stRow->fetchColumn() ?: 'Service';
                $chgDesc = $p['notes'] ?: $stName;
                $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,payment_id,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,'auto_collected',?)")
                    ->execute([$p['unit_id'], $p['tenant_id'], $p['service_type_id'], $p['amount'], $chgDesc, $p['payment_date'], $p['period_month'], $p['period_year'], $id, $p['received_by']]);
            }
        }
        logActivity($pdo,'RESTORE_PAYMENT','Payments',"Restored deleted payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Payment restored successfully.']);
}

// ── Purge Payment (permanent hard delete) ────────────────────
if ($action === 'purge_payment') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');

    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=? AND deleted_at IS NOT NULL");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found in trash.');

    $pdo->beginTransaction();
    try {
        if ($p['payment_type'] === 'service') {
            $chargeQ = $pdo->prepare("SELECT id, source FROM unit_charges WHERE payment_id=?");
            $chargeQ->execute([$id]);
            $linkedCharge = $chargeQ->fetch();
            if ($linkedCharge && $linkedCharge['source'] === 'auto_collected') {
                $pdo->prepare("DELETE FROM unit_charges WHERE id=?")->execute([$linkedCharge['id']]);
            }
        }
        $pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
        logActivity($pdo,'PURGE_PAYMENT','Payments',"Permanently deleted payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Payment permanently deleted.']);
}

// ── Void Payment ──────────────────────────────────────────────
if ($action === 'void_payment') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');
    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found.');
    if ($p['deleted_at'])            jsonErr('Cannot void a deleted payment. Restore it from trash first.');
    if ($p['status'] === 'voided')   jsonErr('Payment is already voided.');
    if ($p['status'] === 'refunded') jsonErr('Cannot void a fully refunded payment.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET status='voided' WHERE id=?")->execute([$id]);
        // Remove the cash entry so the void is immediately reflected in all balance calculations.
        $pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'")->execute([$id]);
        if ($p['payment_type'] === 'service') {
            // Pre-billed charges existed before the payment — release the link so
            // they reappear as outstanding. Auto-collected charges were created
            // by save_payment and have no pre-existing counterpart, so they must
            // be deleted entirely; otherwise they show up as phantom "Unpaid"
            // entries that inflate outstanding balances.
            $pdo->prepare("UPDATE unit_charges SET payment_id=NULL WHERE payment_id=? AND source='pre_billed'")->execute([$id]);
            $pdo->prepare("DELETE FROM unit_charges WHERE payment_id=? AND source='auto_collected'")->execute([$id]);
        }
        logActivity($pdo,'VOID_PAYMENT','Payments',"Voided payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Payment voided. It is excluded from totals but remains on record.']);
}

// ── Restore Voided Payment ────────────────────────────────────
if ($action === 'restore_payment') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');
    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found.');
    if ($p['status'] !== 'voided') jsonErr('Payment is not voided.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET status='paid' WHERE id=?")->execute([$id]);
        // Re-create the cash entry that was removed when this payment was voided.
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$p['received_by'],'received',$p['amount'],$id,"Payment received: {$p['invoice_no']}",$p['payment_date']]);
        // Re-link the unit_charges row the same way save_payment did. Prefer an
        // existing pre_billed outstanding charge for the same period; if none,
        // recreate the auto_collected row that void_payment deleted.
        if ($p['payment_type'] === 'service') {
            $look = $pdo->prepare("SELECT id FROM unit_charges WHERE unit_id=? AND service_type_id=? AND period_month=? AND period_year=? AND payment_id IS NULL AND source='pre_billed' LIMIT 1");
            $look->execute([$p['unit_id'], $p['service_type_id'], $p['period_month'], $p['period_year']]);
            $existChargeId = $look->fetchColumn();
            if ($existChargeId) {
                $pdo->prepare("UPDATE unit_charges SET payment_id=?, amount=?, charge_date=? WHERE id=?")
                    ->execute([$id, $p['amount'], $p['payment_date'], $existChargeId]);
            } else {
                $stRow = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
                $stRow->execute([$p['service_type_id']]);
                $stName  = $stRow->fetchColumn() ?: 'Service';
                $chgDesc = $p['notes'] ?: $stName;
                $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,payment_id,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,'auto_collected',?)")
                    ->execute([$p['unit_id'], $p['tenant_id'], $p['service_type_id'], $p['amount'], $chgDesc, $p['payment_date'], $p['period_month'], $p['period_year'], $id, $p['received_by']]);
            }
        }
        logActivity($pdo,'RESTORE_PAYMENT','Payments',"Restored voided payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => 'Payment restored and active again.']);
}

// ── Get Single Payment ────────────────────────────────────────
if ($action === 'get_payment') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $row = $pdo->prepare("SELECT p.*, ru.unit_name, t.full_name as tenant_name, st.name as service_name, u.full_name as cashier_name
        FROM payments p
        LEFT JOIN rental_units ru ON p.unit_id=ru.id
        LEFT JOIN tenants t       ON p.tenant_id=t.id
        LEFT JOIN service_types st ON p.service_type_id=st.id
        LEFT JOIN users u          ON p.received_by=u.id
        WHERE p.id=?");
    $row->execute([$id]);
    $data = $row->fetch();
    if (!$data) jsonErr('Payment not found.');
    jsonOk(['payment' => $data]);
}

// ── Get Payments for a Unit+Period ───────────────────────────
if ($action === 'get_unit_payments') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $month  = (int)($_POST['month']   ?? date('n'));
    $year   = (int)($_POST['year']    ?? date('Y'));

    $rows = $pdo->prepare("SELECT p.*, st.name as service_name, u.full_name as cashier_name,
        COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id=p.id), 0) as refunded_total
        FROM payments p
        LEFT JOIN service_types st ON p.service_type_id=st.id
        LEFT JOIN users u          ON p.received_by=u.id
        WHERE p.unit_id=? AND p.period_month=? AND p.period_year=? AND p.deleted_at IS NULL AND p.status != 'voided'
        ORDER BY p.payment_date DESC, p.created_at DESC");
    $rows->execute([$unitId, $month, $year]);
    $payments = $rows->fetchAll();

    $unit = $pdo->prepare("SELECT ru.*, t.full_name as tenant_name, t.id as tenant_id FROM rental_units ru LEFT JOIN tenants t ON t.unit_id=ru.id AND t.status='active' WHERE ru.id=?");
    $unit->execute([$unitId]);
    $unitData = $unit->fetch();

    $cq = $pdo->prepare("SELECT uc.*, st.name as service_name FROM unit_charges uc LEFT JOIN service_types st ON uc.service_type_id=st.id WHERE uc.unit_id=? AND uc.period_month=? AND uc.period_year=? ORDER BY uc.charge_date ASC, uc.created_at ASC");
    $cq->execute([$unitId, $month, $year]);
    $charges = $cq->fetchAll();

    jsonOk(['payments' => $payments, 'unit' => $unitData, 'charges' => $charges]);
}

// ── Monthly Summary for All Units ────────────────────────────
if ($action === 'monthly_summary') {
    $month = (int)($_POST['month'] ?? date('n'));
    $year  = (int)($_POST['year']  ?? date('Y'));

    $rows = $pdo->prepare("
        SELECT
            ru.id, ru.unit_name, ru.monthly_rate, ru.status, ru.due_day,
            t.full_name as tenant_name, t.contract_start,
            COALESCE(SUM(CASE WHEN p.payment_type='rent' THEN p.amount ELSE 0 END), 0)    as rent_paid,
            COALESCE(SUM(CASE WHEN p.payment_type='service' THEN p.amount ELSE 0 END), 0) as service_paid,
            COALESCE(SUM(p.amount), 0) as total_paid,
            COALESCE((SELECT SUM(uc.amount) FROM unit_charges uc WHERE uc.unit_id=ru.id AND uc.period_month=? AND uc.period_year=? AND uc.payment_id IS NULL), 0) as outstanding_charges,
            (SELECT u2.full_name FROM payments p2 LEFT JOIN users u2 ON p2.received_by=u2.id WHERE p2.unit_id=ru.id AND p2.period_month=? AND p2.period_year=? AND p2.deleted_at IS NULL AND p2.status != 'voided' ORDER BY p2.created_at DESC LIMIT 1) as last_cashier
        FROM rental_units ru
        LEFT JOIN tenants t  ON t.unit_id=ru.id AND t.status='active'
        LEFT JOIN payments p ON p.unit_id=ru.id AND p.period_month=? AND p.period_year=? AND p.status != 'voided' AND p.deleted_at IS NULL
        GROUP BY ru.id
        ORDER BY ru.unit_name
    ");
    $rows->execute([$month, $year, $month, $year, $month, $year]);
    $summary = $rows->fetchAll();

    // Compute status + balance per unit (cents math — no float drift)
    foreach ($summary as &$row) {
        // Vacant units have no active tenant, so there is nothing to charge or owe.
        // Skip the rent computation so the row renders as a neutral dash, not a red balance.
        if ($row['status'] === 'vacant') {
            $row['expected_charge'] = '0.00';
            $row['balance']         = '0.00';
            $row['pay_status']      = 'gray';
            continue;
        }
        $rate         = getRateForMonth($pdo, (int)$row['id'], (float)$row['monthly_rate'], $month, $year);
        $expected     = prorateFirstMonth($rate, (int)$row['due_day'], $row['contract_start'] ?? null, $month, $year);
        $paid         = $row['rent_paid'];
        $unpaidRent   = money_max('0.00', money_sub($expected, $paid));
        $balance      = money_add($unpaidRent, $row['outstanding_charges']);
        $row['expected_charge'] = $expected;
        $row['balance'] = $balance;
        if (!money_is_pos($paid)) {
            $daysInMo  = (int)date('t', mktime(0,0,0,$month,1,$year));
            $dueTs     = mktime(0,0,0,$month,min((int)$row['due_day'],$daysInMo),$year);
            $row['pay_status'] = (time() > $dueTs) ? 'red' : 'amber';
        } elseif (money_is_pos($balance)) {
            $row['pay_status'] = 'amber';
        } else {
            $row['pay_status'] = 'green';
        }
    }
    unset($row);

    jsonOk(['summary' => $summary, 'month' => $month, 'year' => $year]);
}

// ── Get Tenants for a Unit (for dropdown) ─────────────────────
if ($action === 'get_unit_tenants') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $rows = $pdo->prepare("SELECT id, full_name FROM tenants WHERE unit_id=? ORDER BY status='active' DESC, full_name");
    $rows->execute([$unitId]);
    jsonOk(['tenants' => $rows->fetchAll()]);
}

// ── Process Refund ────────────────────────────────────────────
if ($action === 'process_refund') {
    requireAdmin();
    $paymentId     = (int)($_POST['payment_id'] ?? 0);
    $refundAmount  = $_POST['amount'] ?? '0';  // keep as string for cents math
    $reason        = trim($_POST['reason'] ?? '');

    if (!$paymentId)                  jsonErr('Payment ID required.');
    if (!money_is_pos($refundAmount)) jsonErr('Refund amount must be greater than zero.');
    if ($reason === '')               jsonErr('Reason is required.');

    // Refund validation and write must happen inside one serialised view of
    // the payment row, otherwise two concurrent refund requests can both
    // observe alreadyRefunded=0 and both over-refund. SELECT ... FOR UPDATE
    // holds the row until commit; the already-refunded sum is recomputed
    // inside the transaction.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT p.*, ru.unit_name
             FROM payments p
             LEFT JOIN rental_units ru ON p.unit_id=ru.id
             WHERE p.id=? FOR UPDATE"
        );
        $stmt->execute([$paymentId]);
        $pay = $stmt->fetch();
        if (!$pay)                          jsonErr('Payment not found.');
        if (!empty($pay['deleted_at']))     jsonErr('Cannot refund a deleted payment. Restore it from trash first.');
        if ($pay['status'] === 'voided')    jsonErr('Cannot refund a voided payment. Restore it first.');
        if ($pay['status'] === 'refunded')  jsonErr('This payment has already been fully refunded.');

        $refStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE payment_id=?");
        $refStmt->execute([$paymentId]);
        $alreadyRefunded = $refStmt->fetchColumn();
        $maxRefund       = money_sub($pay['amount'], $alreadyRefunded);
        if (money_gt($refundAmount, $maxRefund)) jsonErr("Maximum refundable amount is " . money($maxRefund) . ".");

        $pdo->prepare("INSERT INTO refunds (payment_id, amount, reason, refunded_by) VALUES (?,?,?,?)")
            ->execute([$paymentId, $refundAmount, $reason, $_SESSION['user']['id']]);

        // Refunded if this refund completes the full amount; otherwise partially_refunded.
        $totalRefundedAfter = money_add($alreadyRefunded, $refundAmount);
        $newStatus = money_gte($totalRefundedAfter, $pay['amount']) ? 'refunded' : 'partially_refunded';
        $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$newStatus, $paymentId]);

        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'], 'refunded', $refundAmount, $paymentId, "Refund: {$pay['invoice_no']} — $reason", date('Y-m-d')]);

        logActivity($pdo,'PROCESS_REFUND','Payments',"Refunded " . money($refundAmount) . " for payment #{$paymentId} ({$pay['invoice_no']}): $reason");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => "Refund of " . money($refundAmount) . " processed successfully.", 'status' => $newStatus]);
}

// ── Add / Update Service Charge (Pre-billing) ─────────────────
if ($action === 'save_charge') {
    $chargeId    = (int)($_POST['id'] ?? 0);
    $unitId      = (int)($_POST['unit_id'] ?? 0);
    $tenantId    = (int)($_POST['tenant_id'] ?? 0) ?: null;
    $serviceId   = (int)($_POST['service_type_id'] ?? 0) ?: null;
    $amount      = trim((string)($_POST['amount'] ?? '0'));
    $description = trim($_POST['description'] ?? '');
    $chargeDate  = $_POST['charge_date'] ?? date('Y-m-d');
    $periodMonth = (int)($_POST['period_month'] ?? date('n'));
    $periodYear  = (int)($_POST['period_year']  ?? date('Y'));

    if (!$unitId)              jsonErr('Rental unit is required.');
    if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
    if (!$description && $serviceId) {
        $stRow = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
        $stRow->execute([$serviceId]);
        $description = $stRow->fetchColumn() ?: 'Service Charge';
    }
    if (!$description) jsonErr('Description is required.');

    if ($chargeId) {
        $chk = $pdo->prepare("SELECT payment_id FROM unit_charges WHERE id=?");
        $chk->execute([$chargeId]);
        $chkRow = $chk->fetch();
        if (!$chkRow) jsonErr('Charge not found.');
        if ($chkRow['payment_id'] !== null) jsonErr('Cannot edit a paid charge. Refund the payment first.');
        $pdo->prepare("UPDATE unit_charges SET service_type_id=?,amount=?,description=?,charge_date=?,period_month=?,period_year=? WHERE id=? AND payment_id IS NULL")
            ->execute([$serviceId,$amount,$description,$chargeDate,$periodMonth,$periodYear,$chargeId]);
        logActivity($pdo,'UPDATE_CHARGE','Charges',"Updated service charge #$chargeId: $description ₱$amount");
        jsonOk(['msg' => 'Charge updated.', 'id' => $chargeId]);
    } else {
        $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,source,created_by) VALUES (?,?,?,?,?,?,?,?,'pre_billed',?)")
            ->execute([$unitId,$tenantId,$serviceId,$amount,$description,$chargeDate,$periodMonth,$periodYear,$_SESSION['user']['id']]);
        $newChargeId = (int)$pdo->lastInsertId();
        logActivity($pdo,'CREATE_CHARGE','Charges',"Created service charge #$newChargeId: $description ₱$amount for unit #$unitId");
        jsonOk(['msg' => 'Service charge added.', 'id' => $newChargeId]);
    }
}

// ── Delete Service Charge (Unpaid only) ───────────────────────
if ($action === 'delete_charge') {
    requireAdmin();
    $chargeId = (int)($_POST['id'] ?? 0);
    if (!$chargeId) jsonErr('Charge ID required.');
    $chk = $pdo->prepare("SELECT * FROM unit_charges WHERE id=?");
    $chk->execute([$chargeId]);
    $c = $chk->fetch();
    if (!$c) jsonErr('Charge not found.');
    if ($c['payment_id'] !== null) jsonErr('Cannot delete a paid charge. Use refund to reverse the payment.');
    $pdo->prepare("DELETE FROM unit_charges WHERE id=?")->execute([$chargeId]);
    logActivity($pdo,'DELETE_CHARGE','Charges',"Deleted service charge #{$chargeId}: {$c['description']} ₱{$c['amount']}");
    jsonOk(['msg' => 'Service charge deleted.']);
}

// ── Get Refunds for a Payment ─────────────────────────────────
if ($action === 'get_payment_refunds') {
    $paymentId = (int)($_POST['payment_id'] ?? $_GET['payment_id'] ?? 0);
    $rows = $pdo->prepare("
        SELECT r.*, u.full_name as refunded_by_name
        FROM refunds r
        LEFT JOIN users u ON r.refunded_by = u.id
        WHERE r.payment_id = ?
        ORDER BY r.refunded_at ASC
    ");
    $rows->execute([$paymentId]);
    jsonOk(['refunds' => $rows->fetchAll()]);
}

// ── Bulk Delete Payments ──────────────────────────────────────
if ($action === 'bulk_delete_payments') {
    requireAdmin();
    $ids = array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: []));
    if (empty($ids)) jsonErr('No payment IDs provided.');

    $deleted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $pay = $pdo->prepare("SELECT * FROM payments WHERE id=? AND deleted_at IS NULL");
            $pay->execute([$id]);
            $p = $pay->fetch();
            if (!$p) continue;

            // Same side-effects as single delete_payment — keep cash + unit_charges consistent.
            $pdo->prepare("UPDATE payments SET deleted_at=NOW() WHERE id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=? AND transaction_type='received'")->execute([$id]);
            if ($p['payment_type'] === 'service') {
                $pdo->prepare("UPDATE unit_charges SET payment_id=NULL WHERE payment_id=? AND source='pre_billed'")->execute([$id]);
                $pdo->prepare("DELETE FROM unit_charges WHERE payment_id=? AND source='auto_collected'")->execute([$id]);
            }
            logActivity($pdo, 'DELETE_PAYMENT', 'Payments', "Bulk soft-deleted payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
            $deleted++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonErr('Bulk delete failed: ' . $e->getMessage());
    }
    jsonOk(['msg' => "Moved {$deleted} payment(s) to trash.", 'deleted' => $deleted]);
}

exit;
