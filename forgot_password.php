<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $studentId = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!csrf_is_valid()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $studentId === '') {
        $error = 'Enter the email and student ID registered with this account.';
    } elseif (strlen($password) < 8) {
        $error = 'Use a new password with at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The new passwords do not match.';
    } else {
        $lookup = $db->prepare("SELECT user_id FROM users WHERE email=? AND student_id=? AND role='Student' LIMIT 1");
        $lookup->bind_param('ss', $email, $studentId);
        $lookup->execute();
        $student = $lookup->get_result()->fetch_assoc();

        if (!$student) {
            $error = 'Those details do not match a student account.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $studentUserId = (int) $student['user_id'];
            $update = $db->prepare("UPDATE users SET password_hash=? WHERE user_id=? AND role='Student'");
            $update->bind_param('si', $passwordHash, $studentUserId);
            $update->execute();
            $success = 'Your password has been reset. You can now log in.';
        }
    }
}

$pageTitle = 'Reset student password';
require __DIR__ . '/includes/header.php';
?>
<section class="narrow-layout">
    <div class="form-card">
        <div class="eyebrow">Student account</div>
        <h1>Reset your password</h1>
        <p class="muted">Confirm your registered email and student ID, then choose a new password.</p>
        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="notice notice-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($success === ''): ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label>Registered email<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>"></label>
            <label>Student ID<input type="text" name="student_id" required maxlength="40" autocomplete="off" value="<?= h($_POST['student_id'] ?? '') ?>"></label>
            <label>New password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirm new password<input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label>
            <button class="button primary" type="submit">Reset password</button>
        </form>
        <?php endif; ?>
        <p class="form-foot"><a href="/ecms_v1/login.php">Back to log in</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
