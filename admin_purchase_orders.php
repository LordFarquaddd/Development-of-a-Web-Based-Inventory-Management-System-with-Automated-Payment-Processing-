<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/stock_helpers.php';
require_role('Admin');

$statusOptions = ['all', 'pending', 'received', 'cancelled'];
$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, $statusOptions, true)) {
    $filter = 'all';
}

$sql = "SELECT po.po_id, po.order_date, po.status, po.total_amount, s.supplier_name
        FROM purchase_orders po
        JOIN suppliers s ON s.supplier_id = po.supplier_id";
$params = [];

if ($filter !== 'all') {
    $sql .= " WHERE po.status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY po.order_date DESC, po.po_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchaseOrders = $stmt->fetchAll();


function poStatusColor($status)
{
    return match ($status) {
        'pending' => '#e0b400',
        'received' => '#1e7e34',
        'cancelled' => '#b91c1c',
        default => '#708090',
    };
}

$riskCount = count(stock_at_risk($pdo));

$pageTitle  = 'Purchase Orders';
$activePage = 'purchase_orders';
$extraHead  = <<<'HTML'
<style>

    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .filters a {
        display: inline-block;
        padding: 6px 14px;
        margin-right: 8px;
        border-radius: 20px;
        background: #dbe4ec;
        color: #193E57;
        text-decoration: none;
        font-size: 13px;
    }

    .filters a.active {
        background: #17364B;
        color: #fff;
    }

    .btn-new {
        background: #e0b400;
        color: #193E57;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 13px;
        text-decoration: none;
    }

    .btn-new:hover {
        background: #957908;
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
    }

    .empty {
        text-align: center;
        color: #708090;
        padding: 40px 0;
    }

    .stock-banner {
        background: #fff8e1;
        border-left: 4px solid #e0b400;
        color: #193E57;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
        font-size: 13px;
    }

    .stock-banner a { color: #17364B; font-weight: bold; }
</style>
HTML;

require 'includes/admin_header.php';
?>

    <div class="content">
        <div class="toolbar">
            <h2 style="margin:0;">Purchase Orders</h2>
            <a href="po_create.php" class="btn-new">+ New Purchase Order</a>
        </div>

        <?php if ($riskCount > 0): ?>
            <div class="stock-banner">
                <?= $riskCount ?> item<?= $riskCount === 1 ? ' is' : 's are' ?> low or trending toward stockout.
                <a href="<?= stock_inventory_url() ?>">Review at-risk stock &rarr;</a>
            </div>
        <?php endif; ?>

        <div class="filters" style="margin-bottom:16px;">
            <?php foreach ($statusOptions as $opt): ?>
                <a href="admin_purchase_orders.php?status=<?= $opt ?>" class="<?= $filter === $opt ? 'active' : '' ?>">
                    <?= ucfirst($opt) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($purchaseOrders)): ?>
            <div class="empty">No purchase orders found for this filter.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Order Date</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th></th>
                </tr>
                <?php foreach ($purchaseOrders as $po): ?>
                    <tr>
                        <td>#<?= (int) $po['po_id'] ?></td>
                        <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td><?= date('M j, Y', strtotime($po['order_date'])) ?></td>
                        <td><span class="badge" style="background:<?= poStatusColor($po['status']) ?>">
                                <?= ucfirst($po['status']) ?>
                            </span></td>
                        <td>&#8369;<?= number_format($po['total_amount'], 2) ?></td>
                        <td><a class="view-link" href="po_receive.php?id=<?= (int) $po['po_id'] ?>">View &rarr;</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php require 'includes/admin_footer.php'; ?>