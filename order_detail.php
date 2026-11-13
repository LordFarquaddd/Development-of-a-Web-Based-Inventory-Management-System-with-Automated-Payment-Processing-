<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');
auto_complete_delivered_orders($pdo);

$order_id = (int) ($_GET['id'] ?? 0);
$admin_id = $_SESSION['user_id'];
$message = $_SESSION['flash_message'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

$forwardFlow = ['pending', 'processing', 'shipped', 'delivered', 'completed'];
// The admin moves an order up to 'delivered'. The customer (or the auto-complete timer) completes it.
$adminSteps = ['pending', 'processing', 'shipped', 'delivered'];
// 'delivered' removed: the goods are already with the customer, so restocking them would inflate inventory.
$adminDirectCancelable = ['pending', 'processing', 'shipped'];

function restockOrderItems(PDO $pdo, int $order_id): void
{
    $itemStmt = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
    $itemStmt->execute([$order_id]);
    $orderItems = $itemStmt->fetchAll();

    $restockStmt = $pdo->prepare(
        "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE variant_id = ?"
    );
    $logStmt = $pdo->prepare(
        "INSERT INTO inventory_logs (variant_id, change_qty, reason, reference_table, reference_id)
         VALUES (?, ?, 'RETURN', 'orders', ?)"
    );
    foreach ($orderItems as $oi) {
        $restockStmt->execute([$oi['quantity'], $oi['variant_id']]);
        $logStmt->execute([$oi['variant_id'], $oi['quantity'], $order_id]);
    }
}

/**
 * Store a flash message and redirect back to this order (Post/Redirect/Get),
 * so refreshing the page can never re-send the action.
 */
function flashAndRedirect(int $order_id, string $message, string $error): void
{
    if ($message !== '') {
        $_SESSION['flash_message'] = $message;
    }
    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }
    header('Location: order_detail.php?id=' . $order_id);
    exit;
}

/**
 * Give back one use of the voucher attached to a cancelled order.
 */
function releaseVoucher(PDO $pdo, int $order_id): void
{
    $pdo->prepare(
        "UPDATE discounts d
         JOIN orders o ON o.discount_id = d.discount_id
         SET d.times_used = GREATEST(d.times_used - 1, 0)
         WHERE o.order_id = ?"
    )->execute([$order_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Move the order forward (pending -> ... -> completed)
    if (isset($_POST['update_status'])) {
        $newStatus = $_POST['new_status'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT order_number, customer_id, order_status, payment_status FROM orders WHERE order_id = ?"
        );
        $stmt->execute([$order_id]);
        $before = $stmt->fetch();
        $beforeIdx = $before ? array_search($before['order_status'], $forwardFlow, true) : false;
        $newIdx = array_search($newStatus, $forwardFlow, true);

        // Online payment (GCash/Maya/...) or Cash on Delivery?
        $payStmt = $pdo->prepare(
            "SELECT pm.is_online
             FROM payments p
             JOIN payment_methods pm ON pm.payment_method_id = p.payment_method_id
             WHERE p.order_id = ?
             ORDER BY p.payment_id LIMIT 1"
        );
        $payStmt->execute([$order_id]);
        $isOnlinePayment = (int) $payStmt->fetchColumn() === 1;

        if (!in_array($newStatus, $adminSteps, true)) {
            $error = 'Invalid status.';
        } elseif ($beforeIdx === false || $newIdx <= $beforeIdx) {
            $error = 'This order can\'t be moved to that status.';
        } elseif (
            $isOnlinePayment
            && $before['payment_status'] !== 'paid'
            && in_array($newStatus, ['shipped', 'delivered', 'completed'], true)
        ) {
            $error = 'This is an online-payment order. Confirm the payment first before shipping or completing it.';
        } else {
            try {
                $pdo->beginTransaction();

                // Only move it if it is still in the status we read (guards against double-submits)
                $upd = $pdo->prepare(
                    "UPDATE orders SET order_status = ? WHERE order_id = ? AND order_status = ?"
                );
                $upd->execute([$newStatus, $order_id, $before['order_status']]);
                if ($upd->rowCount() !== 1) {
                    throw new Exception('The order changed while you were updating it. Please try again.');
                }

                if ($newStatus === 'delivered') {
                    // Start the customer-confirmation / auto-complete clock.
                    $pdo->prepare("UPDATE orders SET delivered_at = NOW() WHERE order_id = ?")->execute([$order_id]);
                }

                // Cash on Delivery: the courier collects the cash at the door, so it is paid on
                // delivery. Online orders must already be confirmed as paid (enforced above).
                if ($newStatus === 'delivered' && !$isOnlinePayment) {
                    $pdo->prepare(
                        "UPDATE orders SET payment_status = 'paid' WHERE order_id = ? AND payment_status != 'paid'"
                    )->execute([$order_id]);
                    $pdo->prepare(
                        "UPDATE payments SET payment_status = 'completed', completed_at = NOW()
                         WHERE order_id = ? AND payment_status != 'completed'"
                    )->execute([$order_id]);
                }

                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                     VALUES (?, 'UPDATE_ORDER_STATUS', 'orders', ?, ?)"
                )->execute([$admin_id, $order_id, "Status changed to {$newStatus}"]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }

            if ($error === '') {
                $statusMessages = [
                    'processing' => 'is now being processed',
                    'shipped' => 'has been shipped',
                    'delivered' => 'has been delivered. Please confirm in My Orders that you received it',
                ];
                if (isset($statusMessages[$newStatus])) {
                    notify_user(
                        $pdo,
                        (int) $before['customer_id'],
                        "Your order {$before['order_number']} {$statusMessages[$newStatus]}"
                    );
                }
                $message = 'Order status updated.';
            }
        }
    }

    // approve a pending cancellation request
    if (isset($_POST['approve_cancel'])) {
        try {
            $pdo->beginTransaction();

            // Only succeeds if the order is STILL awaiting a decision, so a refresh,
            // double-click or crafted request can never restock the same order twice.
            $upd = $pdo->prepare(
                "UPDATE orders SET order_status = 'cancelled', previous_status = NULL
                 WHERE order_id = ? AND order_status = 'cancellation_requested'"
            );
            $upd->execute([$order_id]);
            if ($upd->rowCount() !== 1) {
                throw new Exception('This order has no pending cancellation request.');
            }

            restockOrderItems($pdo, $order_id);
            releaseVoucher($pdo, $order_id);

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'APPROVE_CANCELLATION', 'orders', ?, 'Admin approved cancellation request, stock restored')"
            )->execute([$admin_id, $order_id]);
            $pdo->commit();

            $stmt = $pdo->prepare("SELECT order_number, customer_id FROM orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            if ($reviewed = $stmt->fetch()) {
                notify_user(
                    $pdo,
                    (int) $reviewed['customer_id'],
                    "Your cancellation request for order {$reviewed['order_number']} was approved. The order is now cancelled."
                );
            }

            $message = 'Cancellation approved — order cancelled and stock restored.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not approve the cancellation: ' . $e->getMessage();
        }
    }

    // reject a pending cancellation request, reverting the order
    if (isset($_POST['reject_cancel'])) {
        $stmt = $pdo->prepare(
            "SELECT previous_status, order_status, order_number, customer_id FROM orders WHERE order_id = ?"
        );
        $stmt->execute([$order_id]);
        $row = $stmt->fetch();

        if (!$row || $row['order_status'] !== 'cancellation_requested') {
            $error = 'This order has no pending cancellation request.';
        } else {
            $revertTo = $row['previous_status'] ?: 'processing';

            $upd = $pdo->prepare(
                "UPDATE orders SET order_status = ?, previous_status = NULL, cancellation_reason = NULL
                 WHERE order_id = ? AND order_status = 'cancellation_requested'"
            );
            $upd->execute([$revertTo, $order_id]);

            if ($upd->rowCount() !== 1) {
                $error = 'This order has no pending cancellation request.';
            } else {
                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                     VALUES (?, 'REJECT_CANCELLATION', 'orders', ?, ?)"
                )->execute([$admin_id, $order_id, "Cancellation rejected, order reverted to {$revertTo}"]);

                notify_user(
                    $pdo,
                    (int) $row['customer_id'],
                    "Your cancellation request for order {$row['order_number']} was rejected. The order remains " . statusLabel($revertTo) . '.'
                );

                $message = 'Cancellation rejected — order restored.';
            }
        }
    }

    // skip the request step and cancel directly
    if (isset($_POST['direct_cancel'])) {
        $reasonCode = $_POST['cancellation_reason'] ?? '';
        $adminReasons = admin_cancellation_reasons();

        if (!array_key_exists($reasonCode, $adminReasons)) {
            $error = 'Please choose a reason for the cancellation.';
        } else {
            try {
                $pdo->beginTransaction();

                $ph = implode(',', array_fill(0, count($adminDirectCancelable), '?'));
                $upd = $pdo->prepare(
                    "UPDATE orders SET order_status = 'cancelled', previous_status = NULL,
                                        cancellation_reason = ?
                     WHERE order_id = ? AND order_status IN ($ph)"
                );
                $upd->execute(array_merge([$reasonCode, $order_id], $adminDirectCancelable));
                if ($upd->rowCount() !== 1) {
                    throw new Exception('This order cannot be cancelled at its current stage.');
                }

                restockOrderItems($pdo, $order_id);
                releaseVoucher($pdo, $order_id);

                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                     VALUES (?, 'CANCEL_ORDER', 'orders', ?, ?)"
                )->execute([$admin_id, $order_id, "Admin cancelled order directly, stock restored — reason: {$adminReasons[$reasonCode]}"]);
                $pdo->commit();

                $stmt = $pdo->prepare("SELECT order_number, customer_id FROM orders WHERE order_id = ?");
                $stmt->execute([$order_id]);
                if ($reviewed = $stmt->fetch()) {
                    notify_user(
                        $pdo,
                        (int) $reviewed['customer_id'],
                        "Order {$reviewed['order_number']} was cancelled by the store. Reason: {$adminReasons[$reasonCode]}."
                    );
                }

                $message = 'Order cancelled and stock restored.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }

    // confirm a customer-claimed online payment (GCash/Maya)
    if (isset($_POST['confirm_payment'])) {
        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare(
                "UPDATE orders SET payment_status = 'paid'
                 WHERE order_id = ? AND payment_status = 'payment_claimed' AND order_status != 'cancelled'"
            );
            $upd->execute([$order_id]);
            if ($upd->rowCount() !== 1) {
                throw new Exception('No payment claim is awaiting confirmation for this order.');
            }

            $pdo->prepare(
                "UPDATE payments SET payment_status = 'completed', completed_at = NOW()
                 WHERE order_id = ? AND payment_status != 'completed'"
            )->execute([$order_id]);

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'CONFIRM_PAYMENT', 'orders', ?, 'Admin confirmed online payment received')"
            )->execute([$admin_id, $order_id]);
            $pdo->commit();

            $message = 'Payment confirmed.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }

    // reject a customer-claimed payment that wasn't actually received
    if (isset($_POST['reject_payment'])) {
        $upd = $pdo->prepare(
            "UPDATE orders SET payment_status = 'unpaid'
             WHERE order_id = ? AND payment_status = 'payment_claimed'"
        );
        $upd->execute([$order_id]);

        if ($upd->rowCount() !== 1) {
            $error = 'No payment claim is awaiting review for this order.';
        } else {
            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'REJECT_PAYMENT_CLAIM', 'orders', ?, 'Admin rejected payment claim, reverted to unpaid')"
            )->execute([$admin_id, $order_id]);
            $message = 'Payment claim rejected — order marked unpaid again.';
        }
    }

    // Always redirect after a POST so a browser refresh can't repeat the action.
    flashAndRedirect($order_id, $message, $error);
}

$stmt = $pdo->prepare(
    "SELECT o.*, u.first_name, u.last_name, u.email, u.contact_number
     FROM orders o
     JOIN users u ON u.user_id = o.customer_id
     WHERE o.order_id = ?"
);
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
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

$statusIndex = array_search($order['order_status'], $forwardFlow, true);
$stepIndex = array_search($order['order_status'], $adminSteps, true);
$nextStatus = ($stepIndex !== false && $stepIndex < count($adminSteps) - 1) ? $adminSteps[$stepIndex + 1] : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Order <?= htmlspecialchars($order['order_number']) ?> - Admin Panel</title>
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

    .card {
        background: #fff;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        margin-bottom: 20px;
    }

    .card h3 {
        margin-top: 0;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        font-size: 14px;
        padding: 4px 0;
    }

    .info-row span:first-child {
        color: #708090;
    }

    .status-tag {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        color: #fff;
        font-size: 12px;
        font-weight: bold;
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

    .cancel-banner {
        background: #fff8e1;
        border: 1px solid #e0b400;
        color: #957908;
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 16px;
        font-size: 13px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    th,
    td {
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid #eee;
        font-size: 13px;
    }

    th {
        color: #708090;
        font-size: 12px;
    }

    label {
        display: block;
        font-weight: bold;
        font-size: 13px;
        margin-top: 12px;
    }

    select {
        width: 100%;
        padding: 8px;
        margin-top: 4px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }

    .actions {
        display: flex;
        gap: 10px;
        margin-top: 14px;
        flex-wrap: wrap;
    }

    .actions form {
        flex: 1;
        min-width: 160px;
    }

    .btn {
        width: 100%;
        padding: 10px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 13px;
        border: none;
        cursor: pointer;
    }

    .btn-primary {
        background: #17364B;
        color: #fff;
    }

    .btn-primary:hover {
        background: #0f2635;
    }

    .btn-warn {
        background: #fff8e1;
        color: #957908;
        border: 1px solid #e0b400;
    }

    .btn-approve {
        background: #eafaf1;
        color: #1e7e34;
        border: 1px solid #1e7e34;
    }

    .btn-reject {
        background: #fdecea;
        color: #b91c1c;
        border: 1px solid #b91c1c;
    }

    .btn-cancel {
        background: #fdecea;
        color: #b91c1c;
    }

    .awaiting {
        color: #957908;
        font-size: 13px;
        font-weight: bold;
    }
    </style>
</head>

<body>

    <div class="navbar">
        <span>HARIBONified Admin Panel</span>
        <a href="admin_orders.php">Back to orders</a>
    </div>

    <div class="content">
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card">
            <h3>Order <?= htmlspecialchars($order['order_number']) ?></h3>
            <div class="info-row">
                <span>Customer</span><span><?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?></span>
            </div>
            <div class="info-row"><span>Email</span><span><?= htmlspecialchars($order['email']) ?></span></div>
            <div class="info-row">
                <span>Contact</span><span><?= htmlspecialchars($order['contact_number'] ?? '—') ?></span></div>
            <div class="info-row"><span>Order
                    Date</span><span><?= date('M j, Y g:i A', strtotime($order['order_date'])) ?></span></div>
            <div class="info-row"><span>Shipping
                    Address</span><span><?= nl2br(htmlspecialchars($order['shipping_address'] ?? '')) ?></span></div>
            <?php if (!empty($order['notes'])): ?>
                <div class="info-row"><span>Notes</span><span><?= htmlspecialchars($order['notes']) ?></span></div>
            <?php endif; ?>
            <div class="info-row"><span>Status</span>
                <span class="status-tag" style="background:<?= statusColor($order['order_status']) ?>">
                    <?= statusLabel($order['order_status']) ?>
                </span>
            </div>
            <div class="info-row"><span>Payment</span><span><?= paymentLabel($order['payment_status']) ?></span></div>
            <?php if ($order['order_status'] === 'cancelled' && $order['cancellation_reason']): ?>
                <div class="info-row"><span>Cancellation
                        Reason</span><span><?= htmlspecialchars(cancellation_reason_label($order['cancellation_reason'])) ?></span>
                </div>
            <?php endif; ?>
            <div class="info-row"><span>Total</span><span>&#8369;<?= number_format($order['total_amount'], 2) ?></span>
            </div>
            <a href="receipt.php?order_id=<?= (int) $order['order_id'] ?>"
                style="font-size:13px; color:#17364B; font-weight:bold;">View / Print Receipt &rarr;</a>
        </div>

        <?php if ($order['payment_status'] === 'payment_claimed'): ?>
            <div class="card">
                <h3>Payment Confirmation</h3>
                <div class="cancel-banner">The customer says they've paid online. Confirm once you've verified it landed in
                    your account.</div>
                <div class="actions">
                    <form method="post">
                        <button type="submit" name="confirm_payment" value="1" class="btn btn-approve">Confirm Payment
                            Received</button>
                    </form>
                    <form method="post">
                        <button type="submit" name="reject_payment" value="1" class="btn btn-reject">Reject — Not
                            Received</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Items</h3>
            <table>
                <tr>
                    <th>Product</th>
                    <th>Variant</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                </tr>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?>
                        </td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td>&#8369;<?= number_format($item['unit_price'], 2) ?></td>
                        <td>&#8369;<?= number_format($item['line_subtotal'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if ($order['order_status'] === 'cancellation_requested'): ?>
            <div class="card">
                <h3>Cancellation Request</h3>
                <div class="info-row"><span>Reason
                        given</span><span><?= htmlspecialchars(cancellation_reason_label($order['cancellation_reason'])) ?></span>
                </div>
                <div class="cancel-banner">This order's cancellation is awaiting your decision. Approving will cancel the
                    order and return the items to stock.</div>
                <div class="actions">
                    <form method="post">
                        <button type="submit" name="approve_cancel" value="1" class="btn btn-approve">Approve
                            Cancellation</button>
                    </form>
                    <form method="post">
                        <button type="submit" name="reject_cancel" value="1" class="btn btn-reject">Reject &amp; Restore
                            Order</button>
                    </form>
                </div>
            </div>
        <?php elseif (!in_array($order['order_status'], ['completed', 'cancelled'], true)): ?>
            <div class="card">
                <h3>Update Status</h3>
                <?php if ($order['order_status'] === 'delivered'): ?>
                    <div class="cancel-banner awaiting">Delivered. Waiting for the customer to confirm receipt. It completes automatically <?= (int) DELIVERY_AUTO_COMPLETE_DAYS ?> days after delivery.</div>
                <?php endif; ?>
                <?php if ($nextStatus): ?>
                    <form method="post">
                        <label>Move order forward to:</label>
                        <select name="new_status">
                            <?php foreach ($adminSteps as $s):
                                if ($s === $order['order_status'] || array_search($s, $forwardFlow, true) < $statusIndex)
                                    continue; ?>
                                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_status" value="1" class="btn btn-primary"
                            style="margin-top:12px;">Update Status</button>
                    </form>
                <?php endif; ?>

                <div class="actions">
                    <?php if (in_array($order['order_status'], $adminDirectCancelable, true)): ?>
                        <form method="post" onsubmit="return confirm('Cancel this order directly? Stock will be restored.');">
                            <label>Reason</label>
                            <select name="cancellation_reason" required>
                                <option value="">-- Select a reason --</option>
                                <?php foreach (admin_cancellation_reasons() as $code => $label): ?>
                                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="direct_cancel" value="1" class="btn btn-cancel"
                                style="margin-top:8px;">Cancel Order Directly</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>

</html>