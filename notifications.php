<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (isset($_POST['notification_id'])) {
        $notificationId = (int) $_POST['notification_id'];
        $mark = $db->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        $mark->bind_param('ii', $notificationId, $userId);
        $mark->execute();
        flash('Notification marked as read.');
        header('Location: /ecms_v1/notifications.php');
        exit;
    } elseif (isset($_POST['mark_all'])) {
        $mark = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $mark->bind_param('i', $userId);
        $mark->execute();
        flash('All notifications marked as read.');
        header('Location: /ecms_v1/notifications.php');
        exit;
    }
}

$filter = $_GET['filter'] ?? 'all';
$where = match ($filter) {
    'unread' => ' AND n.is_read = 0',
    'read' => ' AND n.is_read = 1',
    default => '',
};
$query = $db->prepare('SELECT n.notification_id, n.complaint_id, n.notification_title, n.notification_message, n.is_read, n.created_at FROM notifications n WHERE n.user_id = ?' . $where . ' ORDER BY n.created_at DESC');
$query->bind_param('i', $userId);
$query->execute();
$notifications = $query->get_result()->fetch_all(MYSQLI_ASSOC);

// Opening the inbox marks the notifications shown on this page as read.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $notifications) {
    $markOpened = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $markOpened->bind_param('i', $userId);
    $markOpened->execute();
    foreach ($notifications as &$notification) {
        $notification['is_read'] = 1;
    }
    unset($notification);
}

$pageTitle = 'Notifications';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading"><div><div class="eyebrow"><?= h($_SESSION['role']) ?> portal</div><h1>Notifications</h1><p class="muted">Complaint updates sent to your account.</p></div><div class="action-row"><a class="button secondary" href="/ecms_v1/dashboard.php">← Back to dashboard</a></div></section>
<?php show_flash(); ?>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<div class="notification-toolbar"><nav class="filter-tabs"><a class="<?= $filter === 'all' ? 'selected' : '' ?>" href="/ecms_v1/notifications.php">All</a><a class="<?= $filter === 'unread' ? 'selected' : '' ?>" href="/ecms_v1/notifications.php?filter=unread">Unread</a><a class="<?= $filter === 'read' ? 'selected' : '' ?>" href="/ecms_v1/notifications.php?filter=read">Read</a></nav>
    <?php if ($notifications): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button class="text-button" name="mark_all" value="1">Mark all as read</button></form><?php endif; ?>
</div>
<section class="notification-list">
    <?php if (!$notifications): ?><article class="content-card empty-state"><h2>No notifications</h2><p class="muted">New complaint updates will appear here.</p></article>
    <?php else: foreach ($notifications as $item): ?><article class="notification-card <?= $item['is_read'] ? '' : 'unread' ?>"><div class="notification-copy"><div class="notification-topline"><strong><?= h($item['notification_title']) ?></strong><span><?= h(date('d M Y, g:i a', strtotime($item['created_at']))) ?></span></div><p><?= h($item['notification_message']) ?></p><?php if ($item['complaint_id']): ?><a href="/ecms_v1/complaint_detail.php?id=<?= (int) $item['complaint_id'] ?>">View complaint ECMS-<?= str_pad((string) $item['complaint_id'], 5, '0', STR_PAD_LEFT) ?></a><?php endif; ?></div>
        <?php if (!$item['is_read']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="notification_id" value="<?= (int) $item['notification_id'] ?>"><button class="text-button" type="submit">Mark as read</button></form><?php endif; ?></article><?php endforeach; endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
