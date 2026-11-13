<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard_customer.php');
    exit;
}

$customer_id = $_SESSION['user_id'];
$variant_id  = (int)($_POST['variant_id'] ?? 0);
$quantity    = max(1, (int)($_POST['quantity'] ?? 1));

$stmt = $pdo->prepare("SELECT variant_id, product_id, stock_quantity FROM product_variants WHERE variant_id = ?");
$stmt->execute([$variant_id]);
$variant = $stmt->fetch();

if (!$variant) {
    die('Invalid product variant.');
}
if ($variant['stock_quantity'] < $quantity) {
    die('Not enough stock available for that quantity.');
}

$stmt = $pdo->prepare("SELECT cart_id FROM carts WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$cart = $stmt->fetch();

if ($cart) {
    $cart_id = $cart['cart_id'];
} else {
    $pdo->prepare("INSERT INTO carts (customer_id) VALUES (?)")->execute([$customer_id]);
    $cart_id = $pdo->lastInsertId();
}

$stmt = $pdo->prepare("SELECT cart_item_id, quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?");
$stmt->execute([$cart_id, $variant_id]);
$existing = $stmt->fetch();

if ($existing) {
    $newQty = min($existing['quantity'] + $quantity, $variant['stock_quantity']);
    $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?")
        ->execute([$newQty, $existing['cart_item_id']]);
} else {
    $pdo->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?, ?, ?)")
        ->execute([$cart_id, $variant_id, $quantity]);
}

header('Location: product.php?id=' . $variant['product_id'] . '&added=1');
exit;
