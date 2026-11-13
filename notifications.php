<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_login();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notification_id = (int) ($_POST['notification_id'] ?? 0);
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?")
        ->execute([$notification_id, $user_id]);
    header('Location: notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
        ->execute([$user_id]);
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT notification_id, message, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50"
);
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

$backLink = match ($role) {
    'Admin' => 'dashboard_admin.php',
    default => 'dashboard_customer.php',
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Notifications - HARIBONified Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        max-width: 640px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .toolbar h2 {
        margin: 0;
    }

    .toolbar button {
        background: none;
        border: none;
        color: #17364B;
        font-size: 13px;
        font-weight: bold;
        text-decoration: underline;
        cursor: pointer;
        font-family: inherit;
    }

    .item {
        background: #fff;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 10px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
    }

    .item.unread {
        border-left: 4px solid #e0b400;
    }

    .msg {
        font-size: 14px;
    }

    .time {
        color: #708090;
        font-size: 11px;
        margin-top: 4px;
    }

    .item button {
        background: #dbe4ec;
        color: #193E57;
        border: none;
        border-radius: 4px;
        padding: 5px 10px;
        font-size: 11px;
        cursor: pointer;
        font-weight: bold;
        white-space: nowrap;
        font-family: inherit;
    }

    .empty {
        text-align: center;
        color: #708090;
        padding: 60px 0;
    }
    </style>
</head>

<body>

    <div class="navbar">
        <span>HARIBONified Store</span>
        <a href="<?= htmlspecialchars($backLink) ?>">Back to dashboard</a>
    </div>

    <div class="content">
        <div class="toolbar">
            <h2>Notifications</h2>
            <?php if (!empty($notifications)): ?>
                <form method="post" style="margin:0;">
                    <button type="submit" name="mark_all_read" value="1">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty">No notifications yet.</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <div class="item <?= $n['is_read'] ? '' : 'unread' ?>">
                    <div>
                        <div class="msg"><?= htmlspecialchars($n['message']) ?></div>
                        <div class="time"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                    <?php if (!$n['is_read']): ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
                            <button type="submit" name="mark_read" value="1">Mark read</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>

</html>