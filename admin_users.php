<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/departments.php';
require_role(['Administrator']);

$error = '';
$allowedRoles = ['Student', 'MPP Welfare', 'Staff Department', 'Administrator'];
$accountStatuses = ['Pending', 'Active', 'Deactivated'];
$departmentOptions = ecms_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'update_status';
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if (in_array($action, ['create_user', 'edit_user'], true)) {
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? '';
            $studentId = trim($_POST['student_id'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a name and valid email address.';
            } elseif (!in_array($role, $allowedRoles, true)) {
                $error = 'Choose a valid account role.';
            } elseif ($role === 'Student' && $studentId === '') {
                $error = 'Enter the student ID for this student account.';
            } elseif ($role === 'Staff Department' && !in_array($department, $departmentOptions, true)) {
                $error = 'Choose a valid department for the staff account.';
            } elseif ($action === 'create_user' && strlen($password) < 8) {
                $error = 'Use a password with at least 8 characters.';
            } elseif ($action === 'edit_user' && $password !== '' && strlen($password) < 8) {
                $error = 'A new password must contain at least 8 characters.';
            } else {
                $studentIdValue = $role === 'Student' && $studentId !== '' ? $studentId : null;
                $departmentValue = $role === 'Staff Department' ? $department : null;
                $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;

                try {
                    if ($action === 'create_user') {
                        $status = 'Pending';
                        $insert = $db->prepare('INSERT INTO users (full_name, student_id, email, password_hash, role, department, account_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $insert->bind_param('sssssss', $name, $studentIdValue, $email, $passwordHash, $role, $departmentValue, $status);
                        $insert->execute();
                        flash('User account created as Pending. Activate it from the user list when ready.');
                    } else {
                        $currentQuery = $db->prepare('SELECT role, department, account_status FROM users WHERE user_id=? LIMIT 1');
                        $currentQuery->bind_param('i', $targetId);
                        $currentQuery->execute();
                        $currentUser = $currentQuery->get_result()->fetch_assoc();
                        if (!$currentUser) {
                            throw new RuntimeException('The selected account was not found.');
                        }
                        if ($targetId === (int) $_SESSION['user_id'] && $role !== 'Administrator') {
                            throw new RuntimeException('You cannot change your own administrator role.');
                        }
                        if ($currentUser['role'] === 'Administrator' && $currentUser['account_status'] === 'Active' && $role !== 'Administrator') {
                            $activeAdmins = (int) $db->query("SELECT COUNT(*) AS total FROM users WHERE role='Administrator' AND account_status='Active'")->fetch_assoc()['total'];
                            if ($activeAdmins <= 1) {
                                throw new RuntimeException('Keep at least one active administrator account in the system.');
                            }
                        }
                        if ($currentUser['role'] === 'Student' && $role !== 'Student') {
                            $complaintCheck = $db->prepare('SELECT COUNT(*) AS total FROM complaints WHERE user_id=?');
                            $complaintCheck->bind_param('i', $targetId);
                            $complaintCheck->execute();
                            if ((int) $complaintCheck->get_result()->fetch_assoc()['total'] > 0) {
                                throw new RuntimeException('This student has complaint history. Keep the Student role to preserve access to those records.');
                            }
                        }

                        $db->begin_transaction();
                        $staffAssignmentChanged = $currentUser['role'] === 'Staff Department'
                            && ($role !== 'Staff Department' || $departmentValue !== $currentUser['department']);
                        if ($staffAssignmentChanged) {
                            $assigned = $db->prepare("SELECT complaint_id FROM complaint_assignments WHERE staff_id=? AND assignment_status<>'Reassigned'");
                            $assigned->bind_param('i', $targetId);
                            $assigned->execute();
                            $complaintIds = array_column($assigned->get_result()->fetch_all(MYSQLI_ASSOC), 'complaint_id');
                            if ($complaintIds) {
                                $release = $db->prepare("UPDATE complaint_assignments SET assignment_status='Reassigned', staff_id=NULL WHERE staff_id=? AND assignment_status<>'Reassigned'");
                                $release->bind_param('i', $targetId);
                                $release->execute();
                                foreach ($complaintIds as $complaintId) {
                                    ecms_notify_roles($db, (int) $complaintId, ['MPP Welfare', 'Administrator'], 'Complaint needs reassignment', 'A staff role or department changed while this complaint was assigned. Please route it to the correct department.');
                                }
                            }
                        }
                        $update = $db->prepare('UPDATE users SET full_name=?, email=?, role=?, student_id=?, department=?, password_hash=COALESCE(?, password_hash) WHERE user_id=?');
                        $update->bind_param('ssssssi', $name, $email, $role, $studentIdValue, $departmentValue, $passwordHash, $targetId);
                        $update->execute();
                        $db->commit();
                        flash('User details updated.');
                    }
                    header('Location: /ecms_v1/admin_users.php');
                    exit;
                } catch (Throwable $exception) {
                    try {
                        $db->rollback();
                    } catch (Throwable $ignored) {
                    }
                    if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
                        $error = str_contains($exception->getMessage(), 'uq_users_department')
                            ? 'That department is already assigned to another staff account.'
                            : 'That email or student ID is already in use.';
                    } else {
                        $error = $exception instanceof RuntimeException
                            ? $exception->getMessage()
                            : 'The account could not be saved. Please check the details and try again.';
                    }
                }
            }
        } else {
            $newStatus = $_POST['account_status'] ?? '';
            if ($targetId === (int) $_SESSION['user_id']) {
                $error = 'You cannot deactivate your own administrator account.';
            } elseif (!in_array($newStatus, $accountStatuses, true)) {
                $error = 'Choose a valid account status.';
            } else {
                $target = $db->prepare('SELECT role, full_name FROM users WHERE user_id=?');
                $target->bind_param('i', $targetId);
                $target->execute();
                $targetInfo = $target->get_result()->fetch_assoc();
                if (!$targetInfo) {
                    $error = 'The selected account was not found.';
                } elseif ($targetInfo['role'] === 'Administrator' && $newStatus !== 'Active') {
                    $activeAdmins = (int) $db->query("SELECT COUNT(*) AS total FROM users WHERE role='Administrator' AND account_status='Active'")->fetch_assoc()['total'];
                    if ($activeAdmins <= 1) {
                        $error = 'Keep at least one active administrator account in the system.';
                    }
                }
                if ($error === '') {
                    try {
                        $db->begin_transaction();
                        if ($targetInfo['role'] === 'Staff Department' && $newStatus === 'Deactivated') {
                            $assigned = $db->prepare("SELECT complaint_id FROM complaint_assignments WHERE staff_id=? AND assignment_status<>'Reassigned'");
                            $assigned->bind_param('i', $targetId);
                            $assigned->execute();
                            $complaintIds = array_column($assigned->get_result()->fetch_all(MYSQLI_ASSOC), 'complaint_id');
                            $release = $db->prepare("UPDATE complaint_assignments SET assignment_status='Reassigned', staff_id=NULL WHERE staff_id=? AND assignment_status<>'Reassigned'");
                            $release->bind_param('i', $targetId);
                            $release->execute();
                            foreach ($complaintIds as $complaintId) {
                                ecms_notify_roles($db, (int) $complaintId, ['MPP Welfare', 'Administrator'], 'Complaint needs reassignment', 'A staff account was deactivated while assigned to this complaint. Please select a new staff member.');
                            }
                        }
                        $update = $db->prepare('UPDATE users SET account_status=? WHERE user_id=?');
                        $update->bind_param('si', $newStatus, $targetId);
                        $update->execute();
                        $db->commit();
                        flash($targetInfo['role'] === 'Staff Department' && $newStatus === 'Deactivated'
                            ? 'Account deactivated and assigned complaints returned for reassignment.'
                            : 'Account status updated.');
                        header('Location: /ecms_v1/admin_users.php');
                        exit;
                    } catch (Throwable $exception) {
                        $db->rollback();
                        $error = 'The account status could not be updated. Please try again.';
                    }
                }
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';
$query = $db->prepare("SELECT user_id, full_name, student_id, email, role, department, account_status, created_at FROM users WHERE full_name LIKE ? OR email LIKE ? OR role LIKE ? ORDER BY FIELD(account_status, 'Pending', 'Active', 'Deactivated'), created_at DESC");
$query->bind_param('sss', $like, $like, $like);
$query->execute();
$users = $query->get_result()->fetch_all(MYSQLI_ASSOC);
$usedDepartmentsResult = $db->query("SELECT department FROM users WHERE role='Staff Department' AND department IS NOT NULL");
$usedDepartments = array_column($usedDepartmentsResult->fetch_all(MYSQLI_ASSOC), 'department');
$departmentOptions = ecms_departments();
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: ((($_POST['action'] ?? '') === 'edit_user') ? (int) ($_POST['user_id'] ?? 0) : 0);
$createFormValues = ($_POST['action'] ?? '') === 'create_user';
$editUser = null;
if ($editId > 0) {
    $editQuery = $db->prepare('SELECT user_id, full_name, student_id, email, role, department FROM users WHERE user_id=? LIMIT 1');
    $editQuery->bind_param('i', $editId);
    $editQuery->execute();
    $editUser = $editQuery->get_result()->fetch_assoc();
}
$totalUsers = (int) $db->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'];
$pageTitle = 'Manage users';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-heading"><div><div class="eyebrow">Administrator</div><h1>Manage users</h1><p class="muted">Add accounts, update user details, and manage access.</p></div><a class="button secondary" href="/ecms_v1/dashboard.php">← Back to dashboard</a></section>
<?php show_flash(); ?><?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="content-card user-count-card"><div><span class="stat-label">Total users</span><strong><?= $totalUsers ?></strong><small>Student, MPP Welfare, staff and administrator accounts</small></div></section>
<section class="content-card admin-user-create"><h2>Add a user</h2><p class="muted">New accounts start as Pending. Activate them from the user list when ready.</p>
<form method="post" class="admin-user-form user-editor"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_user">
<label>Full name<input name="full_name" required maxlength="120" value="<?= h($createFormValues ? ($_POST['full_name'] ?? '') : '') ?>"></label>
<label>Email address<input type="email" name="email" required maxlength="190" value="<?= h($createFormValues ? ($_POST['email'] ?? '') : '') ?>"></label>
<label>Role<select name="role" data-user-role required><option value="">Choose a role</option><?php foreach ($allowedRoles as $roleOption): ?><option value="<?= h($roleOption) ?>" <?= ($_POST['action'] ?? '') === 'create_user' && ($_POST['role'] ?? '') === $roleOption ? 'selected' : '' ?>><?= h($roleOption) ?></option><?php endforeach; ?></select></label>
<label data-student-field>Student ID<input name="student_id" maxlength="40" value="<?= h($createFormValues ? ($_POST['student_id'] ?? '') : '') ?>"></label>
<label data-department-field>Department<select name="department"><option value="">Choose a department</option><?php foreach ($departmentOptions as $departmentOption): ?><option value="<?= h($departmentOption) ?>" <?= ($_POST['action'] ?? '') === 'create_user' && ($_POST['department'] ?? '') === $departmentOption ? 'selected' : '' ?>><?= h($departmentOption) ?></option><?php endforeach; ?></select></label>
<label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
<div class="admin-user-form-action"><button class="button primary" type="submit">Create user account</button></div></form></section>
<?php if ($editUser): ?>
<section class="content-card admin-user-create" id="edit-user"><div class="edit-user-heading"><div><h2>Edit user</h2><p class="muted">Leave the password field blank to keep the current password.</p></div><a class="button secondary" href="/ecms_v1/admin_users.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>">Cancel</a></div>
<form method="post" class="admin-user-form user-editor"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>">
<label>Full name<input name="full_name" required maxlength="120" value="<?= h($editUser['full_name']) ?>"></label>
<label>Email address<input type="email" name="email" required maxlength="190" value="<?= h($editUser['email']) ?>"></label>
<label>Role<select name="role" data-user-role required><?php foreach ($allowedRoles as $roleOption): ?><option value="<?= h($roleOption) ?>" <?= $editUser['role'] === $roleOption ? 'selected' : '' ?>><?= h($roleOption) ?></option><?php endforeach; ?></select></label>
<label data-student-field>Student ID<input name="student_id" maxlength="40" value="<?= h($editUser['student_id'] ?? '') ?>"></label>
<label data-department-field>Department<select name="department"><option value="">Choose a department</option><?php foreach ($departmentOptions as $departmentOption): ?><option value="<?= h($departmentOption) ?>" <?= ($editUser['department'] ?? '') === $departmentOption ? 'selected' : '' ?>><?= h($departmentOption) ?></option><?php endforeach; ?></select></label>
<label>New password<input type="password" name="password" minlength="8" autocomplete="new-password"></label>
<div class="admin-user-form-action"><button class="button primary" type="submit">Save user details</button></div></form></section>
<?php endif; ?>
<form class="search-form" method="get"><input name="q" value="<?= h($search) ?>" placeholder="Search name, email, or role"><button class="button secondary" type="submit">Search</button></form>
<section class="content-card table-card"><div class="table-scroll"><table><thead><tr><th>Name / ID</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Registered</th><th>Update access</th><th>Edit</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= h($user['full_name']) ?><small class="table-note"><?= h($user['student_id'] ?? '') ?></small></td><td><?= h($user['email']) ?></td><td><?= h($user['role']) ?></td><td><?= h($user['department'] ?: '—') ?></td><td><span class="status-pill account-<?= h(strtolower($user['account_status'])) ?>"><?= h($user['account_status']) ?></span></td><td><?= h(date('d M Y', strtotime($user['created_at']))) ?></td><td><?php if ((int) $user['user_id'] === (int) $_SESSION['user_id']): ?><span class="muted">Current account</span><?php else: ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>"><select name="account_status"><?php foreach ($accountStatuses as $status): ?><option <?= $user['account_status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select><button class="text-button" type="submit">Save</button></form><?php endif; ?></td><td><a class="button small-button secondary" href="/ecms_v1/admin_users.php?<?= h(http_build_query(array_filter(['q' => $search, 'edit' => (int) $user['user_id']], static fn($value) => $value !== ''))) ?>#edit-user">Edit</a></td></tr><?php endforeach; ?>
</tbody></table><?php if (!$users): ?><div class="empty-state"><h2>No users found</h2></div><?php endif; ?></div></section>
<script>
document.querySelectorAll('.user-editor').forEach(form => {
    const role = form.querySelector('[data-user-role]');
    const studentField = form.querySelector('[data-student-field]');
    const departmentField = form.querySelector('[data-department-field]');
    const studentInput = studentField.querySelector('input');
    const departmentSelect = departmentField.querySelector('select');
    function updateUserFields() {
        const isStudent = role.value === 'Student';
        const isStaff = role.value === 'Staff Department';
        studentField.classList.toggle('hidden', !isStudent);
        departmentField.classList.toggle('hidden', !isStaff);
        studentInput.required = isStudent;
        departmentSelect.required = isStaff;
    }
    role.addEventListener('change', updateUserFields);
    updateUserFields();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
