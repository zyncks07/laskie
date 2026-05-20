<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
if (isset($_SESSION['user'])) {
    $u = $_SESSION['user'];
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')[0]);
    try {
        $pdo->prepare("INSERT INTO system_logs (user_id,username,action,module,details,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$u['id'], $u['username'], 'LOGOUT', 'Auth', 'User signed out', $ip]);
    } catch (Exception $e) {}
}
session_destroy();
header('Location: index.php');
exit;
