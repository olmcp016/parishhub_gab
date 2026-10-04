<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/supporting-documents.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/scheduling.php';
require_once __DIR__ . '/includes/generated-form-workflow.php';

$id = (int) ($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);
$pdo = db();
$user = currentUser();
$baptismFinalizationStage = 'initial_request';
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
        foreach (baptismDraftAllDocuments($draft) as $i => $label) {
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
    $baptismFinalizationStage = 'validate_request';
    verifyCsrf();
    $baptismFinalizationStage = 'validate_documents';
    $missing = baptismDraftComplete($pdo, $draft);
    if ($missing) {
        flash('error', 'Please complete: ' . implode(', ', $missing) . '.');
        redirect(url('baptism-draft.php?draft_id=' . $id));
    }
    $baptismFinalizationStage = 'begin_transaction';
    $pdo->beginTransaction();
    try {
        $baptismFinalizationStage = 'lock_draft';
        $locked = baptismDraftLoad($pdo, $id, $user, $token, true);
        if (!$locked || $locked['status'] !== 'draft') throw new RuntimeException('Draft unavailable.');
        if (!empty($locked['expires_at']) && strtotime((string) $locked['expires_at']) <= time()) throw new RuntimeException('This Baptism booking draft has expired.');
        $baptismFinalizationStage = 'validate_locked_documents';
        $missing = baptismDraftComplete($pdo, $locked);
        if ($missing) throw new RuntimeException('Please complete: ' . implode(', ', $missing) . '.');
        $baptismFinalizationStage = 'validate_schedule';
        $check = validateBooking('Baptism', $locked['appointment_date'], $locked['appointment_time'], null, $locked['schedule_type'], (int) $locked['service_id']);
        if (!$check['valid']) throw new RuntimeException($check['message']);
        $baptismFinalizationStage = 'resolve_appointment_owner';
        $appointmentParishionerId = !empty($locked['parishioner_id'])
            ? (int) $locked['parishioner_id']
            : guestParishionerId();
        if ($appointmentParishionerId <= 0) {
            throw new RuntimeException('Guest appointment owner could not be resolved.');
        }
        $guestReference = generateGuestReference();
        $stmt = $pdo->prepare(
            "INSERT INTO appointments
             (parishioner_id, service_id, priest_id, appointment_date, appointment_time,
              status_id, remarks, schedule_type, pss_claim, pss_classification,
              sponsor_count, guest_name, guest_email, guest_phone, guest_reference,
              contact_phone, location_address, requirements_snapshot)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'pending_verification',
                     ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $baptismFinalizationStage = 'insert_appointment';
        $stmt->execute([
            $appointmentParishionerId, $locked['service_id'], $locked['priest_id'],
            $locked['appointment_date'], $check['forcedTime'] ?: $locked['appointment_time'],
            $locked['remarks'], $locked['schedule_type'], $locked['pss_claim'],
            $locked['sponsor_count'], $locked['guest_name'], $locked['guest_email'],
            $locked['guest_phone'], $guestReference, $locked['contact_phone'],
            $locked['location_address'], json_encode(baptismDraftRequiredDocuments($locked))
        ]);
        $appointmentId = (int) $pdo->lastInsertId();
        $baptismFinalizationStage = 'transfer_documents';
        $pdo->prepare('UPDATE uploaded_documents SET appointment_id = ?, baptism_draft_id = NULL WHERE baptism_draft_id = ?')->execute([$appointmentId, $id]);
        $baptismFinalizationStage = 'transfer_generated_forms';
        $pdo->prepare('UPDATE generated_baptism_forms SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $id]);
        $baptismFinalizationStage = 'finalize_draft';
        $pdo->prepare("UPDATE baptism_booking_drafts SET status = 'finalized', finalized_appointment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_id = ?")->execute([$appointmentId, $id]);
        $baptismFinalizationStage = 'commit';
        $pdo->commit();
        redirect($guestReference
            ? url('status.php?ref=' . urlencode($guestReference) . '&contact=' . urlencode((string) ($locked['guest_phone'] ?: $locked['guest_email'])))
            : url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        $rolledBack = false;
        if ($pdo->inTransaction()) { $pdo->rollBack(); $rolledBack = true; }
        $sqlState = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();
        error_log(sprintf(
            'Baptism finalization failed: stage=%s draft_id=%d exception=%s sqlstate=%s transaction_active=%s rolled_back=%s message=%s file=%s line=%d',
            $baptismFinalizationStage,
            $id,
            get_class($e),
            (string) $sqlState,
            $pdo->inTransaction() ? 'yes' : 'no',
            $rolledBack ? 'yes' : 'no',
            preg_replace('/\s+/', ' ', $e->getMessage()),
            $e->getFile(),
            $e->getLine()
        ));
        flash('error', 'The Baptism booking could not be submitted.');
        redirect(url('baptism-draft.php?draft_id=' . $id));
    }
}

$stmt = $pdo->prepare('SELECT requirement_label, document_id, file_name FROM uploaded_documents WHERE baptism_draft_id = ? AND superseded_by IS NULL AND document_source = \'uploaded\' ORDER BY document_id DESC');
$stmt->execute([$id]);
$uploadedDocuments = [];
foreach ($stmt->fetchAll() as $document) {
    if (!isset($uploadedDocuments[$document['requirement_label']])) $uploadedDocuments[$document['requirement_label']] = $document;
}
$stmt = $pdo->prepare('SELECT f.form_type, f.document_id, f.status, d.review_status, d.verified, d.rejection_reason FROM generated_baptism_forms f LEFT JOIN uploaded_documents d ON d.document_id = f.document_id WHERE f.draft_id = ?');
$stmt->execute([$id]);
$forms = [];
foreach ($stmt->fetchAll() as $row) $forms[$row['form_type']] = $row;
$missingRequirements = baptismDraftComplete($pdo, $draft);
$canSubmit = $missingRequirements === [];
$missingFormTypes = array_values(array_filter(
    $missingRequirements,
    static fn(string $requirement): bool => in_array($requirement, BAPTISM_DRAFT_FORMS, true)
));
$missingSupportingDocuments = array_values(array_filter(
    $missingRequirements,
    static fn(string $requirement): bool => !in_array($requirement, BAPTISM_DRAFT_FORMS, true)
));
$pageTitle = 'Baptism Requirements';
$usesPublicShell = !$user;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card"><h2>Supporting Documents</h2>
<?php renderSupportingDocumentCards(baptismDraftAllDocuments($draft), $uploadedDocuments, 'baptism_draft', $id); ?>
</div>
<div class="card"><h2>Baptism Forms</h2>
<?php generatedFormWorkflowGuide(true); ?>
<?php foreach (BAPTISM_DRAFT_FORMS as $type):
  $row = $forms[$type] ?? null;
  $documentId = $row && !empty($row['document_id']) ? (int) $row['document_id'] : 0;
  $state = generatedFormWorkflowState($row, $row, true);
  $title = baptismFormDefinition($type)['title'];
?>
<div class="generated-form-item">
  <div class="generated-form-header">
    <span class="generated-form-title"><?= e($title) ?></span>
    <span class="generated-form-status"><?= $state['code'] === 'approved' ? '✓ ' : ($state['code'] === 'not_started' ? '○ ' : '') ?><?= e($state['label']) ?></span>
    <div class="text-muted" style="margin-top:4px; font-size:.9rem;"><?= e($state['description']) ?></div>
    <?php if (!empty($row['rejection_reason'])): ?><div class="text-muted" style="margin-top:4px; font-size:.9rem;">Secretary's note: <?= e($row['rejection_reason']) ?></div><?php endif; ?>
  </div>
  <div class="generated-form-actions">
    <?php if ($state['code'] !== 'approved'): ?><a class="btn btn-outline btn-sm" href="<?= url('baptism-draft-form.php?draft_id=' . $id . '&form_type=' . urlencode($type)) ?>"><?= e(generatedFormWorkflowActionLabel($state)) ?></a><?php endif; ?>
    <?php if ($documentId): ?>
      <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . $documentId) ?>">View PDF</a>
      <a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . $documentId . '&download=1') ?>">Download PDF</a>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$canSubmit): ?>
  <?php if ($missingFormTypes && !$missingSupportingDocuments): ?>
    <p class="helper-text"><?= count($missingFormTypes) ?> required form<?= count($missingFormTypes) === 1 ? '' : 's' ?> still need<?= count($missingFormTypes) === 1 ? 's' : '' ?> to be generated before you can submit your appointment request.</p>
  <?php else: ?>
    <p class="helper-text">Complete the following before submitting:</p>
  <?php endif; ?>
  <ul class="baptism-submit-blockers">
    <?php foreach ($missingSupportingDocuments as $label): ?><li><?= e($label) ?> — upload required</li><?php endforeach; ?>
    <?php foreach ($missingFormTypes as $type): ?><li><?= e(baptismFormDefinition($type)['title']) ?> — Generate PDF required</li><?php endforeach; ?>
  </ul>
<?php endif; ?>
<form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="submit_appointment"><button class="btn btn-primary" type="submit" <?= $canSubmit ? '' : 'disabled' ?>>Submit Appointment Request</button></form></div>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
