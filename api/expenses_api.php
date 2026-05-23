<?php
// api/expenses_api.php — Expenses CRUD API
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();
csrfRequirePost();

header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Save Expense ──────────────────────────────────────────────
if ($action === 'save_expense') {
    $id          = (int)($_POST['id'] ?? 0);
    $unitId      = (int)($_POST['unit_id'] ?? 0) ?: null;
    $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $amount      = (float)($_POST['amount'] ?? 0);
    $expDate     = $_POST['expense_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $notes       = nullOrStr($_POST['notes'] ?? '');
    $receiptUrl  = nullOrStr($_POST['receipt_url'] ?? '');
    $receiptPath = null;

    if ($amount <= 0)   jsonErr('Amount must be greater than zero.');
    if (!$description)  jsonErr('Description is required.');
    if (empty($_SESSION['user']['id'])) jsonErr('No logged-in user. Expenses must be recorded by a system user.');

    // Handle file upload
    if (!empty($_FILES['receipt_file']['name'])) {
        $up = handleUpload('receipt_file', 'receipts');
        if ($up['error']) jsonErr($up['error']);
        $receiptPath = $up['path'];
    }

    if ($id) {
        requireAdmin(); // Only admins may edit existing expenses
        // Fetch before state for audit trail
        $oldRow = $pdo->prepare("SELECT unit_id, category_id, amount, expense_date, description, notes FROM expenses WHERE id=?");
        $oldRow->execute([$id]);
        $before = $oldRow->fetch() ?: [];

        $pdo->beginTransaction();
        try {
            if ($receiptPath) {
                $pdo->prepare("UPDATE expenses SET unit_id=?,category_id=?,amount=?,expense_date=?,description=?,notes=?,receipt_path=?,receipt_url=?,recorded_by=? WHERE id=?")
                    ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptPath,$receiptUrl,$_SESSION['user']['id'],$id]);
            } else {
                $pdo->prepare("UPDATE expenses SET unit_id=?,category_id=?,amount=?,expense_date=?,description=?,notes=?,receipt_url=?,recorded_by=? WHERE id=?")
                    ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptUrl,$_SESSION['user']['id'],$id]);
            }

            $pdo->prepare("UPDATE cash_transactions SET amount=?,transaction_date=? WHERE reference_expense_id=?")
                ->execute([$amount, $expDate, $id]);

            $after = ['unit_id'=>$unitId,'category_id'=>$categoryId,'amount'=>$amount,'expense_date'=>$expDate,'description'=>$description,'notes'=>$notes];
            logChange($pdo,'UPDATE_EXPENSE','Expenses',$before,$after);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg' => 'Expense updated successfully.']);
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO expenses (unit_id,category_id,amount,expense_date,description,notes,receipt_path,receipt_url,recorded_by)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptPath,$receiptUrl,$_SESSION['user']['id']]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_expense_id,notes,transaction_date)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$_SESSION['user']['id'],'expense',$amount,$newId,"Expense: $description",$expDate]);

            logActivity($pdo,'CREATE_EXPENSE','Expenses',"Recorded expense: $description ₱$amount");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        jsonOk(['msg' => 'Expense recorded successfully.', 'id' => $newId]);
    }
}

// ── Get Single Expense ────────────────────────────────────────
if ($action === 'get_expense') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->prepare("SELECT e.*, ec.name as category_name, ru.unit_name, u.full_name as recorder_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN rental_units ru       ON e.unit_id     = ru.id
        LEFT JOIN users u               ON e.recorded_by = u.id
        WHERE e.id = ?");
    $row->execute([$id]);
    $data = $row->fetch();
    if (!$data) jsonErr('Expense not found.');
    jsonOk(['expense' => $data]);
}

// ── Delete Expense (soft) ─────────────────────────────────────
if ($action === 'delete_expense') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Expense ID required.');

    $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NULL");
    $exp->execute([$id]);
    $e = $exp->fetch();
    if (!$e) jsonErr('Expense not found or already deleted.');

    $pdo->prepare("UPDATE expenses SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    logActivity($pdo,'DELETE_EXPENSE','Expenses',"Soft-deleted expense #{$id}: {$e['description']} ₱{$e['amount']}");
    jsonOk(['msg' => 'Expense moved to trash. It can be restored from the Transaction Manager.']);
}

// ── Restore Deleted Expense (from trash) ──────────────────────
if ($action === 'restore_deleted_expense') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Expense ID required.');

    $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NOT NULL");
    $exp->execute([$id]);
    $e = $exp->fetch();
    if (!$e) jsonErr('Expense not found in trash.');

    $pdo->prepare("UPDATE expenses SET deleted_at=NULL WHERE id=?")->execute([$id]);
    logActivity($pdo,'RESTORE_EXPENSE','Expenses',"Restored deleted expense #{$id}: {$e['description']} ₱{$e['amount']}");
    jsonOk(['msg' => 'Expense restored successfully.']);
}

// ── Purge Expense (permanent hard delete) ─────────────────────
if ($action === 'purge_expense') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Expense ID required.');

    $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NOT NULL");
    $exp->execute([$id]);
    $e = $exp->fetch();
    if (!$e) jsonErr('Expense not found in trash.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM cash_transactions WHERE reference_expense_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        logActivity($pdo,'PURGE_EXPENSE','Expenses',"Permanently deleted expense #{$id}: {$e['description']} ₱{$e['amount']}");
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
    jsonOk(['msg' => 'Expense permanently deleted.']);
}

// ── List Expenses (with filters) ──────────────────────────────
if ($action === 'list_expenses') {
    $month    = (int)($_POST['month'] ?? 0);
    $year     = (int)($_POST['year']  ?? date('Y'));
    $unitId   = (int)($_POST['unit_id'] ?? 0);
    $catId    = (int)($_POST['category_id'] ?? 0);
    $recorder = (int)($_POST['recorded_by'] ?? 0);
    $dateFrom = nullOrStr($_POST['date_from'] ?? '');
    $dateTo   = nullOrStr($_POST['date_to']   ?? '');

    $where  = ['1=1', 'e.deleted_at IS NULL'];
    $params = [];

    if ($dateFrom && $dateTo) {
        $where[] = 'e.expense_date BETWEEN ? AND ?'; $params[] = $dateFrom; $params[] = $dateTo;
    } elseif ($dateFrom) {
        $where[] = 'e.expense_date >= ?'; $params[] = $dateFrom;
    } elseif ($dateTo) {
        $where[] = 'e.expense_date <= ?'; $params[] = $dateTo;
    } else {
        // Sargable date filter: only when both month + year are supplied
        // does it make sense to bound; otherwise the index can still help
        // via year-only ranges.
        if ($month > 0 && $year > 0) {
            [$ms, $me] = monthRange($month, $year);
            $where[] = 'e.expense_date >= ? AND e.expense_date < ?';
            $params[] = $ms; $params[] = $me;
        } elseif ($year > 0) {
            [$ys, $ye] = yearRange($year);
            $where[] = 'e.expense_date >= ? AND e.expense_date < ?';
            $params[] = $ys; $params[] = $ye;
        }
    }
    if ($unitId)    { $where[] = 'e.unit_id=?';       $params[] = $unitId; }
    if ($catId)     { $where[] = 'e.category_id=?';   $params[] = $catId; }
    if ($recorder)  { $where[] = 'e.recorded_by=?';   $params[] = $recorder; }

    $sql = "SELECT e.*, ec.name as category_name, ru.unit_name, u.full_name as recorder_name
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN rental_units ru       ON e.unit_id     = ru.id
            LEFT JOIN users u               ON e.recorded_by = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.expense_date DESC, e.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $total = array_sum(array_column($rows, 'amount'));
    jsonOk(['expenses' => $rows, 'total' => $total, 'count' => count($rows)]);
}

// ── Category CRUD ─────────────────────────────────────────────
if ($action === 'save_category') {
    requireAdmin();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = nullOrStr($_POST['description'] ?? '');
    if (!$name) jsonErr('Category name is required.');
    if ($id) {
        $pdo->prepare("UPDATE expense_categories SET name=?,description=? WHERE id=?")->execute([$name,$desc,$id]);
        logActivity($pdo,'UPDATE_EXPENSE_CAT','Expenses',"Updated expense category #$id ($name)");
    } else {
        $pdo->prepare("INSERT INTO expense_categories (name,description) VALUES (?,?)")->execute([$name,$desc]);
        logActivity($pdo,'CREATE_EXPENSE_CAT','Expenses',"Created expense category: $name");
    }
    jsonOk(['msg' => 'Category saved.']);
}

if ($action === 'get_category') {
    $row = $pdo->prepare("SELECT * FROM expense_categories WHERE id=?");
    $row->execute([(int)$_POST['id']]);
    jsonOk(['category' => $row->fetch()]);
}

if ($action === 'delete_category') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id=?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() > 0) jsonErr('Cannot delete: category has existing expense records.');
    $pdo->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$id]);
    logActivity($pdo,'DELETE_EXPENSE_CAT','Expenses',"Deleted expense category #$id");
    jsonOk(['msg' => 'Category deleted.']);
}

// ── Bulk Delete Expenses ──────────────────────────────────────
if ($action === 'bulk_delete_expenses') {
    requireAdmin();
    $ids = array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: []));
    if (empty($ids)) jsonErr('No expense IDs provided.');

    $deleted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NULL");
            $exp->execute([$id]);
            $e = $exp->fetch();
            if (!$e) continue;

            $pdo->prepare("UPDATE expenses SET deleted_at=NOW() WHERE id=?")->execute([$id]);
            logActivity($pdo, 'DELETE_EXPENSE', 'Expenses', "Bulk soft-deleted expense #{$id}: {$e['description']} ₱{$e['amount']}");
            $deleted++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonErr('Bulk delete failed: ' . $e->getMessage());
    }
    jsonOk(['msg' => "Moved {$deleted} expense(s) to trash.", 'deleted' => $deleted]);
}

exit;
