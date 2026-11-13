<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

$customer_id = $_SESSION['user_id'];

if (isset($_GET['remove'])) {
    $cart_item_id = (int)$_GET['remove'];
    $pdo->prepare(
        "DELETE ci FROM cart_items ci
         JOIN carts c ON c.cart_id = ci.cart_id
         WHERE ci.cart_item_id = ? AND c.customer_id = ?"
    )->execute([$cart_item_id, $customer_id]);
    header('Location: cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    foreach ($_POST['qty'] as $cart_item_id => $qty) {
        $qty = max(1, (int)$qty);
        $pdo->prepare(
            "UPDATE cart_items ci
             JOIN carts c ON c.cart_id = ci.cart_id
             SET ci.quantity = ?
             WHERE ci.cart_item_id = ? AND c.customer_id = ?"
        )->execute([$qty, (int)$cart_item_id, $customer_id]);
    }
    header('Location: cart.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT ci.cart_item_id, ci.quantity, pv.variant_id, pv.size, pv.color, pv.price, pv.stock_quantity,
            p.product_name, p.image_url
     FROM cart_items ci
     JOIN carts c ON c.cart_id = ci.cart_id
     JOIN product_variants pv ON pv.variant_id = ci.variant_id
     JOIN products p ON p.product_id = pv.product_id
     WHERE c.customer_id = ?
     ORDER BY ci.added_at DESC"
);
$stmt->execute([$customer_id]);
$items = $stmt->fetchAll();

$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

$stmt = $pdo->prepare(
    "SELECT order_id, order_number, order_date, order_status, total_amount
     FROM orders WHERE customer_id = ? ORDER BY order_date DESC LIMIT 3"
);
$stmt->execute([$customer_id]);
$recentOrders = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Your Cart - HARIBONified Store</title>
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
        max-width: 800px; 
        margin: 30px auto; 
        padding: 0 20px; 
    }
    table { 
        width: 100%; 
        border-collapse: collapse; 
        background: #fff; 
        border-radius: 8px; 
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
    th, td { 
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
    .item-name { 
        font-weight: bold; 
    }
    .item-variant { 
        color: #708090; 
        font-size: 12px; 
    }
    input[type=number] { 
        width: 60px; 
        padding: 6px; 
        border: 1px solid #ccc; 
        border-radius: 4px; 
    }
    .remove-link { 
        color: #b91c1c; 
        font-size: 13px; 
        text-decoration: none; 
    }
    .summary { 
        margin-top: 20px; 
        background: #fff; 
        padding: 20px; 
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08); 
        text-align: right; 
    }
    .summary .total { 
        font-size: 18px; 
        font-weight: bold; 
        margin-bottom: 14px; }
    .btn { 
        padding: 10px 20px; 
        border-radius: 6px; 
        font-weight: bold; 
        font-size: 14px; 
        text-decoration: none; 
        display: inline-block; 
        cursor: pointer; 
        border: none; 
    }
    .btn-update {
        background: #dbe4ec; 
        color: #193E57; 
        margin-right: 10px; 
    }
    .btn-checkout { 
        background: #e0b400; 
        color: #193E57; 
    }
    .btn-checkout:hover {
        background: #957908; 
        }
    .empty { 
        text-align: center; 
        color: #708090; 
        padding: 60px 0; 
        }

    .recent-orders {
        margin-top: 24px;
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .recent-orders h3 {
        margin: 0 0 12px;
        font-size: 15px;
    }
    .recent-orders .see-all {
        float: right;
        font-size: 12px;
        font-weight: normal;
        color: #17364B;
        text-decoration: none;
    }
    .order-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid #eee;
        font-size: 13px;
    }
    .order-row:last-child {
        border-bottom: none;
    }
    .order-row .num {
        font-weight: bold;
    }
    .order-row .date {
        color: #708090;
        font-size: 12px;
    }
    .order-row .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        color: #fff;
        font-size: 11px;
    }
</style>
</head>
<body>

<?php require 'includes/customer_header.php'; ?>

<div class="content">
    <h2>Your Cart</h2>

    <?php if (empty($items)): ?>
        <div class="empty">Your cart is empty. <a href="dashboard_customer.php">Go browse products</a>.</div>
    <?php else: ?>
        <form method="post" action="cart.php">
            <table>
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Line Total</th>
                    <th></th>
                </tr>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                            <div class="item-variant">
                                <?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?>
                            </div>
                        </td>
                        <td>&#8369;<?= number_format($item['price'], 2) ?></td>
                        <td>
                            <input type="number" name="qty[<?= $item['cart_item_id'] ?>]"
                                   value="<?= $item['quantity'] ?>" min="1" max="<?= $item['stock_quantity'] ?>">
                        </td>
                        <td>&#8369;<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                        <td><a class="remove-link" href="cart.php?remove=<?= $item['cart_item_id'] ?>">Remove</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div style="margin-top:14px;">
                <button type="submit" name="update" value="1" class="btn btn-update">Update Quantities</button>
            </div>
        </form>

        <div class="summary">
            <div class="total">Subtotal: &#8369;<?= number_format($subtotal, 2) ?></div>
            <a href="checkout.php" class="btn btn-checkout">Proceed to Checkout</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($recentOrders)): ?>
        <div class="recent-orders">
            <h3>Recent Orders <a class="see-all" href="my_orders.php">See all</a></h3>
            <?php foreach ($recentOrders as $o): ?>
                <div class="order-row">
                    <div>
                        <div class="num"><?= htmlspecialchars($o['order_number']) ?></div>
                        <div class="date"><?= date('M j, Y', strtotime($o['order_date'])) ?></div>
                    </div>
                    <div>&#8369;<?= number_format($o['total_amount'], 2) ?></div>
                    <span class="badge" style="background:<?= statusColor($o['order_status']) ?>">
                        <?= statusLabel($o['order_status']) ?>
                    </span>
                    <a href="receipt.php?order_id=<?= (int)$o['order_id'] ?>&from=cart" style="font-size:12px; color:#17364B; font-weight:bold; text-decoration:none;">Receipt</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>