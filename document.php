<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = db()->prepare(
    'SELECT d.document_id, d.file_name, d.file_path, d.file_type, a.parishioner_id
     FROM uploaded_documents d JOIN appointments a ON a.appointment_id = d.appointment_id
     WHERE d.document_id = ?'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$user = currentUser();
$authorized = in_array($user['role_name'], ['Secretary', 'Admin'], true);
if (!$authorized && $user['role_name'] === 'Parishioner') {
    $stmt = db()->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
    $stmt->execute([$document['parishioner_id'], $user['user_id']]);
    $authorized = (bool) $stmt->fetchColumn();
}
if (!$authorized) {
    http_response_code(403);
    exit('You are not authorized to view this document.');
}

$relativePath = ltrim((string) $document['file_path'], '/\\');
$relativePath = preg_replace('#^public[/\\]uploads[/\\]#i', '', $relativePath);
$relativePath = preg_replace('#^uploads[/\\]#i', '', $relativePath);
$fileName = basename($relativePath);
$uploadRoot = realpath(__DIR__ . '/public/uploads');
$filePath = $uploadRoot ? realpath($uploadRoot . DIRECTORY_SEPARATOR . $fileName) : false;
if (!$uploadRoot || !$filePath || !is_file($filePath) || strncmp($filePath, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(404);
    exit('Document file is no longer available.');
}

$mime = $document['file_type'] ?: (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream');
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!in_array($mime, $allowedMimes, true)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($document['file_name'])) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
