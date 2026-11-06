<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
$evidenceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$evidenceId) {
    http_response_code(404);
    exit('File not found.');
}
$query = $db->prepare('SELECT e.file_name, e.file_type, e.file_path, c.complaint_id, c.user_id FROM supporting_evidence e JOIN complaints c ON c.complaint_id = e.complaint_id WHERE e.evidence_id = ?');
$query->bind_param('i', $evidenceId);
$query->execute();
$file = $query->get_result()->fetch_assoc();
if (!$file) {
    http_response_code(404);
    exit('File not found.');
}
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];
$allowed = $role === 'Administrator' || $role === 'MPP Welfare' || ($role === 'Student' && (int) $file['user_id'] === $userId);
if ($role === 'Staff Department') {
    $check = $db->prepare("SELECT 1 FROM complaint_assignments a JOIN users u ON u.user_id = ? WHERE a.complaint_id = ? AND a.assignment_status <> 'Reassigned' AND (a.staff_id = ? OR a.department = u.department) LIMIT 1");
    $check->bind_param('iii', $userId, $file['complaint_id'], $userId);
    $check->execute();
    $allowed = (bool) $check->get_result()->fetch_row();
}
$path = __DIR__ . '/uploads/' . basename($file['file_path']);
if (!$allowed) {
    http_response_code(403);
    exit('You do not have permission to download this file.');
}
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
$preview = ($_GET['preview'] ?? '') === '1';
$supportedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
$contentType = in_array($file['file_type'], $supportedTypes, true) ? $file['file_type'] : 'application/octet-stream';
if (!$preview && $contentType === 'application/octet-stream') {
    header('Content-Disposition: attachment; filename="' . rawurlencode($file['file_name']) . '"');
}
header('Content-Type: ' . $contentType);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
if ($contentType !== 'application/octet-stream') {
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . rawurlencode($file['file_name']) . '"');
}
readfile($path);
