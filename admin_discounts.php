<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_role('Admin');

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $code           = strtoupper(trim($_POST['code'] ?? ''));
    $description    = trim($_POST['description'] ?? '');
    $discount_type  = $_POST['discount_type'] ?? '';
    $discount_value = (float)($_POST['discount_value'] ?? 0);
    $start_date     = $_POST['start_date'] ?? '';
    $end_date       = $_POST['end_date'] ?? '';
    $usage_limit    = trim($_POST['usage_limit'] ?? '');
    $usage_limit    = $usage_limit === '' ? null : max(1, (int)$usage_limit);

    if ($code === '') {
        $error = 'Voucher code is required.';
    } elseif (!in_array($discount_type, ['percentage', 'fixed_amount'], true)) {
        $error = 'Please choose a valid discount type.';
    } elseif ($discount_value <= 0) {
        $error = 'Discount value must be greater than zero.';
    } elseif ($discount_type === 'percentage' && $discount_value > 100) {
        $error = 'A percentage discount cannot exceed 100.';
    } elseif ($start_date === '' || $end_date === '') {
        $error = 'Please choose both a start and end date.';
    } elseif ($end_date < $start_date) {
        $error = 'End date cannot be before the start date.';
    } else {
        $stmt = $pdo->prepare("SELECT discount_id FROM discounts WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            $error = "A voucher with the code \"$code\" already exists.";
        } else {
            $pdo->prepare(
                "INSERT INTO discounts (code, description, discount_type, discount_value,
                                         start_date, end_date, usage_limit, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
            )->execute([
                $code, $description, $discount_type, $discount_value,
                $start_date, $end_date, $usage_limit
            ]);
            $discount_id = $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
                 VALUES (?, 'CREATE_VOUCHER', 'discounts', ?, ?)"
            )->execute([$admin_id, $discount_id, "Created voucher $code"]);

            $message = "Voucher \"$code\" created.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $discount_id = (int)($_POST['discount_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT code, status FROM discounts WHERE discount_id = ?");
    $stmt->execute([$discount_id]);
    $d = $stmt->fetch();

    if ($d) {
        $newStatus = $d['status'] === 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE discounts SET status = ? WHERE discount_id = ?")
            ->execute([$newStatus, $discount_id]);

        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, details)
             VALUES (?, 'TOGGLE_VOUCHER_STATUS', 'discounts', ?, ?)"
        )->execute([$admin_id, $discount_id, "{$d['code']} set to $newStatus"]);

        $message = "Voucher \"{$d['code']}\" set to $newStatus.";
    }
}

$vouchers = $pdo->query(
    "SELECT * FROM discounts ORDER BY status ASC, end_date DESC"
)->fetchAll();

$pageTitle  = 'Manage Discounts';
$activePage = 'discounts';
$extraHead  = <<<'HTML'
<style>
    .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); margin-bottom: 20px; }
    .card h3 { margin-top: 0; }
    .message { background: #eafaf1; color: #1e7e34; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    .error { background: #fdecea; color: #b91c1c; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    label { display: block; font-weight: bold; font-size: 13px; margin-top: 12px; }
    input[type=text], input[type=number], input[type=date], select, textarea {
        width: 100%; padding: 8px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px;
        font-size: 14px; font-family: inherit;
    }
    .row { display: flex; gap: 16px; }
    .row > div { flex: 1; }
    .btn-create {
        margin-top: 20px; width: 100%; padding: 12px; background: #e0b400; color: #193E57;
        border: none; border-radius: 6px; font-weight: bold; font-size: 15px; cursor: pointer;
        font-family: 'Montserrat', sans-serif;
    }
    .btn-create:hover { background: #957908; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: middle; }
    th { background: #17364B; color: #fff; font-size: 12px; }
    .code { font-family: 'Montserrat', sans-serif; font-weight: bold; letter-spacing: 0.5px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; color: #fff; font-size: 11px; font-weight: bold; }
    .badge.active { background: #1e7e34; }
    .badge.inactive { background: #708090; }
    .toggle-btn {
        background: none; border: 1px solid #ccc; border-radius: 4px; padding: 5px 10px;
        font-size: 12px; cursor: pointer; font-weight: bold;
    }
    .toggle-btn.deactivate { color: #b91c1c; border-color: #b91c1c; }
    .toggle-btn.activate { color: #1e7e34; border-color: #1e7e34; }
    .empty { text-align: center; color: #708090; padding: 40px 0; }
    .usage { color: #708090; font-size: 12px; }
</style>
HTML;

require 'includes/admin_header.php';
?>

<div class="content">
    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <h3>Create Voucher</h3>
        <form method="post">
            <div class="row">
                <div>
                    <label>Voucher Code</label>
                    <input type="text" name="code" placeholder="e.g. WELCOME10" required>
                </div>
                <div>
                    <label>Usage Limit (optional)</label>
                    <input type="number" name="usage_limit" min="1" placeholder="Unlimited if blank">
                </div>
            </div>

            <label>Description (optional)</label>
            <input type="text" name="description" placeholder="e.g. 10% off for new customers">

            <div class="row">
                <div>
                    <label>Discount Type</label>
                    <select name="discount_type" required>
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed_amount">Fixed Amount (&#8369;)</option>
                    </select>
                </div>
                <div>
                    <label>Discount Value</label>
                    <input type="number" name="discount_value" min="0" step="0.01" required>
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" name="end_date" required>
                </div>
            </div>

            <button type="submit" name="create" value="1" class="btn-create">Create Voucher</button>
        </form>
    </div>

    <div class="card">
        <h3>All Vouchers</h3>
        <?php if (empty($vouchers)): ?>
            <div class="empty">No vouchers created yet.</div>
        <?php else: ?>
            <table>
                <tr>
                    <th>Code</th>
                    <th>Value</th>
                    <th>Valid Period</th>
                    <th>Usage</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                <?php foreach ($vouchers as $v): ?>
                    <tr>
                        <td>
                            <span class="code"><?= htmlspecialchars($v['code']) ?></span>
                            <?php if ($v['description']): ?><br><span class="usage"><?= htmlspecialchars($v['description']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?= $v['discount_type'] === 'percentage'
                                ? (int)$v['discount_value'] . '% off'
                                : '&#8369;' . number_format($v['discount_value'], 2) . ' off' ?>
                        </td>
                        <td><?= date('M j, Y', strtotime($v['start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($v['end_date'])) ?></td>
                        <td class="usage">
                            <?= (int)$v['times_used'] ?> used<?= $v['usage_limit'] !== null ? ' / ' . (int)$v['usage_limit'] : ' (unlimited)' ?>
                        </td>
                        <td><span class="badge <?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                        <td>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="discount_id" value="<?= (int)$v['discount_id'] ?>">
                                <button type="submit" name="toggle_status" value="1"
                                        class="toggle-btn <?= $v['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                                    <?= $v['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/admin_footer.php'; ?>