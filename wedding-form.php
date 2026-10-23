<?php
if (isset($_GET['draft_id']) || isset($_POST['draft_id'])) {
    require __DIR__ . '/wedding-draft-form.php';
    exit;
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/generated-form-workflow.php';
require_once __DIR__ . '/includes/requirements-review.php';

$appointmentId = (int) ($_GET['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
$type = (string) ($_GET['form_type'] ?? $_POST['form_type'] ?? '');
if (!in_array($type, WEDDING_FORM_TYPES, true)) { http_response_code(400); exit('Invalid form.'); }

$user = currentUser();
$guest = $_SESSION['guest_status_verification'] ?? null;
$isGuest = !$user && is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();
if ($user && !in_array($user['role_name'] ?? '', ['Parishioner'], true)) { http_response_code(403); exit('Only the appointment owner may edit this form.'); }

$stmt = db()->prepare("SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ? AND s.category = 'Wedding'");
$stmt->execute([$appointmentId]);
$appointment = $stmt->fetch();
if (!$appointment) { http_response_code(404); exit('Appointment not found.'); }
if ((int) ($appointment['status_id'] ?? 0) === 3) { http_response_code(409); exit('This appointment was rejected as a whole and must be rebooked.'); }
if ($user) {
    $q = db()->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
    $q->execute([$appointment['parishioner_id'], $user['user_id']]);
    if (!$q->fetchColumn()) { http_response_code(403); exit('Not authorized.'); }
} elseif (!$isGuest) { http_response_code(403); exit('Verify the guest appointment before accessing this form.'); }
if ($isGuest) refreshGuestBookingAccess($appointmentId);
// Back to THIS booking (a guest gets a signed link, not the public lookup form).
$bookingUrl = applicantBookingUrl($appointmentId, $appointment['guest_reference'] ?? null, $isGuest);

$formQuery = db()->prepare('SELECT g.*, d.review_status, d.verified, d.rejection_reason FROM generated_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.service_category = \'Wedding\' AND g.appointment_id = ? AND g.form_type = ?');
$formQuery->execute([$appointmentId, $type]);
$form = $formQuery->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
$bookingContact = [
    'name' => trim((string) ($appointment['guest_name'] ?? '')),
    'address' => trim((string) ($appointment['location_address'] ?? '')),
    'phone' => trim((string) (($appointment['contact_phone'] ?? '') ?: ($appointment['guest_phone'] ?? ''))),
    'birthdate' => '',
    'gender' => '',
];
if ($user) {
    $profileQuery = db()->prepare('SELECT firstname, lastname, middlename, phone, address, birthdate, gender FROM users WHERE user_id = ?');
    $profileQuery->execute([$user['user_id']]);
    $profile = $profileQuery->fetch() ?: [];
    // guest_name is never set for a Parishioner, so the profile is the only
    // source for name/birthdate/gender — but Step 1's own contact_phone /
    // location_address (specific to this booking) take priority over the
    // general profile, which is only a fallback when Step 1 left them blank.
    $bookingContact['name'] = trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? ''])));
    if ($bookingContact['phone'] === '') $bookingContact['phone'] = trim((string) ($profile['phone'] ?? ''));
    if ($bookingContact['address'] === '') $bookingContact['address'] = trim((string) ($profile['address'] ?? ''));
    $bookingContact['birthdate'] = trim((string) ($profile['birthdate'] ?? ''));
    $bookingContact['gender'] = strtolower(trim((string) ($profile['gender'] ?? '')));
}
$data = weddingPrefillFormData($pdo, 0, $appointmentId, $type, $data, $bookingContact, $appointment);
$matrimonyFormData = null;
if ($type === 'cluster_clearance') {
    $qMat = $pdo->prepare("SELECT form_data FROM generated_forms WHERE service_category = 'Wedding' AND appointment_id = ? AND form_type = 'matrimony_application'");
    $qMat->execute([$appointmentId]);
    $matrimonyFormData = json_decode((string) $qMat->fetchColumn(), true) ?: null;
}
$error = null;
$isAjaxGenerate = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? '') === 'generate' ? 'generate' : 'save';
    $isAjaxGenerate = $action === 'generate' && !empty($_POST['ajax_generate']);
    $data = [];
    foreach (weddingFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($type === 'matrimony_application') $data = weddingMarriageNormalizeData($data);
    elseif ($type === 'cluster_clearance') $data = weddingClusterNormalizeData($data);
    elseif ($type === 'wedding_sponsor_clearance') $data = weddingSponsorNormalizeData($data);
    if ($type === 'cluster_clearance') $data['wedding_date'] = (string) ($appointment['appointment_date'] ?? '');
    if ($type === 'wedding_sponsor_clearance' && !isset($data['service_requested'])) $data['service_requested'] = 'Kasal';
    $validationErrors = weddingFormValidationErrors($type, $data, $action === 'generate');
    if ($validationErrors) $error = ($action === 'generate' ? 'Please complete all required fields before continuing. ' : '') . implode(' ', $validationErrors);
    if (!$error) {
        $pdo = db();
        $newPath = null;
        $stored = null;
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM generated_forms WHERE service_category = \'Wedding\' AND appointment_id = ? AND form_type = ? FOR UPDATE');
            $lock->execute([$appointmentId, $type]);
            $form = $lock->fetch() ?: null;
            $documentId = $form['document_id'] ?? null;
            $status = 'draft';
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($form && $form['status'] === 'approved') {
                throw new RuntimeException('Approved forms require Secretary review before they can be changed.');
            }
            $dataChanged = $form && (string) ($form['form_data'] ?? '') !== $encoded;
            if ($action === 'save' && $dataChanged && $documentId) {
                // Keep the historical document row, but detach it from the
                // edited draft until a new PDF is generated.
                $documentId = null;
            }
            if ($action === 'generate') {
                $previousDocumentQuery = $pdo->prepare("SELECT document_id FROM uploaded_documents WHERE appointment_id = ? AND document_source = 'generated' AND generated_form_type = ? AND superseded_by IS NULL ORDER BY document_id DESC LIMIT 1 FOR UPDATE");
                $previousDocumentQuery->execute([$appointmentId, $type]);
                $previousDocumentId = (int) ($previousDocumentQuery->fetchColumn() ?: 0);
                $pdf = weddingFormPdf($type, $data);
                $stored = documentStorageWriteBytes($pdf);
                $newPath = $stored['path'];
                $label = weddingFormDefinition($type)['title'] . '.pdf';
                $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)");
                $insert->execute([$appointmentId, $label, $stored['key'], $label, $type]);
                $newDocumentId = (int) $pdo->lastInsertId();
                if ($previousDocumentId) {
                    $pdo->prepare("UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL")->execute([$newDocumentId, $previousDocumentId, $appointmentId]);
                }
                $documentId = $newDocumentId;
                $status = 'pending_review';
            }
            if ($form) {
                $pdo->prepare('UPDATE generated_forms SET form_data = ?, document_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?')->execute([$encoded, $documentId, $status, $form['generated_form_id']]);
            } else {
                $pdo->prepare('INSERT INTO generated_forms (service_category, appointment_id, form_type, form_data, document_id, status) VALUES (\'Wedding\', ?, ?, ?, ?, ?)')->execute([$appointmentId, $type, $encoded, $documentId, $status]);
            }
            $pdo->commit();
            if ($isAjaxGenerate) {
                // Shown on the booking page the browser goes to next.
                flash('success', regeneratedFormMessage($appointmentId));
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'doc_id' => $newDocumentId, 'redirect' => $bookingUrl]);
                exit;
            }
            flash('success', $action === 'generate' ? regeneratedFormMessage($appointmentId) : 'Draft saved successfully. You can continue completing your application later.');
            if ($action === 'generate') {
                redirect($bookingUrl);
            }
            redirect(url('wedding-form.php?appointment_id=' . $appointmentId . '&form_type=' . urlencode($type)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($newPath && is_file($newPath)) { @unlink($newPath); }
            if ($stored && str_starts_with($stored['key'] ?? '', 'supabase://')) { try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
            error_log($e->getMessage());
            $errorMsg = $e->getMessage() === 'Approved forms require Secretary review before they can be changed.' ? $e->getMessage() : 'The form could not be saved. Please try again.';
            if ($isAjaxGenerate) { http_response_code(500); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => $errorMsg]); exit; }
            $error = $errorMsg;
        }
    }
}

if ($isAjaxGenerate && $error) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}
$def = weddingFormDefinition($type);
$previewDocumentId = 0;
if ($form && !empty($form['document_id'])) {
    $requestedPreviewId = (int) ($_GET['generated_document_id'] ?? 0);
    $previewDocumentId = $requestedPreviewId === (int) $form['document_id'] ? $requestedPreviewId : 0;
}
$pageTitle = $def['title'];
$usesPublicShell = !$user;
$backLink = $bookingUrl;
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
  <a href="<?= $backLink ?>" class="back-link">← Back to Appointment</a>
  <div style="text-align:center; margin-bottom:30px;"><h1 style="margin:0 0 10px; color:var(--brown);"><?= e($def['title']) ?></h1></div>
  <?php if ($type === 'matrimony_application'): ?>
    <p class="text-muted">Susiha ug tul-ira ang impormasyon sa aplikante sa dili pa himoon ang opisyal nga PDF. Ang edad kwentahon base sa petsa sa pagkatawo ug petsa sa kasal. <span class="generated-form-guide-translation">(Review and correct the applicant information before generating the official PDF. Ages are calculated from each birth date as of the wedding date.)</span></p>
  <?php else: ?>
    <p class="text-muted">Kompletoha ang form, i-save ang draft, o himoa ang PDF nga walay pirma. Ang mga bahin para sa pirma magpabiling walay sulod. <span class="generated-form-guide-translation">(Complete the form, save a draft, or generate a printable unsigned PDF. Physical signature areas remain blank.)</span></p>
  <?php endif; ?>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2;"><?= e($error) ?></div><?php endif; ?>
  <?php if ($previewDocumentId): ?>
    <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">The application form was generated. If the PDF did not open automatically, <a href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.</div>
  <?php endif; ?>
  <?php if ($form && (($form['status'] ?? '') === 'rejected' || ($form['review_status'] ?? '') === 'rejected')): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger);"><strong>Secretary requested revisions.</strong><?= !empty($form['rejection_reason']) ? ' ' . e($form['rejection_reason']) : ' Please review the form and generate a corrected PDF.' ?></div><?php endif; ?>
  <?php if ($type === 'matrimony_application' && $bookingContact['name'] !== ''): ?>
    <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
      <strong>Booking contact:</strong> <?= e($bookingContact['name']) ?><?= $bookingContact['phone'] ? ' · ' . e($bookingContact['phone']) : '' ?>
      <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;"><button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="groom">Use for Groom</button><button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="bride">Use for Bride</button></div>
    </div>
  <?php elseif ($type === 'cluster_clearance'): ?>
    <?php if (!empty($matrimonyFormData)): ?>
      <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark); margin-bottom:12px;">
        <strong>Na-detect nga Impormasyon gikan sa Step 1 (Application):</strong><br>
        Groom: <strong><?= e($matrimonyFormData['groom_name'] ?? '—') ?></strong> | Bride: <strong><?= e($matrimonyFormData['bride_name'] ?? '—') ?></strong>
        <p style="margin:6px 0 8px; font-size:13px;">Pindota aron awtomatikong mabutang ang mga detalye (Pangalan, Petsa Natawo, Ginikanan, Tirahan):</p>
        <div class="flex gap-2" style="flex-wrap:wrap;">
          <button class="btn btn-outline btn-sm" type="button" id="btnFillGroomKaslonon">Auto-fill: Groom ang Kaslonon</button>
          <button class="btn btn-outline btn-sm" type="button" id="btnFillBrideKaslonon">Auto-fill: Bride ang Kaslonon</button>
        </div>
      </div>
    <?php elseif ($bookingContact['name'] !== ''): ?>
      <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
        <strong>Booking contact:</strong> <?= e($bookingContact['name']) ?>
        <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
          <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="kaslonon">Use for Kaslonon</button>
          <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="spouse">Use for Spouse</button>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="POST" id="weddingGeneratedForm" data-general-error="Please complete all required fields before continuing.">
    <?= csrfField() ?>
    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <input type="hidden" name="form_type" value="<?= e($type) ?>">
    <?php if ($type === 'matrimony_application'): ?>
      <div class="form-group"><label for="date_applied">Date Applied *</label><input id="date_applied" name="date_applied" type="date" value="<?= e($data['date_applied'] ?? '') ?>" required></div>
      <?php foreach (['groom' => 'Groom / Male Information', 'bride' => 'Bride / Female Information'] as $prefix => $heading): ?>
        <div class="form-section">
          <h3><?= e($heading) ?></h3>
          <div class="form-group"><label for="<?= $prefix ?>_name">Full Name *</label><input id="<?= $prefix ?>_name" name="<?= $prefix ?>_name" type="text" value="<?= e($data[$prefix.'_name'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-row">
            <div class="form-group"><label for="<?= $prefix ?>_birth_date">Date of Birth *</label><input id="<?= $prefix ?>_birth_date" name="<?= $prefix ?>_birth_date" type="date" value="<?= e($data[$prefix.'_birth_date'] ?? '') ?>" required data-label="<?= e(ucfirst($prefix)) ?> Date of Birth" data-past data-min-age="18" data-age-on="<?= e((string) ($appointment['appointment_date'] ?? '')) ?>" data-age-label="the wedding date"></div>
            <div class="form-group"><label for="<?= $prefix ?>_cell">Cell Number *</label><input id="<?= $prefix ?>_cell" name="<?= $prefix ?>_cell" data-label="<?= e(ucfirst($prefix)) ?> Cell Number" type="tel" value="<?= e($data[$prefix.'_cell'] ?? '') ?>" maxlength="11" pattern="^09\d{9}$" title="Must be a valid 11-digit mobile number starting with 09" inputmode="numeric" required></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label for="<?= $prefix ?>_father">Father *</label><input id="<?= $prefix ?>_father" name="<?= $prefix ?>_father" type="text" value="<?= e($data[$prefix.'_father'] ?? '') ?>" maxlength="150" required></div>
            <div class="form-group"><label for="<?= $prefix ?>_mother">Mother *</label><input id="<?= $prefix ?>_mother" name="<?= $prefix ?>_mother" type="text" value="<?= e($data[$prefix.'_mother'] ?? '') ?>" maxlength="150" required></div>
          </div>
          <div class="form-group"><label for="<?= $prefix ?>_mother_maiden_name">Mother's Maiden Name *</label><input id="<?= $prefix ?>_mother_maiden_name" name="<?= $prefix ?>_mother_maiden_name" type="text" value="<?= e($data[$prefix.'_mother_maiden_name'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="<?= $prefix ?>_address">Address *</label><input id="<?= $prefix ?>_address" name="<?= $prefix ?>_address" type="text" value="<?= e($data[$prefix.'_address'] ?? '') ?>" maxlength="255" required></div>
        </div>
      <?php endforeach; ?>
      <div class="form-section">
        <h3>Wedding Information</h3>
        <div class="form-row"><div class="form-group"><label for="wedding_date">Date of Wedding *</label><input id="wedding_date" name="wedding_date" type="date" value="<?= e($data['wedding_date'] ?? '') ?>" required></div><div class="form-group"><label for="wedding_time">Time of Wedding *</label><input id="wedding_time" name="wedding_time" type="time" value="<?= e($data['wedding_time'] ?? '') ?>" required></div></div>
      </div>
    <?php elseif ($type === 'cluster_clearance'): ?>
      <div class="form-section">
        <h3>Applicant / Kaslonon Information</h3>
        <div class="form-group"><label for="kaslonon_name">NGALAN SA KASLONON *<span class="field-translation">Name of the person getting married (applicant)</span></label><input id="kaslonon_name" name="kaslonon_name" type="text" value="<?= e($data['kaslonon_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
          <div class="form-group"><label for="kaslonon_birth_date">PETSA NATAWO *<span class="field-translation">Date of birth</span></label><input id="kaslonon_birth_date" name="kaslonon_birth_date" type="date" value="<?= e($data['kaslonon_birth_date'] ?? '') ?>" required data-label="Kaslonon Date of Birth" data-past data-min-age="18" data-age-on="<?= e((string) ($appointment['appointment_date'] ?? '')) ?>" data-age-label="the wedding date"></div>
          <div class="form-group"><label for="kaslonon_status">ESTADO *<span class="field-translation">Civil status</span></label><input id="kaslonon_status" name="kaslonon_status" type="text" value="<?= e($data['kaslonon_status'] ?? '') ?>" maxlength="50" required></div>
          <div class="form-group"><label for="kaslonon_religion">RELIHIYON *<span class="field-translation">Religion</span></label><input id="kaslonon_religion" name="kaslonon_religion" type="text" value="<?= e($data['kaslonon_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Spouse Information</h3>
        <div class="form-group"><label for="spouse_name">NGALAN SA PAMANHUNON/PANGASAW-ONON *<span class="field-translation">Name of the groom/bride (spouse)</span></label><input id="spouse_name" name="spouse_name" type="text" value="<?= e($data['spouse_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
          <div class="form-group"><label for="spouse_birth_date">PETSA NATAWO (SPOUSE) *<span class="field-translation">Spouse's date of birth</span></label><input id="spouse_birth_date" name="spouse_birth_date" type="date" value="<?= e($data['spouse_birth_date'] ?? '') ?>" required data-label="Spouse Date of Birth" data-past data-min-age="18" data-age-on="<?= e((string) ($appointment['appointment_date'] ?? '')) ?>" data-age-label="the wedding date"></div>
          <div class="form-group"><label for="spouse_status">ESTADO *<span class="field-translation">Civil status</span></label><input id="spouse_status" name="spouse_status" type="text" value="<?= e($data['spouse_status'] ?? '') ?>" maxlength="50" required></div>
          <div class="form-group"><label for="spouse_religion">RELIHIYON *<span class="field-translation">Religion</span></label><input id="spouse_religion" name="spouse_religion" type="text" value="<?= e($data['spouse_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Parents</h3>
        <div class="form-row">
          <div class="form-group"><label for="father_name">AMAHAN *<span class="field-translation">Father</span></label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="father_religion">RELIHIYON *<span class="field-translation">Father's religion</span></label><input id="father_religion" name="father_religion" type="text" value="<?= e($data['father_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label for="mother_name">INAHAN *<span class="field-translation">Mother</span></label><input id="mother_name" name="mother_name" type="text" value="<?= e($data['mother_name'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="mother_religion">RELIHIYON *<span class="field-translation">Mother's religion</span></label><input id="mother_religion" name="mother_religion" type="text" value="<?= e($data['mother_religion'] ?? '') ?>" maxlength="50" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Marriage Status of Parents</h3>
        <div class="form-group"><label>UNSANG KASALA ANG NADAWAT SA GINIKANAN? *<span class="field-translation">Marriage type received by the parents</span></label><div class="choice-row">
            <?php foreach (['Simbahan' => 'Church marriage', 'Sibil' => 'Civil marriage', 'Wala' => 'None'] as $option => $eng): ?>
              <label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?> required> <?= e($option) ?> <span class="text-muted" style="font-size:0.9em;">— <?= e($eng) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="form-row">
          <div class="form-group"><label for="marriage_place">DIIN <span data-marriage-required>*</span><span class="field-translation">Place of marriage</span></label><input id="marriage_place" name="marriage_place" type="text" value="<?= e($data['marriage_place'] ?? '') ?>" maxlength="150"></div>
          <div class="form-group"><label for="marriage_date">KANUS-A <span data-marriage-required>*</span><span class="field-translation">Date of marriage</span></label><input id="marriage_date" name="marriage_date" type="date" value="<?= e($data['marriage_date'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Address / Chapel / Cluster</h3>
        <div class="form-group"><label for="address">PINUY-ANAN *<span class="field-translation">Applicant's home address</span></label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
        <div class="form-group"><label for="spouse_address">PINUY-ANAN (SPOUSE) *<span class="field-translation">Spouse's home address</span></label><input id="spouse_address" name="spouse_address" type="text" value="<?= e($data['spouse_address'] ?? '') ?>" maxlength="255" required></div>
        <div class="form-row">
          <div class="form-group"><label for="chapel">SAKOP SA KAPILYA SA *<span class="field-translation">Parish / chapel membership</span></label><input id="chapel" name="chapel" type="text" value="<?= e($data['chapel'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="cluster_name">NGALAN SA CLUSTER *<span class="field-translation">Cluster name</span></label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </div>

      <div class="form-section">
        <h3>Sponsors</h3>
        <div class="form-row">
          <div class="form-group"><label for="sponsor_1">SPONSOR 1 *<span class="field-translation">Wedding sponsor 1</span></label><input id="sponsor_1" name="sponsor_1" type="text" value="<?= e($data['sponsor_1'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="sponsor_2">SPONSOR 2 *<span class="field-translation">Wedding sponsor 2</span></label><input id="sponsor_2" name="sponsor_2" type="text" value="<?= e($data['sponsor_2'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </div>
    <?php elseif ($type === 'wedding_sponsor_clearance'): ?>
      <div class="form-section">
        <h3>Sponsor Information</h3>
        <div class="form-group"><label for="recipient_name">Name of Recipient *</label><input id="recipient_name" name="recipient_name" type="text" value="<?= e($data['recipient_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-group"><label for="address">Address / Pinuy-anan *</label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
      </div>

      <div class="form-section">
        <h3>Wedding Information</h3>
        <div class="form-row">
          <div class="form-group"><label for="groom_name">Groom *</label><input id="groom_name" name="groom_name" type="text" value="<?= e($data['groom_name'] ?? '') ?>" maxlength="150" required></div>
          <div class="form-group"><label for="bride_name">Bride *</label><input id="bride_name" name="bride_name" type="text" value="<?= e($data['bride_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
        <div class="form-group"><label for="service_date">Date of Service *</label><input id="service_date" name="service_date" type="date" value="<?= e($data['service_date'] ?? '') ?>" required></div>
      </div>

      <div class="form-section">
        <h3>Cluster Information</h3>
        <div class="form-row">
          <div class="form-group"><label for="cluster_number">Member of Cluster No. *</label><input id="cluster_number" name="cluster_number" type="number" min="1" max="9999" step="1" value="<?= e($data['cluster_number'] ?? '') ?>" required></div>
          <div class="form-group"><label for="cluster_name">Cluster Name *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($type === 'wedding_sponsor_clearance'): ?><p class="text-muted">Serbisyo nga gipangayo: Kasal (ang ubang opisyal nga kapilian makita sa nahimong form). <span class="generated-form-guide-translation">(Service requested: Kasal; the other official choices remain printed on the generated form.)</span></p><?php endif; ?>
    <?php generatedFormWorkflowActionHelper(); ?>
    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 20px;">
      <button class="btn btn-outline" style="width: 100%;" name="action" value="save" formnovalidate data-skip-validation>Save Draft</button>
      <button class="btn btn-primary" style="width: 100%;" name="action" value="generate">Generate PDF</button>
    </div>
  </form>
</div>
<?php if (in_array($type, WEDDING_FORM_TYPES, true)): ?>
<script>
(function () {
  var form = document.getElementById('weddingGeneratedForm');
  if (!form) return;
  var contact = <?= json_encode($bookingContact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.querySelectorAll('[data-copy-booking-contact]').forEach(function (button) {
    button.addEventListener('click', function () {
      var prefix = button.getAttribute('data-copy-booking-contact');
      var values = { name: contact.name, birth_date: contact.birthdate, address: contact.address, cell: contact.phone };
      Object.keys(values).forEach(function (suffix) { var input = document.getElementById(prefix + '_' + suffix); if (input && values[suffix]) input.value = values[suffix]; });
    });
  });
  var marriageChoices = form.querySelectorAll('input[name="parent_marriage"]');
  var marriagePlace = document.getElementById('marriage_place');
  var marriageDate = document.getElementById('marriage_date');
  function syncMarriageFields() {
    if (!marriageChoices.length || !marriagePlace || !marriageDate) return;
    var selected = form.querySelector('input[name="parent_marriage"]:checked');
    var required = !!selected && selected.value !== 'Wala';
    marriagePlace.required = required;
    marriageDate.required = required;
    marriagePlace.disabled = !!selected && selected.value === 'Wala';
    marriageDate.disabled = !!selected && selected.value === 'Wala';
    form.querySelectorAll('[data-marriage-required]').forEach(function (marker) { marker.style.display = required ? '' : 'none'; });
  }
  marriageChoices.forEach(function (choice) { choice.addEventListener('change', syncMarriageFields); });
  syncMarriageFields();
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

  var appData = <?= json_encode($matrimonyFormData, JSON_UNESCAPED_UNICODE) ?>;
  var btnGroom = document.getElementById('btnFillGroomKaslonon');
  var btnBride = document.getElementById('btnFillBrideKaslonon');
  if (btnGroom && appData) {
    btnGroom.addEventListener('click', function () {
      if (document.getElementById('kaslonon_name')) document.getElementById('kaslonon_name').value = (appData.groom_name || '').toUpperCase();
      if (document.getElementById('kaslonon_birth_date')) document.getElementById('kaslonon_birth_date').value = appData.groom_birth_date || '';
      if (document.getElementById('address')) document.getElementById('address').value = (appData.groom_address || '').toUpperCase();
      if (document.getElementById('father_name')) document.getElementById('father_name').value = (appData.groom_father || '').toUpperCase();
      if (document.getElementById('mother_name')) document.getElementById('mother_name').value = (appData.groom_mother_maiden_name || appData.groom_mother || '').toUpperCase();
      if (document.getElementById('spouse_name')) document.getElementById('spouse_name').value = (appData.bride_name || '').toUpperCase();
      if (document.getElementById('spouse_birth_date')) document.getElementById('spouse_birth_date').value = appData.bride_birth_date || '';
      if (document.getElementById('spouse_address')) document.getElementById('spouse_address').value = (appData.bride_address || '').toUpperCase();
    });
  }
  if (btnBride && appData) {
    btnBride.addEventListener('click', function () {
      if (document.getElementById('kaslonon_name')) document.getElementById('kaslonon_name').value = (appData.bride_name || '').toUpperCase();
      if (document.getElementById('kaslonon_birth_date')) document.getElementById('kaslonon_birth_date').value = appData.bride_birth_date || '';
      if (document.getElementById('address')) document.getElementById('address').value = (appData.bride_address || '').toUpperCase();
      if (document.getElementById('father_name')) document.getElementById('father_name').value = (appData.bride_father || '').toUpperCase();
      if (document.getElementById('mother_name')) document.getElementById('mother_name').value = (appData.bride_mother_maiden_name || appData.bride_mother || '').toUpperCase();
      if (document.getElementById('spouse_name')) document.getElementById('spouse_name').value = (appData.groom_name || '').toUpperCase();
      if (document.getElementById('spouse_birth_date')) document.getElementById('spouse_birth_date').value = appData.groom_birth_date || '';
      if (document.getElementById('spouse_address')) document.getElementById('spouse_address').value = (appData.groom_address || '').toUpperCase();
    });
  }

  attachInlineValidation(form);
  form.addEventListener('submit', function (event) {
    if (!event.submitter || event.submitter.value !== 'generate' || !form.checkValidity()) return;

    event.preventDefault();

    var newWin = window.open('about:blank', 'parishhubWeddingFormPdf');

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
      if (newWin && !newWin.closed) {
        newWin.location.href = <?= json_encode(url('document.php?id=')) ?> + data.doc_id;
      }
      window.location.href = data.redirect;
    })
    .catch(function (err) {
      if (newWin && !newWin.closed) newWin.close();
      btn.disabled = false;
      btn.textContent = origLabel;
      showInlineError(err.message || 'An unexpected error occurred. Please try again.');
    });
  });
  <?php if ($previewDocumentId): ?>window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubWeddingFormPdf');<?php endif; ?>
}());
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
