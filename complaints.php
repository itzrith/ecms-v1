<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$sortPriority = $_GET['sort_priority'] ?? '';
$department = trim($_GET['department'] ?? '');
$category = trim($_GET['category'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$queue = trim($_GET['queue'] ?? '');
$dateError = '';
$dateRangeInvalid = false;

if (!in_array($sortPriority, ['asc', 'desc'], true)) {
    $sortPriority = '';
}
if ($status !== '' && !in_array($status, ['Submitted', 'Under Review', 'Assigned', 'In Progress', 'Resolved'], true)) {
    $status = '';
}
if ($priority !== '' && !in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true)) {
    $priority = '';
}
if ($category !== '' && !ctype_digit($category)) {
    $category = '';
}
$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if ($from !== '' && !$isValidDate($from)) {
    $dateError = 'Enter a valid start date.';
    $dateRangeInvalid = true;
}
if ($to !== '' && !$isValidDate($to)) {
    $dateError = 'Enter a valid end date.';
    $dateRangeInvalid = true;
}
if ($from !== '' && $to !== '' && $isValidDate($from) && $isValidDate($to) && $from > $to) {
    $dateError = 'The start date must be on or before the end date.';
    $dateRangeInvalid = true;
}

$conditions = [];
$params = [];
$types = '';
if ($role === 'Student') {
    $conditions[] = 'c.user_id = ?';
    $params[] = $userId;
    $types .= 'i';
} elseif ($role === 'Staff Department') {
    $conditions[] = '(a.staff_id = ? OR (a.staff_id IS NULL AND a.department = (SELECT department FROM users WHERE user_id = ?)))';
    $params[] = $userId;
    $params[] = $userId;
    $types .= 'ii';
}
if ($search !== '') {
    $conditions[] = '(c.title LIKE ? OR CAST(c.complaint_id AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($status !== '') {
    $conditions[] = 'c.status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($priority !== '') {
    $conditions[] = 'c.priority = ?';
    $params[] = $priority;
    $types .= 's';
}
if ($category !== '') {
    $conditions[] = 'c.category_id = ?';
    $params[] = (int) $category;
    $types .= 'i';
}
if ($from !== '' && $isValidDate($from)) {
    $conditions[] = 'DATE(c.submitted_at) >= ?';
    $params[] = $from;
    $types .= 's';
}
if ($to !== '' && $isValidDate($to)) {
    $conditions[] = 'DATE(c.submitted_at) <= ?';
    $params[] = $to;
    $types .= 's';
}
if ($dateRangeInvalid) {
    $conditions[] = '1 = 0';
}
if ($department !== '' && in_array($role, ['Administrator', 'MPP Welfare'], true)) {
    $conditions[] = 'a.department = ?';
    $params[] = $department;
    $types .= 's';
}
if ($queue === 'unassigned' && in_array($role, ['Administrator', 'MPP Welfare'], true)) {
    $conditions[] = '(a.assignment_id IS NULL OR a.staff_id IS NULL)';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$priorityOrder = $sortPriority === 'asc'
    ? "FIELD(c.priority,'Low','Medium','High','Critical') ASC, "
    : ($sortPriority === 'desc' ? "FIELD(c.priority,'Low','Medium','High','Critical') DESC, " : '');
$fromSql = " FROM complaints c
    LEFT JOIN complaint_categories cc ON cc.category_id=c.category_id
    LEFT JOIN complaint_assignments a ON a.assignment_id=(SELECT MAX(a2.assignment_id) FROM complaint_assignments a2 WHERE a2.complaint_id=c.complaint_id AND a2.assignment_status<>'Reassigned')
    LEFT JOIN users assignee ON assignee.user_id=a.staff_id";

$countQuery = $db->prepare('SELECT COUNT(DISTINCT c.complaint_id)' . $fromSql . $where);
if ($params) {
    $countBind = [$types];
    foreach ($params as $index => $value) {
        $countBind[] = &$params[$index];
    }
    call_user_func_array([$countQuery, 'bind_param'], $countBind);
}
$countQuery->execute();
$totalComplaints = (int) $countQuery->get_result()->fetch_row()[0];
$perPage = 10;
$totalPages = max(1, (int) ceil($totalComplaints / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$selectSql = "SELECT c.complaint_id,c.title,c.status,c.priority,c.submitted_at,c.is_anonymous,cc.category_name,a.department,assignee.full_name AS assignee_name,
    GREATEST(c.submitted_at,
        COALESCE((SELECT MAX(p.updated_at) FROM complaint_progress p WHERE p.complaint_id=c.complaint_id), c.submitted_at),
        COALESCE((SELECT MAX(a3.assigned_at) FROM complaint_assignments a3 WHERE a3.complaint_id=c.complaint_id), c.submitted_at)
    ) AS last_updated" . $fromSql . $where . ' ORDER BY ' . $priorityOrder . "(c.status='Resolved') ASC, c.submitted_at DESC LIMIT ? OFFSET ?";
$statement = $db->prepare($selectSql);
$selectParams = $params;
$selectTypes = $types . 'ii';
$selectParams[] = $perPage;
$selectParams[] = $offset;
$bind = [$selectTypes];
foreach ($selectParams as $index => $value) {
    $bind[] = &$selectParams[$index];
}
call_user_func_array([$statement, 'bind_param'], $bind);
$statement->execute();
$complaints = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

$queueStats = null;
if ($role === 'MPP Welfare') {
    $queueStats = $db->query("SELECT SUM(a.assignment_id IS NULL OR a.staff_id IS NULL) AS unassigned,SUM(c.priority IN ('High','Critical') AND c.status<>'Resolved') AS urgent FROM complaints c LEFT JOIN complaint_assignments a ON a.assignment_id=(SELECT MAX(a2.assignment_id) FROM complaint_assignments a2 WHERE a2.complaint_id=c.complaint_id AND a2.assignment_status<>'Reassigned')")->fetch_assoc();
}
$staffQueue = null;
if ($role === 'Staff Department') {
    $queueQuery = $db->prepare("SELECT COUNT(DISTINCT c.complaint_id) AS open_count FROM complaints c JOIN complaint_assignments a ON a.complaint_id=c.complaint_id AND a.assignment_status<>'Reassigned' WHERE (a.staff_id=? OR (a.staff_id IS NULL AND a.department=(SELECT department FROM users WHERE user_id=?))) AND c.status<>'Resolved'");
    $queueQuery->bind_param('ii', $userId, $userId);
    $queueQuery->execute();
    $staffQueue = $queueQuery->get_result()->fetch_assoc();
}
$departments = $db->query("SELECT DISTINCT department FROM complaint_assignments WHERE assignment_status<>'Reassigned' ORDER BY department")->fetch_all(MYSQLI_ASSOC);
$categories = $db->query('SELECT category_id, category_name FROM complaint_categories ORDER BY category_name')->fetch_all(MYSQLI_ASSOC);
$pageTitle = $role === 'Student' ? 'My complaints' : ($role === 'Staff Department' ? 'Assigned tasks' : 'Complaint queue');
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading">
    <div><div class="eyebrow"><?= h($role) ?> portal</div><h1><?= h($pageTitle) ?></h1><p class="muted"><?= $role === 'MPP Welfare' ? 'Review reports, set priority and assign the right department.' : ($role === 'Staff Department' ? 'Work on assigned complaints and keep students informed.' : 'Search and review complaint records.') ?></p></div>
    <?php if ($role === 'Student'): ?><a class="button primary" href="/ecms_v1/new_complaint.php">Submit a complaint</a><?php endif; ?>
</section>
<?php show_flash(); ?>
<?php if ($dateError !== ''): ?><div class="notice error"><?= h($dateError) ?></div><?php endif; ?>
<?php if ($role === 'MPP Welfare' && $queueStats): ?>
<section class="stat-grid role-queue-stats" aria-label="Triage queue summary">
    <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php?queue=unassigned"><span class="stat-label">Needs assignment</span><strong><?= (int) ($queueStats['unassigned'] ?? 0) ?></strong><small>Unassigned or awaiting a staff member</small></a>
    <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php?priority=High"><span class="stat-label">High priority / critical</span><strong><?= (int) ($queueStats['urgent'] ?? 0) ?></strong><small>Open complaints needing attention</small></a>
</section>
<?php elseif ($role === 'Staff Department' && $staffQueue): ?>
<section class="stat-grid role-queue-stats" aria-label="My work summary">
    <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php"><span class="stat-label">Open assignments</span><strong><?= (int) ($staffQueue['open_count'] ?? 0) ?></strong><small>Complaints in your department queue</small></a>
</section>
<?php endif; ?>
<section class="content-card report-filters">
    <form method="get" class="complaint-filter-form">
        <label>Search<input name="q" value="<?= h($search) ?>" placeholder="Title or ECMS reference"></label>
        <label>From date<input type="date" name="from" value="<?= h($from) ?>"></label>
        <label>To date<input type="date" name="to" value="<?= h($to) ?>"></label>
        <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $item): ?><option value="<?= (int) $item['category_id'] ?>" <?= $category === (string) $item['category_id'] ? 'selected' : '' ?>><?= h($item['category_name']) ?></option><?php endforeach; ?></select></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['Submitted', 'Under Review', 'Assigned', 'In Progress', 'Resolved'] as $item): ?><option value="<?= h($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
        <label>Priority<select name="priority"><option value="">All priorities</option><?php foreach (['Low', 'Medium', 'High', 'Critical'] as $item): ?><option value="<?= h($item) ?>" <?= $priority === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
        <label>Sort priority<select name="sort_priority"><option value="">Default order</option><option value="desc" <?= $sortPriority === 'desc' ? 'selected' : '' ?>>Descending (Critical to Low)</option><option value="asc" <?= $sortPriority === 'asc' ? 'selected' : '' ?>>Ascending (Low to Critical)</option></select></label>
        <?php if (in_array($role, ['Administrator', 'MPP Welfare'], true)): ?><label>Department<select name="department"><option value="">All departments</option><?php foreach ($departments as $item): ?><option value="<?= h($item['department']) ?>" <?= $department === $item['department'] ? 'selected' : '' ?>><?= h($item['department']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <input type="hidden" name="queue" value="<?= h($queue) ?>">
        <button class="button secondary" type="submit">Apply filters</button>
        <?php if ($search !== '' || $from !== '' || $to !== '' || $category !== '' || $status !== '' || $priority !== '' || $sortPriority !== '' || $department !== '' || $queue !== ''): ?><a class="button secondary" href="/ecms_v1/complaints.php">Clear</a><?php endif; ?>
    </form>
</section>
<section class="content-card table-card">
    <?php if (!$complaints): ?><div class="empty-state"><h2>No complaints found</h2><p class="muted"><?= $role === 'Student' ? 'Your submitted complaints will appear here.' : 'No records match this queue or filter.' ?></p></div>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Reference</th><th>Complaint</th><th>Category</th><?php if ($role !== 'Student'): ?><th>Department / staff</th><?php endif; ?><th>Priority</th><th>Status</th><th>Submitted</th><?php if ($role === 'Student'): ?><th>Last updated</th><?php endif; ?><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
    <?php foreach ($complaints as $complaint): ?>
    <tr><td>ECMS-<?= str_pad((string) $complaint['complaint_id'], 5, '0', STR_PAD_LEFT) ?></td><td><?= h($complaint['title']) ?><?php if ($complaint['is_anonymous'] && $role !== 'Administrator'): ?><small class="table-note">Anonymous student</small><?php endif; ?></td><td><?= h($complaint['category_name'] ?? 'Uncategorised') ?></td>
        <?php if ($role !== 'Student'): ?><td><?= h($complaint['department'] ?: 'Not assigned') ?><?php if ($complaint['assignee_name']): ?><small class="table-note"><?= h($complaint['assignee_name']) ?></small><?php endif; ?></td><?php endif; ?>
        <td><span class="priority-pill <?= h(complaint_priority_class($complaint['priority'])) ?>"><?= h($complaint['priority']) ?></span></td><td><span class="status-pill <?= h(complaint_status_class($complaint['status'])) ?>"><?= h($complaint['status']) ?></span></td>
        <td><?= h(date('d M Y', strtotime($complaint['submitted_at']))) ?></td><?php if ($role === 'Student'): ?><td><?= h(date('d M Y', strtotime($complaint['last_updated']))) ?></td><?php endif; ?><td><a class="button small-button secondary" href="/ecms_v1/complaint_detail.php?id=<?= (int) $complaint['complaint_id'] ?>"><?= $role === 'Student' ? 'View' : 'Open' ?></a></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php if ($totalComplaints > 0): ?>
<nav class="pagination" aria-label="Complaint history pages"><span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalComplaints) ?> of <?= $totalComplaints ?></span><div><?php if ($page > 1): ?><a class="button secondary" href="/ecms_v1/complaints.php?<?= h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><span class="pagination-current">Page <?= $page ?> of <?= $totalPages ?></span><?php if ($page < $totalPages): ?><a class="button secondary" href="/ecms_v1/complaints.php?<?= h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></div></nav>
<?php endif; ?>
<p class="form-foot"><a href="/ecms_v1/dashboard.php">← Back to dashboard</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
