<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

$customer_id = $_SESSION['user_id'];
$shipping_address = trim($_POST['shipping_address'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$payment_method = in_array($_POST['payment_method'] ?? 'cod', ['gcash', 'maya'], true)
    ? $_POST['payment_method']
    : 'cod';

if ($shipping_address === '') {
    die('Shipping address is required. Please go back and fill it in.');
}

$stmt = $pdo->prepare(
    "SELECT ci.cart_item_id, ci.quantity, pv.variant_id, pv.price, pv.stock_quantity
     FROM cart_items ci
     JOIN carts c ON c.cart_id = ci.cart_id
     JOIN product_variants pv ON pv.variant_id = ci.variant_id
     WHERE c.customer_id = ?"
);
$stmt->execute([$customer_id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    die('Your cart is empty.');
}

$subtotal = 0;
foreach ($items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping_fee = 50.00;

$order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

try {
    $pdo->beginTransaction();

    // Re-check stock inside the transaction with row locks so two
    // simultaneous checkouts can't both oversell the same variant.
    $lockStmt = $pdo->prepare(
        "SELECT stock_quantity FROM product_variants WHERE variant_id = ? FOR UPDATE"
    );
    foreach ($items as $item) {
        $lockStmt->execute([$item['variant_id']]);
        $currentStock = $lockStmt->fetchColumn();

        if ($currentStock === false) {
            throw new Exception('One of your items is no longer available.');
        }
        if ($item['quantity'] > $currentStock) {
            throw new Exception('One of your items no longer has enough stock. Please go back to your cart and adjust the quantity.');
        }
    }

    $discount_id = null;
    $discount_amount = 0;
    $applied_discount = $_SESSION['applied_discount'] ?? null;

    if ($applied_discount) {
        $stmt = $pdo->prepare(
            "SELECT discount_id, discount_type, discount_value, usage_limit, times_used
             FROM discounts
             WHERE discount_id = ?
               AND status = 'active'
               AND CURDATE() BETWEEN start_date AND end_date
               AND (usage_limit IS NULL OR times_used < usage_limit)
             FOR UPDATE"
        );
        $stmt->execute([$applied_discount['discount_id']]);
        $discount = $stmt->fetch();

        if ($discount) {
            $discount_id = $discount['discount_id'];
            if ($discount['discount_type'] === 'percentage') {
                $discount_amount = $subtotal * (min((float)$discount['discount_value'], 100) / 100);
            } else {
                $discount_amount = min((float)$discount['discount_value'], $subtotal);
            }

            $pdo->prepare(
                "UPDATE discounts SET times_used = times_used + 1 WHERE discount_id = ?"
            )->execute([$discount_id]);
        }

    }

    $total = $subtotal - $discount_amount + $shipping_fee;

    $stmt = $pdo->prepare(
        "INSERT INTO orders (order_number, customer_id, discount_id, order_status, payment_status,
                              subtotal, discount_amount, shipping_fee, total_amount, shipping_address, notes)
         VALUES (?, ?, ?, 'pending', 'unpaid', ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $order_number, $customer_id, $discount_id,
        $subtotal, $discount_amount, $shipping_fee, $total, $shipping_address, $notes
    ]);
    $order_id = $pdo->lastInsertId();

    // Stock is deducted (and a SALE log written) by the database trigger
    // trg_order_item_stock_out on every order_items insert. Do NOT deduct it again here.
    $stmt = $pdo->prepare(
        "INSERT INTO order_items (order_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
    );
    foreach ($items as $item) {
        $stmt->execute([$order_id, $item['variant_id'], $item['quantity'], $item['price']]);
    }

    $methodName = match ($payment_method) {
        'gcash' => 'GCash / Online Payment',
        'maya' => 'Maya',
        default => 'Cash on Delivery',
    };

    $paymentMethodId = $pdo->prepare(
        "SELECT payment_method_id FROM payment_methods WHERE method_name = ?"
    );
    $paymentMethodId->execute([$methodName]);
    $codId = $paymentMethodId->fetchColumn();

    if (!$codId) {
        throw new Exception("$methodName payment method is not set up in payment_methods table.");
    }

    $pdo->prepare(
        "INSERT INTO payments (order_id, payment_method_id, amount, payment_status)
         VALUES (?, ?, ?, 'pending')"
    )->execute([$order_id, $codId, $total]);

    $pdo->prepare(
        "DELETE ci FROM cart_items ci
         JOIN carts c ON c.cart_id = ci.cart_id
         WHERE c.customer_id = ?"
    )->execute([$customer_id]);

    $auditDetails = "Order $order_number placed via $methodName";
    if ($discount_id) {
        $auditDetails .= " with voucher {$applied_discount['code']} (-₱" . number_format($discount_amount, 2) . ")";
    }
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
         VALUES (?, 'CREATE_ORDER', 'orders', ?, ?)"
    )->execute([$customer_id, $order_id, $auditDetails]);

    $pdo->commit();

    unset($_SESSION['applied_discount']);

    if (function_exists('notify_order_handlers')) {
        notify_order_handlers($pdo, "New order {$order_number} placed — total ₱" . number_format($total, 2));
    }

    header('Location: order_confirmation.php?order_id=' . $order_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('Something went wrong while placing your order. Please try again or contact support. (' . htmlspecialchars($e->getMessage()) . ')');
}