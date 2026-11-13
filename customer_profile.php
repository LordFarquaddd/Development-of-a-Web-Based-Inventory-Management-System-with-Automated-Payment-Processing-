<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$customer_id = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT first_name, last_name, email, contact_number, address, created_at
     FROM users WHERE user_id = ?"
);
$stmt->execute([$customer_id]);
$user = $stmt->fetch();

if (!$user) {
    die('Account not found.');
}

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name     = trim($_POST['first_name'] ?? '');
    $last_name      = trim($_POST['last_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address        = trim($_POST['address'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First and last name are required.';
    }

    if (empty($errors)) {
        $pdo->prepare(
            "UPDATE users SET first_name = ?, last_name = ?, contact_number = ?, address = ?
             WHERE user_id = ?"
        )->execute([$first_name, $last_name, $contact_number, $address, $customer_id]);

        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'UPDATE_PROFILE', 'users', ?, 'Customer updated their own profile')"
        )->execute([$customer_id, $customer_id]);

        $success = 'Your information has been updated.';

        $user['first_name']     = $first_name;
        $user['last_name']      = $last_name;
        $user['contact_number'] = $contact_number;
        $user['address']        = $address;
    }
}

$stmt = $pdo->prepare(
    "SELECT code, description, discount_type, discount_value, end_date
     FROM discounts
     WHERE status = 'active'
       AND CURDATE() BETWEEN start_date AND end_date
       AND (usage_limit IS NULL OR times_used < usage_limit)
     ORDER BY end_date ASC"
);
$stmt->execute();
$vouchers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Account - HARIBONified Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Lato', sans-serif;
        background: #f4f7fa;
        margin: 0;
        color: #193E57;
    }
    .navbar {
        background: #17364B;
        padding: 14px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #f6f8ff;
        font-family: 'Montserrat', sans-serif;
    }
    .navbar a {
        color: #e0b400;
        text-decoration: none;
        font-size: 14px;
    }
    .content {
        max-width: 640px;
        margin: 30px auto;
        padding: 0 20px;
    }
    .profile-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
    }
    .avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #e0b400;
        flex-shrink: 0;
    }
    .profile-header h2 {
        margin: 0 0 4px;
    }
    .profile-header .email {
        color: #708090;
        font-size: 13px;
    }
    .card {
        background: #fff;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }
    .card h3 {
        margin-top: 0;
    }
    label {
        display: block;
        font-weight: bold;
        font-size: 13px;
        margin-top: 12px;
    }
    input[type=text], textarea, input[readonly] {
        width: 100%;
        padding: 8px;
        margin-top: 4px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    input[readonly] {
        background: #f4f7fa;
        color: #708090;
    }
    .member-since {
        color: #708090;
        font-size: 12px;
        margin-top: 4px;
    }
    .btn-save {
        margin-top: 20px;
        padding: 10px 20px;
        background: #e0b400;
        color: #193E57;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        font-size: 14px;
        cursor: pointer;
        font-family: 'Montserrat', sans-serif;
    }
    .btn-save:hover {
        background: #957908;
    }
    .success {
        background: #eafaf1;
        color: #1e7e34;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }
    .voucher {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border: 1px dashed #e0b400;
        border-radius: 6px;
        margin-bottom: 10px;
        background: #fff8e1;
    }
    .voucher:last-child {
        margin-bottom: 0;
    }
    .voucher .code {
        font-family: 'Montserrat', sans-serif;
        font-weight: bold;
        font-size: 15px;
        color: #17364B;
        letter-spacing: 0.5px;
    }
    .voucher .desc {
        font-size: 12px;
        color: #708090;
        margin-top: 2px;
    }
    .voucher .value {
        font-weight: bold;
        color: #957908;
        white-space: nowrap;
    }
    .voucher .expiry {
        font-size: 11px;
        color: #708090;
        white-space: nowrap;
    }
    .no-vouchers {
        color: #708090;
        font-size: 13px;
    }
    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }
</style>
</head>
<body>

<?php require 'includes/customer_header.php'; ?>

<div class="content">
    <div class="profile-header">
        <div class="avatar"></div>
        <div>
            <h2><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
            <div class="email"><?= htmlspecialchars($user['email']) ?></div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="error"><?php foreach ($errors as $err): ?><?= htmlspecialchars($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>My Information</h3>
        <form method="post" action="customer_profile.php">
            <label>Email</label>
            <input type="text" value="<?= htmlspecialchars($user['email']) ?>" readonly>

            <label>First Name</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>

            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>

            <label>Contact Number</label>
            <input type="text" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>">

            <label>Address</label>
            <textarea name="address" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>

            <div class="member-since">Member since <?= date('F j, Y', strtotime($user['created_at'])) ?></div>

            <button type="submit" class="btn-save">Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h3>Available Vouchers</h3>
        <?php if (empty($vouchers)): ?>
            <div class="no-vouchers">No vouchers available right now. Check back soon!</div>
        <?php else: ?>
            <?php foreach ($vouchers as $v): ?>
                <div class="voucher">
                    <div>
                        <div class="code"><?= htmlspecialchars($v['code']) ?></div>
                        <?php if ($v['description']): ?>
                            <div class="desc"><?= htmlspecialchars($v['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                        <div class="value">
                            <?= $v['discount_type'] === 'percentage'
                                ? (int)$v['discount_value'] . '% off'
                                : '&#8369;' . number_format($v['discount_value'], 2) . ' off' ?>
                        </div>
                        <div class="expiry">Valid until <?= date('M j, Y', strtotime($v['end_date'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>