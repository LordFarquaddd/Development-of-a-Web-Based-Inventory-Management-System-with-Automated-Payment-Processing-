<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/stock_helpers.php';
require_role('Admin');

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

$allowedReasons = ['RESTOCK', 'ADJUSTMENT', 'RETURN', 'DAMAGED'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust'])) {
    $variant_id = (int)($_POST['variant_id'] ?? 0);
    $change_qty = (int)($_POST['change_qty'] ?? 0);
    $reason     = $_POST['reason'] ?? '';

    if ($change_qty === 0) {
        $error = 'Enter a non-zero quantity to adjust.';
    } elseif (!in_array($reason, $allowedReasons, true)) {
        $error = 'Please choose a valid reason.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT stock_quantity, sku FROM product_variants WHERE variant_id = ? FOR UPDATE"
            );
            $stmt->execute([$variant_id]);
            $variant = $stmt->fetch();

            if (!$variant) {
                throw new Exception('That variant no longer exists.');
            }

            $newQty = $variant['stock_quantity'] + $change_qty;
            if ($newQty < 0) {
                throw new Exception(
                    "That would take {$variant['sku']} below zero (currently {$variant['stock_quantity']})."
                );
            }

            $pdo->prepare(
                "UPDATE product_variants SET stock_quantity = ? WHERE variant_id = ?"
            )->execute([$newQty, $variant_id]);

            $pdo->prepare(
                "INSERT INTO inventory_logs (variant_id, change_qty, reason, reference_table, reference_id)
                 VALUES (?, ?, ?, 'manual_admin_adjustment', ?)"
            )->execute([$variant_id, $change_qty, $reason, $admin_id]);

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'STOCK_ADJUSTMENT', 'product_variants', ?, ?)"
            )->execute([
                $admin_id,
                $variant_id,
                "{$variant['sku']}: {$change_qty} ({$reason}), new stock = {$newQty}"
            ]);

            $pdo->commit();
            $message = "Stock updated for {$variant['sku']}: now {$newQty} on hand.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$filter = $_GET['filter'] ?? 'all';
$focus  = (int)($_GET['focus'] ?? 0);

$allVariants = stock_sort(stock_fetch_variants($pdo));

$lowStockCount = count(array_filter($allVariants, fn($v) => in_array($v['level'], ['out', 'low'], true)));
$riskCount     = count(array_filter($allVariants, fn($v) => $v['level'] !== 'ok'));

$variants = match ($filter) {
    'low'   => array_values(array_filter($allVariants, fn($v) => in_array($v['level'], ['out', 'low'], true))),
    'risk'  => array_values(array_filter($allVariants, fn($v) => $v['level'] !== 'ok')),
    default => $allVariants,
};

$pageTitle  = 'Inventory';
$activePage = 'inventory';
$extraHead  = <<<'HTML'
<style>

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

    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #eee;
        font-size: 13px;
        vertical-align: middle;
    }

    th {
        background: #17364B;
        color: #fff;
        font-size: 12px;
    }

    .sku {
        color: #708090;
        font-size: 11px;
    }

    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
    }

    .badge.low {
        background: #b91c1c;
        color: #fff;
    }

    .badge.ok {
        background: #eafaf1;
        color: #1e7e34;
    }

    .adjust-form {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .adjust-form input[type=number] {
        width: 64px;
        padding: 5px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }

    .adjust-form select {
        padding: 5px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 12px;
    }

    .adjust-form button {
        background: #17364B;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 10px;
        font-size: 12px;
        cursor: pointer;
        font-weight: bold;
    }

    .adjust-form button:hover {
        background: #0f2635;
    }

    .empty {
        text-align: center;
        color: #708090;
        padding: 40px 0;
    }

    tr.low-stock td { background: #fdecea; }
    tr.watch-stock td { background: #fff8e1; }
    tr.focused td { animation: focusPulse 2.4s ease-out 1; box-shadow: inset 0 0 0 2px #e0b400; }
    @keyframes focusPulse { 0% { background: #ffe58a; } 100% { background: inherit; } }
    tr[id^="variant-"] { scroll-margin-top: 90px; }

    .badge.out { background: #7f1d1d; color: #fff; }
    .badge.watch { background: #e0b400; color: #193E57; }

    .meter { width: 90px; height: 6px; background: #e5eaef; border-radius: 3px; margin-top: 5px; overflow: hidden; }
    .meter span { display: block; height: 100%; border-radius: 3px; }
    .rate { color: #708090; font-size: 11px; }
</style>
HTML;

require 'includes/admin_header.php';
?>

    <div class="content">
        <h2>Inventory</h2>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="filters">
            <a href="admin_inventory.php?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All Items</a>
            <a href="admin_inventory.php?filter=low" class="<?= $filter === 'low' ? 'active' : '' ?>">
                Low Stock (<?= (int)$lowStockCount ?>)
            </a>
            <a href="admin_inventory.php?filter=risk" class="<?= $filter === 'risk' ? 'active' : '' ?>">
                At Risk (<?= (int)$riskCount ?>)
            </a>
        </div>

        <?php if (empty($variants)): ?>
            <div class="empty">No items to show.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Product</th>
                    <th>Variant</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Reorder Level</th>
                    <th>Daily Use (<?= STOCK_LOOKBACK_DAYS ?>d)</th>
                    <th>Runs Out In</th>
                    <th>Status</th>
                    <th>Adjust Stock</th>
                </tr>
                <?php foreach ($variants as $v):
                    $rowClass = match ($v['level']) {
                        'out', 'low' => 'low-stock',
                        'watch'      => 'watch-stock',
                        default      => '',
                    };
                    if ($focus === (int)$v['variant_id']) $rowClass .= ' focused';
                    $meterMax = max(1, (int)$v['reorder_level'] * 2);
                    $meterPct = min(100, round(((int)$v['stock_quantity'] / $meterMax) * 100));
                ?>
                    <tr id="variant-<?= (int)$v['variant_id'] ?>" class="<?= trim($rowClass) ?>">
                        <td>
                            <?= htmlspecialchars($v['product_name']) ?><br>
                            <span class="sku"><?= htmlspecialchars($v['category_name']) ?></span>
                        </td>
                        <td>
                            <?= htmlspecialchars($v['size']) ?><?= $v['color'] ? ' - ' . htmlspecialchars($v['color']) : '' ?><br>
                            <span class="sku">SKU: <?= htmlspecialchars($v['sku']) ?></span>
                        </td>
                        <td>&#8369;<?= number_format($v['price'], 2) ?></td>
                        <td>
                            <?= (int)$v['stock_quantity'] ?>
                            <div class="meter"><span style="width:<?= $meterPct ?>%;background:<?= stock_level_color($v['level']) ?>"></span></div>
                        </td>
                        <td><?= (int)$v['reorder_level'] ?></td>
                        <td><?= number_format($v['daily_rate'], 1) ?>/day</td>
                        <td><?= stock_days_text($v['days_left']) ?></td>
                        <td>
                            <span class="badge <?= $v['level'] ?>"><?= stock_level_label($v['level']) ?></span>
                        </td>
                        <td>
                            <form method="post" class="adjust-form">
                                <input type="hidden" name="variant_id" value="<?= $v['variant_id'] ?>">
                                <input type="number" name="change_qty" placeholder="&plusmn;qty" required>
                                <select name="reason">
                                    <option value="RESTOCK">Restock</option>
                                    <option value="ADJUSTMENT">Adjustment</option>
                                    <option value="RETURN">Return</option>
                                    <option value="DAMAGED">Damaged</option>
                                </select>
                                <button type="submit" name="adjust" value="1">Apply</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php if ($focus): ?>
    <script>
        document.getElementById('variant-<?= $focus ?>')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    </script>
<?php endif; ?>

<?php require 'includes/admin_footer.php'; ?>