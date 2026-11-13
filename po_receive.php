<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

$po_id = (int)($_GET['id'] ?? 0);
$staff_id = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_item'])) {
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    $quantity   = (int)($_POST['quantity'] ?? 0);
    $unit_cost  = (float)($_POST['unit_cost'] ?? 0);

    if ($quantity <= 0) {
        $error = 'Quantity received must be greater than zero.';
    } elseif ($unit_cost < 0) {
        $error = 'Unit cost cannot be negative.';
    } else {
        $stmt = $pdo->prepare("SELECT sku, stock_quantity FROM product_variants WHERE variant_id = ? FOR UPDATE");
        $stmt->execute([$variant_id]);
        $variant = $stmt->fetch();

        if (!$variant) {
            $error = 'Invalid product variant selected.';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    "INSERT INTO purchase_order_items (po_id, variant_id, quantity, unit_cost)
                     VALUES (?, ?, ?, ?)"
                )->execute([$po_id, $variant_id, $quantity, $unit_cost]);

                $pdo->prepare(
                    "UPDATE purchase_orders po
                     SET total_amount = (
                         SELECT COALESCE(SUM(quantity * unit_cost), 0)
                         FROM purchase_order_items
                         WHERE po_id = po.po_id
                     )
                     WHERE po_id = ?"
                )->execute([$po_id]);

                $newQty = $variant['stock_quantity'] + $quantity;

                $pdo->prepare(
                    "UPDATE product_variants SET stock_quantity = ? WHERE variant_id = ?"
                )->execute([$newQty, $variant_id]);

                $pdo->prepare(
                    "INSERT INTO inventory_logs (variant_id, change_qty, reason, reference_table, reference_id)
                     VALUES (?, ?, 'RESTOCK', 'purchase_orders', ?)"
                )->execute([$variant_id, $quantity, $po_id]);

                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                     VALUES (?, 'RECEIVE_PO_ITEM', 'purchase_order_items', ?, ?)"
                )->execute([$staff_id, $po_id, "Received {$quantity} of {$variant['sku']} for PO #{$po_id}, new stock = {$newQty}"]);

                $pdo->commit();
                $message = "Received {$quantity} of {$variant['sku']} — stock updated automatically (now {$newQty} on hand).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Something went wrong: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_received'])) {
    $pdo->prepare("UPDATE purchase_orders SET status = 'received' WHERE po_id = ?")->execute([$po_id]);
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
         VALUES (?, 'PO_MARKED_RECEIVED', 'purchase_orders', ?, 'Purchase order marked as fully received')"
    )->execute([$staff_id, $po_id]);
    $message = 'Purchase order marked as received.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_po'])) {
    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = ?")->execute([$po_id]);
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
         VALUES (?, 'PO_CANCELLED', 'purchase_orders', ?, 'Purchase order cancelled')"
    )->execute([$staff_id, $po_id]);
    $message = 'Purchase order cancelled.';
}

$stmt = $pdo->prepare(
    "SELECT po.*, s.supplier_name, s.contact_person, s.phone
     FROM purchase_orders po
     JOIN suppliers s ON s.supplier_id = po.supplier_id
     WHERE po.po_id = ?"
);
$stmt->execute([$po_id]);
$po = $stmt->fetch();

if (!$po) {
    die('Purchase order not found.');
}

$stmt = $pdo->prepare(
    "SELECT poi.*, pv.sku, pv.size, pv.color, pv.stock_quantity, p.product_name
     FROM purchase_order_items poi
     JOIN product_variants pv ON pv.variant_id = poi.variant_id
     JOIN products p ON p.product_id = pv.product_id
     WHERE poi.po_id = ?
     ORDER BY poi.po_item_id DESC"
);
$stmt->execute([$po_id]);
$receivedItems = $stmt->fetchAll();

$allVariants = $pdo->query(
    "SELECT pv.variant_id, pv.sku, pv.size, pv.color, p.product_name
     FROM product_variants pv
     JOIN products p ON p.product_id = pv.product_id
     ORDER BY p.product_name, pv.size"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PO #<?= (int)$po['po_id'] ?> - Staff Panel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Lato', sans-serif; background: #f4f7fa; margin: 0; color: #193E57; }
    .navbar {
        background: #17364B; padding: 14px 30px; display: flex; justify-content: space-between;
        align-items: center; color: #f6f8ff; font-family: 'Montserrat', sans-serif;
    }
    .navbar a { color: #e0b400; text-decoration: none; font-size: 14px; }
    .content { max-width: 800px; margin: 30px auto; padding: 0 20px; }
    .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.08);
             margin-bottom: 20px; }
    .card h3 { margin-top: 0; }
    .info-row { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; }
    .info-row span:first-child { color: #708090; }
    .status-tag {
        display: inline-block; padding: 3px 10px; border-radius: 12px; color: #fff; font-size: 12px; font-weight: bold;
    }
    .message { background: #eafaf1; color: #1e7e34; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    .error { background: #fdecea; color: #b91c1c; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    label { display: block; font-weight: bold; font-size: 13px; margin-top: 12px; }
    select, input[type=number] {
        width: 100%; padding: 8px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
        font-family: inherit;
    }
    .btn-receive {
        margin-top: 16px; width: 100%; padding: 10px; background: #17364B; color: #fff;
        border: none; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer;
    }
    .btn-receive:hover { background: #0f2635; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
    th { color: #708090; font-size: 12px; }
    .actions { display: flex; gap: 10px; margin-top: 10px; }
    .btn-mark { flex: 1; padding: 10px; border-radius: 6px; font-weight: bold; font-size: 13px; border: none; cursor: pointer; }
    .btn-mark.received { background: #e0b400; color: #193E57; }
    .btn-mark.received:hover { background: #957908; }
    .btn-mark.cancel { background: #fdecea; color: #b91c1c; }
    .empty-items { color: #708090; font-size: 13px; padding: 10px 0; }
</style>
</head>
<body>

<div class="navbar">
    <span>HARIBONified Staff Panel</span>
    <a href="purchase_orders.php">Back to purchase orders</a>
</div>

<div class="content">
    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <h3>Purchase Order #<?= (int)$po['po_id'] ?></h3>
        <div class="info-row"><span>Supplier</span><span><?= htmlspecialchars($po['supplier_name']) ?></span></div>
        <?php if ($po['contact_person']): ?>
            <div class="info-row"><span>Contact</span><span><?= htmlspecialchars($po['contact_person']) ?><?= $po['phone'] ? ' - ' . htmlspecialchars($po['phone']) : '' ?></span></div>
        <?php endif; ?>
        <div class="info-row"><span>Order Date</span><span><?= date('M j, Y', strtotime($po['order_date'])) ?></span></div>
        <div class="info-row"><span>Status</span>
            <span class="status-tag" style="background:<?=
                match($po['status']) { 'pending' => '#e0b400', 'received' => '#1e7e34', 'cancelled' => '#b91c1c', default => '#708090' }
            ?>"><?= ucfirst($po['status']) ?></span>
        </div>
        <div class="info-row"><span>Total (received so far)</span><span>&#8369;<?= number_format($po['total_amount'], 2) ?></span></div>
    </div>

    <?php if ($po['status'] === 'pending'): ?>
        <div class="card">
            <h3>Receive an Item</h3>
            <form method="post">
                <label>Product Variant</label>
                <select name="variant_id" required>
                    <option value="">-- Select variant --</option>
                    <?php foreach ($allVariants as $v): ?>
                        <option value="<?= (int)$v['variant_id'] ?>">
                            <?= htmlspecialchars($v['product_name']) ?> -
                            <?= htmlspecialchars($v['size']) ?><?= $v['color'] ? ' - ' . htmlspecialchars($v['color']) : '' ?>
                            (SKU: <?= htmlspecialchars($v['sku']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Quantity Received</label>
                <input type="number" name="quantity" min="1" required>

                <label>Unit Cost (&#8369;)</label>
                <input type="number" name="unit_cost" min="0" step="0.01" required>

                <button type="submit" name="receive_item" value="1" class="btn-receive">
                    Receive Item (updates stock automatically)
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Items Received</h3>
        <?php if (empty($receivedItems)): ?>
            <div class="empty-items">No items received yet for this PO.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Product</th>
                    <th>Variant</th>
                    <th>Qty</th>
                    <th>Unit Cost</th>
                    <th>Line Total</th>
                    <th>Current Stock</th>
                </tr>
                <?php foreach ($receivedItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['size']) ?><?= $item['color'] ? ' - ' . htmlspecialchars($item['color']) : '' ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td>&#8369;<?= number_format($item['unit_cost'], 2) ?></td>
                        <td>&#8369;<?= number_format($item['quantity'] * $item['unit_cost'], 2) ?></td>
                        <td><?= (int)$item['stock_quantity'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($po['status'] === 'pending'): ?>
        <div class="card">
            <h3>Finalize This PO</h3>
            <div class="actions">
                <form method="post" style="flex:1;">
                    <button type="submit" name="mark_received" value="1" class="btn-mark received" style="width:100%;">
                        Mark as Fully Received
                    </button>
                </form>
                <form method="post" style="flex:1;" onsubmit="return confirm('Cancel this purchase order?');">
                    <button type="submit" name="cancel_po" value="1" class="btn-mark cancel" style="width:100%;">
                        Cancel PO
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>