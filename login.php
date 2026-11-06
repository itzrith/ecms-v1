<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $statement = $db->prepare('SELECT user_id, full_name, email, password_hash, role, account_status FROM users WHERE email = ? LIMIT 1');
        $statement->bind_param('s', $email);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Email or password is incorrect.';
        } elseif ($user['account_status'] !== 'Active') {
            $error = 'This account is waiting for administrator approval or is inactive.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            header('Location: /ecms_v1/dashboard.php');
            exit;
        }
    }
}

$pageTitle = 'Log in';
$bodyClass = 'login-page';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout">
    <div class="intro-panel">
        <div class="eyebrow">A clearer way to be heard</div>
        <h1>Every concern deserves a clear path forward.</h1>
        <p>Submit an education-related complaint, follow its progress, and receive updates in one place.</p>
        <div class="feature-list"><span>✓ Secure access by role</span><span>✓ Complaint progress tracking</span><span>✓ Updates in one place</span></div>
    </div>
    <div class="form-card">
        <div class="eyebrow">Welcome back</div>
        <h2>Log in to ECMS</h2>
        <p class="muted">Use the email address registered with your account.</p>
        <?php show_flash(); ?>
        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label>Email address<input type="email" name="email" required autocomplete="email"></label>
            <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
            <button class="button primary" type="submit">Log in</button>
        </form>
        <p class="form-foot">New student? <a href="/ecms_v1/register.php">Create an account</a></p>
        <p class="form-foot">Student? <a href="/ecms_v1/forgot_password.php">Forgot password?</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
