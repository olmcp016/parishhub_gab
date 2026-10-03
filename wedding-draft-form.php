<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-draft.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';

$draftId = (int) ($_REQUEST['draft_id'] ?? 0);
$type = (string) ($_REQUEST['form_type'] ?? '');
if (!in_array($type, WEDDING_DRAFT_FORMS, true)) { http_response_code(400); exit('Invalid form.'); }
$user = currentUser();
$token = weddingDraftGuestToken($draftId);
$pdo = db();
$draft = weddingDraftLoad($pdo, $draftId, $user, $token);
if (!$draft) { http_response_code(403); exit('Not authorized.'); }
if ($draft['status'] !== 'draft') exit('This Wedding booking draft is no longer editable.');
if (strtotime($draft['expires_at']) <= time()) exit('This Wedding booking draft has expired.');

$q = $pdo->prepare('SELECT * FROM generated_wedding_forms WHERE draft_id = ? AND form_type = ?');
$q->execute([$draftId, $type]);
$form = $q->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
$bookingContact = [
    'name' => trim((string) ($draft['guest_name'] ?? '')),
    'address' => trim((string) ($draft['location_address'] ?? '')),
    'phone' => trim((string) (($draft['contact_phone'] ?? '') ?: ($draft['guest_phone'] ?? ''))),
    'birthdate' => '',
    'gender' => '',
];
if ($user) {
    $profileQuery = $pdo->prepare('SELECT firstname, lastname, middlename, phone, address, birthdate, gender FROM users WHERE user_id = ?');
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
        if (empty($data['wedding_date'])) $data['wedding_date'] = (string) ($draft['appointment_date'] ?? '');
        if (empty($data['wedding_time'])) $data['wedding_time'] = substr((string) ($draft['appointment_time'] ?? ''), 0, 5);
    } else {
        $data = [
            'date_applied' => date('Y-m-d'),
            'wedding_date' => (string) ($draft['appointment_date'] ?? ''),
            'wedding_time' => substr((string) ($draft['appointment_time'] ?? ''), 0, 5),
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
    if (!$form) {
        $data = [
            'kaslonon_name' => $bookingContact['name'],
            'kaslonon_birth_date' => $bookingContact['birthdate'],
            'address' => $bookingContact['address'],
            'wedding_date' => (string) ($draft['appointment_date'] ?? ''),
        ];
    } elseif (empty($data['wedding_date'])) {
        $data['wedding_date'] = (string) ($draft['appointment_date'] ?? '');
    }
} elseif ($type === 'wedding_sponsor_clearance') {
    if (!$form) {
        $qApp = $pdo->prepare('SELECT form_data FROM generated_wedding_forms WHERE draft_id = ? AND form_type = ?');
        $qApp->execute([$draftId, 'matrimony_application']);
        $appForm = $qApp->fetch();
        $appData = $appForm ? (json_decode($appForm['form_data'], true) ?: []) : [];
        $data = [
            'recipient_name' => $bookingContact['name'],
            'address' => $bookingContact['address'],
            'groom_name' => $appData['groom_name'] ?? '',
            'bride_name' => $appData['bride_name'] ?? '',
            'service_date' => (string) ($draft['appointment_date'] ?? ''),
        ];
    }
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? '') === 'generate' ? 'generate' : 'save';
    foreach (weddingFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($type === 'cluster_clearance') $data['wedding_date'] = (string) ($draft['appointment_date'] ?? '');
    $validationErrors = weddingFormValidationErrors($type, $data, $action === 'generate');
    if ($validationErrors) $error = implode(' ', $validationErrors);
    if (!$error) {
        $stored = null;
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM generated_wedding_forms WHERE draft_id = ? AND form_type = ? FOR UPDATE');
            $q->execute([$draftId, $type]);
            $form = $q->fetch() ?: null;
            $documentId = $form['document_id'] ?? null;
            $status = 'draft';
            if ($action === 'generate') {
                $pdf = weddingFormPdf($type, $data);
                $stored = documentStorageWriteBytes($pdf);
                $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (NULL, ?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)");
                $label = weddingFormDefinition($type)['title'] . '.pdf';
                $insert->execute([$draftId, $label, $stored['key'], $label, $type]);
                $newDocumentId = (int) $pdo->lastInsertId();
                if ($documentId) {
                    $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND draft_id = ?')->execute([$newDocumentId, $documentId, $draftId]);
                }
                $documentId = $newDocumentId;
                $status = 'pending_review';
            }
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($form) {
                $pdo->prepare('UPDATE generated_wedding_forms SET form_data = ?, document_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?')->execute([$json, $documentId, $status, $form['generated_form_id']]);
            } else {
                $pdo->prepare('INSERT INTO generated_wedding_forms (appointment_id, draft_id, form_type, form_data, document_id, status) VALUES (NULL, ?, ?, ?, ?, ?)')->execute([$draftId, $type, $json, $documentId, $status]);
            }
            $pdo->commit();
            if ($action === 'generate') {
                redirect(url('wedding-draft-form.php?draft_id=' . $draftId . '&form_type=' . urlencode($type) . '&generated_document_id=' . $documentId));
            }
            redirect(url('wedding-draft.php?draft_id=' . $draftId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored && !empty($stored['key'])) {
                try { documentStorageDelete($stored['key']); } catch (Throwable $ignored) { error_log('Generated document cleanup failed.'); }
            }
            $error = 'The form could not be saved.';
        }
    }
}

$definition = weddingFormDefinition($type);
$previewDocumentId = 0;
if ($form && !empty($form['document_id'])) {
    $requestedPreviewId = (int) ($_GET['generated_document_id'] ?? 0);
    $previewDocumentId = $requestedPreviewId === (int) $form['document_id'] ? $requestedPreviewId : 0;
}
$pageTitle = $definition['title'];
$usesPublicShell = !$user;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card" style="max-width:900px;margin:auto;">
    <h2><?= e($definition['title']) ?></h2>
    <?php if (in_array($type, ['matrimony_application', 'cluster_clearance'])): ?><p class="text-muted">Review and correct the applicant information before generating the official PDF. Ages are calculated from each birth date as of the wedding date.</p><?php endif; ?>
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($previewDocumentId): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
            The application form was generated. If the PDF did not open automatically,
            <a id="generatedFormFallback" href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.
        </div>
    <?php endif; ?>
    <?php if ($type === 'matrimony_application' && $bookingContact['name'] !== ''): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
            <strong>Booking contact:</strong> <?= e($bookingContact['name']) ?><?= $bookingContact['phone'] ? ' · ' . e($bookingContact['phone']) : '' ?>
            <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
                <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="groom">Use for Groom</button>
                <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="bride">Use for Bride</button>
            </div>
        </div>
    <?php elseif ($type === 'cluster_clearance' && $bookingContact['name'] !== ''): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
            <strong>Booking contact:</strong> <?= e($bookingContact['name']) ?>
            <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
                <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="kaslonon">Use for Kaslonon</button>
                <button class="btn btn-outline btn-sm" type="button" data-copy-booking-contact="spouse">Use for Spouse</button>
            </div>
        </div>
    <?php endif; ?>
    <form method="POST" id="weddingGeneratedForm">
        <?= csrfField() ?>
        <?php if ($type === 'matrimony_application'): ?>
            <div class="form-group">
                <label for="date_applied">Date Applied *</label>
                <input id="date_applied" name="date_applied" type="date" value="<?= e($data['date_applied'] ?? '') ?>" required>
            </div>
            <?php foreach ([
                'groom' => 'Groom / Male Information',
                'bride' => 'Bride / Female Information',
            ] as $prefix => $heading): ?>
                <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                    <legend style="font-weight:700; padding:0 8px;"><?= e($heading) ?></legend>
                    <div class="form-group">
                        <label for="<?= $prefix ?>_name">Full Name *</label>
                        <input id="<?= $prefix ?>_name" name="<?= $prefix ?>_name" type="text" value="<?= e($data[$prefix.'_name'] ?? '') ?>" maxlength="150" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="<?= $prefix ?>_birth_date">Date of Birth *</label>
                            <input id="<?= $prefix ?>_birth_date" name="<?= $prefix ?>_birth_date" type="date" value="<?= e($data[$prefix.'_birth_date'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="<?= $prefix ?>_cell">Cell Number *</label>
                            <input id="<?= $prefix ?>_cell" name="<?= $prefix ?>_cell" type="tel" value="<?= e($data[$prefix.'_cell'] ?? '') ?>" maxlength="11" pattern="^09\d{9}$" title="Must be a valid 11-digit mobile number starting with 09" inputmode="numeric" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="<?= $prefix ?>_father">Father *</label>
                            <input id="<?= $prefix ?>_father" name="<?= $prefix ?>_father" type="text" value="<?= e($data[$prefix.'_father'] ?? '') ?>" maxlength="150" required>
                        </div>
                        <div class="form-group">
                            <label for="<?= $prefix ?>_mother">Mother *</label>
                            <input id="<?= $prefix ?>_mother" name="<?= $prefix ?>_mother" type="text" value="<?= e($data[$prefix.'_mother'] ?? '') ?>" maxlength="150" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="<?= $prefix ?>_mother_maiden_name">Mother's Maiden Name *</label>
                        <input id="<?= $prefix ?>_mother_maiden_name" name="<?= $prefix ?>_mother_maiden_name" type="text" value="<?= e($data[$prefix.'_mother_maiden_name'] ?? '') ?>" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label for="<?= $prefix ?>_address">Address *</label>
                        <input id="<?= $prefix ?>_address" name="<?= $prefix ?>_address" type="text" value="<?= e($data[$prefix.'_address'] ?? '') ?>" maxlength="255" required>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">Wedding Information</legend>
                <div class="form-row">
                    <div class="form-group"><label for="wedding_date">Date of Wedding *</label><input id="wedding_date" name="wedding_date" type="date" value="<?= e($data['wedding_date'] ?? '') ?>" required></div>
                    <div class="form-group"><label for="wedding_time">Time of Wedding *</label><input id="wedding_time" name="wedding_time" type="time" value="<?= e($data['wedding_time'] ?? '') ?>" required></div>
                </div>
            </fieldset>
        <?php elseif ($type === 'cluster_clearance'): ?>
            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">APPLICANT / KASLONON INFORMATION</legend>
                <div class="form-group"><label for="kaslonon_name">Ngalan sa Kaslonon *</label><input id="kaslonon_name" name="kaslonon_name" type="text" value="<?= e($data['kaslonon_name'] ?? '') ?>" maxlength="150" required></div>
                <div class="form-row">
                    <div class="form-group"><label for="kaslonon_birth_date">Petsa Natawo *</label><input id="kaslonon_birth_date" name="kaslonon_birth_date" type="date" value="<?= e($data['kaslonon_birth_date'] ?? '') ?>" required></div>
                    <div class="form-group"><label for="kaslonon_status">Estado *</label><input id="kaslonon_status" name="kaslonon_status" type="text" value="<?= e($data['kaslonon_status'] ?? '') ?>" maxlength="50" required></div>
                    <div class="form-group"><label for="kaslonon_religion">Relihiyon *</label><input id="kaslonon_religion" name="kaslonon_religion" type="text" value="<?= e($data['kaslonon_religion'] ?? '') ?>" maxlength="50" required></div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">SPOUSE INFORMATION</legend>
                <div class="form-group"><label for="spouse_name">Ngalan sa Pamanhunon/Pangasaw-onon *</label><input id="spouse_name" name="spouse_name" type="text" value="<?= e($data['spouse_name'] ?? '') ?>" maxlength="150" required></div>
                <div class="form-row">
                    <div class="form-group"><label for="spouse_birth_date">Petsa Natawo (Spouse) *</label><input id="spouse_birth_date" name="spouse_birth_date" type="date" value="<?= e($data['spouse_birth_date'] ?? '') ?>" required></div>
                    <div class="form-group"><label for="spouse_status">Estado *</label><input id="spouse_status" name="spouse_status" type="text" value="<?= e($data['spouse_status'] ?? '') ?>" maxlength="50" required></div>
                    <div class="form-group"><label for="spouse_religion">Relihiyon *</label><input id="spouse_religion" name="spouse_religion" type="text" value="<?= e($data['spouse_religion'] ?? '') ?>" maxlength="50" required></div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">PARENTS</legend>
                <div class="form-row">
                    <div class="form-group"><label for="father_name">Amahan *</label><input id="father_name" name="father_name" type="text" value="<?= e($data['father_name'] ?? '') ?>" maxlength="150" required></div>
                    <div class="form-group"><label for="father_religion">Relihiyon (Amahan) *</label><input id="father_religion" name="father_religion" type="text" value="<?= e($data['father_religion'] ?? '') ?>" maxlength="50" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="mother_name">Inahan *</label><input id="mother_name" name="mother_name" type="text" value="<?= e($data['mother_name'] ?? '') ?>" maxlength="150" required></div>
                    <div class="form-group"><label for="mother_religion">Relihiyon (Inahan) *</label><input id="mother_religion" name="mother_religion" type="text" value="<?= e($data['mother_religion'] ?? '') ?>" maxlength="50" required></div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">MARRIAGE STATUS OF PARENTS</legend>
                <div class="form-group">
                    <label>Unsang Kasala ang Nadawat sa Ginikanan? *</label>
                    <div class="form-row" role="group" aria-label="Unsang Kasala ang Nadawat sa Ginikanan?">
                        <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?>
                            <label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?> required> <?= e($option) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="marriage_place">Diin (Place) <span data-marriage-required>*</span></label><input id="marriage_place" name="marriage_place" type="text" value="<?= e($data['marriage_place'] ?? '') ?>" maxlength="150"></div>
                    <div class="form-group"><label for="marriage_date">Kanus-a (Date) <span data-marriage-required>*</span></label><input id="marriage_date" name="marriage_date" type="date" value="<?= e($data['marriage_date'] ?? '') ?>"></div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">ADDRESS / CHAPEL / CLUSTER</legend>
                <div class="form-group"><label for="address">Pinuy-anan (Applicant Address) *</label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
                <div class="form-group"><label for="spouse_address">Pinuy-anan (Spouse Address) *</label><input id="spouse_address" name="spouse_address" type="text" value="<?= e($data['spouse_address'] ?? '') ?>" maxlength="255" required></div>
                <div class="form-row">
                    <div class="form-group"><label for="chapel">Sakop sa Kapilya sa *</label><input id="chapel" name="chapel" type="text" value="<?= e($data['chapel'] ?? '') ?>" maxlength="150" required></div>
                    <div class="form-group"><label for="cluster_name">Ngalan sa Cluster *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">SPONSORS</legend>
                <div class="form-row">
                    <div class="form-group"><label for="sponsor_1">Sponsor 1 *</label><input id="sponsor_1" name="sponsor_1" type="text" value="<?= e($data['sponsor_1'] ?? '') ?>" maxlength="150" required></div>
                    <div class="form-group"><label for="sponsor_2">Sponsor 2 *</label><input id="sponsor_2" name="sponsor_2" type="text" value="<?= e($data['sponsor_2'] ?? '') ?>" maxlength="150" required></div>
                </div>
            </fieldset>
        <?php elseif ($type === 'wedding_sponsor_clearance'): ?>
            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">SPONSOR INFORMATION</legend>
                <div class="form-group"><label for="recipient_name">Name of Recipient *</label><input id="recipient_name" name="recipient_name" type="text" value="<?= e($data['recipient_name'] ?? '') ?>" maxlength="150" required></div>
                <div class="form-group"><label for="address">Address / Pinuy-anan *</label><input id="address" name="address" type="text" value="<?= e($data['address'] ?? '') ?>" maxlength="255" required></div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">WEDDING INFORMATION</legend>
                <div class="form-row">
                    <div class="form-group"><label for="groom_name">Groom *</label><input id="groom_name" name="groom_name" type="text" value="<?= e($data['groom_name'] ?? '') ?>" maxlength="150" required></div>
                    <div class="form-group"><label for="bride_name">Bride *</label><input id="bride_name" name="bride_name" type="text" value="<?= e($data['bride_name'] ?? '') ?>" maxlength="150" required></div>
                </div>
                <div class="form-group"><label for="service_date">Date of Service *</label><input id="service_date" name="service_date" type="date" value="<?= e($data['service_date'] ?? '') ?>" required></div>
            </fieldset>

            <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
                <legend style="font-weight:700; padding:0 8px;">CLUSTER INFORMATION</legend>
                <div class="form-row">
                    <div class="form-group"><label for="cluster_number">Member of Cluster No. *</label><input id="cluster_number" name="cluster_number" type="number" min="1" max="9999" step="1" value="<?= e($data['cluster_number'] ?? '') ?>" required></div>
                    <div class="form-group"><label for="cluster_name">Cluster Name *</label><input id="cluster_name" name="cluster_name" type="text" value="<?= e($data['cluster_name'] ?? '') ?>" maxlength="150" required></div>
                </div>
            </fieldset>
        <?php else: ?>
        <?php foreach ($definition['fields'] as $key => $label): ?>
            <div class="form-group">
                <label><?= e($label) ?></label>
                <?php if ($key === 'parent_marriage'): ?>
                    <div class="form-row" role="group" aria-label="Unsang Kasala ang Nadawat sa Ginikanan?">
                        <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><label><input type="radio" name="parent_marriage" value="<?= e($option) ?>" <?= ($data['parent_marriage'] ?? '') === $option ? 'checked' : '' ?>> <?= e($option) ?></label><?php endforeach; ?>
                    </div>
                <?php elseif ($key === 'active_status'): ?>
                    <select name="<?= e($key) ?>" required><option value="">Select</option><?php foreach (['Active', 'Inactive'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
                <?php elseif ($key === 'service_requested'): ?>
                    <select name="<?= e($key) ?>" required><?php foreach (['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others'] as $option): ?><option value="<?= e($option) ?>" <?= ($data[$key] ?? 'Kasal') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <input name="<?= e($key) ?>" value="<?= e($data[$key] ?? '') ?>" <?= in_array($key, weddingFormRequiredFields($type), true) ? 'required' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <button class="btn btn-outline" name="action" value="save" <?= in_array($type, ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance']) ? 'formnovalidate' : '' ?>>Save Draft</button>
        <button class="btn btn-primary" name="action" value="generate"><?= in_array($type, ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance']) ? 'Generate Application Form' : 'Generate Form' ?></button>
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
      Object.keys(values).forEach(function (suffix) {
        var inputId = prefix + '_' + suffix;
        if (prefix === 'kaslonon' && suffix === 'address') inputId = 'address';
        var input = document.getElementById(inputId);
        if (input && values[suffix]) input.value = values[suffix];
      });
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
  form.addEventListener('submit', function (event) {
    var submitter = event.submitter;
    if (!submitter || submitter.value !== 'generate' || !form.checkValidity()) return;
    window.open('about:blank', 'parishhubMarriagePdf');
  });
  <?php if ($previewDocumentId): ?>
  window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubMarriagePdf');
  <?php endif; ?>
}());
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
