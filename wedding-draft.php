<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/scheduling.php';

$draftId = (int) ($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);
$user = currentUser();
$guestToken = weddingDraftGuestToken($draftId);
$pdo = db();
$draft = weddingDraftLoad($pdo, $draftId, $user, $guestToken);
if (!$draft) { http_response_code(403); exit('Wedding draft not found or access denied.'); }
if ($draft['status'] === 'finalized' && !empty($draft['finalized_appointment_id'])) redirect(url('parishioner/appointment-detail.php?id=' . (int) $draft['finalized_appointment_id']));
if (strtotime($draft['expires_at']) <= time()) exit('This Wedding booking draft has expired. Please start again.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'upload_documents') {
        $storedKeys = [];
        try {
            $pdo->beginTransaction();
            $locked = weddingDraftLoad($pdo, $draftId, $user, $guestToken, true);
            if (!$locked || $locked['status'] !== 'draft' || strtotime($locked['expires_at']) <= time()) throw new RuntimeException('This Wedding booking draft has expired or is no longer available.');
            foreach (weddingDraftRequiredDocuments($locked) as $index => $label) {
                $field = 'req_doc_' . $index;
                if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $validation = validateUploadedFile($_FILES[$field]);
                if (!$validation['valid']) throw new RuntimeException($label . ': ' . documentValidationMessage($validation['reason']));
                $stored = documentStorageMoveUpload($_FILES[$field]['tmp_name'], pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                $storedKeys[] = $stored['key'];
                $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
                $insert->execute([$draftId, basename((string) $_FILES[$field]['name']), $stored['key'], $validation['mime'], $label]);
            }
            $pdo->commit();
            flash('success', 'Wedding supporting documents saved successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($storedKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Draft document cleanup failed.'); } }
            flash('error', $e->getMessage());
        }
        redirect(url('wedding-draft.php?draft_id=' . $draftId));
    }
    $finalizationStage = 'initial_validation';
    $missing = weddingDraftComplete($pdo, $draft);
    if ($missing) { flash('error', 'Please complete: ' . implode(', ', $missing) . '.'); redirect(url('wedding-draft.php?draft_id=' . $draftId)); }
    $finalizationStage = 'begin_transaction';
    $pdo->beginTransaction();
    try {
        $finalizationStage = 'lock_and_authorize_draft';
        $locked = weddingDraftLoad($pdo, $draftId, $user, $guestToken, true);
        if (!$locked || $locked['status'] !== 'draft' || strtotime($locked['expires_at']) <= time()) throw new RuntimeException('Draft is no longer available.');
        $finalizationStage = 'validate_locked_draft';
        $finalizationStage = 'validate_documents';
        $missing = weddingDraftComplete($pdo, $locked);
        if ($missing) throw new RuntimeException('Please complete: ' . implode(', ', $missing) . '.');
        if ($locked['wedding_sponsor_count'] === null || (int) $locked['wedding_sponsor_count'] < 0) throw new RuntimeException('Please provide a valid number of wedding sponsors.');
        $finalizationStage = 'validate_generated_forms';
        $finalizationStage = 'validate_schedule';
        $schedule = validateBooking('Wedding', $locked['appointment_date'], $locked['appointment_time'], null, $locked['schedule_type'], (int) $locked['service_id']);
        if (!$schedule['valid']) throw new RuntimeException($schedule['message']);
        $finalTime = $schedule['forcedTime'] ?: $locked['appointment_time'];
        $guestReference = $locked['parishioner_id'] ? null : generateGuestReference();
        $appointmentParishionerId = $locked['parishioner_id'] ?: guestParishionerId();
        $finalizationStage = 'insert_appointment';
        $stmt = $pdo->prepare("INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, schedule_type, pss_claim, pss_classification, wedding_sponsor_count, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'pending_verification', ?, ?, ?, ?, ?, ?, ?, ?) RETURNING appointment_id");
        $stmt->execute([$appointmentParishionerId, $locked['service_id'], $locked['priest_id'], $locked['appointment_date'], $finalTime, $locked['remarks'], $locked['schedule_type'], $locked['pss_claim'], $locked['wedding_sponsor_count'], $locked['guest_name'], $locked['guest_email'], $locked['guest_phone'], $guestReference, $locked['contact_phone'], $locked['location_address'], json_encode(weddingDraftRequiredDocuments($locked))]);
        $appointmentId = (int) $stmt->fetchColumn();
        if ($appointmentId < 1) throw new RuntimeException('Wedding appointment could not be created.');
        $finalizationStage = 'transfer_supporting_documents';
        $pdo->prepare('UPDATE uploaded_documents SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $draftId]);
        $finalizationStage = 'transfer_generated_documents';
        // Generated PDF metadata is included in uploaded_documents and was
        // transferred by the ownership update above.
        $finalizationStage = 'transfer_generated_forms';
        $pdo->prepare('UPDATE generated_wedding_forms SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $draftId]);
        $finalizationStage = 'finalize_draft';
        $pdo->prepare("UPDATE wedding_booking_drafts SET status = 'finalized', finalized_appointment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_id = ?")->execute([$appointmentId, $draftId]);
        $finalizationStage = 'commit';
        $pdo->commit();
        redirect($guestReference ? url('status.php?ref=' . urlencode($guestReference) . '&contact=' . urlencode((string) ($locked['guest_phone'] ?: $locked['guest_email']))) : url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        $rolledBack = false;
        if ($pdo->inTransaction()) { $pdo->rollBack(); $rolledBack = true; }
        $sqlState = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();
        error_log(sprintf(
            'Wedding finalization failed: stage=%s draft_id=%d exception=%s sqlstate=%s transaction_active=%s rolled_back=%s message=%s',
            $finalizationStage,
            $draftId,
            get_class($e),
            (string) $sqlState,
            $pdo->inTransaction() ? 'yes' : 'no',
            $rolledBack ? 'yes' : 'no',
            preg_replace('/\s+/', ' ', $e->getMessage())
        ));
        flash('error', 'The Wedding booking could not be submitted.'); redirect(url('wedding-draft.php?draft_id=' . $draftId));
    }
}

$docs = $pdo->prepare('SELECT requirement_label FROM uploaded_documents WHERE draft_id = ? AND superseded_by IS NULL');
$docs->execute([$draftId]);
$uploaded = array_unique($docs->fetchAll(PDO::FETCH_COLUMN));
$forms = $pdo->prepare('SELECT form_type, status, document_id FROM generated_wedding_forms WHERE draft_id = ?');
$forms->execute([$draftId]);
$formRows = [];
foreach ($forms->fetchAll() as $row) $formRows[$row['form_type']] = $row;
$requiredDocumentsComplete = count(array_intersect(weddingDraftRequiredDocuments($draft), $uploaded)) === count(weddingDraftRequiredDocuments($draft));
$generatedFormsComplete = true;
foreach (WEDDING_DRAFT_FORMS as $requiredForm) {
    if (empty($formRows[$requiredForm]['document_id'])) { $generatedFormsComplete = false; break; }
}
$canSubmit = $requiredDocumentsComplete && $generatedFormsComplete;
$pageTitle = 'Wedding Requirements';
$usesPublicShell = !$user;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card" style="max-width:850px;margin:auto;"><h2>Supporting Documents</h2><form method="POST" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="upload_documents"><?php foreach (weddingDraftRequiredDocuments($draft) as $index => $label): if (in_array($label, $uploaded, true)): ?><p style="margin:8px 0;">✓ <?= e($label) ?> — <strong>Uploaded</strong></p><?php else: ?><p style="margin:8px 0;">○ <?= e($label) ?> — <strong>Required</strong><br><input type="file" name="req_doc_<?= $index ?>" accept=".pdf,.jpg,.jpeg,.png" required></p><?php endif; endforeach; ?><button class="btn btn-secondary" type="submit">Save Documents</button></form></div>
<div class="card" style="max-width:850px;margin:auto;">
  <h2>Wedding Forms</h2>
  <?php foreach (WEDDING_DRAFT_FORMS as $type):
    $row = $formRows[$type] ?? null;
    $title = weddingFormDefinition($type)['title'];
    $documentId = $row && !empty($row['document_id']) ? (int) $row['document_id'] : 0;
    $editLabel = $type === 'matrimony_application'
        ? ($row ? 'Edit Form' : 'Fill Out Form')
        : ($row ? 'Edit Form' : 'Complete Form');
  ?>
    <div class="generated-form-item">
      <div class="generated-form-header">
        <span class="generated-form-title"><?= e($title) ?></span>
        <span class="generated-form-status"><?= $documentId ? '✓ Generated' : ($row ? '○ Draft' : '○ Not completed') ?></span>
      </div>
      <div class="generated-form-actions">
        <a class="btn btn-outline btn-sm" href="<?= url('wedding-draft-form.php?draft_id=' . $draftId . '&form_type=' . urlencode($type)) ?>"><?= e($editLabel) ?></a>
        <?php if ($documentId): ?>
          <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . $documentId) ?>"><?= $type === 'matrimony_application' ? 'View Generated Form' : 'View PDF' ?></a>
          <a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . $documentId . '&download=1') ?>">Download PDF</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <form method="POST">
    <?= csrfField() ?>
    <?php if (!$canSubmit): ?><p class="helper-text">Save all required documents and complete all Wedding forms before submitting the appointment request.</p><?php endif; ?>
    <button class="btn btn-primary" type="submit" <?= $canSubmit ? '' : 'disabled' ?>>Submit Appointment Request</button>
  </form>
</div>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
