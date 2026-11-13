<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');
auto_complete_delivered_orders($pdo);

$filter = $_GET['status'] ?? 'all';

$sql = "SELECT o.order_id, o.order_number, o.order_date, o.order_status, o.payment_status,
               o.total_amount, u.first_name, u.last_name
        FROM orders o
        JOIN users u ON u.user_id = o.customer_id";
$params = [];

if ($filter !== 'all') {
    $sql .= " WHERE o.order_status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY o.order_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statusOptions = ['all', 'pending', 'processing', 'shipped', 'delivered', 'completed', 'cancellation_requested', 'cancelled'];

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

$pageTitle  = 'Orders';
$activePage = 'orders';
$extraHead  = <<<'HTML'
<style>
    .filters {
        margin-bottom: 16px;
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
    table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    th,td {
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
    .pay-badge {
        font-size: 12px;
        color: #708090;
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
</style>
HTML;

require 'includes/admin_header.php';
?>

    <div class="content">
        <h2>Orders</h2>

        <div class="filters">
            <?php foreach ($statusOptions as $opt): ?>
                <a href="admin_orders.php?status=<?= $opt ?>" class="<?= $filter === $opt ? 'active' : '' ?>">
                    <?= $opt === 'all' ? 'All' : statusLabel($opt) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($orders)): ?>
            <div class="empty">No orders here.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th></th>
                </tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['order_number']) ?></td>
                        <td><?= htmlspecialchars($o['first_name'] . ' ' . $o['last_name']) ?></td>
                        <td><?= date('M j, Y g:i A', strtotime($o['order_date'])) ?></td>
                        <td><span class="badge" style="background:<?= statusColor($o['order_status']) ?>">
                                <?= statusLabel($o['order_status']) ?>
                            </span></td>
                        <td><span class="pay-badge"><?= paymentLabel($o['payment_status']) ?></span></td>
                        <td>&#8369;<?= number_format($o['total_amount'], 2) ?></td>
                        <td><a class="view-link" href="order_detail.php?id=<?= $o['order_id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php require 'includes/admin_footer.php'; ?>