<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$customer_id = $_SESSION['user_id'];
$order_id = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$cancelableStatuses = ['pending', 'processing'];
$reasons = customer_cancellation_reasons();

$stmt = $pdo->prepare("SELECT order_number, order_status FROM orders WHERE order_id = ? AND customer_id = ?");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}
if (!in_array($order['order_status'], $cancelableStatuses, true)) {
    die('This order can no longer be cancelled — it has already been shipped or processed further.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['cancellation_reason'] ?? '';

    if (!array_key_exists($reason, $reasons)) {
        $error = 'Please choose a reason for cancelling.';
    } else {
        $pdo->prepare(
            "UPDATE orders SET previous_status = order_status, order_status = 'cancellation_requested',
                                cancellation_reason = ? WHERE order_id = ?"
        )->execute([$reason, $order_id]);

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'REQUEST_CANCELLATION', 'orders', ?, ?)"
        )->execute([$customer_id, $order_id, "Customer requested order cancellation: {$reasons[$reason]}"]);

        notify_admins(
            $pdo,
            "Cancellation requested for order {$order['order_number']} by " . current_user_name()
            . " — reason: {$reasons[$reason]}"
        );

        header('Location: my_orders.php?cancel_requested=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Cancel Order <?= htmlspecialchars($order['order_number']) ?> - HARIBONified Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    * {
        box-sizing: border-box;
    }

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
        max-width: 500px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .card {
        background: #fff;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }

    .card h3 {
        margin-top: 0;
    }

    .order-ref {
        color: #708090;
        font-size: 13px;
        margin-bottom: 16px;
    }

    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }

    label {
        display: block;
        font-weight: bold;
        font-size: 13px;
        margin-top: 4px;
    }

    select {
        width: 100%;
        padding: 8px;
        margin-top: 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }

    .actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .btn {
        flex: 1;
        padding: 10px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 14px;
        border: none;
        cursor: pointer;
        text-align: center;
        text-decoration: none;
    }

    .btn-confirm {
        background: #b91c1c;
        color: #fff;
    }

    .btn-confirm:hover {
        background: #8f1616;
    }

    .btn-back {
        background: #dbe4ec;
        color: #193E57;
    }
    </style>
</head>

<body>

<?php require 'includes/customer_header.php'; ?>

    <div class="content">
        <div class="card">
            <h3>Cancel this order?</h3>
            <div class="order-ref">Order <?= htmlspecialchars($order['order_number']) ?></div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post">
                <label>Why are you cancelling?</label>
                <select name="cancellation_reason" required>
                    <option value="">-- Select a reason --</option>
                    <?php foreach ($reasons as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="actions">
                    <a href="my_orders.php" class="btn btn-back">Never mind</a>
                    <button type="submit" class="btn btn-confirm">Request Cancellation</button>
                </div>
            </form>
        </div>
    </div>

</body>

</html>