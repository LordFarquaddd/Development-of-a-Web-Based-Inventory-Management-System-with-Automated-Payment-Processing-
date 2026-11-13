<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Staff');

$pendingOrders = $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'pending'"
)->fetchColumn();

$unpaidCod = $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE payment_status IN ('unpaid','pending')"
)->fetchColumn();

$lowStock = $pdo->query(
    "SELECT COUNT(*) FROM product_variants WHERE stock_quantity <= reorder_level"
)->fetchColumn();

$switchError = $_GET['switch_error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">

    <title>Staff Dashboard - HARIBONified Store</title>
    <style>
    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Lato',
            sans-serif;
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
        font-family: 'Montserrat', sans-serif;
    }

    .content {
        max-width: 900px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .stats {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 30px;
    }

    .stat-card {
        flex: 1;
        min-width: 180px;
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        text-align: center;
    }

    .stat-card .num {
        font-size: 28px;
        font-weight: bold;
        color: #17364B;
    }

    .stat-card .label {
        font-size: 13px;
        color: #708090;
        margin-top: 4px;
    }

    .stat-card.alert .num {
        color: #b91c1c;
    }

    .links {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .link-card {
        flex: 1;
        min-width: 220px;
        background: #17364B;
        color: #f6f8ff;
        border-radius: 8px;
        padding: 24px;
        text-decoration: none;
        display: block;
    }

    .link-card:hover {
        background: #0f2635;
    }

    .link-card h3 {
        margin: 0 0 6px;
        color: #e0b400;
    }

    .link-card p {
        margin: 0;
        font-size: 13px;
        color: #dbe4ec;
    }

    .switch-toggle {
        display: inline-block;
        margin-top: 4px;
        font-size: 13px;
        color: #708090;
        cursor: pointer;
        text-decoration: underline;
    }
    .switch-card {
        display: none;
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        margin-top: 20px;
        max-width: 360px;
    }
    .switch-card.open {
        display: block;
    }
    .switch-card h4 {
        margin: 0 0 4px;
        font-size: 14px;
    }
    .switch-card .hint {
        font-size: 12px;
        color: #708090;
        margin-bottom: 10px;
    }
    .switch-card label {
        display: block;
        font-weight: bold;
        font-size: 12px;
        margin-top: 10px;
    }
    .switch-card input {
        width: 100%;
        padding: 7px;
        margin-top: 4px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 13px;
        box-sizing: border-box;
    }
    .switch-card button {
        margin-top: 14px;
        width: 100%;
        padding: 9px;
        background: #17364B;
        color: #fff;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        font-size: 13px;
        cursor: pointer;
    }
    .switch-card button:hover {
        background: #0f2635;
    }
    .switch-error {
        background: #fdecea;
        color: #b91c1c;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 12px;
        margin-top: 10px;
    }
    </style>
</head>

<body>

    <div class="navbar">
        <span>HARIBONified Staff Panel</span>
        <div>
            <span style="margin-right:16px;">Hi, <?= htmlspecialchars(current_user_name()) ?></span>
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <div class="content">
        <h2>Overview</h2>
        <div class="stats">
            <div class="stat-card <?= $pendingOrders > 0 ? 'alert' : '' ?>">
                <div class="num"><?= (int) $pendingOrders ?></div>
                <div class="label">Pending Orders</div>
            </div>
            <div class="stat-card <?= $unpaidCod > 0 ? 'alert' : '' ?>">
                <div class="num"><?= (int) $unpaidCod ?></div>
                <div class="label">Awaiting Payment</div>
            </div>
            <div class="stat-card <?= $lowStock > 0 ? 'alert' : '' ?>">
                <div class="num"><?= (int) $lowStock ?></div>
                <div class="label">Low Stock Items</div>
            </div>
        </div>

        <div class="links">
            <a class="link-card" href="orders.php">
                <h3>Manage Orders &rarr;</h3>
                <p>View incoming orders, update status, and confirm payments.</p>
            </a>
            <a class="link-card" href="inventory.php">
                <h3>Manage Inventory &rarr;</h3>
                <p>Check stock levels and see what needs restocking.</p>
            </a>
            <a class="link-card" href="pos.php">
                <h3>Point of Sale &rarr;</h3>
                <p>Ring up a walk-in / in-store sale and print a receipt.</p>
            </a>
        </div>

        <span class="switch-toggle" onclick="toggleSwitchCard()">Need Admin access? Switch account &rarr;</span>

        <div class="switch-card <?= $switchError ? 'open' : '' ?>" id="switchCard">
            <h4>Switch to Admin</h4>
            <div class="hint">Enter an Admin account's login to jump straight to the Admin dashboard.</div>
            <?php if ($switchError): ?>
                <div class="switch-error"><?= htmlspecialchars($switchError) ?></div>
            <?php endif; ?>
            <form method="post" action="quick_switch.php">
                <label>Admin Email</label>
                <input type="email" name="admin_email" required>
                <label>Admin Password</label>
                <input type="password" name="admin_password" required>
                <button type="submit">Switch to Admin</button>
            </form>
        </div>
    </div>

<script>
function toggleSwitchCard() {
    document.getElementById('switchCard').classList.toggle('open');
}
</script>

</body>

</html>