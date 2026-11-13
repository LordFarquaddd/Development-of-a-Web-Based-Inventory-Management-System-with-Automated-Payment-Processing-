<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role_name']) {
        case 'Admin': header('Location: dashboard_admin.php'); break;
        default:      header('Location: dashboard_customer.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.email, u.password_hash,
                    u.status, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($user['status'] !== 'active') {
            $error = 'Your account is ' . $user['status'] . '. Please contact support.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role_name']  = $user['role_name'];

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'LOGIN', 'users', ?, 'User logged in')"
            )->execute([$user['user_id'], $user['user_id']]);

            switch ($user['role_name']) {
                case 'Admin':
                    header('Location: dashboard_admin.php');
                    break;
                case 'Staff':
                    header('Location: dashboard_staff.php');
                    break;
                default:
                    header('Location: dashboard_customer.php');
            }
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">

<title>Log In - HARIBONified Store</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { 
        font-family: 'Lato', sans-serif; 
        background: #193E57; 
        margin: 0;
        color: #f6f8ff;
        overflow-x: hidden;
    }
    .login-container {
        display: flex;
        width: 100vw;
        min-height: 100vh;      
        box-sizing: border-box;
    }

   .left-panel {
        position: relative;
        width: 50%;
        min-height: 100vh;
        background-color: #193E57;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .wave-divider {
        position: absolute;
        top: 0;
        right: -80px; 
        width: 120px;
        height: 100%;
        pointer-events: none;
    }

    .logo-container {
        text-align: center;
        z-index: 2;
        padding: 20px;
    }

    .logo-img {
        max-width: 500px;
        height: auto;
        display: block;
        margin: 0 auto;
    }

    .right-panel {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;        
        background-color: #ffffff;
    }

    .card {
        width: 400px;
        background: #17364B;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px #1b1c44;
    }
    h1 {
        font-size: 22px;
        margin-top: 0;
    }
    label {
        display: block;
        margin-top: 12px;
        font-weight: bold;
        font-size: 14px;
    }
    input[type=email], input[type=password] {
        width: 100%; 
        padding: 8px; 
        margin-top: 4px; 
        box-sizing: border-box;
        border: 1px solid #ccc; 
        border-radius: 4px;
    }
    .form-input {
        width: 100%; 
        padding: 8px; 
        margin-top: 4px; 
        box-sizing: border-box;
        border: 1px solid #ccc; 
        border-radius: 4px;
    }
    button {
        margin-top: 20px;
        width: 100%;
        padding: 10px;
        background: #e0b400;
        color: #193E57;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 15px;
        font-weight: bold;
    }
    button:hover {
        background: #957908;
    }
    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px;
        border-radius: 4px;
        margin-top: 12px;
    }
    .link {
        text-align: center;
        margin-top: 16px;
        font-size: 14px;
    }
    .link a {
        color: #e0b400;
    }
</style>
</head>

<body>
    <div class="login-container"> 
        <div class="left-panel">
            <div class="logo-container">
                <img src="logo.png" alt="HARIBONified Logo" class="logo-img" width="200" height="100">
                <img src="logotext2.png" alt="HARIBONified text" class="logo-img" width="500" height="450">
            </div>
    
            <svg class="wave-divider" viewBox="0 0 100 100" preserveAspectRatio="none">
                <path d="M 0,0 
                        L 100,0 
                        C 40,15 100,35 65,55 
                        C 30,75 90,85 100,105
                        L 0,100 Z" 
                        fill="#193e57" />
            </svg>
        </div>

        <div class="right-panel">
            <div class="card">
                <h1>Log In</h1>
                <form method="post" action="login.php">
                    <label>Email</label>
                    <input type="email" name="email" class="form-input" required>

                    <label>Password</label>
                    <div style="position: relative;">
                        <input type="password" name="password" id="password" class="form-input" required style="padding-right: 40px;">
                        <img src="view.png" id="toggleIcon" onclick="togglePassword()" 
                            style="position:absolute; right:10px; top:50%; transform:translateY(-50%); 
                                    cursor:pointer; width:20px; height:20px;">
                    </div>

                    <button type="submit">Log In</button>
                </form>

                <div class="link">No account yet? <a href="register.php">Register here</a></div>
            </div>
        </div>
    </div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon = document.getElementById('toggleIcon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.src = 'hide.png';
    } else {
        input.type = 'password';
        icon.src = 'view.png';
    }
}

</script>
</body>
</html>