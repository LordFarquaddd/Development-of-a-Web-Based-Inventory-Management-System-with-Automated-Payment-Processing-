<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

// ---------- Overview numbers ----------
$pending   = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending'")->fetchColumn();
$cancelled = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'cancelled'")->fetchColumn();
$lowStock  = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE stock_quantity <= reorder_level")->fetchColumn();
$products  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

// ---------- Selected date (defaults to today) ----------
$dateStr = $_GET['date'] ?? date('Y-m-d');
$d = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$d || $d->format('Y-m-d') !== $dateStr) {
    $d = new DateTime('today');
    $dateStr = $d->format('Y-m-d');
}

// ---------- Sales report: the 7 days ending on the selected date ----------
$metric = (($_GET['metric'] ?? 'sales') === 'orders') ? 'orders' : 'sales';
$start  = (clone $d)->modify('-6 days')->format('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT DATE(order_date) AS day,
            COUNT(*) AS order_count,
            COALESCE(SUM(total_amount), 0) AS sales
     FROM orders
     WHERE order_status = 'completed'
       AND DATE(order_date) BETWEEN :s AND :e
     GROUP BY DATE(order_date)"
);
$stmt->execute([':s' => $start, ':e' => $dateStr]);
$byDay = [];
foreach ($stmt as $row) {
    $byDay[$row['day']] = $row;
}

$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = (clone $d)->modify("-$i days");
    $key = $day->format('Y-m-d');
    $chart[] = [
        'label' => $day->format('D j'),
        'value' => isset($byDay[$key])
            ? ($metric === 'sales' ? (float)$byDay[$key]['sales'] : (int)$byDay[$key]['order_count'])
            : 0,
    ];
}
$max   = max(1, max(array_column($chart, 'value')));
$total = array_sum(array_column($chart, 'value'));

// ---------- Shared layout settings ----------
$pageTitle  = 'Overview';
$activePage = 'overview';
$extraHead  = <<<HTML
<style>
    .row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .main h1 { margin: 0; font-size: 26px; font-weight: 600; }
    .main h2 { margin: 0; font-size: 18px; font-weight: 500; }

    .pill {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 14px;
        min-width: 124px;
        padding: 6px 8px 6px 12px;
        font-size: 13px;
        border: 1px solid #6B5F1F;
        border-radius: 6px;
        cursor: pointer;
    }
    .pill .chev {
        margin-left: auto;
        width: 20px;
        height: 20px;
        display: grid;
        place-items: center;
        background: #EDC937;
        border-radius: 4px;
        font-size: 11px;
        line-height: 1;
    }
    .pill input, .pill select {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 20px;
        margin: 28px 0 40px;
    }
    .stat {
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 14px 18px;
        border: 1px solid #6B5F1F;
        border-radius: 10px;
    }
    .stat-icon { width: 52px; height: 34px; flex: none; background: #EDC937; border-radius: 6px; }
    .stat-label { font-size: 13px; line-height: 1.3; }
    .stat-value { font-size: 16px; font-weight: 600; }

    .report { margin-top: 18px; padding: 24px 28px; min-height: 320px; background: #D9D9D9; border-radius: 40px; }
    .report-total { margin: 0 0 12px; font-size: 13px; color: #193E57; }
    .report svg { width: 100%; height: auto; display: block; }

    @media (max-width: 1100px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 600px)  { .stats { grid-template-columns: 1fr; } }
</style>
HTML;

require 'includes/admin_header.php';
?>

<form method="get" id="filters">
    <div class="row">
        <h1>Overview</h1>
        <label class="pill">
            <span><?= $d->format('d M Y') ?></span>
            <span class="chev">&#9662;</span>
            <input type="date" name="date" value="<?= $dateStr ?>" onchange="this.form.submit()">
        </label>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-icon"></div>
            <div><div class="stat-label">Pending Orders</div><div class="stat-value"><?= $pending ?></div></div>
        </div>
        <div class="stat">
            <div class="stat-icon"></div>
            <div><div class="stat-label">Cancelled Orders</div><div class="stat-value"><?= $cancelled ?></div></div>
        </div>
        <div class="stat">
            <div class="stat-icon"></div>
            <div><div class="stat-label">Low-stock Items</div><div class="stat-value"><?= $lowStock ?></div></div>
        </div>
        <div class="stat">
            <div class="stat-icon"></div>
            <div><div class="stat-label">Total Products</div><div class="stat-value"><?= $products ?></div></div>
        </div>
    </div>

    <div class="row">
        <h2>Your sales report</h2>
        <label class="pill">
            <span><?= $metric === 'sales' ? 'Total Sales' : 'Total Orders' ?></span>
            <span class="chev">&#9662;</span>
            <select name="metric" onchange="this.form.submit()">
                <option value="sales"  <?= $metric === 'sales'  ? 'selected' : '' ?>>Total Sales</option>
                <option value="orders" <?= $metric === 'orders' ? 'selected' : '' ?>>Total Orders</option>
            </select>
        </label>
    </div>
</form>

<div class="report">
    <p class="report-total">
        Last 7 days:
        <strong><?= $metric === 'sales' ? '₱' . number_format($total, 2) : $total . ' order' . ($total === 1 ? '' : 's') ?></strong>
    </p>

    <svg viewBox="0 0 700 260" role="img" aria-label="Bar chart of <?= $metric === 'sales' ? 'sales' : 'orders' ?> for the last 7 days">
        <line x1="40" y1="220" x2="690" y2="220" stroke="#8d8d8d"/>
        <?php
        $barW = 52;
        $step = 650 / 7;
        foreach ($chart as $i => $c):
            $h = ($c['value'] / $max) * 180;
            $x = 40 + $i * $step + ($step - $barW) / 2;
            $y = 220 - $h;
            $text = $metric === 'sales' ? '₱' . number_format($c['value'], 0) : $c['value'];
        ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= $barW ?>" height="<?= round($h, 1) ?>" rx="6" fill="#EDC937"/>
            <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= round($y - 6, 1) ?>" text-anchor="middle" font-size="12" fill="#193E57"><?= $c['value'] > 0 ? $text : '' ?></text>
            <text x="<?= round($x + $barW / 2, 1) ?>" y="242" text-anchor="middle" font-size="12" fill="#17212B"><?= $c['label'] ?></text>
        <?php endforeach; ?>
    </svg>
</div>

<?php require 'includes/admin_footer.php'; ?>