<?php
// api/expenses_api.php — Expenses CRUD API
session_start();
define('JSON_RESPONSE', true);
require_once '../config/db.php';
require_once '../config/functions.php';
requireLogin();

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

    // Handle file upload
    if (!empty($_FILES['receipt_file']['name'])) {
        $up = handleUpload('receipt_file', 'receipts');
        if ($up['error']) jsonErr($up['error']);
        $receiptPath = $up['path'];
    }

    if ($id) {
        // Update — keep existing receipt if no new file uploaded
        if ($receiptPath) {
            $pdo->prepare("UPDATE expenses SET unit_id=?,category_id=?,amount=?,expense_date=?,description=?,notes=?,receipt_path=?,receipt_url=?,recorded_by=? WHERE id=?")
                ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptPath,$receiptUrl,$_SESSION['user']['id'],$id]);
        } else {
            $pdo->prepare("UPDATE expenses SET unit_id=?,category_id=?,amount=?,expense_date=?,description=?,notes=?,receipt_url=?,recorded_by=? WHERE id=?")
                ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptUrl,$_SESSION['user']['id'],$id]);
        }

        // Update linked cash transaction amount
        $pdo->prepare("UPDATE cash_transactions SET amount=?,transaction_date=? WHERE reference_expense_id=?")
            ->execute([$amount, $expDate, $id]);

        logActivity($pdo,'UPDATE_EXPENSE','Expenses',"Updated expense #$id: $description ₱$amount");
        jsonOk(['msg' => 'Expense updated successfully.']);
    } else {
        // Insert
        $pdo->prepare("INSERT INTO expenses (unit_id,category_id,amount,expense_date,description,notes,receipt_path,receipt_url,recorded_by)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$unitId,$categoryId,$amount,$expDate,$description,$notes,$receiptPath,$receiptUrl,$_SESSION['user']['id']]);
        $newId = (int)$pdo->lastInsertId();

        // Auto-create cash transaction (expense reduces cash on hand)
        $pdo->prepare("INSERT INTO cash_transactions (user_id,transaction_type,amount,reference_expense_id,notes,transaction_date)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'],'expense',$amount,$newId,"Expense: $description",$expDate]);

        logActivity($pdo,'CREATE_EXPENSE','Expenses',"Recorded expense: $description ₱$amount");
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

// ── Delete Expense ────────────────────────────────────────────
if ($action === 'delete_expense') {
    requireAdmin();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('Expense ID required.');

    $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
    $exp->execute([$id]);
    $e = $exp->fetch();
    if (!$e) jsonErr('Expense not found.');

    // Remove linked cash transaction
    $pdo->prepare("DELETE FROM cash_transactions WHERE reference_expense_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);

    logActivity($pdo,'DELETE_EXPENSE','Expenses',"Deleted expense #{$id}: {$e['description']} ₱{$e['amount']}");
    jsonOk(['msg' => 'Expense deleted. Cash record also removed.']);
}

// ── List Expenses (with filters) ──────────────────────────────
if ($action === 'list_expenses') {
    $month    = (int)($_POST['month'] ?? 0);
    $year     = (int)($_POST['year']  ?? date('Y'));
    $unitId   = (int)($_POST['unit_id'] ?? 0);
    $catId    = (int)($_POST['category_id'] ?? 0);
    $recorder = (int)($_POST['recorded_by'] ?? 0);

    $where  = ['1=1'];
    $params = [];

    if ($month > 0) { $where[] = 'MONTH(e.expense_date)=?'; $params[] = $month; }
    if ($year  > 0) { $where[] = 'YEAR(e.expense_date)=?';  $params[] = $year; }
    if ($unitId)    { $where[] = 'e.unit_id=?';              $params[] = $unitId; }
    if ($catId)     { $where[] = 'e.category_id=?';          $params[] = $catId; }
    if ($recorder)  { $where[] = 'e.recorded_by=?';          $params[] = $recorder; }

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

exit;
