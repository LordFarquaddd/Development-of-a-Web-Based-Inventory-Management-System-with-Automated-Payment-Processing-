<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

$admin_id = $_SESSION['user_id'];
$error = '';
$success = '';

if (!isset($_SESSION['pos_sale']) || !is_array($_SESSION['pos_sale'])) {
    $_SESSION['pos_sale'] = []; 
}

function get_walkin_customer_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = 'walkin@haribonified.local'");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Customer'")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO users (role_id, first_name, last_name, email, password_hash, status)
         VALUES (?, 'Walk-in', 'Customer', 'walkin@haribonified.local', ?, 'active')"
    )->execute([$roleId, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);

    return (int)$pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan'])) {
    $sku = trim($_POST['sku'] ?? '');

    if ($sku === '') {
        $error = 'Scan or type a SKU/barcode first.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT pv.variant_id, pv.sku, pv.size, pv.color, pv.price, pv.stock_quantity, p.product_name
             FROM product_variants pv
             JOIN products p ON p.product_id = pv.product_id
             WHERE pv.sku = ?"
        );
        $stmt->execute([$sku]);
        $variant = $stmt->fetch();

        if (!$variant) {
            $error = "Product not found for SKU \"$sku\".";
        } elseif ($variant['stock_quantity'] <= 0) {
            $error = htmlspecialchars($variant['product_name']) . ' is out of stock.';
        } else {
            $vid = $variant['variant_id'];
            if (isset($_SESSION['pos_sale'][$vid])) {
                $newQty = min($_SESSION['pos_sale'][$vid]['qty'] + 1, $variant['stock_quantity']);
                $_SESSION['pos_sale'][$vid]['qty'] = $newQty;
            } else {
                $_SESSION['pos_sale'][$vid] = [
                    'sku' => $variant['sku'],
                    'product_name' => $variant['product_name'],
                    'size' => $variant['size'],
                    'color' => $variant['color'],
                    'price' => (float)$variant['price'],
                    'stock_quantity' => (int)$variant['stock_quantity'],
                    'qty' => 1,
                ];
            }
            $success = "Added {$variant['product_name']} to the sale.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_qty'])) {
    foreach ($_POST['qty'] as $variant_id => $qty) {
        $variant_id = (int)$variant_id;
        $qty = max(1, (int)$qty);
        if (isset($_SESSION['pos_sale'][$variant_id])) {
            $cap = $_SESSION['pos_sale'][$variant_id]['stock_quantity'];
            $_SESSION['pos_sale'][$variant_id]['qty'] = min($qty, $cap);
        }
    }
}

if (isset($_GET['remove'])) {
    unset($_SESSION['pos_sale'][(int)$_GET['remove']]);
    header('Location: admin_pos.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_sale'])) {
    $_SESSION['pos_sale'] = [];
    header('Location: admin_pos.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $payment_method_id = (int)($_POST['payment_method_id'] ?? 0);
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);

    if (empty($_SESSION['pos_sale'])) {
        $error = 'Scan at least one product before completing the sale.';
    } elseif (!$payment_method_id) {
        $error = 'Select a payment method.';
    } else {
        $subtotal = 0;
        foreach ($_SESSION['pos_sale'] as $line) {
            $subtotal += $line['price'] * $line['qty'];
        }
        $total = $subtotal; 

        if ($payment_amount < $total) {
            $error = 'Insufficient payment. Total is ₱' . number_format($total, 2)
                    . ', received ₱' . number_format($payment_amount, 2) . '.';
        } else {
            try {
                $pdo->beginTransaction();

                foreach ($_SESSION['pos_sale'] as $vid => $line) {
                    $stmt = $pdo->prepare(
                        "SELECT stock_quantity FROM product_variants WHERE variant_id = ? FOR UPDATE"
                    );
                    $stmt->execute([$vid]);
                    $current = $stmt->fetchColumn();
                    if ($current === false || $current < $line['qty']) {
                        throw new Exception("{$line['product_name']} ({$line['sku']}) no longer has enough stock.");
                    }
                }

                $customer_id = get_walkin_customer_id($pdo);
                $order_number = 'POS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $change = $payment_amount - $total;

                $stmt = $pdo->prepare(
                    "INSERT INTO orders (order_number, customer_id, order_status, payment_status,
                                          subtotal, discount_amount, shipping_fee, total_amount, shipping_address, notes)
                     VALUES (?, ?, 'completed', 'paid', ?, 0, 0, ?, 'In-store pickup (POS sale)', ?)"
                );
                $stmt->execute([
                    $order_number, $customer_id, $subtotal, $total,
                    'POS sale processed by ' . current_user_name()
                    . ' | Cash received: ₱' . number_format($payment_amount, 2)
                    . ' | Change: ₱' . number_format($change, 2)
                ]);
                $order_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO order_items (order_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
                );
                foreach ($_SESSION['pos_sale'] as $vid => $line) {
                    $stmt->execute([$order_id, $vid, $line['qty'], $line['price']]);
                }

                $pdo->prepare(
                    "INSERT INTO payments (order_id, payment_method_id, amount, payment_status, completed_at)
                     VALUES (?, ?, ?, 'completed', NOW())"
                )->execute([$order_id, $payment_method_id, $total]);

                $pdo->prepare(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                     VALUES (?, 'CREATE_POS_SALE', 'orders', ?, ?)"
                )->execute([
                    $admin_id, $order_id,
                    "POS sale $order_number, total ₱" . number_format($total, 2)
                    . ", cash ₱" . number_format($payment_amount, 2)
                    . ", change ₱" . number_format($change, 2)
                ]);

                $pdo->commit();

                $_SESSION['pos_sale'] = [];
                header('Location: admin_pos.php?completed=1&order_id=' . $order_id
                    . '&change=' . urlencode(number_format($change, 2)));
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$saleItems = $_SESSION['pos_sale'];
$subtotal = 0;
foreach ($saleItems as $line) {
    $subtotal += $line['price'] * $line['qty'];
}

$paymentMethods = $pdo->query(
    "SELECT payment_method_id, method_name FROM payment_methods WHERE status = 'active' ORDER BY method_name"
)->fetchAll();

$completed = isset($_GET['completed']);
$completedOrderId = (int)($_GET['order_id'] ?? 0);
$completedChange = $_GET['change'] ?? null;

$pageTitle  = 'Point of Sale';
$activePage = 'pos';
$extraHead  = <<<'HTML'
<style>

    .content {
        display: grid;
        grid-template-columns: 1.6fr 1fr;
        gap: 20px;
        align-items: start;
    }
    @media (max-width: 800px) {
        .content { grid-template-columns: 1fr; }
    }

    .card {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }
    .card h3 { margin-top: 0; }

    .message {
        background: #eafaf1;
        color: #1e7e34;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
        grid-column: 1 / -1;
    }
    .error {
        background: #fdecea;
        color: #b91c1c;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
        grid-column: 1 / -1;
    }
    .completed-banner {
        background: #17364B;
        color: #fff;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        grid-column: 1 / -1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .completed-banner .big { font-size: 15px; }
    .completed-banner .change { font-weight: bold; color: #e0b400; font-size: 18px; }
    .completed-banner a {
        background: #e0b400;
        color: #193E57;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: bold;
        text-decoration: none;
        font-size: 13px;
    }

    .scan-box {
        display: flex;
        gap: 8px;
    }
    .scan-box input[type=text] {
        flex: 1;
        padding: 12px;
        font-size: 16px;
        border: 2px solid #17364B;
        border-radius: 6px;
    }
    .scan-box button {
        padding: 12px 20px;
        background: #17364B;
        color: #fff;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        cursor: pointer;
    }
    .scan-hint {
        font-size: 12px;
        color: #708090;
        margin-top: 6px;
    }

    table { width: 100%; border-collapse: collapse; }
    th, td {
        padding: 10px 8px;
        text-align: left;
        border-bottom: 1px solid #eee;
        font-size: 13px;
    }
    th { color: #708090; font-size: 11px; text-transform: uppercase; }
    .sub { color: #708090; font-size: 11px; }
    input[type=number].qty-input {
        width: 55px;
        padding: 5px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    .remove-link { color: #b91c1c; font-size: 12px; text-decoration: none; }
    .empty-sale { color: #708090; font-size: 13px; padding: 20px 0; text-align: center; }

    .btn-update {
        margin-top: 10px;
        background: #dbe4ec;
        color: #193E57;
        border: none;
        padding: 8px 14px;
        border-radius: 6px;
        font-size: 12px;
        cursor: pointer;
        font-weight: bold;
    }
    .btn-clear {
        margin-top: 10px;
        background: none;
        color: #b91c1c;
        border: none;
        font-size: 12px;
        cursor: pointer;
        text-decoration: underline;
    }

    .totals-row {
        display: flex;
        justify-content: space-between;
        font-size: 14px;
        padding: 4px 0;
    }
    .totals-row.grand {
        font-weight: bold;
        font-size: 20px;
        border-top: 1px solid #ddd;
        padding-top: 10px;
        margin-top: 6px;
    }

    label { display: block; font-weight: bold; font-size: 13px; margin-top: 12px; }
    select, input[type=number].pay-input {
        width: 100%;
        padding: 10px;
        margin-top: 4px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
    }
    .change-preview {
        margin-top: 10px;
        font-size: 13px;
        color: #708090;
    }

    .btn-checkout {
        margin-top: 16px;
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
    .btn-checkout:hover { background: #957908; }

    .btn-camera {
        padding: 12px 16px;
        background: #dbe4ec;
        color: #193E57;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        cursor: pointer;
        white-space: nowrap;
    }
    .btn-camera:hover { background: #c7d3de; }

    .camera-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(23,54,75,0.85);
        z-index: 50;
        align-items: center;
        justify-content: center;
    }
    .camera-modal.open { display: flex; }
    .camera-modal-box {
        background: #fff;
        border-radius: 8px;
        padding: 16px;
        width: 360px;
        max-width: 92vw;
    }
    .camera-modal-box h3 { margin: 0 0 10px; font-size: 15px; }
    #camera-reader { width: 100%; border-radius: 6px; overflow: hidden; }
    .camera-modal-hint { font-size: 12px; color: #708090; margin-top: 8px; }
    .camera-modal-close {
        margin-top: 12px;
        width: 100%;
        padding: 10px;
        background: #b91c1c;
        color: #fff;
        border: none;
        border-radius: 6px;
        font-weight: bold;
        cursor: pointer;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
HTML;

require 'includes/admin_header.php';
?>

    <div class="content">

        <?php if ($completed): ?>
            <div class="completed-banner">
                <div>
                    <div class="big">Sale completed successfully!</div>
                    <div class="change">Change due: &#8369;<?= htmlspecialchars($completedChange ?? '0.00') ?></div>
                </div>
                <a href="receipt.php?order_id=<?= $completedOrderId ?>">Print Receipt &rarr;</a>
            </div>
        <?php endif; ?>

        <?php if ($success): ?><div class="message"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div>
            <div class="card">
                <h3>Scan Product</h3>
                <form method="post" class="scan-box" id="scanForm">
                    <input type="hidden" name="scan" value="1">
                    <input type="text" name="sku" id="skuInput" placeholder="Scan barcode or type SKU..." autofocus autocomplete="off">
                    <button type="submit">Add</button>
                    <button type="button" class="btn-camera" onclick="openCameraScanner()">&#128247; Camera</button>
                </form>
                <div class="scan-hint">Barcode scanners act like a keyboard &mdash; scanning will type the SKU and hit Enter automatically. No scanner? Use the Camera button instead.</div>
            </div>

            <div class="card">
                <h3>Current Sale</h3>
                <?php if (empty($saleItems)): ?>
                    <div class="empty-sale">No items yet. Scan a product to begin.</div>
                <?php else: ?>
                    <form method="post">
                        <table>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th>Line Total</th>
                                <th></th>
                            </tr>
                            <?php foreach ($saleItems as $vid => $line): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($line['product_name']) ?><br>
                                        <span class="sub">
                                            <?= htmlspecialchars($line['size']) ?><?= $line['color'] ? ' - ' . htmlspecialchars($line['color']) : '' ?>
                                            &middot; SKU <?= htmlspecialchars($line['sku']) ?>
                                        </span>
                                    </td>
                                    <td>&#8369;<?= number_format($line['price'], 2) ?></td>
                                    <td>
                                        <input type="number" class="qty-input" name="qty[<?= $vid ?>]"
                                               value="<?= $line['qty'] ?>" min="1" max="<?= $line['stock_quantity'] ?>">
                                    </td>
                                    <td>&#8369;<?= number_format($line['price'] * $line['qty'], 2) ?></td>
                                    <td><a class="remove-link" href="admin_pos.php?remove=<?= $vid ?>">Remove</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <button type="submit" name="update_qty" value="1" class="btn-update">Update Quantities</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Clear the entire sale?');">
                        <button type="submit" name="clear_sale" value="1" class="btn-clear">Clear sale</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h3>Payment</h3>
                <div class="totals-row"><span>Subtotal</span><span>&#8369;<?= number_format($subtotal, 2) ?></span></div>
                <div class="totals-row grand"><span>Total</span><span>&#8369;<?= number_format($subtotal, 2) ?></span></div>

                <form method="post" id="checkoutForm">
                    <label>Payment Method</label>
                    <select name="payment_method_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($paymentMethods as $pm): ?>
                            <option value="<?= $pm['payment_method_id'] ?>"><?= htmlspecialchars($pm['method_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Amount Received</label>
                    <input type="number" class="pay-input" name="payment_amount" id="payInput" step="0.01" min="0" required>

                    <div class="change-preview" id="changePreview"></div>

                    <button type="submit" name="checkout" value="1" class="btn-checkout" <?= empty($saleItems) ? 'disabled' : '' ?>>
                        Complete Sale
                    </button>
                </form>
            </div>
        </div>

    </div>

    <div class="camera-modal" id="cameraModal">
        <div class="camera-modal-box">
            <h3>Scan with Camera</h3>
            <div id="camera-reader"></div>
            <div class="camera-modal-hint">Point the camera at the product's barcode. It will be added automatically once recognized.</div>
            <button type="button" class="camera-modal-close" onclick="closeCameraScanner()">Cancel</button>
        </div>
    </div>

<script>
 
    var total = <?= json_encode($subtotal) ?>;
    var payInput = document.getElementById('payInput');
    var preview = document.getElementById('changePreview');
    if (payInput) {
        payInput.addEventListener('input', function () {
            var paid = parseFloat(payInput.value) || 0;
            var diff = paid - total;
            if (paid === 0) {
                preview.textContent = '';
            } else if (diff >= 0) {
                preview.textContent = 'Change: ₱' + diff.toFixed(2);
                preview.style.color = '#1e7e34';
            } else {
                preview.textContent = 'Short by ₱' + Math.abs(diff).toFixed(2);
                preview.style.color = '#b91c1c';
            }
        });
    }


    var skuInput = document.getElementById('skuInput');
    if (skuInput) { skuInput.focus(); }


    var html5QrCode = null;

    function openCameraScanner() {
        document.getElementById('cameraModal').classList.add('open');

        html5QrCode = new Html5Qrcode("camera-reader", {
            formatsToSupport: [
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.CODE_39
            ]
        });

        html5QrCode.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 250, height: 120 } },
            function onScanSuccess(decodedText) {
                skuInput.value = decodedText;
                closeCameraScanner();
                document.getElementById('scanForm').submit();
            },
            function onScanFailure() {

            }
        ).catch(function (err) {
            alert('Could not access the camera: ' + err);
            closeCameraScanner();
        });
    }

    function closeCameraScanner() {
        document.getElementById('cameraModal').classList.remove('open');
        if (html5QrCode) {
            html5QrCode.stop().then(function () {
                html5QrCode.clear();
                html5QrCode = null;
            }).catch(function () {
                html5QrCode = null;
            });
        }
    }
</script>

<?php require 'includes/admin_footer.php'; ?>