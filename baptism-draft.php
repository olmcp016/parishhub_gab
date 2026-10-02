<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/scheduling.php';

$id = (int) ($_REQUEST['draft_id'] ?? 0);
$pdo = db();
$user = currentUser();
$token = baptismDraftToken($id);
$draft = baptismDraftLoad($pdo, $id, $user, $token);
if (!$draft) { http_response_code(403); exit('Baptism draft not found or access denied.'); }
if (($draft['status'] ?? '') === 'draft' && !empty($draft['expires_at']) && strtotime((string) $draft['expires_at']) <= time()) {
    http_response_code(410);
    exit('This Baptism booking draft has expired. Please start a new booking.');
}
if ($draft['status'] === 'finalized' && !empty($draft['finalized_appointment_id'])) {
    redirect(url('parishioner/appointment-detail.php?id=' . (int) $draft['finalized_appointment_id']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_documents') {
    verifyCsrf();
    $storedKeys = [];
    try {
        $pdo->beginTransaction();
        foreach (baptismDraftRequiredDocuments($draft) as $i => $label) {
            $field = 'req_doc_' . $i;
            $file = $_FILES[$field] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $validation = validateUploadedFile($file);
            if (!$validation['valid']) throw new RuntimeException($label . ': ' . documentValidationMessage($validation['reason']));
            $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedKeys[] = $stored['key'];
            $stmt = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, baptism_draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
            $stmt->execute([$id, $file['name'], $stored['key'], $stored['mime'], $label]);
        }
        $pdo->commit();
        redirect(url('baptism-draft.php?draft_id=' . $id));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($storedKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Baptism document cleanup failed.'); } }
        flash('error', $e->getMessage());
        redirect(url('baptism-draft.php?draft_id=' . $id));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $missing = baptismDraftComplete($pdo, $draft);
    if ($missing) {
        flash('error', 'Please complete: ' . implode(', ', $missing) . '.');
        redirect(url('baptism-draft.php?draft_id=' . $id));
    }
    $pdo->beginTransaction();
    try {
        $locked = baptismDraftLoad($pdo, $id, $user, $token, true);
        if (!$locked || $locked['status'] !== 'draft') throw new RuntimeException('Draft unavailable.');
        if (!empty($locked['expires_at']) && strtotime((string) $locked['expires_at']) <= time()) throw new RuntimeException('This Baptism booking draft has expired.');
        $missing = baptismDraftComplete($pdo, $locked);
        if ($missing) throw new RuntimeException('Please complete: ' . implode(', ', $missing) . '.');
        $check = validateBooking('Baptism', $locked['appointment_date'], $locked['appointment_time'], null, $locked['schedule_type'], (int) $locked['service_id']);
        if (!$check['valid']) throw new RuntimeException($check['message']);
        $guestReference = $locked['parishioner_id'] ? null : generateGuestReference();
        $stmt = $pdo->prepare(
            "INSERT INTO appointments
             (parishioner_id, service_id, priest_id, appointment_date, appointment_time,
              status_id, remarks, schedule_type, pss_claim, pss_classification,
              sponsor_count, guest_name, guest_email, guest_phone, guest_reference,
              contact_phone, location_address, requirements_snapshot)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'pending_verification',
                     ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $locked['parishioner_id'], $locked['service_id'], $locked['priest_id'],
            $locked['appointment_date'], $check['forcedTime'] ?: $locked['appointment_time'],
            $locked['remarks'], $locked['schedule_type'], $locked['pss_claim'],
            $locked['sponsor_count'], $locked['guest_name'], $locked['guest_email'],
            $locked['guest_phone'], $guestReference, $locked['contact_phone'],
            $locked['location_address'], json_encode(baptismDraftRequiredDocuments($locked))
        ]);
        $appointmentId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE uploaded_documents SET appointment_id = ?, baptism_draft_id = NULL WHERE baptism_draft_id = ?')->execute([$appointmentId, $id]);
        $pdo->prepare('UPDATE generated_baptism_forms SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $id]);
        $pdo->prepare("UPDATE baptism_booking_drafts SET status = 'finalized', finalized_appointment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_id = ?")->execute([$appointmentId, $id]);
        $pdo->commit();
        redirect($guestReference
            ? url('status.php?ref=' . urlencode($guestReference) . '&contact=' . urlencode((string) ($locked['guest_phone'] ?: $locked['guest_email'])))
            : url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'The Baptism booking could not be submitted.');
        redirect(url('baptism-draft.php?draft_id=' . $id));
    }
}

$stmt = $pdo->prepare('SELECT requirement_label FROM uploaded_documents WHERE baptism_draft_id = ? AND superseded_by IS NULL');
$stmt->execute([$id]);
$uploaded = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt = $pdo->prepare('SELECT form_type, document_id, status FROM generated_baptism_forms WHERE draft_id = ?');
$stmt->execute([$id]);
$forms = [];
foreach ($stmt->fetchAll() as $row) $forms[$row['form_type']] = $row;
$pageTitle = 'Baptism Requirements';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/dash-start.php';
?>
<div class="card"><h2>Supporting Documents</h2>
<?php foreach (baptismDraftRequiredDocuments($draft) as $label): ?>
<p><?= in_array($label, $uploaded, true) ? '✓' : '○' ?> <?= e($label) ?> — <?= in_array($label, $uploaded, true) ? 'Uploaded' : 'Required' ?></p>
<?php endforeach; ?>
<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?><input type="hidden" name="action" value="upload_documents">
<?php foreach (baptismDraftRequiredDocuments($draft) as $i => $label): ?><div class="form-group"><label><?= e($label) ?></label><input type="file" name="req_doc_<?= (int) $i ?>" accept=".pdf,.jpg,.jpeg,.png"></div><?php endforeach; ?>
<button class="btn btn-outline" type="submit">Save Documents</button>
</form></div>
<div class="card"><h2>Baptism Forms</h2>
<?php foreach (BAPTISM_DRAFT_FORMS as $type): $row = $forms[$type] ?? null; $isGenerated = $row && !empty($row['document_id']) && in_array($row['status'] ?? '', ['pending_review', 'approved'], true); $documentId = $isGenerated ? (int) $row['document_id'] : 0; $title = baptismFormDefinition($type)['title']; ?>
<p><?= $documentId ? '✓' : '○' ?> <?= e($title) ?> — <?= $documentId ? 'Generated' : ($row ? 'Draft' : 'Not completed') ?>
<a class="btn btn-outline btn-sm" href="<?= url('baptism-draft-form.php?draft_id=' . $id . '&form_type=' . urlencode($type)) ?>"><?= $row ? 'Edit Form' : 'Complete Form' ?></a>
<?php if ($documentId): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . $documentId) ?>">View PDF</a>
<a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . $documentId . '&download=1') ?>">Download PDF</a><?php endif; ?></p>
<?php endforeach; ?><form method="POST"><?= csrfField() ?><button class="btn btn-primary" type="submit">Submit Appointment Request</button></form></div>
<?php include __DIR__ . '/includes/footer.php';
