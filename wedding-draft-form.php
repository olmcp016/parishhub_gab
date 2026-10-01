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
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach (weddingFormDefinition($type)['fields'] as $key => $label) {
        $data[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    if ($type === 'wedding_sponsor_clearance') $data['service_requested'] = 'Kasal';
    if (($_POST['action'] ?? '') === 'generate') {
        foreach (weddingFormRequiredFields($type) as $key) {
            if (($data[$key] ?? '') === '') $error = 'Please complete all required fields before generating the form.';
        }
    }
    if (!$error) {
        $stored = null;
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM generated_wedding_forms WHERE draft_id = ? AND form_type = ? FOR UPDATE');
            $q->execute([$draftId, $type]);
            $form = $q->fetch() ?: null;
            $documentId = $form['document_id'] ?? null;
            $status = 'draft';
            if (($_POST['action'] ?? '') === 'generate') {
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
$pageTitle = $definition['title'];
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/dash-start.php';
?>
<div class="card" style="max-width:900px;margin:auto;">
    <h2><?= e($definition['title']) ?></h2>
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
        <?= csrfField() ?>
        <?php foreach ($definition['fields'] as $key => $label): ?>
            <div class="form-group">
                <label><?= e($label) ?></label>
                <input name="<?= e($key) ?>" value="<?= e($data[$key] ?? '') ?>" <?= in_array($key, weddingFormRequiredFields($type), true) ? 'required' : '' ?>>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-outline" name="action" value="save">Save Draft</button>
        <button class="btn btn-primary" name="action" value="generate">Generate Form</button>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php';
