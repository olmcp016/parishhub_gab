<?php
if (isset($_REQUEST['draft_id'])) {
    require __DIR__ . '/wedding-draft-form.php';
    exit;
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';

$appointmentId = (int) ($_REQUEST['appointment_id'] ?? 0);
$type = (string) ($_REQUEST['form_type'] ?? '');
if (!in_array($type, WEDDING_FORM_TYPES, true)) { http_response_code(400); exit('Invalid form.'); }

$user = currentUser();
$guest = $_SESSION['guest_status_verification'] ?? null;
$isGuest = !$user && is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();
if ($user && !in_array($user['role_name'] ?? '', ['Parishioner'], true)) { http_response_code(403); exit('Only the appointment owner may edit this form.'); }

$stmt = db()->prepare("SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ? AND s.category = 'Wedding'");
$stmt->execute([$appointmentId]);
$appointment = $stmt->fetch();
if (!$appointment) { http_response_code(404); exit('Appointment not found.'); }
if ($user) {
    $q = db()->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
    $q->execute([$appointment['parishioner_id'], $user['user_id']]);
    if (!$q->fetchColumn()) { http_response_code(403); exit('Not authorized.'); }
} elseif (!$isGuest) { http_response_code(403); exit('Verify the guest appointment before accessing this form.'); }

$formQuery = db()->prepare('SELECT * FROM generated_wedding_forms WHERE appointment_id = ? AND form_type = ?');
$formQuery->execute([$appointmentId, $type]);
$form = $formQuery->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? '') === 'generate' ? 'generate' : 'save';
    $data = [];
    foreach (weddingFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($type === 'wedding_sponsor_clearance') {
        $data['service_requested'] = 'Kasal';
    }
    if ($action === 'generate') {
        $missing = [];
        foreach (weddingFormRequiredFields($type) as $key) {
            if (($data[$key] ?? '') === '') { $missing[] = weddingFormDefinition($type)['fields'][$key]; }
        }
        if ($missing) {
            $error = 'Complete these fields before generating: ' . implode(', ', $missing) . '.';
        }
    }
    if (!$error) {
        $pdo = db();
        $newPath = null;
        $stored = null;
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM generated_wedding_forms WHERE appointment_id = ? AND form_type = ? FOR UPDATE');
            $lock->execute([$appointmentId, $type]);
            $form = $lock->fetch() ?: null;
            $documentId = $form['document_id'] ?? null;
            $status = 'draft';
            if ($action === 'generate') {
                if ($form && $form['status'] === 'approved') {
                    throw new RuntimeException('Approved forms require Secretary review before they can be changed.');
                }
                $pdf = weddingFormPdf($type, $data);
                $stored = documentStorageWriteBytes($pdf);
                $newPath = $stored['path'];
                $label = weddingFormDefinition($type)['title'] . '.pdf';
                $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)");
                $insert->execute([$appointmentId, $label, $stored['key'], $label, $type]);
                $newDocumentId = (int) $pdo->lastInsertId();
                if ($documentId) {
                    $pdo->prepare("UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL")->execute([$newDocumentId, $documentId, $appointmentId]);
                }
                $documentId = $newDocumentId;
                $status = 'pending_review';
            }
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($form) {
                $pdo->prepare('UPDATE generated_wedding_forms SET form_data = ?, document_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?')->execute([$encoded, $documentId, $status, $form['generated_form_id']]);
            } else {
                $pdo->prepare('INSERT INTO generated_wedding_forms (appointment_id, form_type, form_data, document_id, status) VALUES (?, ?, ?, ?, ?)')->execute([$appointmentId, $type, $encoded, $documentId, $status]);
            }
            $pdo->commit();
            flash('success', $action === 'generate' ? 'Form generated and submitted for review.' : 'Draft saved.');
            redirect(url('wedding-form.php?appointment_id=' . $appointmentId . '&form_type=' . urlencode($type)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($newPath && is_file($newPath)) { @unlink($newPath); }
            if ($stored && str_starts_with($stored['key'] ?? '', 'supabase://')) { try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
            error_log($e->getMessage());
            $error = $e->getMessage() === 'Approved forms require Secretary review before they can be changed.' ? $e->getMessage() : 'The form could not be saved. Please try again.';
        }
    }
}

$def = weddingFormDefinition($type);
$pageTitle = $def['title'];
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/dash-start.php';
?>
<div class="card" style="max-width:900px; margin:auto;">
  <h2><?= e($def['title']) ?></h2>
  <p class="text-muted">Complete the form, save a draft, or generate a printable unsigned PDF. Physical signature areas remain blank.</p>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2;"><?= e($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <input type="hidden" name="form_type" value="<?= e($type) ?>">
    <?php foreach ($def['fields'] as $key => $label): ?>
      <?php $inputType = in_array($key, ['date_applied', 'groom_birth_date', 'bride_birth_date', 'kaslonon_birth_date', 'marriage_date', 'service_date'], true) ? 'date' : 'text'; ?>
      <div class="form-group"><label for="<?= e($key) ?>"><?= e($label) ?><?php if (in_array($key, weddingFormRequiredFields($type), true)): ?> *<?php endif; ?></label>
        <?php if ($key === 'parent_marriage'): ?>
          <select id="<?= e($key) ?>" name="<?= e($key) ?>" required><option value="">Select</option><?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
        <?php elseif ($key === 'active_status'): ?>
          <select id="<?= e($key) ?>" name="<?= e($key) ?>" required><option value="">Select</option><?php foreach (['Active', 'Inactive'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
        <?php elseif ($key === 'service_requested'): ?>
          <select id="<?= e($key) ?>" name="<?= e($key) ?>" required><option value="Kasal" selected>Kasal</option></select>
        <?php else: ?>
          <input id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($data[$key] ?? '') ?>" type="<?= $inputType ?>" <?= in_array($key, weddingFormRequiredFields($type), true) ? 'required' : '' ?>>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($type === 'wedding_sponsor_clearance'): ?><p class="text-muted">Service Requested: Kasal (the other official choices remain printed on the generated form).</p><?php endif; ?>
    <button class="btn btn-outline" name="action" value="save">Save Draft</button>
    <button class="btn btn-primary" name="action" value="generate">Generate Form</button>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php';
