<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/generated-form-workflow.php';
require_once __DIR__ . '/includes/requirements-review.php';

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
    if ((int) ($appointment['status_id'] ?? 0) === 3) { http_response_code(409); exit('This appointment was rejected as a whole and must be rebooked.'); }
    if ($user) {
        $ownerQuery = $pdo->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
        $ownerQuery->execute([$appointment['parishioner_id'], $user['user_id']]);
        if (!$ownerQuery->fetchColumn()) { http_response_code(403); exit('Not authorized (28).'); }
    } elseif (!$isGuest) {
        http_response_code(403); exit('Verify the guest appointment before accessing this form (30).');
    }
    if ($isGuest) refreshGuestBookingAccess($appointmentId);
    $draft = [
        'contact_phone' => (string) (($appointment['contact_phone'] ?? '') ?: ($appointment['guest_phone'] ?? '')),
        'appointment_date' => (string) ($appointment['appointment_date'] ?? ''),
        'location_address' => (string) ($appointment['location_address'] ?? ''),
    ];
    $q = $pdo->prepare('SELECT g.*, d.review_status, d.verified, d.rejection_reason FROM generated_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.service_category = \'Baptism\' AND g.appointment_id = ? AND g.form_type = ?');
    $q->execute([$appointmentId, $type]);
} else {
    $draft = baptismDraftLoad($pdo, $id, $user, baptismDraftToken($id));
    if (!$draft) { http_response_code(403); exit('Not authorized (40). id: ' . $id . ' user: ' . ($user ? $user['role_name'] : 'none')); }
    if (($draft['status'] ?? '') !== 'draft' || strtotime((string) $draft['expires_at']) <= time()) {
        http_response_code(410);
        exit('This Baptism booking draft has expired or is no longer editable.');
    }
    $q = $pdo->prepare('SELECT g.*, d.review_status, d.verified, d.rejection_reason FROM generated_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.service_category = \'Baptism\' AND g.draft_id = ? AND g.form_type = ?');
    $q->execute([$id, $type]);
}
$form = $q->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
$data = baptismNormalizeData($data);
$error = null;

if ($type === 'katin_awan_bunyag' && !$form) {
    // Phone numbers and gender belong to users in the current schema;
    // parishioners stores the profile relation and baptism-specific
    // metadata only. The child's own parent is very likely the one
    // booking, so default the matching parent field from their profile.
    $contactPhone = trim((string) ($draft['contact_phone'] ?? ''));
    $parentName = '';
    $parentGender = '';
    if ($user && ($user['role_name'] ?? '') === 'Parishioner') {
        $pq = $pdo->prepare('SELECT firstname, lastname, middlename, phone, gender FROM users WHERE user_id = ?');
        $pq->execute([$user['user_id']]);
        $profile = $pq->fetch() ?: [];
        if (!$contactPhone && !empty($profile['phone'])) $contactPhone = trim((string) $profile['phone']);
        $parentGender = strtolower(trim((string) ($profile['gender'] ?? '')));
        $parentName = trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? ''])));
    }
    $data = [
        'cellphone' => $contactPhone,
        'father_name' => $parentGender === 'male' ? $parentName : '',
        'mother_name' => $parentGender === 'female' ? $parentName : '',
    ];
} elseif ($type === 'cluster_clearance_baptism_sponsor') {
    $kq = $pdo->prepare($isAppointmentForm
        ? 'SELECT form_data FROM generated_forms WHERE service_category = \'Baptism\' AND appointment_id = ? AND form_type = ?'
        : 'SELECT form_data FROM generated_forms WHERE service_category = \'Baptism\' AND draft_id = ? AND form_type = ?');
    $kq->execute([$isAppointmentForm ? $appointmentId : $id, 'katin_awan_bunyag']);
    $katin = $kq->fetchColumn();
    if ($katin) {
        $kData = json_decode($katin, true) ?: [];
        if (empty($data['child_name'])) $data['child_name'] = $kData['child_name'] ?? '';
        if (empty($data['father_name'])) $data['father_name'] = $kData['father_name'] ?? '';
        if (empty($data['mother_maiden_name'])) $data['mother_maiden_name'] = $kData['mother_name'] ?? '';
        if (empty($data['cluster_name'])) $data['cluster_name'] = $kData['cluster_name'] ?? '';
    }
    if (empty($data['service_date'])) $data['service_date'] = (string) ($draft['appointment_date'] ?? '');
    if (empty($data['address'])) $data['address'] = trim((string) ($draft['location_address'] ?? ''));
    $data = baptismNormalizeData($data);
}

$isAjaxGenerate = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? 'save') === 'generate' ? 'generate' : 'save';
    $isAjaxGenerate = $action === 'generate' && !empty($_POST['ajax_generate']);
    foreach (baptismFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $data = baptismNormalizeData($data);
    $validationErrors = baptismFormValidationErrors($type, $data, $action === 'generate');
    if ($validationErrors) $error = ($action === 'generate' ? 'Please complete all required fields before continuing. ' : '') . implode(' ', $validationErrors);

    if (!$error) {
        $stored = null;
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pdo->beginTransaction();
            $lockedQuery = $pdo->prepare($isAppointmentForm
                ? 'SELECT * FROM generated_forms WHERE service_category = \'Baptism\' AND appointment_id = ? AND form_type = ? FOR UPDATE'
                : 'SELECT * FROM generated_forms WHERE service_category = \'Baptism\' AND draft_id = ? AND form_type = ? FOR UPDATE');
            $lockedQuery->execute([$isAppointmentForm ? $appointmentId : $id, $type]);
            $form = $lockedQuery->fetch() ?: $form;

            if ($isAppointmentForm && $form && ($form['status'] ?? '') === 'approved') {
                throw new RuntimeException('Approved forms require Secretary review before they can be changed.');
            }

            if ($action === 'save') {
                $dataChanged = $form && (string) ($form['form_data'] ?? '') !== $json;
                $documentId = $form['document_id'] ?? null;
                if ($dataChanged && $documentId) {
                    // Keep the old PDF row for history, but detach it from the
                    // current form until a new PDF is generated.
                    $documentId = null;
                }
                if ($form) {
                    $pdo->prepare("UPDATE generated_forms SET form_data = ?, document_id = ?, status = 'draft', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                        ->execute([$json, $documentId, $form['generated_form_id']]);
                } else {
                    $pdo->prepare("INSERT INTO generated_forms (service_category, appointment_id, draft_id, form_type, form_data, document_id, status) VALUES ('Baptism', ?, ?, ?, ?, NULL, 'draft')")
                        ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $type, $json]);
                }
                $pdo->commit();
                flash('success', 'Baptism form draft saved. You can continue editing it from Baptism Requirements.');
                $contextQuery = $isAppointmentForm ? 'appointment_id=' . $appointmentId : 'draft_id=' . $id;
                redirect(url('baptism-draft-form.php?' . $contextQuery . '&form_type=' . urlencode($type)));
            }

            $previousDocumentQuery = $pdo->prepare($isAppointmentForm
                ? "SELECT document_id FROM uploaded_documents WHERE appointment_id = ? AND document_source = 'generated' AND generated_form_type = ? AND superseded_by IS NULL ORDER BY document_id DESC LIMIT 1 FOR UPDATE"
                : "SELECT document_id FROM uploaded_documents WHERE draft_id = ? AND document_source = 'generated' AND generated_form_type = ? AND superseded_by IS NULL ORDER BY document_id DESC LIMIT 1 FOR UPDATE");
            $previousDocumentQuery->execute([$isAppointmentForm ? $appointmentId : $id, $type]);
            $previousDocumentId = (int) ($previousDocumentQuery->fetchColumn() ?: 0);

            $pdf = baptismFormPdf($type, $data);
            $stored = documentStorageWriteBytes($pdf);
            $label = baptismFormDefinition($type)['title'] . '.pdf';
            $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (?, ?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)")
                ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $label, $stored['key'], $label, $type]);
            $newId = (int) $pdo->lastInsertId();
            if ($previousDocumentId) {
                $supersede = $pdo->prepare($isAppointmentForm
                    ? 'UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL'
                    : 'UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND draft_id = ? AND superseded_by IS NULL');
                $supersede->execute([$newId, $previousDocumentId, $isAppointmentForm ? $appointmentId : $id]);
            }
            if ($form) {
                $pdo->prepare("UPDATE generated_forms SET form_data = ?, document_id = ?, status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                    ->execute([$json, $newId, $form['generated_form_id']]);
            } else {
                $pdo->prepare("INSERT INTO generated_forms (service_category, appointment_id, draft_id, form_type, form_data, document_id, status) VALUES ('Baptism', ?, ?, ?, ?, ?, 'pending_review')")
                    ->execute([$isAppointmentForm ? $appointmentId : null, $isAppointmentForm ? null : $id, $type, $json, $newId]);
            }
            $pdo->commit();
            if ($isAjaxGenerate) {
                // An existing booking returns to that booking's requirements (guest: signed link).
                $redirectAfterGenerate = $isAppointmentForm
                    ? applicantBookingUrl($appointmentId, $appointment['guest_reference'] ?? null, $isGuest)
                    : url('baptism-draft.php?draft_id=' . $id);
                if ($isAppointmentForm) flash('success', regeneratedFormMessage($appointmentId));
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'doc_id' => $newId, 'redirect' => $redirectAfterGenerate]);
                exit;
            }
            if ($isAppointmentForm) {
                flash('success', regeneratedFormMessage($appointmentId));
                redirect(applicantBookingUrl($appointmentId, $appointment['guest_reference'] ?? null, $isGuest));
            }
            flash('success', 'Baptism form generated and submitted for review.');
            redirect(url('baptism-draft.php?draft_id=' . $id . '&auto_open_pdf=' . $newId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($stored) { try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Baptism generated-document cleanup failed.'); } }
            error_log('Baptism form save/generate failed: ' . $e->getMessage());
            $errorMsg = $e->getMessage() === 'Approved forms require Secretary review before they can be changed.'
                ? $e->getMessage()
                : ($action === 'generate' ? 'The Baptism form could not be generated.' : 'The Baptism form draft could not be saved.');
            if ($isAjaxGenerate) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                exit;
            }
            $error = $errorMsg;
        }
    }
}

// Validation failed on an AJAX generate request — return JSON instead of rendering HTML.
if ($isAjaxGenerate && $error) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$def = baptismFormDefinition($type);
$previewDocumentId = 0;
if ($form && !empty($form['document_id'])) {
    $requestedPreviewId = (int) ($_GET['generated_document_id'] ?? 0);
    $previewDocumentId = $requestedPreviewId === (int) $form['document_id'] ? $requestedPreviewId : 0;
}
$pageTitle = $def['title'];
$usesPublicShell = !$user;
$backLink = $isAppointmentForm
    ? applicantBookingUrl($appointmentId, $appointment['guest_reference'] ?? null, $isGuest)
    : url('baptism-draft.php?draft_id=' . $id);
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<style>
input[type="text"], textarea {
    text-transform: uppercase;
}
.form-section { background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-bottom:24px; border:1px solid var(--cream-dark); }
.form-section h3 { margin-top:0; color:var(--brown); margin-bottom:20px; font-size:1.1rem; border-bottom:2px solid var(--cream); padding-bottom:10px; }
.choice-row { display:flex; flex-wrap:wrap; gap:18px; margin-top:8px; }
.field-translation { display:block; font-size:0.85em; color:#6c757d; font-weight:normal; margin-top:2px; line-height:1.2; }
</style>
<div style="max-width:850px; margin:0 auto; padding:20px;">
  <a href="<?= $backLink ?>" class="back-link">← Back to <?= $isAppointmentForm ? 'Appointment' : 'Baptism Requirements' ?></a>
  <div style="text-align:center; margin-bottom:30px;"><h1 style="margin:0 0 10px; color:var(--brown);"><?= e($def['title']) ?></h1></div>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($previewDocumentId): ?><div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">The form was generated. If the PDF did not open automatically, <a href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.</div><?php endif; ?>
  <?php if ($form && (($form['status'] ?? '') === 'rejected' || ($form['review_status'] ?? '') === 'rejected')): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger);"><strong>Secretary requested revisions.</strong><?= !empty($form['rejection_reason']) ? ' ' . e($form['rejection_reason']) : ' Please review the form and generate a corrected PDF.' ?></div><?php endif; ?>
  <form method="POST" id="baptismGeneratedForm" data-general-error="Please complete all required fields before continuing.">
    <?= csrfField() ?>
    <?php if ($isAppointmentForm): ?>
      <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <?php else: ?>
      <input type="hidden" name="draft_id" value="<?= $id ?>">
    <?php endif; ?>
    <input type="hidden" name="form_type" value="<?= e($type) ?>">
    <?php if ($type === 'katin_awan_bunyag'): ?>
      <div class="form-section">
        <h3>Child Information</h3>
        <div class="form-group"><label for="child_name">NGALAN SA BUNYAGAN *<span class="field-translation">Name of the child to be baptized</span></label><input id="child_name" name="child_name" type="text" value="<?= e($data['child_name'] ?? '') ?>" maxlength="150" placeholder="e.g., Juan Dela Cruz" required></div>
        <div class="form-row">
            <div class="form-group"><label for="birth_date">PETSA NATAWO *<span class="field-translation">Date of birth</span></label><input id="birth_date" name="birth_date" type="date" data-past value="<?= e($data['birth_date'] ?? '') ?>" required></div>
            <div class="form-group"><label for="birth_place">DIIN NATAWO *<span class="field-translation">Place of birth</span></label><input id="birth_place" name="birth_place" type="text" value="<?= e($data['birth_place'] ?? '') ?>" maxlength="150" placeholder="e.g., Balilihan, Bohol" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Parents</h3>
        <div class="form-row">
            <div class="form-group"><label for="father_name">AMAHAN *<span class="field-translation">Father</span></label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" placeholder="e.g., Pedro Dela Cruz" required></div>
            <div class="form-group"><label for="father_religion">RELIHIYON *<span class="field-translation">Religion</span></label><input id="father_religion" name="father_religion" type="text" value="<?= e($data['father_religion'] ?? '') ?>" maxlength="50" placeholder="e.g., Roman Catholic" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="mother_name">INAHAN *<span class="field-translation">Mother</span></label><input id="mother_name" name="mother_name" type="text" value="<?= e($data['mother_name'] ?? '') ?>" maxlength="150" placeholder="e.g., Maria Dela Cruz" required></div>
            <div class="form-group"><label for="mother_religion">RELIHIYON *<span class="field-translation">Religion</span></label><input id="mother_religion" name="mother_religion" type="text" value="<?= e($data['mother_religion'] ?? '') ?>" maxlength="50" placeholder="e.g., Roman Catholic" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Parents' Marriage</h3>
        <div class="form-group"><label>UNSANG KASALA ANG NADAWAT SA GINIKANAN? *<span class="field-translation">Marriage type received by the parents</span></label><div class="choice-row">
            <?php foreach (['Simbahan' => 'Church marriage', 'Sibil' => 'Civil marriage', 'Wala' => 'None'] as $option => $eng): ?>
                <label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?> required> <?= e($option) ?> <span class="text-muted" style="font-size:0.9em;">— <?= e($eng) ?></span></label>
            <?php endforeach; ?>
        </div></div>
        <div class="form-group"><label for="marriage_place">DIIN <span data-marriage-required>*</span><span class="field-translation">Place of marriage</span></label><input id="marriage_place" name="marriage_place" type="text" value="<?= e($data['marriage_place'] ?? '') ?>" maxlength="150" placeholder="e.g., Balilihan Parish Church"></div>
      </div>

      <div class="form-section">
        <h3>Sponsors</h3>
        <div class="form-group"><label for="sponsor_1">SPONSOR 1 *<span class="field-translation">Baptismal godparent 1</span></label><input id="sponsor_1" name="sponsor_1" type="text" value="<?= e($data['sponsor_1'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-group"><label for="sponsor_2">SPONSOR 2 *<span class="field-translation">Baptismal godparent 2</span></label><input id="sponsor_2" name="sponsor_2" type="text" value="<?= e($data['sponsor_2'] ?? '') ?>" maxlength="150" required></div>
      </div>

      <div class="form-section">
        <h3>Location / Contact</h3>
        <div class="form-row">
            <div class="form-group"><label for="chapel">SAKOP SA KAPILYA SA *<span class="field-translation">Parish / chapel membership</span></label><input id="chapel" name="chapel" type="text" value="<?= e($data['chapel'] ?? '') ?>" maxlength="150" placeholder="Enter parish or chapel" required></div>
            <div class="form-group"><label for="cluster_name">NGALAN SA CLUSTER *<span class="field-translation">Cluster name</span></label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" placeholder="Enter cluster name" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="barangay">NGALAN SA BARANGAY *<span class="field-translation">Barangay name</span></label><input id="barangay" name="barangay" type="text" value="<?= e($data['barangay'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="cellphone">Cell #<span class="field-translation">Mobile number</span> *</label><input id="cellphone" name="cellphone" type="tel" value="<?= e($data['cellphone'] ?? '') ?>" maxlength="11" pattern="^09\d{9}$" inputmode="numeric" placeholder="09XXXXXXXXX" title="Must be a valid 11-digit mobile number starting with 09" required></div>
        </div>
      </div>
    <?php elseif ($type === 'cluster_clearance_baptism_sponsor'): ?>
      <div class="form-section">
        <h3>Sponsor Information</h3>
        <div class="form-group"><label for="sponsor_name">Name of Sponsor *</label><input id="sponsor_name" name="sponsor_name" type="text" value="<?= e($data['sponsor_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-group"><label for="address">Pinuy-anan (Address) *</label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
      </div>

      <div class="form-section">
        <h3>Child Information</h3>
        <div class="form-group"><label for="child_name">Name of the Child *</label><input id="child_name" name="child_name" type="text" value="<?= e($data['child_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
            <div class="form-group"><label for="father_name">Father *</label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="mother_maiden_name">Mother Maiden Name *</label><input id="mother_maiden_name" name="mother_maiden_name" type="text" value="<?= e($data['mother_maiden_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Service Information</h3>
        <div class="form-row">
            <div class="form-group"><label for="service_date">Date of Service / Adlaw sa Serbisyo *</label><input id="service_date" name="service_date" type="date" value="<?= e($data['service_date'] ?? '') ?>" required></div>
            <div class="form-group"><label>Service Requested</label><input type="text" value="Bunyag" disabled style="background:#eee; cursor:not-allowed;"></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Cluster Information</h3>
        <div class="form-row">
            <div class="form-group"><label for="cluster_number">Member of Cluster No. *</label><input id="cluster_number" name="cluster_number" type="number" min="1" max="9999" step="1" value="<?= e($data['cluster_number'] ?? '') ?>" required></div>
            <div class="form-group"><label for="cluster_name">Cluster Name *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </div>
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
    <?php generatedFormWorkflowActionHelper(); ?>
    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 20px;">
      <button class="btn btn-outline" style="width: 100%;" name="action" value="save" formnovalidate data-skip-validation>Save Draft</button>
      <button class="btn btn-primary" style="width: 100%;" name="action" value="generate">Generate PDF</button>
    </div>
  </form>
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
  // Auto-uppercase all text inputs as typed
  document.addEventListener('input', function (e) {
    if (e.target && e.target.matches('input[type="text"]:not([data-no-uppercase]), textarea:not([data-no-uppercase])')) {
      var start = e.target.selectionStart;
      var end = e.target.selectionEnd;
      e.target.value = e.target.value.toUpperCase();
      if (start !== null && end !== null) {
        e.target.setSelectionRange(start, end);
      }
    }
  });

  attachInlineValidation(form);
  form.addEventListener('submit', function (event) {
    if (!event.submitter || event.submitter.value !== 'generate' || !form.checkValidity()) return;

    // Intercept generate — use AJAX so we can control the blank tab outcome.
    event.preventDefault();

    // Open the blank tab synchronously (same user-gesture tick) to bypass popup blockers.
    var newWin = window.open('about:blank', 'parishhubBaptismPdf');

    var btn = event.submitter;
    btn.disabled = true;
    var origLabel = btn.textContent;
    btn.textContent = 'Generating…';

    var formData = new FormData(form);
    formData.set('action', 'generate');
    formData.append('ajax_generate', '1');

    function showInlineError(msg) {
      var el = document.getElementById('ajaxGenerateError');
      if (!el) {
        el = document.createElement('div');
        el.id = 'ajaxGenerateError';
        el.className = 'alert';
        el.style.cssText = 'background:var(--danger-bg,#fdeaea);color:var(--danger,#b74040);padding:12px 16px;border-radius:8px;margin-bottom:12px;';
        form.parentNode.insertBefore(el, form);
      }
      el.textContent = msg;
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    fetch(window.location.href, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
    .then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.success) throw new Error(data.error || 'PDF generation failed.');
        return data;
      });
    })
    .then(function (data) {
      // Navigate the pre-opened tab to the PDF.
      if (newWin && !newWin.closed) {
        newWin.location.href = <?= json_encode(url('document.php?id=')) ?> + data.doc_id;
      }
      // Redirect the main window back to the requirements page.
      window.location.href = data.redirect;
    })
    .catch(function (err) {
      if (newWin && !newWin.closed) newWin.close();
      btn.disabled = false;
      btn.textContent = origLabel;
      showInlineError(err.message || 'An unexpected error occurred. Please try again.');
    });
  });
  <?php if ($previewDocumentId): ?>window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubBaptismPdf');<?php endif; ?>
}());
</script>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
