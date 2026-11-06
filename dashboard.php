<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$roleSummary = [];
if ($role === 'Administrator') {
    $roleSummary = $db->query('SELECT COUNT(*) AS user_count FROM users')->fetch_assoc() ?: [];
} elseif ($role === 'Student') {
    $summary = $db->prepare("SELECT SUM(status<>'Resolved') AS open_count FROM complaints WHERE user_id=?");
    $summary->bind_param('i', $userId);
    $summary->execute();
    $roleSummary = $summary->get_result()->fetch_assoc() ?: [];
} elseif ($role === 'MPP Welfare') {
    $roleSummary = $db->query("SELECT SUM(a.assignment_id IS NULL OR a.staff_id IS NULL) AS unassigned,SUM(c.priority IN ('High','Critical') AND c.status<>'Resolved') AS urgent FROM complaints c LEFT JOIN complaint_assignments a ON a.assignment_id=(SELECT MAX(a2.assignment_id) FROM complaint_assignments a2 WHERE a2.complaint_id=c.complaint_id AND a2.assignment_status<>'Reassigned')")->fetch_assoc() ?: [];
} elseif ($role === 'Staff Department') {
    $summary = $db->prepare("SELECT COUNT(DISTINCT c.complaint_id) AS open_count FROM complaints c JOIN complaint_assignments a ON a.complaint_id=c.complaint_id AND a.assignment_status<>'Reassigned' WHERE (a.staff_id=? OR (a.staff_id IS NULL AND a.department=(SELECT department FROM users WHERE user_id=?))) AND c.status<>'Resolved'");
    $summary->bind_param('ii', $userId, $userId);
    $summary->execute();
    $roleSummary = $summary->get_result()->fetch_assoc() ?: [];
}
$dataSql = 'SELECT DISTINCT c.complaint_id, c.status, c.submitted_at, COALESCE(cc.category_name, \'Uncategorised\') AS category_name
    FROM complaints c
    LEFT JOIN complaint_categories cc ON cc.category_id = c.category_id';
$statement = $db->prepare($dataSql . ' ORDER BY c.submitted_at');
$statement->execute();
$complaints = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

$statusOrder = ['Submitted', 'Under Review', 'Assigned', 'In Progress', 'Resolved'];
$statusCounts = array_fill_keys($statusOrder, 0);
$categoryCounts = [];
$total = count($complaints);
$monthlyLabels = [];
$monthlyCounts = [];
$month = new DateTimeImmutable('first day of this month');
for ($offset = 5; $offset >= 0; $offset--) {
    $monthDate = $month->modify('-' . $offset . ' months');
    $monthKey = $monthDate->format('Y-m');
    $monthlyLabels[$monthKey] = $monthDate->format('M Y');
    $monthlyCounts[$monthKey] = 0;
}

foreach ($complaints as $item) {
    $status = $item['status'];
    if (!array_key_exists($status, $statusCounts)) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;
    $category = $item['category_name'];
    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
    $monthKey = date('Y-m', strtotime($item['submitted_at']));
    if (array_key_exists($monthKey, $monthlyCounts)) {
        $monthlyCounts[$monthKey]++;
    }
}
$categoryTotal = array_sum($categoryCounts);
$pending = $statusCounts['Submitted'] + $statusCounts['Under Review'] + $statusCounts['Assigned'];
$inProgress = $statusCounts['In Progress'];
$resolved = $statusCounts['Resolved'];
$palette = ['#175cd3', '#17a673', '#f59e0b', '#8b5cf6', '#ef5b73', '#0891b2', '#64748b', '#d946ef'];
$pieSegments = [];
$pieLegend = [];
$position = 0.0;
$categoryIndex = 0;
foreach ($categoryCounts as $category => $count) {
    $share = $categoryTotal > 0 ? ($count / $categoryTotal) * 100 : 0;
    $end = $position + $share;
    $color = $palette[$categoryIndex % count($palette)];
    $pieSegments[] = $color . ' ' . number_format($position, 3, '.', '') . '% ' . number_format($end, 3, '.', '') . '%';
    $pieLegend[] = ['name' => $category, 'count' => $count, 'color' => $color, 'share' => $share];
    $position = $end;
    $categoryIndex++;
}
$pieBackground = $pieSegments ? 'conic-gradient(' . implode(', ', $pieSegments) . ')' : 'conic-gradient(#e2e8f0 0% 100%)';
$statusColors = ['Submitted' => '#64748b', 'Under Review' => '#f59e0b', 'Assigned' => '#175cd3', 'In Progress' => '#8b5cf6', 'Resolved' => '#17a673'];
$maxMonthly = max(1, ...array_values($monthlyCounts));
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading">
    <div>
        <div class="eyebrow"><?= h($role) ?> portal</div>
        <h1>Hello, <?= h($_SESSION['full_name']) ?></h1>
        <p class="muted">Statistics across all complaints in ECMS.</p>
    </div>
</section>

<section class="stat-grid role-queue-stats" aria-label="Role-specific actions">
    <?php if ($role === 'Administrator'): ?>
        <a class="stat-card stat-card-link" href="/ecms_v1/admin_users.php"><span class="stat-label">Total users</span><strong><?= (int) ($roleSummary['user_count'] ?? 0) ?></strong><small>Manage user accounts and access</small></a>
    <?php elseif ($role === 'Student'): ?>
        <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php"><span class="stat-label">Open complaints</span><strong><?= (int) ($roleSummary['open_count'] ?? 0) ?></strong><small>View your complaint updates</small></a>
    <?php elseif ($role === 'MPP Welfare'): ?>
        <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php?queue=unassigned"><span class="stat-label">Needs assignment</span><strong><?= (int) ($roleSummary['unassigned'] ?? 0) ?></strong><small>Open the triage queue</small></a>
        <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php?priority=High"><span class="stat-label">High / critical</span><strong><?= (int) ($roleSummary['urgent'] ?? 0) ?></strong><small>Open urgent complaints</small></a>
    <?php elseif ($role === 'Staff Department'): ?>
        <a class="stat-card stat-card-link" href="/ecms_v1/complaints.php"><span class="stat-label">Open assignments</span><strong><?= (int) ($roleSummary['open_count'] ?? 0) ?></strong><small>View your department queue</small></a>
    <?php endif; ?>
</section>

<section class="stat-grid dashboard-stat-grid" aria-label="Complaint summary">
    <article class="stat-card">
        <span class="stat-label">Total complaints</span>
        <strong><?= $total ?></strong>
        <small>Across all users</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Awaiting action</span>
        <strong><?= $pending ?></strong>
        <small>Submitted, under review or assigned</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">In progress</span>
        <strong><?= $inProgress ?></strong>
        <small>Currently being handled</small>
    </article>
    <article class="stat-card">
        <span class="stat-label">Resolved</span>
        <strong><?= $resolved ?></strong>
        <small>Marked as resolved</small>
    </article>
</section>

<section class="dashboard-charts" aria-label="Complaint charts">
    <article class="content-card chart-card">
        <div class="chart-heading">
            <div><div class="eyebrow">Breakdown</div><h2>Complaints by status</h2></div>
            <span class="chart-total"><?= $total ?> total</span>
        </div>
        <?php if ($total === 0): ?>
            <p class="chart-empty">No complaints are available to show yet.</p>
        <?php else: ?>
            <div class="status-chart">
                <?php foreach ($statusCounts as $status => $count): ?>
                    <?php $percent = $total > 0 ? ($count / $total) * 100 : 0; ?>
                    <div class="status-chart-row">
                        <div class="status-chart-label"><span><?= h($status) ?></span><strong><?= $count ?></strong></div>
                        <div class="chart-track" role="img" aria-label="<?= h($status) ?>: <?= $count ?> complaints">
                            <span class="chart-bar-fill" style="width: <?= number_format($percent, 2, '.', '') ?>%; background: <?= h($statusColors[$status] ?? '#64748b') ?>"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="content-card chart-card">
        <div class="chart-heading">
            <div><div class="eyebrow">Categories</div><h2>Complaint categories</h2></div>
        </div>
        <?php if ($categoryTotal === 0): ?>
            <p class="chart-empty">Category statistics will appear after a complaint is submitted.</p>
        <?php else: ?>
            <div class="pie-chart-layout">
                <div class="pie-chart" role="img" aria-label="Pie chart showing <?= $categoryTotal ?> complaints by category" style="background: <?= h($pieBackground) ?>">
                    <span><strong><?= $categoryTotal ?></strong><small>complaints</small></span>
                </div>
                <ul class="pie-legend">
                    <?php foreach ($pieLegend as $item): ?>
                        <li><span class="legend-dot" style="background: <?= h($item['color']) ?>"></span><span class="legend-name"><?= h($item['name']) ?></span><strong><?= $item['count'] ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </article>

    <article class="content-card chart-card monthly-chart-card">
        <div class="chart-heading">
            <div><div class="eyebrow">Six-month trend</div><h2>Complaints submitted by month</h2></div>
        </div>
        <div class="monthly-chart" role="img" aria-label="Bar chart of complaints submitted during the last six months">
            <?php foreach ($monthlyCounts as $monthKey => $count): ?>
                <?php $height = $count > 0 ? max(8, ($count / $maxMonthly) * 100) : 0; ?>
                <div class="monthly-bar-column" title="<?= h($monthlyLabels[$monthKey]) ?>: <?= $count ?> complaints">
                    <strong><?= $count ?></strong>
                    <div class="monthly-bar-track"><span style="height: <?= number_format($height, 2, '.', '') ?>%"></span></div>
                    <small><?= h($monthlyLabels[$monthKey]) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
