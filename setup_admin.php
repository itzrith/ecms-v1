<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$count = (int) $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'Administrator'")->fetch_assoc()['total'];
$error = '';
if ($count === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter your full name and a valid email address.';
        } elseif (strlen($password) < 8 || $password !== $confirm) {
            $error = 'Passwords must match and contain at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'Administrator';
            $status = 'Active';
            $statement = $db->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?)');
            $statement->bind_param('sssss', $name, $email, $hash, $role, $status);
            $statement->execute();
            flash('Initial administrator account created. Please log in.');
            header('Location: /ecms_v1/login.php');
            exit;
        }
    }
}

$pageTitle = 'Initial administrator setup';
require __DIR__ . '/includes/header.php';
?>
<section class="narrow-layout"><div class="form-card">
    <div class="eyebrow">One-time local setup</div>
    <h1>Initial administrator</h1>
    <?php if ($count > 0): ?>
        <div class="notice">An administrator account already exists. This setup page is now closed.</div>
        <a class="button secondary" href="/ecms_v1/login.php">Go to log in</a>
    <?php else: ?>
        <p class="muted">Create the first administrator account for this local ECMS installation.</p>
        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label>Full name<input name="full_name" required maxlength="120"></label>
            <label>Email address<input type="email" name="email" required maxlength="190"></label>
            <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirm password<input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label>
            <button class="button primary" type="submit">Create administrator</button>
        </form>
    <?php endif; ?>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
