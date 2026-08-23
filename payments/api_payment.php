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

// ── Charge Waivers (admin write-offs) ─────────────────────────
// Waiving is NOT a refund: no cash moves and no income changes, because a rent
// or service charge was never revenue — only collected payments are. A waiver
// just cancels what the tenant still owes, with a reason and an audit row.
//
// Rent charges are virtual (recomputed per render), so waiving one INSERTs a
// rent_charge_voids row. Service charges are real rows, so they soft-void in
// place. Both are reversible from the SoA.

/**
 * Waive part or all of one virtual rent charge.
 * Caller owns the transaction and must already hold the unit row lock.
 * $amount null/'' means "the whole waivable remainder".
 * Returns [ok(bool), message(string), amount(string)].
 */
function voidRentPeriod(PDO $pdo, int $unitId, int $month, int $year, ?int $tenantId, $amount, string $reason): array {
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) return [false, 'Invalid period.', '0.00'];

    $g     = getGrossRentCharge($pdo, $unitId, $month, $year, $tenantId);
    $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    if (!money_is_pos($g['gross'])) return [false, "No rent charge exists for $label.", '0.00'];

    $paid   = getRentPaidForPeriod($pdo, $unitId, $month, $year);
    $voided = getRentVoidedForPeriod($pdo, $unitId, $month, $year);
    $max    = waivableRent($g['gross'], $paid, $voided);
    if (!money_is_pos($max)) {
        return [false, "$label is already settled (paid or waived) — use Refund to reverse a payment.", '0.00'];
    }

    $amt = ($amount === null || $amount === '') ? $max : from_cents(to_cents($amount));
    if (!money_is_pos($amt))  return [false, 'Waived amount must be greater than zero.', '0.00'];
    if (money_gt($amt, $max)) return [false, "Maximum waivable amount for $label is " . money($max) . '.', '0.00'];

    $pdo->prepare(
        "INSERT INTO rent_charge_voids (unit_id,tenant_id,period_month,period_year,amount,reason,voided_by)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$unitId, $tenantId ?: $g['tenant_id'], $month, $year, $amt, $reason, $_SESSION['user']['id']]);

    logActivity($pdo, 'VOID_RENT_CHARGE', 'Charges',
        'Waived ' . money($amt) . " rent for unit #$unitId $label"
        . ($g['tenant_name'] ? " ({$g['tenant_name']})" : '') . ": $reason");

    return [true, "Rent for $label waived (" . money($amt) . ').', $amt];
}

/**
 * Soft-void one unpaid service charge (unit_charges row).
 * Caller owns the transaction. Returns [ok(bool), message(string), amount(string)].
 */
function voidServiceCharge(PDO $pdo, int $chargeId, string $reason): array {
    $chk = $pdo->prepare("SELECT * FROM unit_charges WHERE id=? FOR UPDATE");
    $chk->execute([$chargeId]);
    $c = $chk->fetch();
    if (!$c)                        return [false, 'Charge not found.', '0.00'];
    if ($c['payment_id'] !== null)  return [false, 'Cannot void a paid charge. Refund the payment first.', '0.00'];
    if (!empty($c['voided_at']))    return [false, 'That charge is already voided.', '0.00'];

    $pdo->prepare("UPDATE unit_charges SET voided_at=NOW(), voided_by=?, void_reason=? WHERE id=? AND payment_id IS NULL")
        ->execute([$_SESSION['user']['id'], $reason, $chargeId]);

    logActivity($pdo, 'VOID_CHARGE', 'Charges',
        "Voided service charge #{$chargeId}: {$c['description']} " . money($c['amount']) . " — $reason");

    return [true, 'Service charge voided.', from_cents(to_cents($c['amount']))];
}

// ── Record Payment ────────────────────────────────────────────
if ($action === 'save_payment') {
    $id          = (int)($_POST['id'] ?? 0);
    $unitId      = (int)($_POST['unit_id'] ?? 0);
    $tenantId    = (int)($_POST['tenant_id'] ?? 0) ?: null;
    $type        = $_POST['payment_type'] ?? 'rent';
    $serviceId   = (int)($_POST['service_type_id'] ?? 0) ?: null;
    $amount      = trim((string)($_POST['amount'] ?? '0'));
    $payDate     = $_POST['payment_date'] ?? date('Y-m-d');
    $dueDate     = nullOrStr($_POST['due_date'] ?? '');
    $periodMonth = (int)($_POST['period_month'] ?? date('n'));
    $periodYear  = (int)($_POST['period_year']  ?? date('Y'));
    $notes       = nullOrStr($_POST['notes'] ?? '');
    $receiptUrl  = nullOrStr($_POST['receipt_url'] ?? '');
    $receiptPath = null;

    if (!$unitId)             jsonErr('Rental unit is required.');
    if (!money_is_pos($amount)) jsonErr('Amount must be greater than zero.');
    if (!in_array($type, ['rent','service'])) jsonErr('Invalid payment type.');

    // Editing an existing payment is admin-only. Guard BEFORE touching the
    // filesystem so a non-admin edit attempt can't leave an orphaned upload
    // (mirrors api/expenses_api.php save_expense ordering).
    if ($id) requireAdmin();

    // Optional proof-of-payment upload (bank-transfer screenshot / PDF receipt).
    // Done once here — outside the INSERT retry loop below — so a retried insert
    // never uploads twice. handleUpload is a no-op when no file was submitted.
    if (!empty($_FILES['receipt_file']['name'])) {
        $up = handleUpload('receipt_file', 'payments');
        if ($up['error']) jsonErr($up['error']);
        $receiptPath = $up['path'];
    }

    if ($id) {
        // Fetch full record for before/after audit trail + validation
        $chk = $pdo->prepare("SELECT * FROM payments WHERE id=?");
        $chk->execute([$id]);
        $chkRow = $chk->fetch();
        if (!$chkRow)                          jsonErr('Payment not found.');
        if ($chkRow['deleted_at'])             jsonErr('Cannot edit a deleted payment. Restore it from the trash first.');
        if ($chkRow['status'] === 'voided')    jsonErr('Cannot edit a voided payment. Restore it first.');

        // Structural changes (unit_id, payment_type, service_type_id) break the
        // unit_charges linkage in subtle ways:
        //   - rent → service has no unit_charges row to update or create here
        //   - service A → service B leaves the old charge linked under the old service
        //   - unit move re-attaches the charge to the wrong unit's outstanding list
        // Rather than reimplement the full save_payment unit_charges logic for
        // every edit shape, refuse the structural change up front. The admin
        // can void the payment and re-record it cleanly.
        if ((int)$chkRow['unit_id'] !== $unitId) {
            jsonErr('Cannot change the unit on an existing payment. Void it and record a new one instead.');
        }
        if ($chkRow['payment_type'] !== $type) {
            jsonErr('Cannot change rent ↔ service on an existing payment. Void it and record a new one instead.');
        }
        if ($type === 'service' && (int)($chkRow['service_type_id'] ?? 0) !== (int)$serviceId) {
            jsonErr('Cannot change the service type on an existing payment. Void it and record a new one instead.');
        }

        $before = array_intersect_key($chkRow, array_flip(['unit_id','tenant_id','payment_type','service_type_id','amount','payment_date','due_date','period_month','period_year','notes']));

        $pdo->beginTransaction();
        try {
            // received_by is intentionally left out of the UPDATE: it records the
            // cashier who collected the payment, not whoever last edited the row.
            // Overwriting it would silently rewrite the cashier on the receipt and
            // create a mismatch with cash_transactions.user_id (which we do NOT
            // touch here). The edit itself is captured in system_logs via
            // logChange() a few lines below.
            // receipt_url always round-trips through the form (editPayment
            // prefills it). receipt_path is only overwritten when a NEW file was
            // uploaded — otherwise omit it so an edit doesn't wipe the existing
            // receipt. Same two-branch shape as save_expense.
            if ($receiptPath) {
                $pdo->prepare("UPDATE payments SET unit_id=?,tenant_id=?,payment_type=?,service_type_id=?,amount=?,payment_date=?,due_date=?,period_month=?,period_year=?,notes=?,receipt_path=?,receipt_url=? WHERE id=?")
                    ->execute([$unitId,$tenantId,$type,$serviceId,$amount,$payDate,$dueDate,$periodMonth,$periodYear,$notes,$receiptPath,$receiptUrl,$id]);
            } else {
                $pdo->prepare("UPDATE payments SET unit_id=?,tenant_id=?,payment_type=?,service_type_id=?,amount=?,payment_date=?,due_date=?,period_month=?,period_year=?,notes=?,receipt_url=? WHERE id=?")
                    ->execute([$unitId,$tenantId,$type,$serviceId,$amount,$payDate,$dueDate,$periodMonth,$periodYear,$notes,$receiptUrl,$id]);
            }

            // Only sync the 'received' cash row that mirrors this payment. Refunds
            // also store reference_payment_id, and we must not overwrite their
            // amount/date with the payment's new values.
            $pdo->prepare("UPDATE cash_transactions SET amount=?,transaction_date=? WHERE reference_payment_id=? AND transaction_type='received'")
                ->execute([$amount, $payDate, $id]);

            if ($type === 'service') {
                // After the guards above, unit_id + service_type stay the same — only
                // amount / charge_date can change, which is exactly what this updates.
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

        // New payment — wrap payments + cash_transactions + unit_charges as one atomic unit.
        // generateInvoiceNo computes MAX(invoice_no)+1 without a lock, so two
        // concurrent saves can compute the same number; the UNIQUE index on
        // invoice_no then bounces one with errno 1062. Retry a handful of
        // times with a fresh number before giving up. The idempotency_key
        // collision path is still handled separately — it means a duplicate
        // submit from the same client, not a races between users.
        $maxRetries = 5;
        $attempt    = 0;
        $newId      = 0;
        $invoiceNo  = '';
        while (true) {
            $attempt++;
            $pdo->beginTransaction();
            try {
                $invoiceNo = generateInvoiceNo($pdo);
                $pdo->prepare("INSERT INTO payments (invoice_no,unit_id,tenant_id,payment_type,service_type_id,amount,period_month,period_year,payment_date,due_date,received_by,notes,receipt_path,receipt_url,idempotency_key)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$invoiceNo,$unitId,$tenantId,$type,$serviceId,$amount,$periodMonth,$periodYear,$payDate,$dueDate,$_SESSION['user']['id'],$notes,$receiptPath,$receiptUrl,$idempotencyKey]);
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
                break; // success — exit retry loop
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $isDup   = $e instanceof PDOException && (int)$e->errorInfo[1] === 1062;
                $errText = $isDup ? ($e->errorInfo[2] ?? '') : '';
                // Idempotency-key collision: same client submitted twice — return the original row.
                if ($isDup && $idempotencyKey !== null && stripos($errText, 'idempotency_key') !== false) {
                    $look = $pdo->prepare("SELECT id, invoice_no FROM payments WHERE idempotency_key=?");
                    $look->execute([$idempotencyKey]);
                    $hit = $look->fetch();
                    if ($hit) {
                        jsonOk(['msg' => 'Payment already recorded.', 'id' => (int)$hit['id'], 'invoice_no' => $hit['invoice_no'], 'idempotent_replay' => true]);
                    }
                }
                // Invoice-number collision: another save grabbed the same number first.
                // Retry with a freshly-computed invoice_no.
                if ($isDup && stripos($errText, 'invoice_no') !== false && $attempt < $maxRetries) {
                    continue;
                }
                throw $e;
            }
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
    // Same reasoning as void_payment: soft-deleting drops the 'received' cash
    // row, but the 'refunded' rows would remain orphaned — the cashier's
    // cash-on-hand would end up minus the refund amount. Refunds must be
    // reversed first, or the payment must be purged (which CASCADE-deletes
    // refunds and all linked cash rows).
    if ($p['status'] === 'refunded' || $p['status'] === 'partially_refunded') {
        jsonErr('Cannot delete a refunded payment. Reverse the refunds first or use Purge from the trash to permanently remove it.');
    }

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
            // Fall back to the acting admin if the original collector was deleted
            // (received_by SET NULL) — cash_transactions.user_id is NOT NULL.
            $cashUserId = $p['received_by'] ?: (int)$_SESSION['user']['id'];
            $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
                ->execute([$cashUserId,'received',$p['amount'],$id,"Payment received: {$p['invoice_no']}",$p['payment_date']]);
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
                    ->execute([$p['unit_id'], $p['tenant_id'], $p['service_type_id'], $p['amount'], $chgDesc, $p['payment_date'], $p['period_month'], $p['period_year'], $id, $cashUserId]);
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
    if ($p['deleted_at'])                       jsonErr('Cannot void a deleted payment. Restore it from trash first.');
    if ($p['status'] === 'voided')              jsonErr('Payment is already voided.');
    if ($p['status'] === 'refunded')            jsonErr('Cannot void a fully refunded payment.');
    // A partial refund means the tenant has already received cash back. Voiding
    // the original receipt now would leave the 'refunded' cash entry orphaned
    // and drag the cashier's cash-on-hand negative by the refund amount. The
    // admin must reverse the refund first (or accept the payment as final).
    if ($p['status'] === 'partially_refunded') jsonErr('Cannot void a partially refunded payment. Reverse the refunds first.');
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
        // Fall back to the acting admin if the original collector was deleted
        // (received_by SET NULL) — cash_transactions.user_id is NOT NULL.
        $cashUserId = $p['received_by'] ?: (int)$_SESSION['user']['id'];
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$cashUserId,'received',$p['amount'],$id,"Payment received: {$p['invoice_no']}",$p['payment_date']]);
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
                    ->execute([$p['unit_id'], $p['tenant_id'], $p['service_type_id'], $p['amount'], $chgDesc, $p['payment_date'], $p['period_month'], $p['period_year'], $id, $cashUserId]);
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

    $cq = $pdo->prepare("SELECT uc.*, st.name as service_name FROM unit_charges uc LEFT JOIN service_types st ON uc.service_type_id=st.id WHERE uc.unit_id=? AND uc.period_month=? AND uc.period_year=? AND uc.voided_at IS NULL ORDER BY uc.charge_date ASC, uc.created_at ASC");
    $cq->execute([$unitId, $month, $year]);
    $charges = $cq->fetchAll();

    // Net of refunds: each payment counts for amount − its refunded_total, so a
    // fully-refunded receipt contributes 0 to the period's Total Paid. Also expose
    // per-row net_amount so the grid can show the effective figure.
    $totalPaid = '0.00';
    foreach ($payments as &$pRow) {
        $pRow['net_amount'] = money_sub($pRow['amount'], $pRow['refunded_total']);
        $totalPaid = money_add($totalPaid, $pRow['net_amount']);
    }
    unset($pRow);
    jsonOk(['payments' => $payments, 'unit' => $unitData, 'charges' => $charges, 'total_paid' => $totalPaid]);
}

// ── Monthly Summary for All Units ────────────────────────────
if ($action === 'monthly_summary') {
    $month = (int)($_POST['month'] ?? date('n'));
    $year  = (int)($_POST['year']  ?? date('Y'));

    $rows = $pdo->prepare("
        SELECT
            ru.id, ru.unit_name, ru.monthly_rate, ru.status, ru.due_day,
            t.full_name as tenant_name, t.contract_start,
            COALESCE(SUM(CASE WHEN p.payment_type='rent' THEN p.amount - COALESCE(r.refsum,0) ELSE 0 END), 0)    as rent_paid,
            COALESCE(SUM(CASE WHEN p.payment_type='service' THEN p.amount - COALESCE(r.refsum,0) ELSE 0 END), 0) as service_paid,
            COALESCE(SUM(p.amount - COALESCE(r.refsum,0)), 0) as total_paid,
            COALESCE((SELECT SUM(uc.amount) FROM unit_charges uc WHERE uc.unit_id=ru.id AND uc.period_month=? AND uc.period_year=? AND uc.payment_id IS NULL AND uc.voided_at IS NULL), 0) as outstanding_charges,
            (SELECT u2.full_name FROM payments p2 LEFT JOIN users u2 ON p2.received_by=u2.id WHERE p2.unit_id=ru.id AND p2.period_month=? AND p2.period_year=? AND p2.deleted_at IS NULL AND p2.status != 'voided' ORDER BY p2.created_at DESC LIMIT 1) as last_cashier
        FROM rental_units ru
        LEFT JOIN tenants t  ON t.unit_id=ru.id AND t.status='active'
        LEFT JOIN payments p ON p.unit_id=ru.id AND p.period_month=? AND p.period_year=? AND p.status != 'voided' AND p.deleted_at IS NULL
        LEFT JOIN (SELECT payment_id, SUM(amount) AS refsum FROM refunds GROUP BY payment_id) r ON r.payment_id = p.id
        GROUP BY ru.id
        ORDER BY ru.unit_name
    ");
    $rows->execute([$month, $year, $month, $year, $month, $year]);
    $summary = $rows->fetchAll();

    // Admin rent waivers for this period, keyed by unit_id — one query for the
    // whole grid rather than one per unit inside the loop below.
    $rentVoids = getRentVoidTotals($pdo, $month, $year);

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
        $rate                = getRateForMonth($pdo, (int)$row['id'], (float)$row['monthly_rate'], $month, $year);
        $row['monthly_rate'] = number_format($rate, 2, '.', ''); // reflect history-adjusted rate in the display column
        $expected     = prorateFirstMonth($rate, (int)$row['due_day'], $row['contract_start'] ?? null, $month, $year);
        $waived       = $rentVoids[(int)$row['id']] ?? '0.00';
        $expected     = money_max('0.00', money_sub($expected, $waived));
        $row['rent_waived']     = $waived;
        $paid         = $row['rent_paid'];
        $unpaidRent   = money_max('0.00', money_sub($expected, $paid));
        $balance      = money_add($unpaidRent, $row['outstanding_charges']);
        $row['expected_charge'] = $expected;
        $row['balance'] = $balance;
        // money_is_pos($expected) guard: a fully-waived month owes nothing, so it
        // must not render as overdue just because no payment was collected.
        if (!money_is_pos($paid) && money_is_pos($expected)) {
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

        // Which cashier physically returns the cash? Admin picks (cashier_id);
        // defaults to the original collector, then the acting admin if that
        // user is gone. That user's cash_on_hand is what drops.
        $cashierId = (int)($_POST['cashier_id'] ?? 0) ?: (int)($pay['received_by'] ?: $_SESSION['user']['id']);
        $cu = $pdo->prepare("SELECT full_name FROM users WHERE id=? AND status='active'");
        $cu->execute([$cashierId]);
        $cashierName = $cu->fetchColumn();
        if (!$cashierName) jsonErr('Selected cashier must be an active user.');

        // Serialize refunds drawing from the same cashier: lock their user row so
        // two concurrent refunds (on different payments) can't both pass the gate
        // below off the same balance snapshot and overdraw cash on hand.
        $pdo->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$cashierId]);

        // Hard gate: you cannot hand back more cash than the cashier is holding.
        // If short, the cashier needs a vault "return to user" to top up first
        // (request flow lives in api/requests_api.php / admin/requests.php).
        $available = getUserCashOnHand($pdo, $cashierId);
        if (money_gt($refundAmount, $available)) {
            jsonErr("$cashierName has only " . money($available) . " cash on hand — short by "
                . money(money_sub($refundAmount, $available))
                . ". Request a vault return to top up their cash before refunding.");
        }

        $pdo->prepare("INSERT INTO refunds (payment_id, amount, reason, refunded_by) VALUES (?,?,?,?)")
            ->execute([$paymentId, $refundAmount, $reason, $_SESSION['user']['id']]);

        // Refunded if this refund completes the full amount; otherwise partially_refunded.
        $totalRefundedAfter = money_add($alreadyRefunded, $refundAmount);
        $newStatus = money_gte($totalRefundedAfter, $pay['amount']) ? 'refunded' : 'partially_refunded';
        $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$newStatus, $paymentId]);

        // Attribute the cash outflow to the chosen cashier (validated + gated
        // above) — they physically return the cash, so their cash_on_hand drops.
        // refunds.refunded_by still captures who APPROVED the refund (the admin).
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_payment_id,notes,transaction_date) VALUES (?,?,?,?,?,?)")
            ->execute([$cashierId, 'refunded', $refundAmount, $paymentId, "Refund: {$pay['invoice_no']} — $reason", date('Y-m-d')]);

        logActivity($pdo,'PROCESS_REFUND','Payments',"Refunded " . money($refundAmount) . " for payment #{$paymentId} ({$pay['invoice_no']}): $reason (cash debited from {$cashierName} #{$cashierId})");
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
        $chk = $pdo->prepare("SELECT service_type_id, amount, description, charge_date, period_month, period_year, payment_id FROM unit_charges WHERE id=?");
        $chk->execute([$chargeId]);
        $chkRow = $chk->fetch();
        if (!$chkRow) jsonErr('Charge not found.');
        if ($chkRow['payment_id'] !== null) jsonErr('Cannot edit a paid charge. Refund the payment first.');
        $before = array_intersect_key($chkRow, array_flip(['service_type_id','amount','description','charge_date','period_month','period_year']));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE unit_charges SET service_type_id=?,amount=?,description=?,charge_date=?,period_month=?,period_year=? WHERE id=? AND payment_id IS NULL")
                ->execute([$serviceId,$amount,$description,$chargeDate,$periodMonth,$periodYear,$chargeId]);
            logChange($pdo,'UPDATE_CHARGE','Charges',$before,['service_type_id'=>$serviceId,'amount'=>$amount,'description'=>$description,'charge_date'=>$chargeDate,'period_month'=>$periodMonth,'period_year'=>$periodYear]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        jsonOk(['msg' => 'Charge updated.', 'id' => $chargeId]);
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO unit_charges (unit_id,tenant_id,service_type_id,amount,description,charge_date,period_month,period_year,source,created_by) VALUES (?,?,?,?,?,?,?,?,'pre_billed',?)")
                ->execute([$unitId,$tenantId,$serviceId,$amount,$description,$chargeDate,$periodMonth,$periodYear,$_SESSION['user']['id']]);
            $newChargeId = (int)$pdo->lastInsertId();
            logActivity($pdo,'CREATE_CHARGE','Charges',"Created service charge #$newChargeId: $description " . money($amount) . " for unit #$unitId");
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        jsonOk(['msg' => 'Service charge added.', 'id' => $newChargeId]);
    }
}

// ── Void Service Charge (Unpaid only) ─────────────────────────
// Soft void, not a DELETE: the row stays for the audit trail and can be
// restored (restore_charge). Action name kept so existing callers keep working.
if ($action === 'delete_charge' || $action === 'void_charge') {
    requireAdmin();
    $chargeId = (int)($_POST['id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');
    if (!$chargeId)     jsonErr('Charge ID required.');
    if ($reason === '') jsonErr('Reason is required.');

    $pdo->beginTransaction();
    try {
        [$ok, $msg] = voidServiceCharge($pdo, $chargeId, $reason);
        if (!$ok) { $pdo->rollBack(); jsonErr($msg); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => $msg]);
}

// ── Void a Rent Charge ────────────────────────────────────────
if ($action === 'void_rent_charge') {
    requireAdmin();
    $unitId   = (int)($_POST['unit_id'] ?? 0);
    $month    = (int)($_POST['period_month'] ?? 0);
    $year     = (int)($_POST['period_year']  ?? 0);
    $tenantId = (int)($_POST['tenant_id'] ?? 0) ?: null;
    $amount   = trim((string)($_POST['amount'] ?? ''));
    $reason   = trim($_POST['reason'] ?? '');

    if (!$unitId)       jsonErr('Rental unit is required.');
    if ($reason === '') jsonErr('Reason is required.');

    // Lock the unit row so two concurrent waivers can't both pass the cap check
    // off the same snapshot and over-waive the period (same guard style as
    // process_refund locking the cashier's user row).
    $pdo->beginTransaction();
    try {
        $pdo->prepare("SELECT id FROM rental_units WHERE id=? FOR UPDATE")->execute([$unitId]);
        [$ok, $msg] = voidRentPeriod($pdo, $unitId, $month, $year, $tenantId, $amount, $reason);
        if (!$ok) { $pdo->rollBack(); jsonErr($msg); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    jsonOk(['msg' => $msg]);
}

// ── Restore a Voided Rent Charge ──────────────────────────────
if ($action === 'restore_rent_charge') {
    requireAdmin();
    $voidId = (int)($_POST['id'] ?? 0);
    if (!$voidId) jsonErr('Waiver ID required.');

    $chk = $pdo->prepare("SELECT * FROM rent_charge_voids WHERE id=?");
    $chk->execute([$voidId]);
    $v = $chk->fetch();
    if (!$v)                        jsonErr('Waiver not found.');
    if (!empty($v['restored_at']))  jsonErr('That waiver has already been restored.');

    $pdo->prepare("UPDATE rent_charge_voids SET restored_at=NOW(), restored_by=? WHERE id=? AND restored_at IS NULL")
        ->execute([$_SESSION['user']['id'], $voidId]);

    $label = date('F Y', mktime(0, 0, 0, (int)$v['period_month'], 1, (int)$v['period_year']));
    logActivity($pdo, 'RESTORE_RENT_CHARGE', 'Charges',
        'Restored ' . money($v['amount']) . " waived rent for unit #{$v['unit_id']} $label (waiver #$voidId)");

    jsonOk(['msg' => "Rent charge for $label restored."]);
}

// ── Restore a Voided Service Charge ───────────────────────────
if ($action === 'restore_charge') {
    requireAdmin();
    $chargeId = (int)($_POST['id'] ?? 0);
    if (!$chargeId) jsonErr('Charge ID required.');

    $chk = $pdo->prepare("SELECT * FROM unit_charges WHERE id=?");
    $chk->execute([$chargeId]);
    $c = $chk->fetch();
    if (!$c)                    jsonErr('Charge not found.');
    if (empty($c['voided_at'])) jsonErr('That charge is not voided.');

    $pdo->prepare("UPDATE unit_charges SET voided_at=NULL, voided_by=NULL, void_reason=NULL WHERE id=?")
        ->execute([$chargeId]);
    logActivity($pdo, 'RESTORE_CHARGE', 'Charges',
        "Restored voided service charge #{$chargeId}: {$c['description']} " . money($c['amount']));

    jsonOk(['msg' => 'Service charge restored.']);
}

// ── Bulk Void (SoA "Void Charges" modal) ──────────────────────
// items: JSON list of {type:'rent',period_month,period_year,tenant_id?} and
// {type:'service',id}. Every item is re-validated through the same helpers the
// single-item actions use — the client's amounts are never trusted.
if ($action === 'bulk_void_charges') {
    requireAdmin();
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $items  = json_decode($_POST['items'] ?? '[]', true) ?: [];

    if (!$unitId)       jsonErr('Rental unit is required.');
    if ($reason === '') jsonErr('Reason is required.');
    if (!$items)        jsonErr('Select at least one charge to void.');
    if (count($items) > 200) jsonErr('Too many charges selected at once (max 200).');

    $done = 0; $totalWaived = '0.00'; $skipped = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("SELECT id FROM rental_units WHERE id=? FOR UPDATE")->execute([$unitId]);
        foreach ($items as $it) {
            $type = $it['type'] ?? '';
            if ($type === 'rent') {
                [$ok, $msg, $amt] = voidRentPeriod(
                    $pdo, $unitId, (int)($it['period_month'] ?? 0), (int)($it['period_year'] ?? 0),
                    (int)($it['tenant_id'] ?? 0) ?: null, null, $reason
                );
            } elseif ($type === 'service') {
                [$ok, $msg, $amt] = voidServiceCharge($pdo, (int)($it['id'] ?? 0), $reason);
            } else {
                $ok = false; $msg = 'Unknown charge type.'; $amt = '0.00';
            }
            if ($ok) { $done++; $totalWaived = money_add($totalWaived, $amt); }
            else     { $skipped[] = $msg; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (!$done) jsonErr($skipped ? implode(' ', array_slice($skipped, 0, 3)) : 'Nothing was voided.');
    $msg = "$done charge" . ($done !== 1 ? 's' : '') . ' voided — ' . money($totalWaived) . ' waived.';
    if ($skipped) $msg .= ' ' . count($skipped) . ' skipped.';
    jsonOk(['msg' => $msg, 'voided' => $done, 'total' => $totalWaived, 'skipped' => $skipped]);
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
            // Refunded / partially-refunded rows are skipped silently: the same
            // reasoning as the single delete_payment path — soft-deleting would
            // leave orphan 'refunded' cash rows and drag cash-on-hand negative.
            if ($p['status'] === 'refunded' || $p['status'] === 'partially_refunded') continue;

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
