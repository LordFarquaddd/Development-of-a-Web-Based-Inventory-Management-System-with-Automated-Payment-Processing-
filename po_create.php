<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

$staff_id = $_SESSION['user_id'];
$error = '';

$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $order_date = $_POST['order_date'] ?? '';

    if ($supplier_id <= 0) {
        $error = 'Please select a supplier.';
    } elseif ($order_date === '') {
        $error = 'Please choose an order date.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO purchase_orders (supplier_id, order_date, status, total_amount, created_by)
             VALUES (?, ?, 'pending', 0, ?)"
        );
        $stmt->execute([$supplier_id, $order_date, $staff_id]);
        $po_id = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'CREATE_PO', 'purchase_orders', ?, 'Purchase order created')"
        )->execute([$staff_id, $po_id]);

        header('Location: po_receive.php?id=' . $po_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>New Purchase Order</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        max-width: 500px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .card {
        background: #fff;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }

    label {
        display: block;
        font-weight: bold;
        font-size: 13px;
        margin-top: 12px;
    }

    select,
    input[type=date] {
        width: 100%;
        padding: 8px;
        margin-top: 4px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }

    .btn-create {
        margin-top: 20px;
        width: 100%;
        padding: 12px;
        background: #e0b400;
        color: #193E57;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        font-size: 15px;
        cursor: pointer;
    }

    .btn-create:hover {
        background: #957908;
    }

    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
    }

    .no-suppliers {
        color: #708090;
        font-size: 13px;
        margin-top: 10px;
    }
    </style>
</head>

<body>

    <div class="navbar">
        <span>HARIBONified Supplies</span>
        <a href="purchase_orders.php">&larr; Back to purchase orders</a>
    </div>

    <div class="content">
        <h2>New Purchase Order</h2>

        <div class="card">
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (empty($suppliers)): ?>
                <div class="no-suppliers">No suppliers found. Add one to your <code>suppliers</code> table first (via
                    phpMyAdmin) before creating a purchase order.</div>
            <?php else: ?>
                <form method="post">
                    <label>Supplier</label>
                    <select name="supplier_id" required>
                        <option value="">-- Select supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Order Date</label>
                    <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" required>

                    <button type="submit" class="btn-create">Create Purchase Order</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>