<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/funeral-draft.php';

function documentUploadJson(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') documentUploadJson(false, 'Invalid upload request.', [], 405);

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    documentUploadJson(false, 'Your session expired. Please refresh the page and try again.', [], 419);
}

$context = (string) ($_POST['context'] ?? '');
$contextId = (int) ($_POST['context_id'] ?? 0);
$label = trim((string) ($_POST['requirement_label'] ?? ''));
$file = $_FILES['document'] ?? null;
if (!$file || !isset($file['tmp_name'])) documentUploadJson(false, 'Please choose a file first.', [], 400);

$validation = validateUploadedFile($file);
if (!$validation['valid']) documentUploadJson(false, documentValidationMessage($validation['reason']), [], 422);

$user = currentUser();
$pdo = db();
$stored = null;
$oldKey = null;

try {
    if ($context === 'baptism_draft') {
        $draft = baptismDraftLoad($pdo, $contextId, $user, baptismDraftToken($contextId));
        if (!$draft || ($draft['status'] ?? '') !== 'draft') documentUploadJson(false, 'This Baptism draft is no longer available.', [], 403);
        $allowed = baptismDraftAllDocuments($draft);
        if (!in_array($label, $allowed, true)) documentUploadJson(false, 'That document is not part of this Baptism request.', [], 422);

        $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, baptism_draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
        $insert->execute([$contextId, basename((string) $file['name']), $stored['key'], $stored['mime'], $label]);
        $newId = (int) $pdo->lastInsertId();
        $old = $pdo->prepare("SELECT document_id FROM uploaded_documents WHERE baptism_draft_id = ? AND requirement_label = ? AND superseded_by IS NULL AND document_id <> ? ORDER BY document_id DESC LIMIT 1");
        $old->execute([$contextId, $label, $newId]);
        $oldId = $old->fetchColumn();
        if ($oldId) $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ?')->execute([$newId, $oldId]);
        $pdo->commit();
        documentUploadJson(true, 'Document uploaded.', ['document_id' => $newId, 'file_name' => basename((string) $file['name']), 'view_url' => url('document.php?id=' . $newId)]);
    }

    if ($context === 'wedding_draft') {
        $draft = weddingDraftLoad($pdo, $contextId, $user, weddingDraftGuestToken($contextId));
        if (!$draft || ($draft['status'] ?? '') !== 'draft') documentUploadJson(false, 'This Wedding draft is no longer available.', [], 403);
        if (!in_array($label, weddingDraftRequiredDocuments($draft), true)) documentUploadJson(false, 'That document is not part of this Wedding request.', [], 422);

        $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
        $insert->execute([$contextId, basename((string) $file['name']), $stored['key'], $stored['mime'], $label]);
        $newId = (int) $pdo->lastInsertId();
        $old = $pdo->prepare("SELECT document_id FROM uploaded_documents WHERE draft_id = ? AND requirement_label = ? AND superseded_by IS NULL AND document_id <> ? ORDER BY document_id DESC LIMIT 1");
        $old->execute([$contextId, $label, $newId]);
        $oldId = $old->fetchColumn();
        if ($oldId) $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ?')->execute([$newId, $oldId]);
        $pdo->commit();
        documentUploadJson(true, 'Document uploaded.', ['document_id' => $newId, 'file_name' => basename((string) $file['name']), 'view_url' => url('document.php?id=' . $newId)]);
    }

    if ($context === 'funeral_draft') {
        $draft = funeralDraftLoad($contextId, $user);
        if (!$draft) documentUploadJson(false, 'This Funeral draft is no longer available.', [], 403);
        if (!in_array($label, funeralDraftRequiredDocuments(), true)) documentUploadJson(false, 'That document is not part of this Funeral request.', [], 422);

        $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $uploads = array_values(array_filter($draft['uploaded_keys'] ?? [], 'is_array'));
        foreach ($uploads as $index => $upload) {
            if (($upload['label'] ?? '') === $label) {
                $oldKey = (string) ($upload['key'] ?? '');
                $uploads[$index] = ['file_name' => basename((string) $file['name']), 'key' => $stored['key'], 'mime' => $stored['mime'], 'label' => $label];
                $_SESSION['funeral_booking_drafts'][$contextId]['uploaded_keys'] = $uploads;
                if ($oldKey !== '') try { documentStorageDelete($oldKey); } catch (Throwable $ignored) { error_log('Old Funeral document cleanup failed.'); }
                documentUploadJson(true, 'Document uploaded.', ['file_name' => basename((string) $file['name'])]);
            }
        }
        $uploads[] = ['file_name' => basename((string) $file['name']), 'key' => $stored['key'], 'mime' => $stored['mime'], 'label' => $label];
        $_SESSION['funeral_booking_drafts'][$contextId]['uploaded_keys'] = $uploads;
        documentUploadJson(true, 'Document uploaded.', ['file_name' => basename((string) $file['name'])]);
    }

    if ($context === 'appointment') {
        if (!$user || ($user['role_name'] ?? '') !== 'Parishioner') documentUploadJson(false, 'You are not authorized to upload this document.', [], 403);
        $owner = $pdo->prepare(
            "SELECT a.appointment_id, a.status_id, a.requirements_snapshot, s.requirements
             FROM appointments a JOIN services s ON s.service_id = a.service_id
             JOIN parishioners p ON p.parishioner_id = a.parishioner_id
             WHERE a.appointment_id = ? AND p.user_id = ?"
        );
        $owner->execute([$contextId, $user['user_id']]);
        $appointment = $owner->fetch();
        if (!$appointment || (int) $appointment['status_id'] !== 1) documentUploadJson(false, 'Only a pending appointment can receive a requirement replacement.', [], 403);
        $requirements = !empty($appointment['requirements_snapshot'])
            ? (json_decode($appointment['requirements_snapshot'], true) ?: [])
            : parseRequirementsList($appointment['requirements']);
        if (!in_array($label, $requirements, true) || $label === 'Katin-awan sa Paglubong') documentUploadJson(false, 'That document is not an upload requirement for this appointment.', [], 422);

        $pdo->beginTransaction();
        $active = $pdo->prepare("SELECT document_id, review_status FROM uploaded_documents WHERE appointment_id = ? AND requirement_label = ? AND superseded_by IS NULL ORDER BY document_id DESC LIMIT 1 FOR UPDATE");
        $active->execute([$contextId, $label]);
        $activeDocument = $active->fetch();
        if ($activeDocument && ($activeDocument['review_status'] ?? '') !== 'rejected') {
            $pdo->rollBack();
            documentUploadJson(false, 'This requirement already has an active submission. Wait for review before uploading another file.', [], 409);
        }
        $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
        $insert->execute([$contextId, basename((string) $file['name']), $stored['key'], $stored['mime'], $label]);
        $newId = (int) $pdo->lastInsertId();
        $oldId = $activeDocument['document_id'] ?? null;
        if ($oldId) $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ?')->execute([$newId, $oldId]);
        $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Documents Updated', ?)")
            ->execute([(int) $user['user_id'], "Your requirement replacement for appointment #$contextId has been submitted and remains under review."]);
        $secretaries = $pdo->query("SELECT user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name IN ('Secretary', 'Admin') AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $notify = $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Documents Updated', ?)");
        foreach ($secretaries as $secretaryId) {
            $notify->execute([(int) $secretaryId, "Appointment #$contextId has updated documents and is ready for review."]);
        }
        $pdo->commit();
        logActivity((int) $user['user_id'], "Resubmitted documents for previously rejected appointment #$contextId", 'Appointments');
        documentUploadJson(true, 'Replacement uploaded and submitted for review.', ['document_id' => $newId, 'file_name' => basename((string) $file['name']), 'view_url' => url('document.php?id=' . $newId)]);
    }

    if ($context === 'guest_appointment') {
        $guest = $_SESSION['guest_status_verification'] ?? null;
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if (!is_array($guest) || (int) ($guest['appointment_id'] ?? 0) !== $contextId || (int) ($guest['expires_at'] ?? 0) < time()) {
            documentUploadJson(false, 'Please verify your booking again before replacing this document.', [], 403);
        }
        $pdo->beginTransaction();
        $appointmentLock = $pdo->prepare('SELECT status_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
        $appointmentLock->execute([$contextId]);
        if ((int) $appointmentLock->fetchColumn() !== 1) {
            $pdo->rollBack();
            documentUploadJson(false, 'This appointment was rejected as a whole and must be rebooked. Requirement replacements are only available while the appointment remains pending.', [], 403);
        }
        $originalQuery = $pdo->prepare('SELECT * FROM uploaded_documents WHERE document_id = ? AND appointment_id = ? FOR UPDATE');
        $originalQuery->execute([$documentId, $contextId]);
        $original = $originalQuery->fetch();
        if (!$original || ($original['review_status'] ?? '') !== 'rejected' || $original['superseded_by'] !== null) {
            $pdo->rollBack();
            documentUploadJson(false, 'This document is no longer eligible for replacement.', [], 422);
        }

        $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, requirement_id, requirement_label, file_name, file_path, file_type, review_status, verified, document_source) VALUES (?, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
        $insert->execute([$contextId, $original['requirement_id'], $original['requirement_label'], basename((string) $file['name']), $stored['key'], $stored['mime']]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ?')->execute([$newId, $documentId]);
        $pdo->commit();
        documentUploadJson(true, 'Replacement uploaded for review.', ['document_id' => $newId, 'file_name' => basename((string) $file['name']), 'view_url' => url('document.php?id=' . $newId)]);
    }

    documentUploadJson(false, 'Invalid upload context.', [], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($stored && !empty($stored['key'])) {
        try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Document upload cleanup failed.'); }
    }
    error_log('Supporting document upload failed: ' . $e->getMessage());
    documentUploadJson(false, 'The document could not be uploaded. Please try again.', [], 500);
}
