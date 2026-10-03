<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';

$id = (int) ($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);
$appointmentId = (int) ($_GET['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
$isAppointmentForm = $appointmentId > 0;
$type = (string) ($_GET['form_type'] ?? $_POST['form_type'] ?? '');
if (!in_array($type, BAPTISM_DRAFT_FORMS, true)) { http_response_code(400); exit('Invalid form.'); }
$user = currentUser();
$pdo = db();
$guest = $_SESSION['guest_status_verification'] ?? null;
$isGuest = !$user && is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();

if ($isAppointmentForm) {
    if ($user && ($user['role_name'] ?? '') !== 'Parishioner') { http_response_code(403); exit('Only the appointment owner may edit this form.'); }
    $aq = $pdo->prepare("SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ? AND s.category = 'Baptism'");
    $aq->execute([$appointmentId]);
    $appointment = $aq->fetch();
    if (!$appointment) { http_response_code(404); exit('Appointment not found.'); }
    if ($user) {
        $ownerQuery = $pdo->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
        $ownerQuery->execute([$appointment['parishioner_id'], $user['user_id']]);
        if (!$ownerQuery->fetchColumn()) { http_response_code(403); exit('Not authorized (28).'); }
    } elseif (!$isGuest) {
        http_response_code(403); exit('Verify the guest appointment before accessing this form (30).');
    }
    $draft = [
        'contact_phone' => (string) (($appointment['contact_phone'] ?? '') ?: ($appointment['guest_phone'] ?? '')),
        'appointment_date' => (string) ($appointment['appointment_date'] ?? ''),
    ];
    $q = $pdo->prepare('SELECT * FROM generated_baptism_forms WHERE appointment_id = ? AND form_type = ?');
    $q->execute([$appointmentId, $type]);
} else {
    $draft = baptismDraftLoad($pdo, $id, $user, baptismDraftToken($id));
    if (!$draft) { http_response_code(403); exit('Not authorized (40). id: ' . $id . ' user: ' . ($user ? $user['role_name'] : 'none')); }
    if (($draft['status'] ?? '') !== 'draft' || strtotime((string) $draft['expires_at']) <= time()) {
        http_response_code(410);
        exit('This Baptism booking draft has expired or is no longer editable.');
    }
    $q = $pdo->prepare('SELECT * FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ?');
    $q->execute([$id, $type]);
}
$form = $q->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
$data = baptismNormalizeData($data);
$error = null;

if ($type === 'katin_awan_bunyag' && !$form) {
    $contactPhone = trim((string) ($draft['contact_phone'] ?? ''));
    if (!$contactPhone && $user && ($user['role_name'] ?? '') === 'Parishioner') {
        $pq = $pdo->prepare('SELECT phone FROM parishioners WHERE user_id = ?');
        $pq->execute([$user['user_id']]);
        $pPhone = $pq->fetchColumn();
        if ($pPhone) $contactPhone = trim((string) $pPhone);
    }
    $data = [
        'cellphone' => $contactPhone
    ];
} elseif ($type === 'cluster_clearance_baptism_sponsor' && !$form) {
    $kq = $pdo->prepare($isAppointmentForm
        ? 'SELECT form_data FROM generated_baptism_forms WHERE appointment_id = ? AND form_type = ?'
        : 'SELECT form_data FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ?');
    $kq->execute([$isAppointmentForm ? $appointmentId : $id, 'katin_awan_bunyag']);
    $katin = $kq->fetchColumn();
    if ($katin) {
        $kData = json_decode($katin, true) ?: [];
        $data['child_name'] = $kData['child_name'] ?? '';
        $data['father_name'] = $kData['father_name'] ?? '';
    }
    $data['service_date'] = (string) ($draft['appointment_date'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? 'save') === 'generate' ? 'generate' : 'save';
    foreach (baptismFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $data = baptismNormalizeData($data);
    $validationErrors = baptismFormValidationErrors($type, $data, $action === 'generate');
    if ($validationErrors) $error = implode(' ', $validationErrors);

    if (!$error) {
        $stored = null;
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pdo->beginTransaction();
            $lockedQuery = $pdo->prepare($isAppointmentForm
                ? 'SELECT * FROM generated_baptism_forms WHERE appointment_id = ? AND form_type = ? FOR UPDATE'
                : 'SELECT * FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ? FOR UPDATE');
            $lockedQuery->execute([$isAppointmentForm ? $appointmentId : $id, $type]);
            $form = $lockedQuery->fetch() ?: $form;

            if ($isAppointmentForm && $form && ($form['status'] ?? '') === 'approved') {
                throw new RuntimeException('Approved forms require Secretary review before they can be changed.');
            }

            if ($action === 'save') {
                if ($form) {
                    $pdo->prepare("UPDATE generated_baptism_forms SET form_data = ?, status = 'draft', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                        ->execute([$json, $form['generated_form_id']]);
                } else {
                    $pdo->prepare("INSERT INTO generated_baptism_forms (appointment_id, draft_id, form_type, form_data, document_id, status) VALUES (?, ?, ?, ?, NULL, 'draft')")
                        ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $type, $json]);
                }
                $pdo->commit();
                flash('success', 'Baptism form draft saved. You can continue editing it from Baptism Requirements.');
                $contextQuery = $isAppointmentForm ? 'appointment_id=' . $appointmentId : 'draft_id=' . $id;
                redirect(url('baptism-draft-form.php?' . $contextQuery . '&form_type=' . urlencode($type)));
            }

            $pdf = baptismFormPdf($type, $data);
            $stored = documentStorageWriteBytes($pdf);
            $label = baptismFormDefinition($type)['title'] . '.pdf';
            $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, baptism_draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (?, NULL, ?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)")
                ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $label, $stored['key'], $label, $type]);
            $newId = (int) $pdo->lastInsertId();
            if ($form && !empty($form['document_id'])) {
                $supersede = $pdo->prepare($isAppointmentForm
                    ? 'UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL'
                    : 'UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND baptism_draft_id = ? AND superseded_by IS NULL');
                $supersede->execute([$newId, $form['document_id'], $isAppointmentForm ? $appointmentId : $id]);
            }
            if ($form) {
                $pdo->prepare("UPDATE generated_baptism_forms SET form_data = ?, document_id = ?, status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                    ->execute([$json, $newId, $form['generated_form_id']]);
            } else {
                $pdo->prepare("INSERT INTO generated_baptism_forms (appointment_id, draft_id, form_type, form_data, document_id, status) VALUES (?, ?, ?, ?, ?, 'pending_review')")
                    ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $type, $json, $newId]);
            }
            $pdo->commit();
            flash('success', 'Baptism form generated and submitted for review.');
            $contextQuery = $isAppointmentForm ? 'appointment_id=' . $appointmentId : 'draft_id=' . $id;
            redirect(url('baptism-draft-form.php?' . $contextQuery . '&form_type=' . urlencode($type) . '&generated_document_id=' . $newId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($stored) { try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Baptism generated-document cleanup failed.'); } }
            error_log('Baptism form save/generate failed: ' . $e->getMessage());
            $error = $e->getMessage() === 'Approved forms require Secretary review before they can be changed.'
                ? $e->getMessage()
                : ($action === 'generate' ? 'The Baptism form could not be generated.' : 'The Baptism form draft could not be saved.');
        }
    }
}

$def = baptismFormDefinition($type);
$previewDocumentId = 0;
if ($form && !empty($form['document_id'])) {
    $requestedPreviewId = (int) ($_GET['generated_document_id'] ?? 0);
    $previewDocumentId = $requestedPreviewId === (int) $form['document_id'] ? $requestedPreviewId : 0;
}
$pageTitle = $def['title'];
$usesPublicShell = !$user;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card">
  <h2><?= e($def['title']) ?></h2>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($previewDocumentId): ?><div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">The form was generated. If the PDF did not open automatically, <a href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.</div><?php endif; ?>
  <form method="POST" id="baptismGeneratedForm">
    <?= csrfField() ?>
    <?php if ($isAppointmentForm): ?>
      <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <?php else: ?>
      <input type="hidden" name="draft_id" value="<?= $id ?>">
    <?php endif; ?>
    <input type="hidden" name="form_type" value="<?= e($type) ?>">
    <?php if ($type === 'katin_awan_bunyag'): ?>
      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">CHILD INFORMATION</legend>
        <div class="form-group"><label for="child_name">Ngalan sa Bunyagan *</label><input id="child_name" name="child_name" type="text" value="<?= e($data['child_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
            <div class="form-group"><label for="birth_date">Petsa Natawo *</label><input id="birth_date" name="birth_date" type="date" value="<?= e($data['birth_date'] ?? '') ?>" required></div>
            <div class="form-group"><label for="birth_place">Diin Natawo *</label><input id="birth_place" name="birth_place" type="text" value="<?= e($data['birth_place'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">PARENTS</legend>
        <div class="form-row">
            <div class="form-group"><label for="father_name">Amahan *</label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="father_religion">Relihiyon *</label><input id="father_religion" name="father_religion" type="text" value="<?= e($data['father_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="mother_name">Inahan *</label><input id="mother_name" name="mother_name" type="text" value="<?= e($data['mother_name'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="mother_religion">Relihiyon *</label><input id="mother_religion" name="mother_religion" type="text" value="<?= e($data['mother_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">PARENTS' MARRIAGE</legend>
        <div class="form-group">
            <label>Unsang Kasala ang Nadawat sa Ginikanan? *</label>
            <div class="form-row" role="group" aria-label="Unsang Kasala ang Nadawat sa Ginikanan?">
                <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?>
                    <label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?> required> <?= e($option) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group"><label for="marriage_place">Diin <span data-marriage-required>*</span></label><input id="marriage_place" name="marriage_place" type="text" value="<?= e($data['marriage_place'] ?? '') ?>" maxlength="150"></div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">SPONSORS</legend>
        <div class="form-group"><label for="sponsor_1">Sponsor 1 *</label><input id="sponsor_1" name="sponsor_1" type="text" value="<?= e($data['sponsor_1'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-group"><label for="sponsor_2">Sponsor 2 *</label><input id="sponsor_2" name="sponsor_2" type="text" value="<?= e($data['sponsor_2'] ?? '') ?>" maxlength="150" required></div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">LOCATION / CONTACT</legend>
        <div class="form-row">
            <div class="form-group"><label for="chapel">Sakop sa Kapilya sa *</label><input id="chapel" name="chapel" type="text" value="<?= e($data['chapel'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="cluster_name">Ngalan sa Cluster *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="barangay">Ngalan sa Barangay *</label><input id="barangay" name="barangay" type="text" value="<?= e($data['barangay'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="cellphone">Cellphone Number *</label><input id="cellphone" name="cellphone" type="tel" value="<?= e($data['cellphone'] ?? '') ?>" maxlength="11" pattern="^09\d{9}$" inputmode="numeric" title="Must be a valid 11-digit mobile number starting with 09" required></div>
        </div>
      </fieldset>
    <?php elseif ($type === 'cluster_clearance_baptism_sponsor'): ?>
      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">SPONSOR INFORMATION</legend>
        <div class="form-group"><label for="sponsor_name">Name of Sponsor *</label><input id="sponsor_name" name="sponsor_name" type="text" value="<?= e($data['sponsor_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-group"><label for="address">Pinuy-anan (Address) *</label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">CHILD INFORMATION</legend>
        <div class="form-group"><label for="child_name">Name of the Child *</label><input id="child_name" name="child_name" type="text" value="<?= e($data['child_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
            <div class="form-group"><label for="father_name">Father *</label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="mother_maiden_name">Mother Maiden Name *</label><input id="mother_maiden_name" name="mother_maiden_name" type="text" value="<?= e($data['mother_maiden_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">SERVICE INFORMATION</legend>
        <div class="form-row">
            <div class="form-group"><label for="service_date">Date of Service / Adlaw sa Serbisyo *</label><input id="service_date" name="service_date" type="date" value="<?= e($data['service_date'] ?? '') ?>" required></div>
            <div class="form-group"><label>Service Requested</label><input type="text" value="Bunyag" disabled style="background:#eee; cursor:not-allowed;"></div>
        </div>
      </fieldset>

      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">CLUSTER INFORMATION</legend>
        <div class="form-row">
            <div class="form-group"><label for="cluster_number">Member of Cluster No. *</label><input id="cluster_number" name="cluster_number" type="number" min="1" max="9999" step="1" value="<?= e($data['cluster_number'] ?? '') ?>" required></div>
            <div class="form-group"><label for="cluster_name">Cluster Name *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </fieldset>
    <?php else: ?>
    <?php foreach ($def['fields'] as $key => $label): ?>
      <div class="form-group">
        <label><?= e($label) ?></label>
        <?php if ($key === 'parent_marriage'): ?>
          <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?>><?= e($option) ?></label><?php endforeach; ?>
        <?php elseif ($key === 'service_requested'): ?>
          <select name="<?= e($key) ?>"><option value="">Select</option><?php foreach (['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? 'Bunyag') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
        <?php elseif ($key === 'active_status'): ?>
          <select name="<?= e($key) ?>"><option value="">Select</option><option value="Active" <?= ($data[$key] ?? '') === 'Active' ? 'selected' : '' ?>>Active</option><option value="Inactive" <?= ($data[$key] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select>
        <?php else: ?>
          <input name="<?= e($key) ?>" value="<?= e($data[$key] ?? '') ?>">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 20px;">
      <button class="btn btn-primary" style="width: 100%;" name="action" value="generate"><?= in_array($type, ['katin_awan_bunyag', 'cluster_clearance_baptism_sponsor']) ? 'Generate Application Form' : 'Generate Form' ?></button>
      <button class="btn btn-outline" style="width: 100%;" name="action" value="save" formnovalidate>Save Draft</button>
    </div>
  </form>
  <?php if (!$isAppointmentForm): ?>
    <p style="margin-top:14px;"><a href="<?= url('baptism-draft.php?draft_id=' . $id) ?>">← Back to Baptism Requirements</a></p>
  <?php endif; ?>
</div>
<script>
(function () {
  var form = document.getElementById('baptismGeneratedForm');
  if (!form) return;
  var choices = form.querySelectorAll('input[name="parent_marriage"]');
  var place = document.getElementById('marriage_place');
  function syncMarriagePlace() {
    if (!choices.length || !place) return;
    var selected = form.querySelector('input[name="parent_marriage"]:checked');
    var required = !!selected && selected.value !== 'Wala';
    place.required = required;
    place.disabled = !!selected && selected.value === 'Wala';
    form.querySelectorAll('[data-marriage-required]').forEach(function (marker) { marker.style.display = required ? '' : 'none'; });
  }
  choices.forEach(function (choice) { choice.addEventListener('change', syncMarriagePlace); });
  syncMarriagePlace();
  form.addEventListener('submit', function (event) {
    if (!event.submitter || event.submitter.value !== 'generate' || !form.checkValidity()) return;
    window.open('about:blank', 'parishhubBaptismPdf');
  });
  <?php if ($previewDocumentId): ?>window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubBaptismPdf');<?php endif; ?>
}());
</script>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
