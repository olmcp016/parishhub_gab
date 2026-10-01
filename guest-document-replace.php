<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/document-storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('status.php'));
}
verifyCsrf();

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$documentId = (int) ($_POST['document_id'] ?? 0);
$redirectUrl = url('status.php?ref=' . urlencode((string) ($_POST['ref'] ?? '')));
$guest = $_SESSION['guest_status_verification'] ?? null;
if (!$guest || (int) ($guest['appointment_id'] ?? 0) !== $appointmentId || (int) ($guest['expires_at'] ?? 0) < time()) {
    flash('error', 'Please verify your reference code and contact information again.');
    redirect($redirectUrl);
}

$file = $_FILES['replacement'] ?? null;
$validation = $file ? validateUploadedFile($file) : ['valid' => false, 'reason' => 'missing'];
if (!$validation['valid']) {
    flash('error', documentValidationMessage($validation['reason'] ?? 'unreadable'));
    redirect($redirectUrl);
}

$pdo = db();
$pdo->beginTransaction();
$newPath = null;
$stored = null;
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM uploaded_documents WHERE document_id = ? AND appointment_id = ? FOR UPDATE'
    );
    $stmt->execute([$documentId, $appointmentId]);
    $original = $stmt->fetch();
    if (!$original || $original['review_status'] !== 'rejected' || $original['superseded_by'] !== null) {
        throw new RuntimeException('This document is no longer eligible for replacement.');
    }

    $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION));
    $newPath = $stored['path'];

    $stmt = $pdo->prepare(
        "INSERT INTO uploaded_documents
         (appointment_id, requirement_id, requirement_label, file_name, file_path, file_type, review_status, verified)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', FALSE)"
    );
    $stmt->execute([$appointmentId, $original['requirement_id'], $original['requirement_label'], $file['name'], $stored['key'], $stored['mime']]);
    $newId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ?')
        ->execute([$newId, $documentId]);
    $pdo->commit();
    flash('success', 'Replacement submitted for Secretary review.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($newPath && is_file($newPath)) @unlink($newPath);
    if ($stored && str_starts_with($stored['key'] ?? '', 'supabase://')) {
        try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); }
    }
    error_log($e->getMessage());
    flash('error', 'The replacement could not be submitted. Please try again.');
}
redirect($redirectUrl);
