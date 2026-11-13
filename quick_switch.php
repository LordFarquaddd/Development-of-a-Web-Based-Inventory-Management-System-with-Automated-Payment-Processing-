<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Staff');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['admin_email'] ?? '');
    $password = $_POST['admin_password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Enter the admin email and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.password_hash,
                    u.status, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($admin['role_name'] !== 'Admin') {
            $error = 'That account is not an Admin account.';
        } elseif ($admin['status'] !== 'active') {
            $error = 'That admin account is ' . $admin['status'] . '.';
        } else {
            $staff_id = $_SESSION['user_id'];

            session_regenerate_id(true);
            $_SESSION['user_id']    = $admin['user_id'];
            $_SESSION['first_name'] = $admin['first_name'];
            $_SESSION['last_name']  = $admin['last_name'];
            $_SESSION['email']      = $admin['email'];
            $_SESSION['role_name']  = $admin['role_name'];

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'QUICK_SWITCH_LOGIN', 'users', ?, ?)"
            )->execute([
                $admin['user_id'],
                $admin['user_id'],
                "Switched into Admin account from Staff dashboard (originating staff user #{$staff_id})"
            ]);

            header('Location: dashboard_admin.php');
            exit;
        }
    }
}

header('Location: dashboard_staff.php?switch_error=' . urlencode($error));
exit;