<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/departments.php';
require_login();

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$complaintId) {
    http_response_code(404);
    exit('Complaint not found.');
}
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$error = '';

$statement = $db->prepare('SELECT c.*, cc.category_name, u.full_name FROM complaints c LEFT JOIN complaint_categories cc ON cc.category_id=c.category_id JOIN users u ON u.user_id=c.user_id WHERE c.complaint_id=?');
$statement->bind_param('i', $complaintId);
$statement->execute();
$complaint = $statement->get_result()->fetch_assoc();
if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found.');
}

$canView = in_array($role, ['Administrator','MPP Welfare'], true) || ($role === 'Student' && (int) $complaint['user_id'] === $userId);
$staffDepartment = '';
if ($role === 'Staff Department') {
    $userQuery = $db->prepare('SELECT department FROM users WHERE user_id=?');
    $userQuery->bind_param('i', $userId);
    $userQuery->execute();
    $staffDepartment = (string) ($userQuery->get_result()->fetch_assoc()['department'] ?? '');
    $assignmentCheck = $db->prepare("SELECT 1 FROM complaint_assignments WHERE complaint_id=? AND (staff_id=? OR department=?) AND assignment_status<>'Reassigned' LIMIT 1");
    $assignmentCheck->bind_param('iis', $complaintId, $userId, $staffDepartment);
    $assignmentCheck->execute();
    $canView = (bool) $assignmentCheck->get_result()->fetch_row();
}
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view this complaint.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'add_evidence') {
        $files = $_FILES['evidence'] ?? null;
        $validFiles = [];
        $allowedTypes = ['image/jpeg','image/png','application/pdf'];
        if (!$files || !isset($files['name']) || !is_array($files['name'])) {
            $error = 'Choose at least one JPG, PNG, or PDF file to upload.';
        } elseif (count($files['name']) > 5) {
            $error = 'You can upload up to 5 files at a time.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($files['name'] as $index => $originalName) {
                $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = 'A file could not be uploaded. Please try again.';
                    break;
                }
                if (($files['size'][$index] ?? 0) > 5 * 1024 * 1024) {
                    $error = 'Each attachment must be 5 MB or smaller.';
                    break;
                }
                $temporaryPath = $files['tmp_name'][$index] ?? '';
                $mimeType = is_uploaded_file($temporaryPath) ? $finfo->file($temporaryPath) : false;
                if (!$mimeType || !in_array($mimeType, $allowedTypes, true)) {
                    $error = 'Attachments must be JPG, PNG, or PDF files.';
                    break;
                }
                $originalName = trim(basename((string) $originalName));
                if ($originalName === '') {
                    $originalName = 'evidence';
                }
                $validFiles[] = ['tmp' => $temporaryPath, 'name' => $originalName, 'mime' => $mimeType];
            }
            if ($error === '' && !$validFiles) {
                $error = 'Choose at least one JPG, PNG, or PDF file to upload.';
            }
        }
        if ($error === '') {
            $savedPaths = [];
            try {
                $uploadDirectory = __DIR__ . '/uploads';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
                    throw new RuntimeException('Could not create the evidence folder.');
                }
                $db->begin_transaction();
                $evidenceInsert = $db->prepare('INSERT INTO supporting_evidence (complaint_id,uploaded_by,file_name,file_type,file_path) VALUES (?,?,?,?,?)');
                foreach ($validFiles as $file) {
                    $extension = $file['mime'] === 'application/pdf' ? '.pdf' : ($file['mime'] === 'image/png' ? '.png' : '.jpg');
                    $storedName = bin2hex(random_bytes(16)) . $extension;
                    $destination = $uploadDirectory . '/' . $storedName;
                    if (!move_uploaded_file($file['tmp'], $destination)) {
                        throw new RuntimeException('Could not save an attachment.');
                    }
                    $savedPaths[] = $destination;
                    $evidenceInsert->bind_param('iisss', $complaintId, $userId, $file['name'], $file['mime'], $storedName);
                    $evidenceInsert->execute();
                }
                if ($role === 'Student') {
                    ecms_notify_roles($db, $complaintId, ['MPP Welfare','Administrator'], 'New supporting evidence', 'A student added supporting evidence to a complaint.');
                }
                $db->commit();
                flash(count($validFiles) . ' evidence file(s) added to this complaint.');
                header('Location: /ecms_v1/complaint_detail.php?id=' . $complaintId);
                exit;
            } catch (Throwable $exception) {
                $db->rollback();
                foreach ($savedPaths as $savedPath) {
                    if (is_file($savedPath)) {
                        unlink($savedPath);
                    }
                }
                $error = 'The evidence could not be saved. Please try again.';
            }
        }
    } elseif (in_array($role, ['Administrator','MPP Welfare'], true) && ($_POST['action'] ?? '') === 'manager_update') {
        $newStatus = $_POST['status'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $priorityReason = trim($_POST['priority_reason'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $department = trim($_POST['department'] ?? '');
        $staffIdText = trim($_POST['staff_id'] ?? '');
        $staffId = $staffIdText === '' ? null : (int) $staffIdText;
        $remarks = trim($_POST['remarks'] ?? '');
        $allowedStatuses = ['Submitted','Under Review','Assigned','In Progress','Resolved'];
        $allowedPriorities = ['Low','Medium','High','Critical'];
        if (!in_array($newStatus, $allowedStatuses, true) || !in_array($priority, $allowedPriorities, true)) {
            $error = 'Choose a valid status and priority.';
        } elseif ($categoryId < 1) {
            $error = 'Choose a complaint category.';
        } elseif (in_array($newStatus, ['Assigned','In Progress'], true) && $department === '') {
            $error = 'Choose a department when assigning a complaint.';
        } elseif ($priority !== $complaint['priority'] && $priorityReason === '') {
            $error = 'Explain why the priority is being changed.';
        } elseif ($department !== '' && !in_array($department, ecms_departments(), true)) {
            $error = 'Choose a department from the list.';
        } elseif ($staffId !== null) {
            $staffCheck = $db->prepare("SELECT user_id FROM users WHERE user_id=? AND role='Staff Department' AND account_status='Active' AND department=?");
            $staffCheck->bind_param('is', $staffId, $department);
            $staffCheck->execute();
            if (!$staffCheck->get_result()->fetch_assoc()) {
                $error = 'The selected staff member must be active and belong to that department.';
            }
        }
        if ($error === '') {
            try {
                $db->begin_transaction();
                $update = $db->prepare('UPDATE complaints SET category_id=?,priority=?,priority_reason=?,status=? WHERE complaint_id=?');
                $update->bind_param('isssi', $categoryId, $priority, $priorityReason, $newStatus, $complaintId);
                $update->execute();
                if ($department !== '') {
                    $oldAssignments = $db->prepare("UPDATE complaint_assignments SET assignment_status='Reassigned' WHERE complaint_id=? AND assignment_status<>'Reassigned'");
                    $oldAssignments->bind_param('i', $complaintId);
                    $oldAssignments->execute();
                    $assignmentStatus = $newStatus === 'Resolved' ? 'Completed' : ($newStatus === 'In Progress' ? 'In Progress' : 'Assigned');
                    $assignment = $db->prepare('INSERT INTO complaint_assignments (complaint_id,staff_id,department,assigned_by,assignment_status) VALUES (?,?,?,?,?)');
                    $assignment->bind_param('iisis', $complaintId, $staffId, $department, $userId, $assignmentStatus);
                    $assignment->execute();
                } else {
                    $assignmentStatus = $newStatus === 'Resolved' ? 'Completed' : ($newStatus === 'In Progress' ? 'In Progress' : 'Assigned');
                    $assignment = $db->prepare("UPDATE complaint_assignments SET assignment_status=? WHERE complaint_id=? AND assignment_status<>'Reassigned'");
                    $assignment->bind_param('si', $assignmentStatus, $complaintId);
                    $assignment->execute();
                }
                if ($newStatus !== 'Submitted' || $remarks !== '') {
                    $progressText = $remarks !== '' ? $remarks : 'Complaint status updated.';
                    $progress = $db->prepare('INSERT INTO complaint_progress (complaint_id,staff_id,status,remarks) VALUES (?,?,?,?)');
                    $progress->bind_param('iiss', $complaintId, $userId, $newStatus, $progressText);
                    $progress->execute();
                }
                if ($remarks !== '') {
                    $response = $db->prepare('INSERT INTO complaint_responses (complaint_id,staff_id,response) VALUES (?,?,?)');
                    $response->bind_param('iis', $complaintId, $userId, $remarks);
                    $response->execute();
                }
                if ($department !== '' && $staffId !== null) {
                    ecms_notify($db, $staffId, $complaintId, 'Complaint assigned to you', 'A complaint has been assigned to your queue.');
                } elseif ($department !== '') {
                    ecms_notify_roles($db, $complaintId, ['Staff Department'], 'New department assignment', 'A complaint has been assigned to your department.', $department);
                }
                ecms_notify($db, (int) $complaint['user_id'], $complaintId, 'Complaint updated', 'Your complaint status is now ' . $newStatus . '.');
                $db->commit();
                flash('Complaint details and status have been updated.');
                header('Location: /ecms_v1/complaint_detail.php?id=' . $complaintId);
                exit;
            } catch (Throwable $exception) {
                $db->rollback();
                $error = 'The update could not be saved. Please check the information and try again.';
            }
        }
    } elseif ($role === 'Staff Department' && ($_POST['action'] ?? '') === 'staff_update') {
        $newStatus = $_POST['status'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');
        if (!in_array($newStatus, ['In Progress','Resolved'], true) || $remarks === '') {
            $error = 'Choose a progress status and enter an update.';
        } else {
            try {
                $db->begin_transaction();
                $update = $db->prepare('UPDATE complaints SET status=? WHERE complaint_id=?');
                $update->bind_param('si', $newStatus, $complaintId);
                $update->execute();
                $progress = $db->prepare('INSERT INTO complaint_progress (complaint_id,staff_id,status,remarks) VALUES (?,?,?,?)');
                $progress->bind_param('iiss', $complaintId, $userId, $newStatus, $remarks);
                $progress->execute();
                $response = $db->prepare('INSERT INTO complaint_responses (complaint_id,staff_id,response) VALUES (?,?,?)');
                $response->bind_param('iis', $complaintId, $userId, $remarks);
                $response->execute();
                $assignmentStatus = $newStatus === 'Resolved' ? 'Completed' : 'In Progress';
                $assignment = $db->prepare("UPDATE complaint_assignments SET assignment_status=? WHERE complaint_id=? AND assignment_status<>'Reassigned' AND (staff_id=? OR department=?)");
                $assignment->bind_param('siis', $assignmentStatus, $complaintId, $userId, $staffDepartment);
                $assignment->execute();
                ecms_notify($db, (int) $complaint['user_id'], $complaintId, 'Complaint progress updated', 'There is a new update to your complaint.');
                $db->commit();
                flash('Your progress update was saved.');
                header('Location: /ecms_v1/complaint_detail.php?id=' . $complaintId);
                exit;
            } catch (Throwable $exception) {
                $db->rollback();
                $error = 'The progress update could not be saved. Please try again.';
            }
        }
    } else {
        $error = 'This action is not available for your account.';
    }
}

$statement = $db->prepare('SELECT c.*,cc.category_name,u.full_name FROM complaints c LEFT JOIN complaint_categories cc ON cc.category_id=c.category_id JOIN users u ON u.user_id=c.user_id WHERE c.complaint_id=?');
$statement->bind_param('i', $complaintId);
$statement->execute();
$complaint = $statement->get_result()->fetch_assoc();
$updates = $db->prepare('SELECT p.status,p.remarks,p.updated_at,u.full_name FROM complaint_progress p JOIN users u ON u.user_id=p.staff_id WHERE p.complaint_id=? ORDER BY p.updated_at DESC');
$updates->bind_param('i', $complaintId);
$updates->execute();
$progress = $updates->get_result()->fetch_all(MYSQLI_ASSOC);
$evidenceQuery = $db->prepare('SELECT evidence_id,file_name,file_type,uploaded_at FROM supporting_evidence WHERE complaint_id=? ORDER BY uploaded_at');
$evidenceQuery->bind_param('i', $complaintId);
$evidenceQuery->execute();
$evidence = $evidenceQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$responseQuery = $db->prepare("SELECT r.response,r.response_at,u.full_name FROM complaint_responses r JOIN users u ON u.user_id=r.staff_id WHERE r.complaint_id=? ORDER BY r.response_at DESC");
$responseQuery->bind_param('i', $complaintId);
$responseQuery->execute();
$responses = $responseQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$categories = $db->query('SELECT category_id,category_name FROM complaint_categories WHERE is_active=1 ORDER BY category_name')->fetch_all(MYSQLI_ASSOC);
$staff = $db->query("SELECT user_id,full_name,department FROM users WHERE role='Staff Department' AND account_status='Active' ORDER BY department,full_name")->fetch_all(MYSQLI_ASSOC);
$currentAssignmentQuery = $db->prepare("SELECT department,staff_id FROM complaint_assignments WHERE complaint_id=? AND assignment_status<>'Reassigned' ORDER BY assigned_at DESC LIMIT 1");
$currentAssignmentQuery->bind_param('i', $complaintId);
$currentAssignmentQuery->execute();
$currentAssignment = $currentAssignmentQuery->get_result()->fetch_assoc() ?: ['department' => '','staff_id' => ''];
$assigneeName = '';
if (!empty($currentAssignment['staff_id'])) {
    $assigneeQuery = $db->prepare('SELECT full_name FROM users WHERE user_id=?');
    $assigneeQuery->bind_param('i', $currentAssignment['staff_id']);
    $assigneeQuery->execute();
    $assigneeName = (string) ($assigneeQuery->get_result()->fetch_assoc()['full_name'] ?? '');
}
$showIdentity = !$complaint['is_anonymous'] || $role === 'Administrator' || ($role === 'Student' && (int) $complaint['user_id'] === $userId);
$pageTitle = 'Complaint details';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading"><div><div class="eyebrow">Complaint details</div><h1>ECMS-<?= str_pad((string) $complaintId, 5, '0', STR_PAD_LEFT) ?></h1></div><a class="button secondary" href="/ecms_v1/complaints.php">← Back to complaints</a></section>
<?php show_flash(); ?>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<div class="detail-grid">
    <section class="content-card">
        <div class="detail-title"><h2><?= h($complaint['title']) ?></h2><span class="status-pill <?= h(complaint_status_class($complaint['status'])) ?>"><?= h($complaint['status']) ?></span></div>
        <dl class="detail-list">
            <div><dt>Category</dt><dd><?= h($complaint['category_name'] ?? 'Uncategorised') ?></dd></div>
            <div><dt>Priority</dt><dd><span class="priority-pill <?= h(complaint_priority_class($complaint['priority'])) ?>"><?= h($complaint['priority']) ?></span></dd></div>
            <div><dt>Location</dt><dd><?= h($complaint['location'] ?: 'Not provided') ?></dd></div>
            <div><dt>Submitted</dt><dd><?= h(date('d M Y, g:i a', strtotime($complaint['submitted_at']))) ?></dd></div>
            <div><dt>Submitted by</dt><dd><?= $showIdentity ? h($complaint['full_name']) : 'Anonymous student' ?></dd></div>
            <div><dt>Assigned department</dt><dd><?= h($currentAssignment['department'] ?: 'Not assigned yet') ?></dd></div>
            <?php if ($role !== 'Student'): ?><div><dt>Assigned staff</dt><dd><?= h($assigneeName ?: 'Department queue') ?></dd></div><?php endif; ?>
            <?php if ($role !== 'Student' && !empty($complaint['priority_reason'])): ?><div><dt>Priority reason</dt><dd><?= h($complaint['priority_reason']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($complaint['status'] !== 'Resolved'): ?><div class="notice notice-info"><strong>What happens next</strong><br><?= $currentAssignment['department'] ? 'The assigned team will review the complaint and post progress updates here.' : 'MPP Welfare will review the complaint and assign it to a suitable department.' ?></div><?php endif; ?>
        <h3>Description</h3><p class="preserve-lines"><?= h($complaint['description']) ?></p>
        <h3>Supporting evidence</h3>
        <?php if ($evidence): ?><ul class="file-list"><?php foreach ($evidence as $file): $previewUrl = '/ecms_v1/download_evidence.php?id='.(int)$file['evidence_id'].'&preview=1'; ?><li class="evidence-item"><div class="evidence-file-row"><span><strong><?= h($file['file_name']) ?></strong> <span class="muted">· <?= h($file['file_type']) ?></span></span><div class="evidence-actions"><a href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Open preview</a> · <a href="/ecms_v1/download_evidence.php?id=<?= (int)$file['evidence_id'] ?>">Download</a></div></div><?php if (in_array($file['file_type'], ['image/jpeg','image/png'], true)): ?><a href="<?= h($previewUrl) ?>" target="_blank" rel="noopener"><img class="evidence-image-preview" src="<?= h($previewUrl) ?>" alt="Preview of <?= h($file['file_name']) ?>" loading="lazy"></a><?php elseif ($file['file_type'] === 'application/pdf'): ?><details class="evidence-pdf-details"><summary>Preview PDF here</summary><iframe class="evidence-pdf-preview" src="<?= h($previewUrl) ?>" title="Preview of <?= h($file['file_name']) ?>" loading="lazy"></iframe></details><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">No evidence files have been added to this complaint yet.</p><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form-stack evidence-upload-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_evidence"><label>Add evidence to this complaint<span class="muted">JPG, PNG, or PDF · up to 5 MB per file · maximum 5 files at a time</span><input type="file" name="evidence[]" accept="image/jpeg,image/png,application/pdf" multiple required></label><button class="button secondary" type="submit">Upload evidence</button></form>
        <?php if ($responses): ?><h3>Responses and updates</h3><?php foreach ($responses as $response): ?><article class="update-item"><div class="message-heading"><strong><?= h($response['full_name']) ?></strong></div><small><?= h(date('d M Y, g:i a', strtotime($response['response_at']))) ?></small><p class="preserve-lines"><?= h($response['response']) ?></p></article><?php endforeach; ?><?php endif; ?>
    </section>
    <aside class="content-card"><h2>Progress history</h2><?php if (!$progress): ?><p class="muted">No progress updates have been added yet.</p><?php else: ?><div class="timeline"><?php foreach ($progress as $item): ?><article class="timeline-item"><span class="timeline-dot" aria-hidden="true"></span><div><strong><span class="status-pill <?= h(complaint_status_class($item['status'])) ?>"><?= h($item['status']) ?></span></strong><small><?= h(date('d M Y, g:i a', strtotime($item['updated_at']))) ?> · <?= h($item['full_name']) ?></small><p class="preserve-lines"><?= h($item['remarks']) ?></p></div></article><?php endforeach; ?></div><?php endif; ?><?php if ($role !== 'Student' && $activity): ?><h3>Activity record</h3><div class="activity-list"><?php foreach ($activity as $event): ?><article class="activity-item"><strong><?= h($event['actor_name']) ?></strong><small><?= h(date('d M Y, g:i a', strtotime($event['created_at']))) ?></small><p><?= h($event['description']) ?></p></article><?php endforeach; ?></div><?php endif; ?></aside>
</div>
<?php if (in_array($role, ['Administrator','MPP Welfare'], true)): ?>
<section class="content-card workflow-card"><div class="eyebrow">MPP / administrator</div><h2>Review and assign</h2><p class="muted">Update the complaint category, priority and status. Add a department when assigning work.</p>
<form method="post" class="form-stack workflow-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="manager_update"><div class="workflow-fields">
<label>Category<select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= (int)$complaint['category_id'] === (int)$category['category_id'] ? 'selected' : '' ?>><?= h($category['category_name']) ?></option><?php endforeach; ?></select></label>
<label>Priority<select name="priority"><?php foreach (['Low','Medium','High','Critical'] as $value): ?><option <?= $complaint['priority'] === $value ? 'selected' : '' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
<label>Priority reason<input type="text" name="priority_reason" maxlength="500" value="<?= h($complaint['priority_reason'] ?? '') ?>" placeholder="Required if changing the priority"><small class="table-note">Explain why the priority is being changed.</small></label>
<label>Status<select name="status"><?php foreach (['Submitted','Under Review','Assigned','In Progress','Resolved'] as $value): ?><option <?= $complaint['status'] === $value ? 'selected' : '' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
<label>Department<select name="department" id="assignment-department"><option value="">Choose a department</option><?php foreach (ecms_departments() as $departmentOption): ?><option value="<?= h($departmentOption) ?>" <?= $currentAssignment['department'] === $departmentOption ? 'selected' : '' ?>><?= h($departmentOption) ?></option><?php endforeach; ?></select></label>
<label>Assign to staff (optional)<select name="staff_id" id="assignment-staff"><option value="">Department queue (no staff selected)</option><?php foreach ($staff as $member): ?><option value="<?= (int)$member['user_id'] ?>" data-department="<?= h($member['department'] ?? '') ?>" <?= (int)$currentAssignment['staff_id'] === (int)$member['user_id'] ? 'selected' : '' ?>><?= h($member['full_name']) ?></option><?php endforeach; ?></select><small class="table-note">Choose a department first; then select a staff member by name.</small></label>
</div><label>Update note / response<textarea name="remarks" rows="3" placeholder="Tell the student what happens next (optional)"></textarea></label><button class="button primary" type="submit">Save review and assignment</button></form></section>
<?php elseif ($role === 'Staff Department'): ?>
<section class="content-card workflow-card"><div class="eyebrow">Department staff</div><h2>Update progress</h2><p class="muted">Record an investigation update for this assigned complaint.</p><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="staff_update"><label>New status<select name="status"><option value="In Progress">In Progress</option><option>Resolved</option></select></label><label>Progress details / response<textarea name="remarks" rows="4" required placeholder="Describe the work completed or next action"></textarea></label><button class="button primary" type="submit">Save progress update</button></form></section>
<?php endif; ?>
<script>
const assignmentDepartment = document.getElementById('assignment-department');
const assignmentStaff = document.getElementById('assignment-staff');
if (assignmentDepartment && assignmentStaff) {
    const staffOptions = Array.from(assignmentStaff.options).slice(1);
    function filterStaffByDepartment() {
        const selectedDepartment = assignmentDepartment.value;
        staffOptions.forEach(option => {
            const matches = selectedDepartment !== '' && option.dataset.department === selectedDepartment;
            option.hidden = !matches;
            option.disabled = !matches;
            if (!matches && option.selected) assignmentStaff.value = '';
        });
    }
    assignmentDepartment.addEventListener('change', filterStaffByDepartment);
    filterStaffByDepartment();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
