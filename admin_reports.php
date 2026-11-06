<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['Administrator']);

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$department = trim($_GET['department'] ?? '');
$conditions = [];
$params = [];
$types = '';
if ($from !== '') {
    $conditions[] = 'DATE(c.submitted_at) >= ?';
    $params[] = $from;
    $types .= 's';
}
if ($to !== '') {
    $conditions[] = 'DATE(c.submitted_at) <= ?';
    $params[] = $to;
    $types .= 's';
}
if ($category !== '' && ctype_digit($category)) {
    $conditions[] = 'c.category_id = ?';
    $params[] = (int) $category;
    $types .= 'i';
}
if (in_array($status, ['Submitted','Under Review','Assigned','In Progress','Resolved'], true)) {
    $conditions[] = 'c.status = ?';
    $params[] = $status;
    $types .= 's';
}
if (in_array($priority, ['Low','Medium','High','Critical'], true)) {
    $conditions[] = 'c.priority = ?';
    $params[] = $priority;
    $types .= 's';
}
if ($department !== '') {
    $conditions[] = 'a.department = ?';
    $params[] = $department;
    $types .= 's';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT c.complaint_id,c.title,c.status,c.priority,c.submitted_at,cc.category_name,a.department,assignee.full_name AS assignee_name
FROM complaints c
LEFT JOIN complaint_categories cc ON cc.category_id=c.category_id
LEFT JOIN complaint_assignments a ON a.assignment_id=(SELECT MAX(a2.assignment_id) FROM complaint_assignments a2 WHERE a2.complaint_id=c.complaint_id AND a2.assignment_status<>'Reassigned')
LEFT JOIN users assignee ON assignee.user_id=a.staff_id" . $where . " ORDER BY COALESCE(NULLIF(a.department,''),'Not assigned'), FIELD(c.priority,'Critical','High','Medium','Low'), cc.category_name, c.status, c.submitted_at DESC";
$statement = $db->prepare($sql);
if ($params) {
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    } call_user_func_array([$statement, 'bind_param'], $bind);
}
$statement->execute();
$rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$pending = 0;
$inProgress = 0;
$resolved = 0;
$categorySummary = [];
$departmentSummary = [];
$statusSummary = [];
$prioritySummary = [];
foreach ($rows as $row) {
    if (in_array($row['status'], ['Submitted','Under Review','Assigned'], true)) {
        $pending++;
    }
    if ($row['status'] === 'In Progress') {
        $inProgress++;
    }
    if ($row['status'] === 'Resolved') {
        $resolved++;
    }
    $categoryName = $row['category_name'] ?? 'Uncategorised';
    $categorySummary[$categoryName] = ($categorySummary[$categoryName] ?? 0) + 1;
    $departmentName = $row['department'] ?: 'Not assigned';
    $departmentSummary[$departmentName] = ($departmentSummary[$departmentName] ?? 0) + 1;
    $statusSummary[$row['status']] = ($statusSummary[$row['status']] ?? 0) + 1;
    $prioritySummary[$row['priority']] = ($prioritySummary[$row['priority']] ?? 0) + 1;
}
ksort($categorySummary);
ksort($departmentSummary);
$statusOrder = ['Submitted', 'Under Review', 'Assigned', 'In Progress', 'Resolved'];
uksort($statusSummary, static fn($left, $right) => array_search($left, $statusOrder, true) <=> array_search($right, $statusOrder, true));
$priorityOrder = ['Critical', 'High', 'Medium', 'Low'];
uksort($prioritySummary, static fn($left, $right) => array_search($left, $priorityOrder, true) <=> array_search($right, $priorityOrder, true));
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!class_exists(ZipArchive::class)) {
        http_response_code(500);
        exit('Excel export is unavailable because PHP ZipArchive is not enabled.');
    }
    $xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $cell = static function (string $value, string $reference, bool $numeric = false) use ($xml): string {
        return $numeric
            ? '<c r="' . $reference . '"><v>' . (int) $value . '</v></c>'
            : '<c r="' . $reference . '" t="inlineStr"><is><t>' . $xml($value) . '</t></is></c>';
    };
    $makeSheet = static function (array $data) use ($cell): string {
        $rowsXml = '';
        foreach ($data as $rowIndex => $row) {
            $cellsXml = '';
            foreach ($row as $columnIndex => $value) {
                $column = '';
                $number = $columnIndex + 1;
                while ($number > 0) {
                    $remainder = ($number - 1) % 26;
                    $column = chr(65 + $remainder) . $column;
                    $number = intdiv($number - 1, 26);
                }
                $cellsXml .= $cell((string) $value, $column . ($rowIndex + 1), is_int($value));
            }
            $rowsXml .= '<row r="' . ($rowIndex + 1) . '">' . $cellsXml . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rowsXml . '</sheetData></worksheet>';
    };
    $summaryRows = [['ECMS complaint report'], ['Total complaints', count($rows)], ['Pending', $pending], ['In progress', $inProgress], ['Resolved', $resolved], [], ['By department', 'Count']];
    foreach ($departmentSummary as $name => $count) $summaryRows[] = [$name, $count];
    $summaryRows[] = [];
    $summaryRows[] = ['By category', 'Count'];
    foreach ($categorySummary as $name => $count) $summaryRows[] = [$name, $count];
    $summaryRows[] = [];
    $summaryRows[] = ['By priority', 'Count'];
    foreach ($prioritySummary as $name => $count) $summaryRows[] = [$name, $count];
    $summaryRows[] = [];
    $summaryRows[] = ['By status', 'Count'];
    foreach ($statusSummary as $name => $count) $summaryRows[] = [$name, $count];
    $recordRows = [['Reference','Title','Category','Priority','Status','Department','Assigned staff','Submitted']];
    foreach ($rows as $row) {
        $recordRows[] = ['ECMS-' . str_pad((string) $row['complaint_id'], 5, '0', STR_PAD_LEFT), $row['title'], $row['category_name'] ?? 'Uncategorised', $row['priority'], $row['status'], $row['department'] ?: 'Not assigned', $row['assignee_name'] ?? '', $row['submitted_at']];
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'ecms_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        http_response_code(500);
        exit('Could not create the Excel report.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Complaint records" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $makeSheet($summaryRows));
    $zip->addFromString('xl/worksheets/sheet2.xml', $makeSheet($recordRows));
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ecms-complaint-report.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecms-complaint-report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Complaint ID','Title','Category','Priority','Status','Department','Assigned staff','Submitted']);
    foreach ($rows as $row) {
        fputcsv($output, [$row['complaint_id'],$row['title'],$row['category_name'],$row['priority'],$row['status'],$row['department'],$row['assignee_name'],$row['submitted_at']]);
    }
    fclose($output);
    exit;
}
$categories = $db->query('SELECT category_id,category_name FROM complaint_categories WHERE is_active=1 ORDER BY category_name')->fetch_all(MYSQLI_ASSOC);
$departments = $db->query("SELECT DISTINCT department FROM users WHERE role='Staff Department' AND account_status='Active' AND department IS NOT NULL ORDER BY department")->fetch_all(MYSQLI_ASSOC);
$pageTitle = 'Complaint reports';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading"><div><div class="eyebrow">Administrator</div><h1>Complaint reports</h1><p class="muted">Generate and review complaint reports by date, category, status, department, and priority.</p></div><a class="button secondary" href="/ecms_v1/dashboard.php">← Back to dashboard</a></section>
<section class="content-card report-filters"><form method="get" class="report-form">
<label>From<input type="date" name="from" value="<?= h($from) ?>"></label><label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
<label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $item): ?><option value="<?= (int) $item['category_id'] ?>" <?= $category === (string) $item['category_id'] ? 'selected' : '' ?>><?= h($item['category_name']) ?></option><?php endforeach; ?></select></label>
<label>Status<select name="status"><option value="">All statuses</option><?php foreach (['Submitted','Under Review','Assigned','In Progress','Resolved'] as $item): ?><option value="<?= h($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
<label>Priority<select name="priority"><option value="">All priorities</option><?php foreach (['Low','Medium','High','Critical'] as $item): ?><option value="<?= h($item) ?>" <?= $priority === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
<label>Department<select name="department"><option value="">All departments</option><?php foreach ($departments as $item): ?><option value="<?= h($item['department']) ?>" <?= $department === $item['department'] ? 'selected' : '' ?>><?= h($item['department']) ?></option><?php endforeach; ?></select></label>
<button class="button primary" type="submit">Generate report</button><a class="button secondary" href="/ecms_v1/admin_reports.php">Reset filters</a></form></section>
<section class="stat-grid report-stats"><article class="stat-card"><span class="stat-label">Total complaints</span><strong><?= count($rows) ?></strong></article><article class="stat-card"><span class="stat-label">Pending</span><strong><?= $pending ?></strong></article><article class="stat-card"><span class="stat-label">In progress</span><strong><?= $inProgress ?></strong></article><article class="stat-card"><span class="stat-label">Resolved</span><strong><?= $resolved ?></strong></article></section>
<section class="content-card report-summary-grid report-breakdown-grid">
<?php foreach (['Department' => $departmentSummary, 'Category' => $categorySummary, 'Priority' => $prioritySummary, 'Status' => $statusSummary] as $heading => $summary): ?>
<div><h2>By <?= h(strtolower($heading)) ?></h2><?php if (!$summary): ?><p class="muted">No data found.</p><?php else: ?><?php $maxCount = max($summary); ?><ul class="summary-list"><?php foreach ($summary as $name => $count): ?><li><div class="report-chart-label"><span><?= h($name) ?></span><strong><?= (int) $count ?></strong></div><div class="report-chart-track" role="img" aria-label="<?= h($name) ?>: <?= (int) $count ?> complaints"><span style="width: <?= number_format(($count / $maxCount) * 100, 1, '.', '') ?>%"></span></div></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php endforeach; ?>
</section>
<section class="content-card table-card"><div class="report-actions"><h2>Complaint records</h2><div><a class="button secondary" href="<?= h('/ecms_v1/admin_reports.php?' . http_build_query(array_merge($_GET, ['export' => 'xlsx']))) ?>">Export Excel</a> <a class="button secondary" href="<?= h('/ecms_v1/admin_reports.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a> <button class="button secondary" type="button" onclick="window.print()">Print / Save PDF</button></div></div><div class="table-scroll"><table><thead><tr><th>Reference</th><th>Title</th><th>Category</th><th>Department / staff</th><th>Priority</th><th>Status</th><th>Submitted</th></tr></thead><tbody>
<?php $currentDepartment = null; foreach ($rows as $row): $rowDepartment = $row['department'] ?: 'Not assigned'; if ($rowDepartment !== $currentDepartment): $currentDepartment = $rowDepartment; ?><tr class="report-group-row"><th colspan="7">Department: <?= h($currentDepartment) ?> <span>(<?= (int) $departmentSummary[$currentDepartment] ?> complaints)</span></th></tr><?php endif; ?><tr><td>ECMS-<?= str_pad((string) $row['complaint_id'], 5, '0', STR_PAD_LEFT) ?></td><td><a href="/ecms_v1/complaint_detail.php?id=<?= (int) $row['complaint_id'] ?>"><?= h($row['title']) ?></a></td><td><?= h($row['category_name'] ?? 'Uncategorised') ?></td><td><?= h($rowDepartment) ?><?php if ($row['assignee_name']): ?><small class="table-note"><?= h($row['assignee_name']) ?></small><?php endif; ?></td><td><span class="priority-pill <?= h(complaint_priority_class($row['priority'])) ?>"><?= h($row['priority']) ?></span></td><td><span class="status-pill <?= h(complaint_status_class($row['status'])) ?>"><?= h($row['status']) ?></span></td><td><?= h(date('d M Y', strtotime($row['submitted_at']))) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$rows): ?><div class="empty-state"><h2>No data found</h2></div><?php endif; ?></div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
