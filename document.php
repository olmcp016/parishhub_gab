<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/baptism-draft.php';

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = db()->prepare(
    'SELECT d.document_id, d.appointment_id, d.draft_id, d.baptism_draft_id, d.file_name, d.file_path, d.file_type, d.generated_form_type, a.parishioner_id
     FROM uploaded_documents d LEFT JOIN appointments a ON a.appointment_id = d.appointment_id
     WHERE d.document_id = ?'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$user = currentUser();
$authorized = $document && $document['appointment_id'] !== null && $user && in_array($user['role_name'], ['Secretary', 'Admin'], true);
if (!$authorized && $user && $user['role_name'] === 'Parishioner') {
    $stmt = db()->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
    $stmt->execute([$document['parishioner_id'], $user['user_id']]);
    $authorized = (bool) $stmt->fetchColumn();
}
if (!$authorized && $document && $document['appointment_id'] === null && $document['draft_id'] !== null) {
    $draft = weddingDraftLoad(db(), (int) $document['draft_id'], $user, weddingDraftGuestToken((int) $document['draft_id']));
    $authorized = (bool) $draft;
}
if (!$authorized && $document && $document['appointment_id'] === null && !empty($document['baptism_draft_id'])) {
    $draft = baptismDraftLoad(db(), (int) $document['baptism_draft_id'], $user, baptismDraftToken((int) $document['baptism_draft_id']));
    $authorized = (bool) $draft;
}
if (!$authorized && !$user) {
    $guest = $_SESSION['guest_status_verification'] ?? null;
    $authorized = $guest
        && (int) ($guest['expires_at'] ?? 0) >= time()
        && (int) ($guest['appointment_id'] ?? 0) === (int) $document['appointment_id'];
}
if (!$authorized) {
    http_response_code(403);
    exit('You are not authorized to view this document.');
}

$storedPath = (string) $document['file_path'];
if (str_starts_with($storedPath, 'supabase://')) {
    try { $stored = documentStorageRead($storedPath); } catch (Throwable $e) { http_response_code(404); exit('Document file is no longer available.'); }
    $filePath = null;
} else {
    $filePath = documentStoragePathFromKey($storedPath);
    $filePath = $filePath ? realpath($filePath) : false;
    $stored = null;
}
$storageRoot = $filePath ? realpath(dirname($filePath)) : false;
if ((!$stored && (!$storageRoot || !$filePath || !is_file($filePath) || strncmp($filePath, $storageRoot . DIRECTORY_SEPARATOR, strlen($storageRoot . DIRECTORY_SEPARATOR)) !== 0))) {
    http_response_code(404);
    exit('Document file is no longer available.');
}

$mime = $document['file_type'] ?: (function_exists('mime_content_type') && $filePath ? mime_content_type($filePath) : 'application/octet-stream');
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!in_array($mime, $allowedMimes, true)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
if ($filePath) header('Content-Length: ' . (string) filesize($filePath)); else header('Content-Length: ' . strlen($stored['body']));
$generatedFilenames = [
    'matrimony_application' => 'Marriage-Requirement-and-Application-Form.pdf',
    'cluster_clearance' => 'Katin-awan-sa-Kasal.pdf',
    'wedding_sponsor_clearance' => 'Cluster-Clearance-Wedding-Sponsors.pdf',
    'katin_awan_bunyag' => 'Katin-awan-sa-Bunyag.pdf',
    'cluster_clearance_baptism_sponsor' => 'Cluster-Clearance-Baptism-Sponsor.pdf',
];
$download = ($_GET['download'] ?? '') === '1';
$filename = $generatedFilenames[$document['generated_form_type'] ?? ''] ?? basename((string) $document['file_name']);
$filename = str_replace(["\"", "\r", "\n"], '', $filename);
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
if ($filePath) readfile($filePath); else echo $stored['body'];
