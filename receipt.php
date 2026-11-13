<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_login();

$order_id = (int)($_GET['order_id'] ?? 0);
$role = $_SESSION['role_name'];

if ($role === 'Admin') {
    $stmt = $pdo->prepare(
        "SELECT o.*, u.first_name, u.last_name, u.email, u.contact_number
         FROM orders o
         JOIN users u ON u.user_id = o.customer_id
         WHERE o.order_id = ?"
    );
    $stmt->execute([$order_id]);
} elseif ($role === 'Customer') {
    $stmt = $pdo->prepare(
        "SELECT o.*, u.first_name, u.last_name, u.email, u.contact_number
         FROM orders o
         JOIN users u ON u.user_id = o.customer_id
         WHERE o.order_id = ? AND o.customer_id = ?"
    );
    $stmt->execute([$order_id, $_SESSION['user_id']]);
} else {
    http_response_code(403);
    die('Access denied.');
}

$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

$stmt = $pdo->prepare(
    "SELECT oi.quantity, oi.unit_price, oi.line_subtotal, pv.size, pv.color, pv.sku, p.product_name
     FROM order_items oi
     JOIN product_variants pv ON pv.variant_id = oi.variant_id
     JOIN products p ON p.product_id = pv.product_id
     WHERE oi.order_id = ?"
);
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT p.*, pm.method_name FROM payments p
     JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
     WHERE p.order_id = ?"
);
$stmt->execute([$order_id]);
$payment = $stmt->fetch();

if ($role === 'Customer') {
    $from = $_GET['from'] ?? 'confirmation';
    $backLink = match ($from) {
        'my_orders' => 'my_orders.php',
        'cart' => 'cart.php',
        default => 'order_confirmation.php?order_id=' . $order_id,
    };
} else {
    $backLink = 'order_detail.php?id=' . $order_id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt <?= htmlspecialchars($order['order_number']) ?> - HARIBONified Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>
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
    .navbar a { color: #e0b400; text-decoration: none; font-size: 14px; }
    .content { max-width: 480px; margin: 30px auto; padding: 0 20px; }
    .receipt {
        background: #fff;
        border-radius: 8px;
        padding: 28px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .store-name {
        text-align: center;
        font-family: 'Montserrat', sans-serif;
        font-weight: bold;
        font-size: 18px;
        color: #17364B;
    }
    .store-sub {
        text-align: center;
        font-size: 11px;
        color: #708090;
        margin-bottom: 16px;
    }
    .divider {
        border-top: 1px dashed #ccc;
        margin: 14px 0;
    }
    .row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        padding: 2px 0;
    }
    .row span:first-child { color: #708090; }
    .item-line {
        font-size: 13px;
        padding: 6px 0;
        border-bottom: 1px dotted #eee;
    }
    .item-line .name { font-weight: bold; }
    .item-line .sub { color: #708090; font-size: 11px; }
    .item-line .amt { text-align: right; }
    .totals .grand {
        font-weight: bold;
        font-size: 16px;
        border-top: 1px solid #ddd;
        padding-top: 8px;
        margin-top: 6px;
    }
    .barcode-wrap {
        text-align: center;
        margin-top: 18px;
    }
    .barcode-wrap svg { max-width: 100%; }
    .status-tag {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: bold;
        background: #eafaf1;
        color: #1e7e34;
    }
    .actions {
        max-width: 480px;
        margin: 16px auto 40px;
        padding: 0 20px;
        display: flex;
        gap: 10px;
    }
    .btn {
        flex: 1;
        text-align: center;
        padding: 10px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 14px;
        text-decoration: none;
        cursor: pointer;
        border: none;
    }
    .btn-print { background: #e0b400; color: #193E57; }
    .btn-print:hover { background: #957908; }
    .btn-back { background: #dbe4ec; color: #193E57; }

    @media print {
        .navbar, .actions { display: none; }
        body { background: #fff; }
        .content { margin: 0; max-width: 100%; }
        .receipt { box-shadow: none; }
    }
</style>
</head>
<body>

<div class="navbar">
    <span>HARIBONified Store</span>
    <a href="<?= htmlspecialchars($backLink) ?>">Back</a>
</div>

<div class="content">
    <div class="receipt">
        <div class="store-name">HARIBONified Store</div>
        <div class="store-sub">Official Receipt</div>

        <div class="row"><span>Order #</span><span><?= htmlspecialchars($order['order_number']) ?></span></div>
        <div class="row"><span>Date</span><span><?= date('M j, Y g:i A', strtotime($order['order_date'])) ?></span></div>
        <div class="row"><span>Customer</span><span><?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?></span></div>
        <div class="row"><span>Status</span><span class="status-tag"><?= htmlspecialchars(ucfirst($order['order_status'])) ?></span></div>
        <div class="row"><span>Payment</span><span><?= htmlspecialchars($payment['method_name'] ?? 'Cash on Delivery') ?> (<?= htmlspecialchars(ucfirst($payment['payment_status'] ?? 'pending')) ?>)</span></div>

        <div class="divider"></div>

        <?php foreach ($items as $item): ?>
            <div class="item-line">
                <div style="display:flex; justify-content:space-between;">
                    <div>
                        <div class="name"><?= htmlspecialchars($item['product_name']) ?></div>
                        <div class="sub">
                            <?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?>
                            &times; <?= (int)$item['quantity'] ?> &middot; SKU <?= htmlspecialchars($item['sku']) ?>
                        </div>
                    </div>
                    <div class="amt">&#8369;<?= number_format($item['line_subtotal'], 2) ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="totals">
            <div class="row"><span>Subtotal</span><span>&#8369;<?= number_format($order['subtotal'], 2) ?></span></div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="row"><span>Discount</span><span>-&#8369;<?= number_format($order['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="row"><span>Shipping</span><span>&#8369;<?= number_format($order['shipping_fee'], 2) ?></span></div>
            <div class="row grand"><span>Total</span><span>&#8369;<?= number_format($order['total_amount'], 2) ?></span></div>
        </div>

        <div class="barcode-wrap">
            <svg id="barcode"></svg>
        </div>
    </div>
</div>

<div class="actions">
    <a href="<?= htmlspecialchars($backLink) ?>" class="btn btn-back">Back</a>
    <button type="button" class="btn btn-print" onclick="window.print()">Print Receipt</button>
</div>

<script>
    JsBarcode("#barcode", "<?= htmlspecialchars($order['order_number']) ?>", {
        format: "CODE128",
        lineColor: "#193E57",
        width: 2,
        height: 60,
        fontSize: 13,
        margin: 10
    });
</script>

</body>
</html>s