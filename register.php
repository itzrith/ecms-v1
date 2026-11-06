<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/departments.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $studentId = trim($_POST['student_id'] ?? '');
        $role = $_POST['role'] ?? 'Student';
        $department = trim($_POST['department'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $allowedRoles = ['Student', 'MPP Welfare', 'Staff Department'];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter your full name and a valid email address.';
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = 'Please choose a valid account role.';
        } elseif (strlen($password) < 8) {
            $error = 'Use a password with at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The passwords do not match.';
        } elseif ($role === 'Student' && $studentId === '') {
            $error = 'Enter your student ID.';
        } elseif ($role === 'Staff Department' && !in_array($department, ecms_departments(), true)) {
            $error = 'Choose a department from the list.';
        } else {
            try {
                $status = 'Pending';
                $studentIdValue = $studentId === '' ? null : $studentId;
                $departmentValue = $department === '' ? null : $department;
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $statement = $db->prepare('INSERT INTO users (full_name, student_id, email, password_hash, role, department, account_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $statement->bind_param('sssssss', $name, $studentIdValue, $email, $passwordHash, $role, $departmentValue, $status);
                $statement->execute();

                flash('Your account registration was sent for administrator approval. You can log in after an administrator activates your account.');
                header('Location: /ecms_v1/login.php');
                exit;
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062 && str_contains($exception->getMessage(), 'uq_users_department')) {
                    $error = 'That department already has a staff account. Please enter a different department.';
                } else {
                    $error = $exception->getCode() === 1062
                        ? 'That email or student ID is already registered.'
                        : 'The account could not be created. Please try again.';
                }
            }
        }
    }
}

$availableDepartments = ecms_available_departments($db);
$pageTitle = 'Create account';
require __DIR__ . '/includes/header.php';
?>
<section class="narrow-layout">
    <div class="form-card">
        <div class="eyebrow">Join ECMS</div>
        <h1>Create an account</h1>
        <p class="muted">All new accounts need administrator approval before you can log in.</p>
        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label>Full name<input type="text" name="full_name" required maxlength="120" autocomplete="name"></label>
            <label>Email address<input type="email" name="email" required maxlength="190" autocomplete="email"></label>
            <label>Account role<select name="role" id="role" required><option>Student</option><option>MPP Welfare</option><option>Staff Department</option></select></label>
            <label id="student-field">Student ID<input type="text" name="student_id" maxlength="40"></label>
            <label id="department-field" class="hidden">Department<select name="department" id="department" aria-label="Department"><option value="">Choose a department</option><?php foreach ($availableDepartments as $departmentOption): ?><option value="<?= h($departmentOption) ?>" <?= ($_POST['department'] ?? '') === $departmentOption ? 'selected' : '' ?>><?= h($departmentOption) ?></option><?php endforeach; ?></select><?php if (!$availableDepartments): ?><small class="table-note">All departments already have a staff account.</small><?php endif; ?></label>
            <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirm password<input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label>
            <button class="button primary" type="submit">Create account</button>
        </form>
        <p class="form-foot">Already registered? <a href="/ecms_v1/login.php">Log in</a></p>
    </div>
</section>
<script>
const role = document.getElementById('role');
const studentField = document.getElementById('student-field');
const departmentField = document.getElementById('department-field');
const departmentSelect = document.getElementById('department');
function updateRoleFields() {
    studentField.classList.toggle('hidden', role.value !== 'Student');
    departmentField.classList.toggle('hidden', role.value !== 'Staff Department');
    departmentSelect.required = role.value === 'Staff Department';
}
role.addEventListener('change', updateRoleFields);
updateRoleFields();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
