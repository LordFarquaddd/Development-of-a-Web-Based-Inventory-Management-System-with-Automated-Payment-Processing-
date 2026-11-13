<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');
auto_complete_delivered_orders($pdo);

$customer_id = $_SESSION['user_id'];
$message = '';
$error = '';

$cancelableStatuses = ['pending', 'processing'];

if (isset($_GET['cancel_requested'])) {
    $message = 'Cancellation requested. An admin will review it shortly.';
}
if (isset($_GET['received'])) {
    $message = 'Thanks for confirming! Your order is now complete.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_received'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT order_number FROM orders WHERE order_id = ? AND customer_id = ?");
    $stmt->execute([$order_id, $customer_id]);
    $mine = $stmt->fetch();

    // Only a delivered order can be confirmed, and only once.
    $upd = $pdo->prepare(
        "UPDATE orders SET order_status = 'completed'
         WHERE order_id = ? AND customer_id = ?
           AND order_status = 'delivered'"
    );
    $upd->execute([$order_id, $customer_id]);

    if ($mine && $upd->rowCount() === 1) {
        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'CUSTOMER_CONFIRMED_RECEIVED', 'orders', ?, 'Customer confirmed the order was received')"
        )->execute([$customer_id, $order_id]);

        notify_admins(
            $pdo,
            "Order {$mine['order_number']} was confirmed as received by " . current_user_name() . '.'
        );

        header('Location: my_orders.php?received=1');
        exit;
    }
    $error = 'This order can\'t be confirmed right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT o.order_status, o.payment_status, pm.method_name
         FROM orders o
         JOIN payments p ON p.order_id = o.order_id
         JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
         WHERE o.order_id = ? AND o.customer_id = ?"
    );
    $stmt->execute([$order_id, $customer_id]);
    $current = $stmt->fetch();

    if (!$current) {
        $error = 'Order not found.';
    } elseif (in_array($current['order_status'], ['cancelled', 'cancellation_requested'], true)) {
        $error = 'This order has been cancelled — no payment is needed.';
    } elseif (stripos($current['method_name'], 'Cash on Delivery') !== false) {
        $error = 'This order is Cash on Delivery — no online payment to confirm.';
    } elseif ($current['payment_status'] !== 'unpaid') {
        $error = 'This order\'s payment status has already been updated.';
    } else {
        $pdo->prepare(
            "UPDATE orders SET payment_status = 'payment_claimed' WHERE order_id = ?"
        )->execute([$order_id]);

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'CUSTOMER_MARKED_PAID', 'orders', ?, ?)"
        )->execute([$customer_id, $order_id, "Customer marked {$current['method_name']} payment as sent"]);

        $message = 'Thanks! We\'ll confirm your payment shortly.';
    }
}

$stmt = $pdo->prepare(
    "SELECT o.order_id, o.order_number, o.order_date, o.order_status, o.total_amount,
            o.payment_status, o.cancellation_reason, pm.method_name
     FROM orders o
     JOIN payments p ON p.order_id = o.order_id
     JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     WHERE o.customer_id = ? ORDER BY o.order_date DESC"
);
$stmt->execute([$customer_id]);
$orders = $stmt->fetchAll();

$firstUnpaidOnline = null; // ['method' => 'gcash'|'maya', ...order row]
foreach ($orders as $o) {
    if (
        stripos($o['method_name'], 'Cash on Delivery') === false
        && $o['payment_status'] === 'unpaid'
        && !in_array($o['order_status'], ['cancelled', 'cancellation_requested'], true)
    ) {
        $firstUnpaidOnline = $o;
        $firstUnpaidOnline['method'] = stripos($o['method_name'], 'GCash') !== false ? 'gcash' : 'maya';
        break;
    }
}

if ($firstUnpaidOnline) {
    if ($firstUnpaidOnline['method'] === 'gcash') {
        $reminderQr = qr_image_or_null(GCASH_QR_IMAGE);
        $reminderName = GCASH_ACCOUNT_NAME;
        $reminderNumber = GCASH_ACCOUNT_NUMBER;
        $reminderLabel = 'GCash';
    } else {
        $reminderQr = qr_image_or_null(MAYA_QR_IMAGE);
        $reminderName = MAYA_ACCOUNT_NAME;
        $reminderNumber = MAYA_ACCOUNT_NUMBER;
        $reminderLabel = 'Maya';
    }
}

function statusColor($status)
{
    return match ($status) {
        'pending' => '#e0b400',
        'processing' => '#3a7bd5',
        'shipped' => '#8e44ad',
        'delivered' => '#16a085',
        'completed' => '#1e7e34',
        'cancellation_requested' => '#b45309',
        'cancelled' => '#b91c1c',
        default => '#708090',
    };
}

function statusLabel($status)
{
    return $status === 'cancellation_requested' ? 'Cancellation Requested' : ucfirst($status);
}

function paymentLabel($status)
{
    return match ($status) {
        'payment_claimed' => 'Awaiting Confirmation',
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        default => ucfirst($status),
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Orders - HARIBONified Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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
        max-width: 900px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .message {
        background: #eafaf1;
        color: #1e7e34;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }

    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }

    th,
    td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #eee;
        font-size: 14px;
    }

    th {
        background: #17364B;
        color: #fff;
        font-size: 13px;
    }

    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        color: #fff;
        font-size: 12px;
    }

    .view-link {
        color: #17364B;
        font-weight: bold;
        text-decoration: none;
        font-size: 13px;
        margin-right: 12px;
    }

    .btn-cancel-link {
        background: none;
        border: none;
        color: #b91c1c;
        font-size: 13px;
        font-weight: bold;
        text-decoration: underline;
        cursor: pointer;
        padding: 0;
        font-family: inherit;
    }

    .awaiting {
        color: #957908;
        font-size: 12px;
        font-weight: bold;
    }

    .btn-cancel {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: bold;
        background: #fdecea;
        color: #b91c1c;
        border: 1px solid #b91c1c;
        text-decoration: none;
    }

    .btn-cancel:hover {
        background: #b91c1c;
        color: #fff;
    }

    .btn-cancel.disabled {
        background: #e5e9ed;
        color: #8a97a3;
        border-color: #cbd3da;
        cursor: not-allowed;
        pointer-events: none;
    }

    .cancel-note {
        color: #708090;
        font-size: 11px;
        margin-top: 4px;
        max-width: 170px;
    }

    .reason-note {
        color: #708090;
        font-size: 11px;
        margin-top: 4px;
        max-width: 160px;
    }

    .empty {
        text-align: center;
        color: #708090;
        padding: 60px 0;
    }

    .qr-reminder {
        background: #fff8e1;
        border: 1px solid #e0b400;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        gap: 16px;
        align-items: center;
        flex-wrap: wrap;
    }

    .qr-reminder img {
        width: 80px;
        height: 80px;
        border-radius: 6px;
        background: #fff;
        padding: 4px;
    }

    .qr-reminder .txt {
        font-size: 13px;
    }

    .btn-mark-paid {
        background: #17364B;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: bold;
        cursor: pointer;
    }

    .btn-received {
        background: #1e7e34;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: bold;
        cursor: pointer;
    }

    .btn-received:hover {
        background: #166027;
    }

    .btn-mark-paid:hover {
        background: #0f2635;
    }

    .pay-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
    }

    .pay-badge.unpaid {
        background: #fdecea;
        color: #b91c1c;
    }

    .pay-badge.claimed {
        background: #fff8e1;
        color: #957908;
    }

    .pay-badge.paid {
        background: #eafaf1;
        color: #1e7e34;
    }

    .qr-sample-tag {
        display: inline-block;
        background: #b45309;
        color: #fff;
        font-size: 10px;
        font-weight: bold;
        padding: 2px 8px;
        border-radius: 10px;
        margin-bottom: 6px;
    }
    </style>
</head>

<body>

   <?php require 'includes/customer_header.php'; ?>

    <div class="content">
        <h2>My Orders</h2>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($firstUnpaidOnline): ?>
            <div class="qr-reminder">
                <?php if ($reminderQr): ?>
                    <img src="<?= htmlspecialchars($reminderQr) ?>" alt="Payment QR code">
                <?php else: ?>
                    <div>
                        <div class="qr-sample-tag">Sample</div>
                        <div id="qrcode-reminder" style="width:80px; height:80px;"></div>
                    </div>
                <?php endif; ?>
                <div class="txt">
                    <strong>You have an order pending <?= htmlspecialchars($reminderLabel) ?> payment.</strong><br>
                    Scan to pay <?= htmlspecialchars($reminderName) ?> &mdash; <?= htmlspecialchars($reminderNumber) ?>,
                    then tap <strong>I've Paid</strong> next to that order below.
                </div>
            </div>
            <?php if (!$reminderQr): ?>
                <script>
                    new QRCode(document.getElementById('qrcode-reminder'), {
                        text: 'SAMPLE QR - TEST ONLY - HARIBONified Store - <?= strtoupper($firstUnpaidOnline['method']) ?> - Order <?= htmlspecialchars($firstUnpaidOnline['order_number']) ?>',
                        width: 80,
                        height: 80
                    });
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <div class="empty">You haven't placed any orders yet. <a href="dashboard_customer.php">Start shopping</a>.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th></th>
                </tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['order_number']) ?></td>
                        <td><?= date('M j, Y g:i A', strtotime($o['order_date'])) ?></td>
                        <td><span class="badge" style="background:<?= statusColor($o['order_status']) ?>">
                                <?= statusLabel($o['order_status']) ?>
                            </span>
                            <?php if ($o['cancellation_reason']): ?>
                                <div class="reason-note">Reason:
                                    <?= htmlspecialchars(cancellation_reason_label($o['cancellation_reason'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span
                                class="pay-badge <?= $o['payment_status'] === 'unpaid' ? 'unpaid' : ($o['payment_status'] === 'paid' ? 'paid' : 'claimed') ?>">
                                <?= paymentLabel($o['payment_status']) ?>
                            </span>
                            <?php if (stripos($o['method_name'], 'Cash on Delivery') === false && $o['payment_status'] === 'unpaid' && !in_array($o['order_status'], ['cancelled', 'cancellation_requested'], true)): ?>
                                <form method="post" style="display:inline; margin-left:6px;">
                                    <input type="hidden" name="order_id" value="<?= (int) $o['order_id'] ?>">
                                    <button type="submit" name="mark_paid" value="1" class="btn-mark-paid">I've Paid</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>&#8369;<?= number_format($o['total_amount'], 2) ?></td>
                        <td>
                            <a class="view-link" href="receipt.php?order_id=<?= (int) $o['order_id'] ?>&from=my_orders">Receipt</a>
                            <?php if (in_array($o['order_status'], $cancelableStatuses, true)): ?>
                                <a class="btn-cancel" href="cancel_request.php?order_id=<?= (int) $o['order_id'] ?>">Cancel order</a>
                            <?php elseif ($o['order_status'] === 'cancellation_requested'): ?>
                                <span class="awaiting">Awaiting admin approval</span>
                            <?php elseif ($o['order_status'] === 'delivered'): ?>
                                <form method="post" style="display:inline;"
                                    onsubmit="return confirm('Confirm that you received this order?');">
                                    <input type="hidden" name="order_id" value="<?= (int) $o['order_id'] ?>">
                                    <button type="submit" name="confirm_received" value="1" class="btn-received">Order Received</button>
                                </form>
                                <div class="cancel-note">No action needed? It completes by itself <?= (int) DELIVERY_AUTO_COMPLETE_DAYS ?> days after delivery.</div>
                            <?php elseif ($o['order_status'] !== 'cancelled'): ?>
                                <span class="btn-cancel disabled" aria-disabled="true">Cancel order</span>
                                <div class="cancel-note">Cancellation is closed because fulfillment has started.</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

</body>

</html>