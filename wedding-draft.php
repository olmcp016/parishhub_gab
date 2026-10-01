<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/scheduling.php';

$draftId = (int) ($_REQUEST['draft_id'] ?? 0);
$user = currentUser(); $guestToken = weddingDraftGuestToken($draftId);
$pdo = db(); $draft = weddingDraftLoad($pdo, $draftId, $user, $guestToken);
if (!$draft) { http_response_code(403); exit('Wedding draft not found or access denied.'); }
if ($draft['status'] === 'finalized' && !empty($draft['finalized_appointment_id'])) { redirect(url('parishioner/appointment-detail.php?id=' . (int) $draft['finalized_appointment_id'])); }
if (strtotime($draft['expires_at']) <= time()) { exit('This Wedding booking draft has expired. Please start again.'); }

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
                $insert->execute([$draftId, basename((string) $_FILES[$field]['name']), $stored['reference'], $validation['mime'], $label]);
            }
            $pdo->commit();
            flash('success', 'Wedding documents saved to your booking draft.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($storedKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Draft document cleanup failed.'); } }
            flash('error', $e->getMessage());
        }
        redirect(url('wedding-draft.php?draft_id=' . $draftId));
    }
    $missing = weddingDraftComplete($pdo, $draft);
    if ($missing) { flash('error', 'Please complete: ' . implode(', ', $missing) . '.'); redirect(url('wedding-draft.php?draft_id=' . $draftId)); }
    $pdo->beginTransaction();
    try {
        $locked = weddingDraftLoad($pdo, $draftId, $user, $guestToken, true);
        if (!$locked || $locked['status'] !== 'draft' || strtotime($locked['expires_at']) <= time()) throw new RuntimeException('Draft is no longer available.');
        $missing = weddingDraftComplete($pdo, $locked);
        if ($missing) throw new RuntimeException('Please complete: ' . implode(', ', $missing) . '.');
        if ($locked['wedding_sponsor_count'] === null || (int) $locked['wedding_sponsor_count'] < 0) throw new RuntimeException('Please provide a valid number of wedding sponsors.');
        $schedule = validateBooking('Wedding', $locked['appointment_date'], $locked['appointment_time'], null, $locked['schedule_type'], (int) $locked['service_id']);
        if (!$schedule['valid']) throw new RuntimeException($schedule['message']);
        $finalTime = $schedule['forcedTime'] ?: $locked['appointment_time'];
        $guestReference = $locked['parishioner_id'] ? null : generateGuestReference();
        $stmt = $pdo->prepare("INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, schedule_type, pss_claim, pss_classification, wedding_sponsor_count, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'pending_verification', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$locked['parishioner_id'], $locked['service_id'], $locked['priest_id'], $locked['appointment_date'], $finalTime, $locked['remarks'], $locked['schedule_type'], $locked['pss_claim'], $locked['wedding_sponsor_count'], $locked['guest_name'], $locked['guest_email'], $locked['guest_phone'], $guestReference, $locked['contact_phone'], $locked['location_address'], json_encode(weddingDraftRequiredDocuments($locked))]);
        $appointmentId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE uploaded_documents SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $draftId]);
        $pdo->prepare('UPDATE generated_wedding_forms SET appointment_id = ?, draft_id = NULL WHERE draft_id = ?')->execute([$appointmentId, $draftId]);
        $pdo->prepare("UPDATE wedding_booking_drafts SET status = 'finalized', finalized_appointment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_id = ?")->execute([$appointmentId, $draftId]);
        $pdo->commit();
        redirect($guestReference ? url('status.php?ref=' . urlencode($guestReference) . '&contact=' . urlencode((string) ($locked['guest_phone'] ?: $locked['guest_email']))) : url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); error_log($e->getMessage()); flash('error', 'The Wedding booking could not be submitted.'); redirect(url('wedding-draft.php?draft_id=' . $draftId));
    }
}
$docs = $pdo->prepare('SELECT requirement_label FROM uploaded_documents WHERE draft_id = ? AND superseded_by IS NULL'); $docs->execute([$draftId]); $uploaded = array_unique($docs->fetchAll(PDO::FETCH_COLUMN));
$forms = $pdo->prepare('SELECT form_type, status FROM generated_wedding_forms WHERE draft_id = ?'); $forms->execute([$draftId]); $formRows = []; foreach ($forms->fetchAll() as $row) $formRows[$row['form_type']] = $row;
$pageTitle = 'Wedding Requirements'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/dash-start.php';
?><div class="card" style="max-width:850px;margin:auto;"><h2>Supporting Documents</h2><form method="POST" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="upload_documents"><?php foreach (weddingDraftRequiredDocuments($draft) as $index => $label): if (!in_array($label, $uploaded, true)): ?><p><?= e($label) ?> <input type="file" name="req_doc_<?= $index ?>" accept=".pdf,.jpg,.jpeg,.png" required></p><?php endif; endforeach; ?><button class="btn btn-secondary" type="submit">Save Documents</button></form></div><?php
?><div class="card" style="max-width:850px;margin:auto;"><h2>Wedding Requirements</h2><h3>Supporting Documents</h3><?php foreach (weddingDraftRequiredDocuments($draft) as $label): ?><p><?= in_array($label, $uploaded, true) ? '✓' : '○' ?> <?= e($label) ?> — <?= in_array($label, $uploaded, true) ? 'Uploaded' : 'Required' ?></p><?php endforeach; ?><h3>Wedding Forms</h3><?php foreach (WEDDING_DRAFT_FORMS as $type): $row=$formRows[$type]??null; $title=weddingFormDefinition($type)['title']; ?><p><?= $row ? '✓' : '○' ?> <?= e($title) ?> — <?= $row ? 'Completed' : 'Not completed' ?> <a class="btn btn-outline btn-sm" href="<?= url('wedding-draft-form.php?draft_id=' . $draftId . '&form_type=' . urlencode($type)) ?>"><?= $row ? 'Edit Form' : 'Complete Form' ?></a></p><?php endforeach; ?><form method="POST"><?= csrfField() ?><button class="btn btn-primary" type="submit">Submit Appointment Request</button></form></div><?php include __DIR__ . '/includes/footer.php';
