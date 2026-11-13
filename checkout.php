<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$customer_id = $_SESSION['user_id'];
$discount_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    $code = trim($_POST['discount_code'] ?? '');

    if ($code === '') {
        $discount_error = 'Enter a voucher code.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT discount_id, code, description, discount_type, discount_value
             FROM discounts
             WHERE code = ?
               AND status = 'active'
               AND CURDATE() BETWEEN start_date AND end_date
               AND (usage_limit IS NULL OR times_used < usage_limit)"
        );
        $stmt->execute([$code]);
        $discount = $stmt->fetch();

        if ($discount) {
            $_SESSION['applied_discount'] = $discount;
        } else {
            $discount_error = 'That voucher code is invalid, expired, or no longer available.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_discount'])) {
    unset($_SESSION['applied_discount']);
}

$stmt = $pdo->prepare(
    "SELECT ci.cart_item_id, ci.quantity, pv.variant_id, pv.size, pv.color, pv.price,
            p.product_name
     FROM cart_items ci
     JOIN carts c ON c.cart_id = ci.cart_id
     JOIN product_variants pv ON pv.variant_id = ci.variant_id
     JOIN products p ON p.product_id = pv.product_id
     WHERE c.customer_id = ?"
);
$stmt->execute([$customer_id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    header('Location: cart.php');
    exit;
}

$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping_fee = 50.00; 

$applied_discount = $_SESSION['applied_discount'] ?? null;
$discount_amount = 0;
if ($applied_discount) {
    if ($applied_discount['discount_type'] === 'percentage') {
        $discount_amount = $subtotal * (min((float)$applied_discount['discount_value'], 100) / 100);
    } else {
        $discount_amount = min((float)$applied_discount['discount_value'], $subtotal);
    }
}

$total = $subtotal - $discount_amount + $shipping_fee;

$stmt = $pdo->prepare("SELECT address FROM users WHERE user_id = ?");
$stmt->execute([$customer_id]);
$defaultAddress = $stmt->fetchColumn();

$gcashQr = qr_image_or_null(GCASH_QR_IMAGE);
$mayaQr  = qr_image_or_null(MAYA_QR_IMAGE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Checkout - HARIBONified Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
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
        max-width: 700px; 
        margin: 30px auto; 
        padding: 0 20px; 
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
    .order-line { 
        display: flex; 
        justify-content: space-between; 
        padding: 8px 0; 
        border-bottom: 1px solid #eee;
        font-size: 14px; 
    }
    .order-line .name { 
        font-weight: bold; 
    }
    .order-line .sub { 
        color: #708090; 
        font-size: 12px; 
    }
    .totals { 
        margin-top: 14px; 
    }
    .totals div { 
        display: flex; 
        justify-content: space-between; 
        padding: 4px 0; font-size: 14px; 
    }
    .totals .grand { 
        font-weight: bold; 
        font-size: 17px; 
        margin-top: 8px; 
        border-top: 1px solid #ddd; 
        padding-top: 10px; 
    }
    label { 
        display: block; 
        font-weight: bold; 
        font-size: 13px;  
        margin-top: 12px; 
    }
    textarea, input[type=text] {
        width: 100%; 
        padding: 8px; 
        margin-top: 4px; 
        border: 1px solid #ccc; 
        border-radius: 4px; 
        font-size: 14px;
        font-family: inherit;
    }
    .payment-note {
        background: #fff8e1; 
        border: 1px solid #e0b400; 
        padding: 12px; 
        border-radius: 6px; 
        font-size: 13px;
        margin-top: 14px;
    }
    .payment-options {
        margin-top: 14px;
    }
    .payment-option {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border: 1px solid #ccc;
        border-radius: 6px;
        padding: 12px;
        margin-bottom: 10px;
        cursor: pointer;
    }
    .payment-option input {
        margin-top: 3px;
    }
    .payment-option .title {
        font-weight: bold;
        font-size: 14px;
    }
    .payment-option .desc {
        font-size: 12px;
        color: #708090;
        margin-top: 2px;
    }
    .qr-block {
        display: none;
        margin-top: 6px;
        text-align: center;
        background: #fff8e1;
        border: 1px solid #e0b400;
        border-radius: 6px;
        padding: 16px;
    }
    .qr-block.open {
        display: block;
    }
    .qr-block img {
        max-width: 200px;
        width: 100%;
        border-radius: 6px;
        background: #fff;
        padding: 8px;
    }
    .qr-block .acct {
        font-size: 13px;
        margin-top: 10px;
        font-weight: bold;
    }
    .qr-block .hint {
        font-size: 12px;
        color: #708090;
        margin-top: 6px;
    }
    .qr-sample-tag {
        display: inline-block;
        background: #b45309;
        color: #fff;
        font-size: 11px;
        font-weight: bold;
        padding: 3px 10px;
        border-radius: 12px;
        margin-bottom: 10px;
    }
    #qrcode-gcash, #qrcode-maya {
        display: flex;
        justify-content: center;
    }
    .voucher-box {
        display: flex;
        gap: 8px;
        margin-top: 6px;
    }
    .voucher-box input[type=text] {
        flex: 1;
        margin-top: 0;
    }
    .voucher-box button {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        background: #17364B;
        color: #fff;
        font-weight: bold;
        font-size: 13px;
        cursor: pointer;
    }
    .voucher-box button:hover {
        background: #0f2635;
    }
    .voucher-applied {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #eafaf1;
        color: #1e7e34;
        padding: 10px 14px;
        border-radius: 6px;
        font-size: 13px;
        margin-top: 6px;
    }
    .voucher-applied button {
        background: none;
        border: none;
        color: #b91c1c;
        font-size: 12px;
        cursor: pointer;
        text-decoration: underline;
        font-weight: bold;
    }
    .voucher-error {
        color: #b91c1c;
        font-size: 13px;
        margin-top: 6px;
    }
    .totals div.discount span:last-child {
        color: #1e7e34;
    }
    .btn-place {
        margin-top: 20px; 
        width: 100%; 
        padding: 12px; 
        background: #e0b400; 
        color: #193E57;
        border: none; 
        border-radius: 6px; 
        font-weight: bold; 
        font-size: 15px; 
        cursor: pointer;
    }
    .btn-place:hover { 
    background: #957908; 
    }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

<?php require 'includes/customer_header.php'; ?>

<div class="content">
    <h2>Checkout</h2>

    <div class="card">
        <h3>Order Summary</h3>
        <?php foreach ($items as $item): ?>
            <div class="order-line">
                <div>
                    <div class="name"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="sub">
                        <?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?>
                        &times; <?= (int)$item['quantity'] ?>
                    </div>
                </div>
                <div>&#8369;<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
            </div>
        <?php endforeach; ?>

        <div class="totals">
            <div><span>Subtotal</span><span>&#8369;<?= number_format($subtotal, 2) ?></span></div>
            <?php if ($applied_discount): ?>
                <div class="discount"><span>Discount (<?= htmlspecialchars($applied_discount['code']) ?>)</span><span>-&#8369;<?= number_format($discount_amount, 2) ?></span></div>
            <?php endif; ?>
            <div><span>Shipping Fee</span><span>&#8369;<?= number_format($shipping_fee, 2) ?></span></div>
            <div class="grand"><span>Total</span><span>&#8369;<?= number_format($total, 2) ?></span></div>
        </div>
    </div>

    <div class="card">
        <h3>Voucher</h3>
        <?php if ($applied_discount): ?>
            <div class="voucher-applied">
                <span>
                    <strong><?= htmlspecialchars($applied_discount['code']) ?></strong> applied
                    <?php if ($applied_discount['description']): ?> &mdash; <?= htmlspecialchars($applied_discount['description']) ?><?php endif; ?>
                </span>
                <form method="post" style="margin:0;">
                    <button type="submit" name="remove_discount" value="1">Remove</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" class="voucher-box">
                <input type="text" name="discount_code" placeholder="Enter voucher code">
                <button type="submit" name="apply_discount" value="1">Apply</button>
            </form>
            <?php if ($discount_error): ?>
                <div class="voucher-error"><?= htmlspecialchars($discount_error) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Shipping & Payment</h3>
        <form method="post" action="checkout_process.php">
            <label>Shipping Address</label>
            <textarea name="shipping_address" rows="3" required><?= htmlspecialchars($defaultAddress ?? '') ?></textarea>

            <label>Order Notes (optional)</label>
            <textarea name="notes" rows="2" placeholder="e.g. preferred delivery time"></textarea>

            <div class="payment-options">
                <label class="payment-option">
                    <input type="radio" name="payment_method" value="cod" checked onchange="togglePaymentMethod()">
                    <div>
                        <div class="title">Cash on Delivery (COD)</div>
                        <div class="desc">Pay in cash when your order arrives.</div>
                    </div>
                </label>
                <label class="payment-option">
                    <input type="radio" name="payment_method" value="gcash" onchange="togglePaymentMethod()">
                    <div>
                        <div class="title">GCash</div>
                        <div class="desc">Scan the QR code and pay before your order ships.</div>
                    </div>
                </label>
                <label class="payment-option">
                    <input type="radio" name="payment_method" value="maya" onchange="togglePaymentMethod()">
                    <div>
                        <div class="title">Maya</div>
                        <div class="desc">Scan the QR code and pay before your order ships.</div>
                    </div>
                </label>
            </div>

            <div class="qr-block" id="qrBlock-gcash">
                <?php if ($gcashQr): ?>
                    <img src="<?= htmlspecialchars($gcashQr) ?>" alt="GCash payment QR code">
                <?php else: ?>
                    <div class="qr-sample-tag">Sample QR &mdash; for testing only</div>
                    <div id="qrcode-gcash"></div>
                <?php endif; ?>
                <div class="acct"><?= htmlspecialchars(GCASH_ACCOUNT_NAME) ?> &mdash; <?= htmlspecialchars(GCASH_ACCOUNT_NUMBER) ?></div>
                <div class="hint">After paying, go to "My Orders" and tap <strong>I've Paid</strong> so we can confirm it.</div>
            </div>

            <div class="qr-block" id="qrBlock-maya">
                <?php if ($mayaQr): ?>
                    <img src="<?= htmlspecialchars($mayaQr) ?>" alt="Maya payment QR code">
                <?php else: ?>
                    <div class="qr-sample-tag">Sample QR &mdash; for testing only</div>
                    <div id="qrcode-maya"></div>
                <?php endif; ?>
                <div class="acct"><?= htmlspecialchars(MAYA_ACCOUNT_NAME) ?> &mdash; <?= htmlspecialchars(MAYA_ACCOUNT_NUMBER) ?></div>
                <div class="hint">After paying, go to "My Orders" and tap <strong>I've Paid</strong> so we can confirm it.</div>
            </div>

            <button type="submit" class="btn-place">Place Order (&#8369;<?= number_format($total, 2) ?>)</button>
            <?php if ($applied_discount): ?>
                <input type="hidden" name="discount_code" value="<?= htmlspecialchars($applied_discount['code']) ?>">
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
var sampleQrGenerated = { gcash: false, maya: false };

function togglePaymentMethod() {
    var method = document.querySelector('input[name="payment_method"]:checked').value;

    document.getElementById('qrBlock-gcash').classList.toggle('open', method === 'gcash');
    document.getElementById('qrBlock-maya').classList.toggle('open', method === 'maya');

    if (method === 'gcash' || method === 'maya') {
        generateSampleQr(method);
    }
}

function generateSampleQr(method) {
    if (sampleQrGenerated[method]) return;
    var el = document.getElementById('qrcode-' + method);
    if (!el || typeof QRCode === 'undefined') return;

    new QRCode(el, {
        text: 'SAMPLE QR - TEST ONLY - HARIBONified Store - ' + method.toUpperCase() + ' - Total: <?= number_format($total, 2) ?>',
        width: 160,
        height: 160
    });
    sampleQrGenerated[method] = true;
}
</script>

</body>
</html>