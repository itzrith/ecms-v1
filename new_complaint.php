<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['Student']);

$error = '';
$categories = $db->query('SELECT category_id, category_name FROM complaint_categories WHERE is_active = 1 ORDER BY category_name')->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        $files = [];

        if ($title === '' || $description === '' || $categoryId < 1) {
            $error = 'Enter a title and description, then choose a category.';
        } else {
            $categoryCheck = $db->prepare('SELECT category_id FROM complaint_categories WHERE category_id = ? AND is_active = 1');
            $categoryCheck->bind_param('i', $categoryId);
            $categoryCheck->execute();
            if (!$categoryCheck->get_result()->fetch_assoc()) {
                $error = 'Please choose a valid complaint category.';
            }
        }

        if ($error === '' && isset($_FILES['evidence']) && is_array($_FILES['evidence']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($_FILES['evidence']['name'] as $index => $originalName) {
                $uploadError = $_FILES['evidence']['error'][$index];
                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = 'A file could not be uploaded. Please try again.';
                    break;
                }
                if ($_FILES['evidence']['size'][$index] > 5 * 1024 * 1024) {
                    $error = 'Each attachment must be 5 MB or smaller.';
                    break;
                }
                $tmpPath = $_FILES['evidence']['tmp_name'][$index];
                $mime = $finfo->file($tmpPath);
                if (!in_array($mime, $allowedTypes, true)) {
                    $error = 'Attachments must be JPG, PNG, or PDF files.';
                    break;
                }
                $files[] = ['tmp' => $tmpPath, 'name' => basename($originalName), 'mime' => $mime];
            }
        }

        if ($error === '') {
            $savedPaths = [];
            try {
                $db->begin_transaction();
                $studentId = (int) $_SESSION['user_id'];
                $priority = 'Medium';
                $status = 'Submitted';
                $statement = $db->prepare('INSERT INTO complaints (user_id, title, description, category_id, location, priority, status, is_anonymous) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->bind_param('ississsi', $studentId, $title, $description, $categoryId, $location, $priority, $status, $anonymous);
                $statement->execute();
                $complaintId = (int) $db->insert_id;

                $uploadDirectory = __DIR__ . '/uploads';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
                    throw new RuntimeException('Could not create the evidence folder.');
                }
                foreach ($files as $file) {
                    $storedName = bin2hex(random_bytes(16)) . ($file['mime'] === 'application/pdf' ? '.pdf' : ($file['mime'] === 'image/png' ? '.png' : '.jpg'));
                    $destination = $uploadDirectory . '/' . $storedName;
                    if (!move_uploaded_file($file['tmp'], $destination)) {
                        throw new RuntimeException('Could not save an attachment.');
                    }
                    $savedPaths[] = $destination;
                    $evidence = $db->prepare('INSERT INTO supporting_evidence (complaint_id, uploaded_by, file_name, file_type, file_path) VALUES (?, ?, ?, ?, ?)');
                    $evidence->bind_param('iisss', $complaintId, $studentId, $file['name'], $file['mime'], $storedName);
                    $evidence->execute();
                }

                ecms_notify_roles($db, $complaintId, ['MPP Welfare', 'Administrator'], 'New complaint to review', 'A new complaint is ready for triage.');
                $db->commit();
                flash('Your complaint was submitted. Reference number: ECMS-' . str_pad((string) $complaintId, 5, '0', STR_PAD_LEFT));
                header('Location: /ecms_v1/complaints.php');
                exit;
            } catch (Throwable $exception) {
                $db->rollback();
                foreach ($savedPaths as $savedPath) {
                    if (is_file($savedPath)) {
                        unlink($savedPath);
                    }
                }
                $error = 'The complaint could not be saved. Please try again.';
            }
        }
    }
}

$pageTitle = 'Submit a complaint';
require __DIR__ . '/includes/header.php';
?>
<section class="narrow-layout">
    <div class="form-card wide-card">
        <div class="eyebrow">Student portal</div>
        <h1>Submit a complaint</h1>
        <p class="muted">Give enough detail to help the responsible team review the issue.</p>

        <div class="notice" role="note"><strong>Need urgent help?</strong> ECMS is not monitored as an emergency service. Contact campus security or the appropriate emergency service directly.</div>
        <details class="help-panel"><summary>Not sure which category to choose?</summary><ul><li>Classes, exams or academic records: Academic</li><li>Repairs, lighting or campus facilities: Facilities</li><li>Computer, Wi-Fi or projector: ICT / Technology</li><li>Student activities or support: Student Services</li></ul><p class="small-note">If you are unsure, choose Other. MPP Welfare can route the report to the correct team.</p></details>

        <?php if ($error !== ''): ?>
            <div class="notice error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <label>
                Complaint title
                <input name="title" required maxlength="180" value="<?= h($_POST['title'] ?? '') ?>">
            </label>
            <label>
                Category
                <select name="category_id" required>
                    <option value="">Choose a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['category_id'] ?>" <?= (int) ($_POST['category_id'] ?? 0) === (int) $category['category_id'] ? 'selected' : '' ?>>
                            <?= h($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Location
                <input name="location" maxlength="180" placeholder="Building, room, or area" value="<?= h($_POST['location'] ?? '') ?>">
            </label>
            <label>
                Description
                <textarea name="description" rows="6" required><?= h($_POST['description'] ?? '') ?></textarea>
            </label>
            <label>
                Supporting evidence
                <span class="muted">Optional · JPG, PNG, or PDF · up to 5 MB each</span>
                <input type="file" name="evidence[]" accept="image/jpeg,image/png,application/pdf" multiple>
            </label>
            <label class="check-row">
                <input type="checkbox" name="is_anonymous" value="1">
                Submit anonymously to complaint handlers
            </label>
            <p class="small-note">
                Your account remains linked to the complaint for follow-up. Your identity will be hidden from MPP and department staff when you select anonymous.
            </p>
            <div class="action-row">
                <button class="button primary" type="submit">Submit complaint</button>
                <a class="button secondary" href="/ecms_v1/dashboard.php">Cancel</a>
            </div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
