<?php
if (isset($_REQUEST['draft_id'])) {
    require __DIR__ . '/wedding-draft-form.php';
    exit;
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';

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
    $bookingContact = [
        'name' => trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? '']))),
        'address' => trim((string) ($profile['address'] ?? '')),
        'phone' => trim((string) ($profile['phone'] ?? '')),
        'birthdate' => trim((string) ($profile['birthdate'] ?? '')),
        'gender' => strtolower(trim((string) ($profile['gender'] ?? ''))),
    ];
}
if ($type === 'matrimony_application') {
    if ($form) {
        $data = weddingMarriageNormalizeData($data);
        if (empty($data['date_applied'])) $data['date_applied'] = date('Y-m-d');
        if (empty($data['wedding_date'])) $data['wedding_date'] = (string) ($appointment['appointment_date'] ?? '');
        if (empty($data['wedding_time'])) $data['wedding_time'] = substr((string) ($appointment['appointment_time'] ?? ''), 0, 5);
    } else {
        $data = [
            'date_applied' => date('Y-m-d'),
            'wedding_date' => (string) ($appointment['appointment_date'] ?? ''),
            'wedding_time' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
        ];
        $side = in_array($bookingContact['gender'], ['male', 'm'], true) ? 'groom' : (in_array($bookingContact['gender'], ['female', 'f'], true) ? 'bride' : null);
        if ($side) {
            $data[$side . '_name'] = $bookingContact['name'];
            $data[$side . '_birth_date'] = $bookingContact['birthdate'];
            $data[$side . '_address'] = $bookingContact['address'];
            $data[$side . '_cell'] = $bookingContact['phone'];
        }
    }
} elseif ($type === 'cluster_clearance') {
    $data = weddingClusterNormalizeData($data);
    $data['wedding_date'] = (string) ($appointment['appointment_date'] ?? '');
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? '') === 'generate' ? 'generate' : 'save';
    $data = [];
    foreach (weddingFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($type === 'cluster_clearance') $data['wedding_date'] = (string) ($appointment['appointment_date'] ?? '');
    if ($type === 'wedding_sponsor_clearance' && !isset($data['service_requested'])) $data['service_requested'] = 'Kasal';
    $validationErrors = weddingFormValidationErrors($type, $data, $action === 'generate');
    if ($validationErrors) $error = implode(' ', $validationErrors);
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
            $preview = $action === 'generate' ? '&generated_document_id=' . $documentId : '';
            redirect(url('wedding-form.php?appointment_id=' . $appointmentId . '&form_type=' . urlencode($type) . $preview));
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
<div class="card" style="max-width:900px; margin:auto;">
  <h2><?= e($def['title']) ?></h2>
  <p class="text-muted"><?= $type === 'matrimony_application' ? 'Review and correct the applicant information before generating the official PDF. Ages are calculated from each birth date as of the wedding date.' : 'Complete the form, save a draft, or generate a printable unsigned PDF. Physical signature areas remain blank.' ?></p>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2;"><?= e($error) ?></div><?php endif; ?>
  <?php if ($previewDocumentId): ?>
    <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">The application form was generated. If the PDF did not open automatically, <a href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.</div>
  <?php endif; ?>
  <?php if ($type === 'matrimony_application' && $bookingContact['name'] !== ''): ?>
    <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
      <strong>Booking contact:</strong> <?= e($bookingContact['name']) ?><?= $bookingContact['phone'] ? ' · ' . e($bookingContact['phone']) : '' ?>
      <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;"><button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="groom">Use for Groom</button><button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="bride">Use for Bride</button></div>
    </div>
  <?php endif; ?>
  <form method="POST" id="weddingGeneratedForm">
    <?= csrfField() ?>
    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <input type="hidden" name="form_type" value="<?= e($type) ?>">
    <?php if ($type === 'matrimony_application'): ?>
      <div class="form-group"><label for="date_applied">Date Applied *</label><input id="date_applied" name="date_applied" type="date" value="<?= e($data['date_applied'] ?? '') ?>" required></div>
      <?php foreach (['groom' => 'Groom / Male Information', 'bride' => 'Bride / Female Information'] as $prefix => $heading): ?>
        <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
          <legend style="font-weight:700; padding:0 8px;"><?= e($heading) ?></legend>
          <?php foreach ([
            'name' => ['Full Name', 'text', 150], 'birth_date' => ['Date of Birth', 'date', null],
            'father' => ['Father', 'text', 150], 'mother' => ['Mother', 'text', 150],
            'mother_maiden_name' => ["Mother's Maiden Name", 'text', 150], 'address' => ['Address', 'text', 255],
            'cell' => ['Cell Number', 'tel', 11],
          ] as $suffix => [$label, $inputType, $maxLength]): $key = $prefix . '_' . $suffix; ?>
            <div class="form-group"><label for="<?= e($key) ?>"><?= e($label) ?> *</label><input id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($inputType) ?>" value="<?= e($data[$key] ?? '') ?>"<?= $maxLength ? ' maxlength="' . (int) $maxLength . '"' : '' ?><?= $inputType === 'tel' ? ' pattern="^09\d{9}$" title="Enter a valid 11-digit mobile number starting with 09" inputmode="numeric"' : '' ?> required></div>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">Wedding Information</legend>
        <div class="form-row"><div class="form-group"><label for="wedding_date">Date of Wedding *</label><input id="wedding_date" name="wedding_date" type="date" value="<?= e($data['wedding_date'] ?? '') ?>" required></div><div class="form-group"><label for="wedding_time">Time of Wedding *</label><input id="wedding_time" name="wedding_time" type="time" value="<?= e($data['wedding_time'] ?? '') ?>" required></div></div>
      </fieldset>
    <?php else: ?>
    <?php foreach ($def['fields'] as $key => $label): ?>
      <?php $inputType = in_array($key, ['date_applied', 'groom_birth_date', 'bride_birth_date', 'kaslonon_birth_date', 'marriage_date', 'service_date'], true) ? 'date' : 'text'; ?>
      <div class="form-group"><label for="<?= e($key) ?>"><?= e($label) ?><?php if (in_array($key, weddingFormRequiredFields($type), true)): ?> *<?php endif; ?></label>
        <?php if ($key === 'parent_marriage'): ?>
          <div class="form-row" role="group" aria-label="Unsang Kasala ang Nadawat sa Ginikanan?">
            <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><label><input type="radio" name="<?= e($key) ?>" value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'checked' : '' ?> required> <?= e($option) ?></label><?php endforeach; ?>
          </div>
        <?php elseif ($key === 'active_status'): ?>
          <select id="<?= e($key) ?>" name="<?= e($key) ?>" required><option value="">Select</option><?php foreach (['Active', 'Inactive'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
        <?php elseif ($key === 'service_requested'): ?>
          <select id="<?= e($key) ?>" name="<?= e($key) ?>" required><option value="Kasal" selected>Kasal</option></select>
        <?php else: ?>
          <input id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($data[$key] ?? '') ?>" type="<?= $key === 'cluster_number' ? 'number' : $inputType ?>"<?= $key === 'cluster_number' ? ' min="1" max="9999" step="1"' : '' ?> <?= in_array($key, weddingFormRequiredFields($type), true) ? 'required' : '' ?>>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($type === 'wedding_sponsor_clearance'): ?><p class="text-muted">Service Requested: Kasal (the other official choices remain printed on the generated form).</p><?php endif; ?>
    <button class="btn btn-outline" name="action" value="save" formnovalidate>Save Draft</button>
    <button class="btn btn-primary" name="action" value="generate"><?= $type === 'matrimony_application' ? 'Generate Application Form' : 'Generate Form' ?></button>
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
  }
  marriageChoices.forEach(function (choice) { choice.addEventListener('change', syncMarriageFields); });
  syncMarriageFields();
  form.addEventListener('submit', function (event) {
    if (!event.submitter || event.submitter.value !== 'generate' || !form.checkValidity()) return;
    window.open('about:blank', 'parishhubGeneratedFormPdf');
  });
  <?php if ($previewDocumentId): ?>window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubGeneratedFormPdf');<?php endif; ?>
}());
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
