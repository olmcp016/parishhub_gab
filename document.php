<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/funeral-draft.php';

$funeralDraftId = (int) ($_GET['funeral_draft_id'] ?? 0);
if ($funeralDraftId > 0) {
    $draft = funeralDraftLoad($funeralDraftId, currentUser());
    if (!$draft) { http_response_code(403); exit('You are not authorized to view this document.'); }
    $draftDocument = $draft['generated_document'] ?? null;
    if (!is_array($draftDocument) || empty($draftDocument['key']) || ($draftDocument['mime'] ?? '') !== 'application/pdf') {
        http_response_code(404); exit('Document not found.');
    }
    try {
        $storedDraftDocument = documentStorageRead((string) $draftDocument['key']);
    } catch (Throwable $e) {
        error_log('Funeral draft document read failed: ' . $e->getMessage());
        http_response_code(404); exit('Document file is no longer available.');
    }
    $draftFilename = basename((string) ($draftDocument['file_name'] ?? 'Katin-awan_sa_Paglubong.pdf'));
    $draftFilename = str_replace(["\"", "\r", "\n", "/", "\\"], '', $draftFilename) ?: 'Katin-awan_sa_Paglubong.pdf';
    $draftDownload = ($_GET['download'] ?? '') === '1';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen((string) $storedDraftDocument['body']));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($draftDownload ? 'attachment' : 'inline') . '; filename="' . $draftFilename . '"; filename*=UTF-8\'\'' . rawurlencode($draftFilename));
    echo $storedDraftDocument['body'];
    exit;
}

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
$download = ($_GET['download'] ?? '') === '1';

function sanitizePdfFilename(string $name): string {
    // Remove unsafe filesystem characters, slashes, quotes, and control chars
    $name = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $name);
    // Collapse spaces to underscores
    $name = preg_replace('/\s+/', '_', trim($name));
    return $name ?: '';
}

$formType = $document['generated_form_type'] ?? '';
$formalFilename = null;

if ($formType) {
    $baseNames = [
        'matrimony_application' => 'Marriage_Requirement_and_Application_Form',
        'cluster_clearance' => 'Katin-awan_sa_Kasal',
        'wedding_sponsor_clearance' => 'Cluster_Clearance_for_Wedding_Sponsor',
        'katin_awan_bunyag' => 'Katin-awan_sa_Bunyag',
        'cluster_clearance_baptism_sponsor' => 'Cluster_Clearance_for_Baptismal_Sponsor',
        'katin_awan_paglubong' => 'Katin-awan_sa_Paglubong',
    ];
    $base = $baseNames[$formType] ?? 'Generated_Form';
    
    $formData = [];
    if (in_array($formType, ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance'])) {
        $stmt = db()->prepare('SELECT form_data FROM generated_wedding_forms WHERE draft_id = ? AND form_type = ?');
        $stmt->execute([$document['draft_id'], $formType]);
        if ($row = $stmt->fetch()) $formData = json_decode($row['form_data'], true) ?: [];
    } elseif (in_array($formType, ['katin_awan_bunyag', 'cluster_clearance_baptism_sponsor'])) {
        $stmt = db()->prepare('SELECT form_data FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ?');
        $stmt->execute([$document['baptism_draft_id'], $formType]);
        if ($row = $stmt->fetch()) $formData = json_decode($row['form_data'], true) ?: [];
    } elseif ($formType === 'katin_awan_paglubong') {
        $stmt = db()->prepare('SELECT form_data FROM generated_funeral_forms WHERE appointment_id = ?');
        $stmt->execute([$document['appointment_id']]);
        if ($row = $stmt->fetch()) $formData = json_decode($row['form_data'], true) ?: [];
    }
    
    $subject = '';
    if ($formType === 'matrimony_application') {
        $groom = sanitizePdfFilename($formData['groom_name'] ?? '');
        $bride = sanitizePdfFilename($formData['bride_name'] ?? '');
        if ($groom && $bride) $subject = $groom . '_and_' . $bride;
        elseif ($groom) $subject = $groom;
        elseif ($bride) $subject = $bride;
    } elseif ($formType === 'cluster_clearance') {
        $kaslonon = sanitizePdfFilename($formData['kaslonon_name'] ?? '');
        $spouse = sanitizePdfFilename($formData['spouse_name'] ?? '');
        if ($kaslonon && $spouse) $subject = $kaslonon . '_and_' . $spouse;
        elseif ($kaslonon) $subject = $kaslonon;
        elseif ($spouse) $subject = $spouse;
    } elseif ($formType === 'wedding_sponsor_clearance') {
        $sponsor = sanitizePdfFilename($formData['recipient_name'] ?? '');
        if ($sponsor) {
            $subject = $sponsor;
        } else {
            $groom = sanitizePdfFilename($formData['groom_name'] ?? '');
            $bride = sanitizePdfFilename($formData['bride_name'] ?? '');
            if ($groom && $bride) $subject = $groom . '_and_' . $bride;
        }
    } elseif ($formType === 'katin_awan_bunyag') {
        $subject = sanitizePdfFilename($formData['child_name'] ?? '');
    } elseif ($formType === 'cluster_clearance_baptism_sponsor') {
        $subject = sanitizePdfFilename($formData['sponsor_name'] ?? '');
    } elseif ($formType === 'katin_awan_paglubong') {
        $subject = sanitizePdfFilename($formData['ngalan_sa_ilubong'] ?? '');
    }
    
    if ($subject) {
        $formalFilename = $base . '_' . $subject . '.pdf';
    } else {
        $formalFilename = $base . '_' . ($document['appointment_id'] ? 'Appointment_' . $document['appointment_id'] : 'Draft_' . ($document['draft_id'] ?: $document['baptism_draft_id'])) . '.pdf';
    }
}

$filename = $formalFilename ?? basename((string) $document['file_name']);
$filename = str_replace(["\"", "\r", "\n", "/", "\\"], '', $filename);
if (!$filename) $filename = 'document.pdf';
$urlEncodedFilename = rawurlencode($filename);

header('Cache-Control: private, max-age=0, must-revalidate');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . $urlEncodedFilename);
header('X-Content-Type-Options: nosniff');
if ($filePath) readfile($filePath); else echo $stored['body'];
