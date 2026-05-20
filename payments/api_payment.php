<?php
// payments/api_payment.php — Payment CRUD API
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

header('Content-Type: application/json');
set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});
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
        // Update existing
        $pdo->prepare("UPDATE payments SET unit_id=?,tenant_id=?,payment_type=?,service_type_id=?,amount=?,payment_date=?,due_date=?,period_month=?,period_year=?,received_by=?,notes=? WHERE id=?")
            ->execute([$unitId,$tenantId,$type,$serviceId,$amount,$payDate,$dueDate,$periodMonth,$periodYear,$_SESSION['user']['id'],$notes,$id]);

        // Update cash transaction
        $pdo->prepare("UPDATE cash_transactions SET amount=?,transaction_date=? WHERE reference_payment_id=?")
            ->execute([$amount, $payDate, $id]);

        // Sync linked service charge amount/date
        if ($type === 'service') {
            $pdo->prepare("UPDATE unit_charges SET amount=?, charge_date=? WHERE payment_id=?")
                ->execute([$amount, $payDate, $id]);
        }

        logActivity($pdo,'UPDATE_PAYMENT','Payments',"Updated payment #$id, unit #$unitId, ₱$amount");
        jsonOk(['msg' => 'Payment updated successfully.', 'id' => $id]);
    } else {
        // New payment
        $invoiceNo = generateInvoiceNo($pdo);
        $pdo->prepare("INSERT INTO payments (invoice_no,unit_id,tenant_id,payment_type,service_type_id,amount,period_month,period_year,payment_date,due_date,received_by,notes)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$invoiceNo,$unitId,$tenantId,$type,$serviceId,$amount,$periodMonth,$periodYear,$payDate,$dueDate,$_SESSION['user']['id'],$notes]);
        $newId = (int)$pdo->lastInsertId();

        // Record cash on hand for the receiving user
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'],'received',$amount,$newId,"Payment received: $invoiceNo",$payDate]);

        // Auto-create or link service charge
        if ($type === 'service') {
            $stRow = $pdo->prepare("SELECT name FROM service_types WHERE id=?");
            $stRow->execute([$serviceId]);
            $stName  = $stRow->fetchColumn() ?: 'Service';
            $chgDesc = $notes ?: $stName;
            // Link to an existing pre-billed charge for this unit+service+period if one exists
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
        jsonOk(['msg' => 'Payment recorded successfully.', 'id' => $newId, 'invoice_no' => $invoiceNo]);
    }
}

// ── Delete Payment ────────────────────────────────────────────
if ($action === 'delete_payment') {
    requireAdmin(); // Only admins can delete payments
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Payment ID required.');

    $pay = $pdo->prepare("SELECT * FROM payments WHERE id=?");
    $pay->execute([$id]);
    $p = $pay->fetch();
    if (!$p) jsonErr('Payment not found.');

    // Handle linked service charge before deleting payment
    if ($p['payment_type'] === 'service') {
        $chargeQ = $pdo->prepare("SELECT id, source FROM unit_charges WHERE payment_id=?");
        $chargeQ->execute([$id]);
        $linkedCharge = $chargeQ->fetch();
        if ($linkedCharge && $linkedCharge['source'] === 'auto_collected') {
            // Auto-created charges are removed with the payment
            $pdo->prepare("DELETE FROM unit_charges WHERE id=?")->execute([$linkedCharge['id']]);
        }
        // Pre-billed charges: FK ON DELETE SET NULL reverts them to unpaid (outstanding)
    }

    // Remove linked cash transaction
    $pdo->prepare("DELETE FROM cash_transactions WHERE reference_payment_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
    logActivity($pdo,'DELETE_PAYMENT','Payments',"Deleted payment #{$id} ({$p['invoice_no']}) ₱{$p['amount']}");
    jsonOk(['msg' => 'Payment deleted. Cash record also removed.']);
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
        WHERE p.unit_id=? AND p.period_month=? AND p.period_year=?
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
            (SELECT u2.full_name FROM payments p2 LEFT JOIN users u2 ON p2.received_by=u2.id WHERE p2.unit_id=ru.id AND p2.period_month=? AND p2.period_year=? ORDER BY p2.created_at DESC LIMIT 1) as last_cashier
        FROM rental_units ru
        LEFT JOIN tenants t  ON t.unit_id=ru.id AND t.status='active'
        LEFT JOIN payments p ON p.unit_id=ru.id AND p.period_month=? AND p.period_year=?
        GROUP BY ru.id
        ORDER BY ru.unit_name
    ");
    $rows->execute([$month, $year, $month, $year, $month, $year]);
    $summary = $rows->fetchAll();

    // Compute status + balance per unit
    foreach ($summary as &$row) {
        $expected = prorateFirstMonth((float)$row['monthly_rate'], (int)$row['due_day'], $row['contract_start'] ?? null, $month, $year);
        $paid     = (float)$row['rent_paid'];
        $balance  = max(0, $expected - $paid) + (float)$row['outstanding_charges'];
        $row['expected_charge'] = $expected;
        $row['balance'] = $balance;
        if ($row['status'] === 'vacant') {
            $row['pay_status'] = 'gray';
        } elseif ($paid <= 0) {
            $dueTs = mktime(0,0,0,$month,$row['due_day'],$year);
            $row['pay_status'] = (time() > $dueTs) ? 'red' : 'amber';
        } elseif ($balance > 0) {
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
    $refundAmount  = (float)($_POST['amount'] ?? 0);
    $reason        = trim($_POST['reason'] ?? '');

    if (!$paymentId)        jsonErr('Payment ID required.');
    if ($refundAmount <= 0) jsonErr('Refund amount must be greater than zero.');
    if ($reason === '')     jsonErr('Reason is required.');

    // Load payment
    $stmt = $pdo->prepare("SELECT p.*, ru.unit_name FROM payments p LEFT JOIN rental_units ru ON p.unit_id=ru.id WHERE p.id=?");
    $stmt->execute([$paymentId]);
    $pay = $stmt->fetch();
    if (!$pay) jsonErr('Payment not found.');
    if ($pay['status'] === 'refunded') jsonErr('This payment has already been fully refunded.');

    // Sum already-refunded amounts
    $refStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE payment_id=?");
    $refStmt->execute([$paymentId]);
    $alreadyRefunded = (float)$refStmt->fetchColumn();
    $maxRefund = (float)$pay['amount'] - $alreadyRefunded;

    if ($refundAmount > $maxRefund + 0.001) jsonErr("Maximum refundable amount is ₱" . number_format($maxRefund, 2) . ".");

    // Insert refund record
    $pdo->prepare("INSERT INTO refunds (payment_id, amount, reason, refunded_by) VALUES (?,?,?,?)")
        ->execute([$paymentId, $refundAmount, $reason, $_SESSION['user']['id']]);

    // Update payment status
    $newStatus = (abs($refundAmount - (float)$pay['amount']) < 0.001 && $alreadyRefunded == 0)
        ? 'refunded'
        : (($alreadyRefunded + $refundAmount >= (float)$pay['amount'] - 0.001) ? 'refunded' : 'partially_refunded');
    $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$newStatus, $paymentId]);

    // Negative cash transaction
    $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
        ->execute([$_SESSION['user']['id'], 'refunded', $refundAmount, $paymentId, "Refund: {$pay['invoice_no']} — $reason", date('Y-m-d')]);

    logActivity($pdo,'PROCESS_REFUND','Payments',"Refunded ₱{$refundAmount} for payment #{$paymentId} ({$pay['invoice_no']}): $reason");
    jsonOk(['msg' => "Refund of ₱" . number_format($refundAmount, 2) . " processed successfully.", 'status' => $newStatus]);
}

// ── Add / Update Service Charge (Pre-billing) ─────────────────
if ($action === 'save_charge') {
    $chargeId    = (int)($_POST['id'] ?? 0);
    $unitId      = (int)($_POST['unit_id'] ?? 0);
    $tenantId    = (int)($_POST['tenant_id'] ?? 0) ?: null;
    $serviceId   = (int)($_POST['service_type_id'] ?? 0) ?: null;
    $amount      = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $chargeDate  = $_POST['charge_date'] ?? date('Y-m-d');
    $periodMonth = (int)($_POST['period_month'] ?? date('n'));
    $periodYear  = (int)($_POST['period_year']  ?? date('Y'));

    if (!$unitId)     jsonErr('Rental unit is required.');
    if ($amount <= 0) jsonErr('Amount must be greater than zero.');
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

exit;
