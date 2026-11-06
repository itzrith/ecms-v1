<?php

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function complaint_status_class(string $status): string
{
    return match ($status) {
        'Submitted' => 'status-submitted',
        'Under Review' => 'status-under-review',
        'Assigned' => 'status-assigned',
        'In Progress' => 'status-in-progress',
        'Resolved' => 'status-resolved',
        default => 'status-neutral',
    };
}

function complaint_priority_class(string $priority): string
{
    return match ($priority) {
        'Low' => 'priority-low',
        'Medium' => 'priority-medium',
        'High' => 'priority-high',
        'Critical' => 'priority-critical',
        default => 'priority-neutral',
    };
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_is_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function require_login(): void
{
    global $db;

    if (empty($_SESSION['user_id'])) {
        header('Location: /ecms_v1/login.php');
        exit;
    }

    $userId = (int) $_SESSION['user_id'];
    $query = $db->prepare("SELECT full_name, role, account_status FROM users WHERE user_id = ? LIMIT 1");
    $query->bind_param('i', $userId);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();

    if (!$user || $user['account_status'] !== 'Active') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: /ecms_v1/login.php');
        exit;
    }

    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function show_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        $item = $_SESSION['flash'];
        echo '<div class="notice ' . h($item['type']) . '">' . h($item['message']) . '</div>';
        unset($_SESSION['flash']);
    }
}

function ecms_notify(mysqli $db, int $userId, int $complaintId, string $title, string $message): void
{
    $statement = $db->prepare('INSERT INTO notifications (user_id, complaint_id, notification_title, notification_message) VALUES (?, ?, ?, ?)');
    $statement->bind_param('iiss', $userId, $complaintId, $title, $message);
    $statement->execute();
}

function ecms_notify_roles(mysqli $db, int $complaintId, array $roles, string $title, string $message, ?string $department = null): void
{
    $roleSlots = implode(',', array_fill(0, count($roles), '?'));
    if ($roleSlots === '') {
        return;
    }
    $sql = "SELECT user_id FROM users WHERE account_status = 'Active' AND role IN ($roleSlots)";
    $types = str_repeat('s', count($roles));
    $values = $roles;
    if ($department !== null) {
        $sql .= ' AND department = ?';
        $types .= 's';
        $values[] = $department;
    }
    $query = $db->prepare($sql);
    $bind = [$types];
    foreach ($values as $index => $value) {
        $bind[] = &$values[$index];
    }
    call_user_func_array([$query, 'bind_param'], $bind);
    $query->execute();
    foreach ($query->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        ecms_notify($db, (int) $row['user_id'], $complaintId, $title, $message);
    }
}
