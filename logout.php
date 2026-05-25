<?php
session_start();
require_once 'config/db.php';
require_once 'config/functions.php';
if (isset($_SESSION['user'])) {
    // logActivity() pulls user + IP from the session/REMOTE_ADDR via getClientIp(),
    // so X-Forwarded-For spoofing can't forge audit rows.
    logActivity($pdo, 'LOGOUT', 'Auth', 'User signed out');
}
session_destroy();
header('Location: index.php');
exit;
