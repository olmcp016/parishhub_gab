<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/baptism-draft.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/document-storage.php';

$id = (int) ($_REQUEST['draft_id'] ?? 0);
$type = (string) ($_REQUEST['form_type'] ?? '');
if (!in_array($type, BAPTISM_DRAFT_FORMS, true)) { http_response_code(400); exit('Invalid form.'); }
$pdo = db();
$draft = baptismDraftLoad($pdo, $id, currentUser(), baptismDraftToken($id));
if (!$draft) { http_response_code(403); exit('Not authorized.'); }
if (($draft['status'] ?? '') !== 'draft' || strtotime((string) $draft['expires_at']) <= time()) {
    http_response_code(410);
    exit('This Baptism booking draft has expired or is no longer editable.');
}

$q = $pdo->prepare('SELECT * FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ?');
$q->execute([$id, $type]);
$form = $q->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = ($_POST['action'] ?? 'save') === 'generate' ? 'generate' : 'save';
    foreach (baptismFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($action === 'generate') {
        foreach (baptismFormRequiredFields($type) as $key) {
            if (($data[$key] ?? '') === '') { $error = 'Please complete all required fields before generating the form.'; break; }
        }
    }

    if (!$error) {
        $stored = null;
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pdo->beginTransaction();
            $lockedQuery = $pdo->prepare('SELECT * FROM generated_baptism_forms WHERE draft_id = ? AND form_type = ? FOR UPDATE');
            $lockedQuery->execute([$id, $type]);
            $form = $lockedQuery->fetch() ?: $form;

            if ($action === 'save') {
                if ($form) {
                    $pdo->prepare("UPDATE generated_baptism_forms SET form_data = ?, status = 'draft', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                        ->execute([$json, $form['generated_form_id']]);
                } else {
                    $pdo->prepare("INSERT INTO generated_baptism_forms (appointment_id, draft_id, form_type, form_data, document_id, status) VALUES (NULL, ?, ?, ?, NULL, 'draft')")
                        ->execute([$id, $type, $json]);
                }
                $pdo->commit();
                redirect(url('baptism-draft-form.php?draft_id=' . $id . '&form_type=' . urlencode($type)));
            }

            $pdf = baptismFormPdf($type, $data);
            $stored = documentStorageWriteBytes($pdf);
            $label = baptismFormDefinition($type)['title'] . '.pdf';
            $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, baptism_draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (NULL, NULL, ?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)")
                ->execute([$id, $label, $stored['key'], $label, $type]);
            $newId = (int) $pdo->lastInsertId();
            if ($form && !empty($form['document_id'])) {
                $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND baptism_draft_id = ?')
                    ->execute([$newId, $form['document_id'], $id]);
            }
            if ($form) {
                $pdo->prepare("UPDATE generated_baptism_forms SET form_data = ?, document_id = ?, status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE generated_form_id = ?")
                    ->execute([$json, $newId, $form['generated_form_id']]);
            } else {
                $pdo->prepare("INSERT INTO generated_baptism_forms (appointment_id, draft_id, form_type, form_data, document_id, status) VALUES (NULL, ?, ?, ?, ?, 'pending_review')")
                    ->execute([$id, $type, $json, $newId]);
            }
            $pdo->commit();
            redirect(url('baptism-draft.php?draft_id=' . $id));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($stored) { try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Baptism generated-document cleanup failed.'); } }
            error_log('Baptism form save/generate failed: ' . $e->getMessage());
            $error = $action === 'generate' ? 'The Baptism form could not be generated.' : 'The Baptism form draft could not be saved.';
        }
    }
}

$def = baptismFormDefinition($type);
$pageTitle = $def['title'];
$usesPublicShell = !$user;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>
<div class="card">
  <h2><?= e($def['title']) ?></h2>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <?php if ($type === 'katin_awan_bunyag'): ?>
      <fieldset style="border:1px solid var(--cream-dark); border-radius:8px; padding:16px; margin:18px 0;">
        <legend style="font-weight:700; padding:0 8px;">CHILD INFORMATION</legend>
        <div class="form-group"><label for="child_name">Ngalan sa Bunyagan *</label><input id="child_name" name="child_name" type="text" value="<?= e($data['child_name'] ?? '') ?>" maxlength="150" required></div>
        <div class="form-row">
            <div class="form-group"><label for="birth_date">Petsa Natawo *</label><input id="birth_date" name="birth_date" type="text" value="<?= e($data['birth_date'] ?? '') ?>" maxlength="50" required></div>
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
        <div class="form-group"><label for="marriage_place">Diin *</label><input id="marriage_place" name="marriage_place" type="text" value="<?= e($data['marriage_place'] ?? '') ?>" maxlength="150" required></div>
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
            <div class="form-group"><label for="cellphone">Cellphone Number *</label><input id="cellphone" name="cellphone" type="text" value="<?= e($data['cellphone'] ?? '') ?>" maxlength="50" required></div>
        </div>
      </fieldset>
    <?php else: ?>
    <?php foreach ($def['fields'] as $key => $label): ?>
      <div class="form-group">
        <label><?= e($label) ?></label>
        <?php if ($key === 'parent_marriage'): ?>
          <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><label><input type="radio" name="<?= e($key) ?>" value="<?= e($option) ?>" <?= ($data[$key] ?? '') === $option ? 'checked' : '' ?>><?= e($option) ?></label><?php endforeach; ?>
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
    <button class="btn btn-outline" name="action" value="save" <?= $type === 'katin_awan_bunyag' ? 'formnovalidate' : '' ?>>Save Draft</button>
    <button class="btn btn-primary" name="action" value="generate"><?= $type === 'katin_awan_bunyag' ? 'Generate Application Form' : 'Generate Form' ?></button>
  </form>
</div>
<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php';
