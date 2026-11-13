<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
         VALUES (?, 'LOGOUT', 'users', ?, 'User logged out')"
    )->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
}

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
