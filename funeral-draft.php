<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/supporting-documents.php';
require_once __DIR__ . '/includes/funeral-draft.php';
require_once __DIR__ . '/includes/funeral-forms.php';
require_once __DIR__ . '/includes/generated-form-workflow.php';

$draftId = (int) ($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);
$user = currentUser();
$pdo = db();
$draft = funeralDraftLoad($draftId, $user);

if (!$draft) {
    http_response_code(403);
    exit('Funeral booking draft not found, expired, or access denied. Please start again.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_documents') {
    verifyCsrf();
    $storedKeys = [];
    try {
        $existingLabels = funeralDraftUploadedLabels($draft);
        foreach (funeralDraftRequiredDocuments() as $index => $label) {
            if (in_array($label, $existingLabels, true)) continue;
            $field = 'req_doc_' . $index;
            $file = $_FILES[$field] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $validation = validateUploadedFile($file);
            if (!$validation['valid']) throw new RuntimeException($label . ': ' . documentValidationMessage($validation['reason']));
            $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedKeys[] = $stored['key'];
            $_SESSION['funeral_booking_drafts'][$draftId]['uploaded_keys'][] = [
                'file_name' => basename((string) $file['name']),
                'key' => $stored['key'],
                'mime' => $stored['mime'],
                'label' => $label
            ];
        }
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    } catch (Throwable $e) {
        foreach ($storedKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Draft document cleanup failed.'); } }
        flash('error', $e->getMessage());
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_appointment') {
    verifyCsrf();
    $missing = funeralDraftMissingRequirements($draft);
    if ($missing) {
        flash('error', 'Please complete: ' . implode(', ', $missing) . '.');
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    }
    
    $formErrors = funeralKatinAwanValidationErrors($draft['katin_awan_payload']);
    if ($formErrors) {
        flash('error', implode(' ', $formErrors));
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    }

    try {
        $appointmentParishionerId = !empty($draft['is_guest']) ? guestParishionerId() : (int) ($draft['parishioner_id'] ?? 0);
        if ($appointmentParishionerId < 1) throw new RuntimeException('Appointment owner could not be resolved.');
        $bookingReference = generateGuestReference();

        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death, pss_claim, pss_classification, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING appointment_id"
        );
        $stmt->execute([
            $appointmentParishionerId,
            $draft['service_id'],
            $draft['priest_id'],
            $draft['date'],
            $draft['finalTime'],
            $draft['remarks'],
            $draft['dateOfDeath'] ?? null,
            $draft['pssClaim'],
            $draft['pssClassification'],
            $draft['guest_name'],
            $draft['guest_email'],
            $draft['guest_phone'],
            $bookingReference,
            $draft['contactPhone'],
            $draft['locationAddress'],
            $draft['requirementsSnapshot']
        ]);
        $appointmentId = (int) $stmt->fetchColumn();
        if ($appointmentId < 1) throw new RuntimeException('Funeral appointment could not be created.');

        foreach ($draft['uploaded_keys'] as $upl) {
            $stmt = $pdo->prepare(
                "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')"
            );
            $stmt->execute([$appointmentId, $upl['file_name'], $upl['key'], $upl['mime'], $upl['label']]);
        }

        processFuneralGeneratedForm($appointmentId, 'katin_awan_paglubong', $draft['katin_awan_payload']);

        $pdo->commit();

        funeralDraftDeletePreview($draft);
        unset($_SESSION['funeral_booking_drafts'][$draftId]);
        unset($_SESSION['funeral_draft_tokens'][$draftId]);
        redirect(!empty($draft['is_guest'])
            ? url('status.php?ref=' . urlencode($bookingReference) . '&contact=' . urlencode((string) (($draft['guest_phone'] ?? '') ?: ($draft['guest_email'] ?? ''))))
            : url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Funeral finalization failed: ' . $e->getMessage());
        flash('error', 'Unable to submit Funeral appointment request. Please try again.');
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    }
}

$uploadedLabels = funeralDraftUploadedLabels($draft);
$uploadedDocuments = [];
foreach (array_filter($draft['uploaded_keys'] ?? [], 'is_array') as $document) {
    if (!isset($uploadedDocuments[$document['label'] ?? ''])) $uploadedDocuments[$document['label'] ?? ''] = $document;
}
$hasKatinAwan = funeralDraftHasGeneratedForm($draft);
$funeralFormState = generatedFormWorkflowState(
    $hasKatinAwan ? ['status' => 'generated', '_has_document' => true] : null,
    null,
    true
);
$missingRequirements = funeralDraftMissingRequirements($draft);
$canSubmit = $missingRequirements === [];

$pageTitle = 'Funeral Requirements';
$usesPublicShell = !empty($draft['is_guest']);
$publicNavActive = 'services';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card"><h2>Supporting Documents</h2>
<?php renderSupportingDocumentCards(funeralDraftRequiredDocuments(), $uploadedDocuments, 'funeral_draft', $draftId); ?>
</div>
<div class="card"><h2>Funeral Forms</h2>
<div class="generated-form-item">
  <div class="generated-form-header">
    <span class="generated-form-title">Katin-awan sa Paglubong</span>
    <span class="generated-form-status"><?= $funeralFormState['code'] === 'generated' ? '✓ ' : '○ ' ?><?= e($funeralFormState['label']) ?></span>
    <div class="text-muted" style="margin-top:4px; font-size:.9rem;"><?= e($funeralFormState['description']) ?></div>
  </div>
  <div class="generated-form-actions">
    <a class="btn btn-outline btn-sm" href="<?= url('funeral-form.php?draft_id=' . $draftId) ?>"><?= e(generatedFormWorkflowActionLabel($funeralFormState)) ?></a>
    <?php if ($hasKatinAwan): ?>
      <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?funeral_draft_id=' . $draftId) ?>">View PDF</a>
      <a class="btn btn-outline btn-sm" href="<?= url('document.php?funeral_draft_id=' . $draftId . '&download=1') ?>">Download PDF</a>
    <?php endif; ?>
  </div>
</div>
<?php if (!$canSubmit): ?><p class="helper-text">Upload the required Death Certificate and generate the Funeral form before submitting.</p><?php endif; ?>
<form method="POST"><?= csrfField() ?><input type="hidden" name="draft_id" value="<?= $draftId ?>"><input type="hidden" name="action" value="submit_appointment"><button class="btn btn-primary" type="submit" <?= $canSubmit ? '' : 'disabled' ?>>Submit Appointment Request</button></form></div>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
