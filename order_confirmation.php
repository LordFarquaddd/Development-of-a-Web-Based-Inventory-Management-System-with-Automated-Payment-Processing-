<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$customer_id = $_SESSION['user_id'];
$order_id = (int)($_GET['order_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT * FROM orders WHERE order_id = ? AND customer_id = ?"
);
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

$stmt = $pdo->prepare(
    "SELECT pm.method_name, p.payment_status
     FROM payments p
     JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     WHERE p.order_id = ?"
);
$stmt->execute([$order_id]);
$payment = $stmt->fetch();

$onlineMethod = null; // 'gcash', 'maya', or null for COD
if ($payment) {
    if (stripos($payment['method_name'], 'GCash') !== false) {
        $onlineMethod = 'gcash';
    } elseif (stripos($payment['method_name'], 'Maya') !== false) {
        $onlineMethod = 'maya';
    }
}

if ($onlineMethod === 'gcash') {
    $qrImage = qr_image_or_null(GCASH_QR_IMAGE);
    $acctName = GCASH_ACCOUNT_NAME;
    $acctNumber = GCASH_ACCOUNT_NUMBER;
} elseif ($onlineMethod === 'maya') {
    $qrImage = qr_image_or_null(MAYA_QR_IMAGE);
    $acctName = MAYA_ACCOUNT_NAME;
    $acctNumber = MAYA_ACCOUNT_NUMBER;
}

$stmt = $pdo->prepare(
    "SELECT oi.quantity, oi.unit_price, oi.line_subtotal, pv.size, pv.color, p.product_name
     FROM order_items oi
     JOIN product_variants pv ON pv.variant_id = oi.variant_id
     JOIN products p ON p.product_id = pv.product_id
     WHERE oi.order_id = ?"
);
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order Confirmed - HARIBONified Store</title>
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
        font-size: 14px; }
    .content { 
        max-width: 700px; 
        margin: 30px auto; 
        padding: 0 20px; }
    .card { 
        background: #fff; 
        border-radius: 8px; 
        padding: 24px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
    .success-banner {
        background: #eafaf1; 
        color: #1e7e34; 
        padding: 16px; 
        border-radius: 6px;
        margin-bottom: 20px;
        text-align: center; 
        font-weight: bold; 
        font-size: 16px;
    }
    .order-meta { 
        display: flex; 
        justify-content: space-between; 
        font-size: 14px; 
        margin-bottom: 16px;
        color: #708090; 
        flex-wrap: wrap; 
        gap: 6px; 
    }
    .order-line {
    display: flex; 
    justify-content: space-between; 
    padding: 8px 0; 
    border-bottom: 1px solid #eee;
    font-size: 14px; 
}
    .order-line .sub { 
        color: #708090; 
        font-size: 12px; 
    }
    .totals { 
        margin-top: 14px; 
        text-align: right; 
    }
    .totals .grand { 
        font-weight: bold; 
        font-size: 17px; 
    }
    .btn-continue {
        display: inline-block; 
        margin-top: 20px; 
        padding: 10px 20px; 
        background: #e0b400; 
        color: #193E57;
        border-radius: 6px; 
        font-weight: bold; 
        text-decoration: none;
    }
</style>
</head>
<body>

<?php require 'includes/customer_header.php'; ?>

<div class="content">
    <div class="success-banner">✓ Order placed successfully!</div>

    <div class="card">
        <div class="order-meta">
            <span>Order #<?= htmlspecialchars($order['order_number']) ?></span>
            <span>Status: <?= htmlspecialchars(ucfirst($order['order_status'])) ?></span>
            <span>Payment: <?= htmlspecialchars($payment['method_name'] ?? 'Cash on Delivery') ?></span>
        </div>

        <?php foreach ($items as $item): ?>
            <div class="order-line">
                <div>
                    <div><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="sub">
                        <?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?>
                        &times; <?= (int)$item['quantity'] ?>
                    </div>
                </div>
                <div>&#8369;<?= number_format($item['line_subtotal'], 2) ?></div>
            </div>
        <?php endforeach; ?>

        <div class="totals">
            <div>Subtotal: &#8369;<?= number_format($order['subtotal'], 2) ?></div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div style="color:#1e7e34;">Discount: -&#8369;<?= number_format($order['discount_amount'], 2) ?></div>
            <?php endif; ?>
            <div>Shipping: &#8369;<?= number_format($order['shipping_fee'], 2) ?></div>
            <div class="grand">Total: &#8369;<?= number_format($order['total_amount'], 2) ?></div>
        </div>

        <a href="dashboard_customer.php" class="btn-continue">Continue Shopping</a>
        <a href="receipt.php?order_id=<?= (int)$order['order_id'] ?>" class="btn-continue" style="background:#dbe4ec; margin-left:10px;">View / Print Receipt</a>
    </div>

    <?php if ($onlineMethod && $payment['payment_status'] !== 'completed'): ?>
        <div class="card" style="margin-top:20px; text-align:center;">
            <h3 style="margin-top:0;">Pay via <?= $onlineMethod === 'gcash' ? 'GCash' : 'Maya' ?></h3>
            <?php if ($qrImage): ?>
                <img src="<?= htmlspecialchars($qrImage) ?>" alt="Payment QR code" style="max-width:200px; width:100%; border-radius:6px;">
            <?php else: ?>
                <div style="display:inline-block; background:#b45309; color:#fff; font-size:11px; font-weight:bold; padding:3px 10px; border-radius:12px; margin-bottom:10px;">Sample QR &mdash; for testing only</div>
                <div id="qrcode-confirm" style="display:flex; justify-content:center;"></div>
            <?php endif; ?>
            <div style="font-weight:bold; margin-top:10px; font-size:14px;">
                <?= htmlspecialchars($acctName) ?> &mdash; <?= htmlspecialchars($acctNumber) ?>
            </div>
            <div style="font-size:13px; color:#708090; margin-top:10px;">
                After paying, go to <a href="my_orders.php">My Orders</a> and tap <strong>I've Paid</strong> so we can confirm it.
            </div>
        </div>
        <?php if (!$qrImage): ?>
            <script>
                new QRCode(document.getElementById('qrcode-confirm'), {
                    text: 'SAMPLE QR - TEST ONLY - HARIBONified Store - <?= strtoupper($onlineMethod) ?> - Order <?= htmlspecialchars($order['order_number']) ?> - Total: <?= number_format($order['total_amount'], 2) ?>',
                    width: 160,
                    height: 160
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>