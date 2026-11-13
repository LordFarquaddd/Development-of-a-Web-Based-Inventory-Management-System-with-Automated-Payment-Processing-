<?php
require_once 'config.php';

$errors = [];
$success = false;

$first_name = $last_name = $email = $contact_number = $address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first_name     = trim($_POST['first_name'] ?? '');
    $last_name      = trim($_POST['last_name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address        = trim($_POST['address'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First and last name are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (empty($errors)) {
        $CUSTOMER_ROLE_ID = 3;
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (role_id, first_name, last_name, email, password_hash, contact_number, address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $CUSTOMER_ROLE_ID, $first_name, $last_name, $email,
            $password_hash, $contact_number, $address
        ]);

        $userId = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'REGISTER', 'users', ?, 'New customer account created')"
        )->execute([$userId, $userId]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Account - HARIBONified Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Lato', Arial, sans-serif;
        background: #193E57;
        margin: 0;
        color: #f6f8ff;
    }
    .container {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        min-height: 100vh;
        box-sizing: border-box;
        padding: 40px 0;
    }
    .card {
        width: 420px;
        background: #17364B;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
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
    input[type=text], input[type=email], input[type=password], textarea {
        width: 100%;
        padding: 8px;
        margin-top: 4px;
        box-sizing: border-box;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-family: inherit;
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
        font-family: 'Montserrat', sans-serif;
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
    .success {
        background: #eafaf1;
        color: #1e7e34;
        padding: 10px;
        border-radius: 4px;
        margin-top: 12px;
    }
    .success a {
        color: #1e7e34;
        font-weight: bold;
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
<div class="container">
    <div class="card">
        <h1>Create an Account</h1>

        <?php if ($success): ?>
            <div class="success">Account created successfully! You can now <a href="login.php">log in</a>.</div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $err): ?>
                        <?= htmlspecialchars($err) ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <label>First Name</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($first_name) ?>" class="form-input" required>

                <label>Last Name</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($last_name) ?>" class="form-input" required>

                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" class="form-input" required>

                <label>Contact Number</label>
                <input type="text" name="contact_number" value="<?= htmlspecialchars($contact_number) ?>" class="form-input">

                <label>Address</label>
                <textarea name="address" rows="2" class="form-input"><?= htmlspecialchars($address) ?></textarea>

                <label>Password</label>
                <div style="position: relative;">
                    <input type="password" name="password" id="password" class="form-input" required style="padding-right: 40px;">
                    <img src="view.png" id="toggleIcon" onclick="togglePassword('password', 'toggleIcon')" 
                        style="position:absolute; right:10px; top:50%; transform:translateY(-50%); 
                                cursor:pointer; width:20px; height:20px;">
                </div>

                <label>Confirm Password</label>
                <div style="position: relative;">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" required style="padding-right: 40px;">
                </div>

                <button type="submit">Register</button>
            </form>

            <div class="link">Already have an account? <a href="login.php">Log in</a></div>
        <?php endif; ?>
    </div>
</div>
<script>
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
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