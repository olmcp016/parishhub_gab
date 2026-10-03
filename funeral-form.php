<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/funeral-forms.php';

$appointmentId = (int) ($_REQUEST['appointment_id'] ?? 0);
$draftId = $_REQUEST['draft_id'] ?? '';
$type = 'katin_awan_paglubong';
$user = currentUser();

$isDraft = !empty($draftId);
$error = null;

if ($isDraft) {
    if (!isset($_SESSION['funeral_booking_drafts'][$draftId])) {
        exit('Funeral booking draft not found or expired.');
    }
    $draft = $_SESSION['funeral_booking_drafts'][$draftId];
    if ($draft['is_guest']) {
        $guestToken = $_SESSION['funeral_draft_tokens'][$draftId] ?? null;
        if (!$guestToken || hash('sha256', $guestToken) !== $draft['guest_token']) exit('Access denied.');
    } else {
        if (!$user || $user['parishioner_id'] != $draft['parishioner_id']) exit('Access denied.');
    }
    
    $data = funeralKatinAwanNormalizeData($draft['katin_awan_payload'] ?: [
        'kanus_a_ilubong' => (string) ($draft['date'] ?? ''),
        'oras_sa_lubong' => substr((string) ($draft['finalTime'] ?? ''), 0, 5),
        'responde' => trim((string) ($draft['guest_name'] ?? ''))
    ]);
    $form = null;
    $appointment = null;
    $previewDocumentId = 0;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $data = funeralKatinAwanNormalizeData($_POST);
        $errors = funeralKatinAwanValidationErrors($data);
        if ($errors) {
            $error = implode(' ', $errors);
        } else {
            $_SESSION['funeral_booking_drafts'][$draftId]['katin_awan_payload'] = $data;
            flash('success', 'Katin-awan sa Paglubong form saved.');
            redirect(url('funeral-draft.php?draft_id=' . $draftId));
        }
    }
} else {
    $guest = $_SESSION['guest_status_verification'] ?? null;
    $isGuest = !$user && is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();
    if ($user && !in_array($user['role_name'] ?? '', ['Parishioner'], true)) { http_response_code(403); exit('Only the appointment owner may edit this form.'); }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ? AND s.category = 'Funeral'");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch();
    if (!$appointment) { http_response_code(404); exit('Funeral appointment not found.'); }
    if ($user) {
        $q = $pdo->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
        $q->execute([$appointment['parishioner_id'], $user['user_id']]);
        if (!$q->fetchColumn()) { http_response_code(403); exit('Not authorized.'); }
    } elseif (!$isGuest) { http_response_code(403); exit('Verify the guest appointment before accessing this form.'); }

    $formQuery = $pdo->prepare('SELECT f.*, d.review_status FROM generated_funeral_forms f LEFT JOIN uploaded_documents d ON d.document_id = f.document_id WHERE f.appointment_id = ? AND f.form_type = ?');
    $formQuery->execute([$appointmentId, $type]);
    $form = $formQuery->fetch() ?: null;
    $data = $form ? funeralKatinAwanNormalizeData(json_decode($form['form_data'], true) ?: []) : funeralKatinAwanNormalizeData([
        'kanus_a_ilubong' => (string) ($appointment['appointment_date'] ?? ''),
        'oras_sa_lubong' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
        'responde' => trim((string) ($appointment['guest_name'] ?? '')),
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $data = funeralKatinAwanNormalizeData($_POST);
        $errors = ($form && ($form['review_status'] ?? '') === 'approved')
            ? ['Approved forms require Secretary review before they can be changed.']
            : funeralKatinAwanValidationErrors($data);
        if ($errors) {
            $error = implode(' ', $errors);
        } else {
            try {
                processFuneralGeneratedForm($appointmentId, $type, $data, $form ? (int) $form['document_id'] : null);
                $formQuery->execute([$appointmentId, $type]);
                $updatedForm = $formQuery->fetch();
                $newDocumentId = (int) ($updatedForm['document_id'] ?? 0);
                flash('success', 'Katin-awan sa Paglubong has been generated and submitted for review.');
                redirect(url('funeral-form.php?appointment_id=' . $appointmentId . '&generated_document_id=' . $newDocumentId));
            } catch (Throwable $e) {
                error_log('Funeral form generation failed: ' . $e->getMessage());
                $error = $e->getMessage() === 'Approved forms require Secretary review before they can be changed.'
                    ? $e->getMessage()
                    : 'The Funeral form could not be generated. Please try again.';
            }
        }
    }

    $previewDocumentId = 0;
    if ($form && !empty($form['document_id'])) {
        $requestedPreviewId = (int) ($_GET['generated_document_id'] ?? 0);
        $previewDocumentId = $requestedPreviewId === (int) $form['document_id'] ? $requestedPreviewId : 0;
    }
}
$definition = funeralFormDefinition($type);

$backLink = $isDraft 
    ? url('funeral-draft.php?draft_id=' . $draftId)
    : ($isGuest ? url('status.php?ref=' . urlencode($appointment['guest_reference'] ?? '')) : url('parishioner/appointment-detail.php?id=' . $appointmentId));
?>
<?php ob_start(); ?>
<style>
.form-section { background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-bottom:24px; border:1px solid var(--cream-dark); }
.form-section h3 { margin-top:0; color:var(--brown); margin-bottom:20px; font-size:1.1rem; border-bottom:2px solid var(--cream); padding-bottom:10px; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.choice-row { display:flex; flex-wrap:wrap; gap:18px; margin-top:8px; }
@media (max-width:600px) { .grid-2 { grid-template-columns:1fr; } }
</style>
<div style="max-width:850px; margin:0 auto; padding:20px;">
  <a href="<?= $backLink ?>" class="back-link">← Back</a>
  <div style="text-align:center; margin-bottom:30px;"><h1 style="margin:0 0 10px; color:var(--brown);"><?= e($definition['title']) ?></h1><p class="text-muted" style="margin:0;">Complete the fields printed on the official parish form. Signature and verification lines remain blank.</p></div>
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php if ($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2;"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($previewDocumentId)): ?><div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">The form was generated. If the PDF did not open automatically, <a href="<?= url('document.php?id=' . $previewDocumentId) ?>" target="_blank" rel="noopener"><strong>View Generated Form</strong></a>.</div><?php endif; ?>
  <?php if (!empty($form) && $form['status'] === 'rejected' && $form['rejection_reason']): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger);"><strong>Secretary requested revisions:</strong> <?= e($form['rejection_reason']) ?></div><?php endif; ?>

  <form method="POST" id="funeralGeneratedForm">
    <?= csrfField() ?>
    <div class="form-section">
      <h3>Deceased Information</h3>
      <div class="form-group"><label for="ngalan_sa_ilubong">Ngalan sa Ilubong *</label><input id="ngalan_sa_ilubong" name="ngalan_sa_ilubong" type="text" maxlength="150" value="<?= e($data['ngalan_sa_ilubong']) ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label for="edad">Edad *</label><input id="edad" name="edad" type="number" min="0" max="120" step="1" value="<?= e($data['edad']) ?>" required></div>
        <div class="form-group"><label for="relihiyon">Relihiyon</label><input id="relihiyon" name="relihiyon" type="text" maxlength="80" value="<?= e($data['relihiyon']) ?>"></div>
      </div>
      <div class="form-group"><label for="pinuy_anan">Pinuy-anan *</label><input id="pinuy_anan" name="pinuy_anan" type="text" maxlength="255" value="<?= e($data['pinuy_anan']) ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label for="sakop_sa_kapilya">Sakop sa Kapilya sa</label><input id="sakop_sa_kapilya" name="sakop_sa_kapilya" type="text" maxlength="150" value="<?= e($data['sakop_sa_kapilya']) ?>"></div>
        <div class="form-group"><label for="ngalan_sa_cluster">Ngalan sa Cluster</label><input id="ngalan_sa_cluster" name="ngalan_sa_cluster" type="text" maxlength="150" value="<?= e($data['ngalan_sa_cluster']) ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <h3>Religious / Last Rites</h3>
      <div class="form-group"><label>Unsang Sakramentoha ang Nadawat? *</label><div class="choice-row">
        <label><input type="checkbox" name="sakramento_hilog" value="1" <?= $data['sakramento_hilog'] ? 'checked' : '' ?>> Hilog</label>
        <label><input type="checkbox" name="sakramento_kumpisal" value="1" <?= $data['sakramento_kumpisal'] ? 'checked' : '' ?>> Kumpisal</label>
        <label><input type="checkbox" name="sakramento_wala" value="1" <?= $data['sakramento_wala'] ? 'checked' : '' ?>> Wala</label>
      </div></div>
    </div>

    <div class="form-section">
      <h3>Death and Burial</h3>
      <div class="form-row">
        <div class="form-group"><label for="kanus_a_namatay">Kanus-a Namatay *</label><input id="kanus_a_namatay" name="kanus_a_namatay" type="date" value="<?= e($data['kanus_a_namatay']) ?>" required></div>
        <div class="form-group"><label for="unsay_namatyan">Unsay Namatyan *</label><input id="unsay_namatyan" name="unsay_namatyan" type="text" maxlength="255" value="<?= e($data['unsay_namatyan']) ?>" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="kanus_a_ilubong">Kanus-a Ilubong *</label><input id="kanus_a_ilubong" name="kanus_a_ilubong" type="date" value="<?= e($data['kanus_a_ilubong']) ?>" required></div>
        <div class="form-group"><label for="oras_sa_lubong">Oras *</label><input id="oras_sa_lubong" name="oras_sa_lubong" type="time" value="<?= e($data['oras_sa_lubong']) ?>" required></div>
      </div>
    </div>

    <div class="form-section">
      <h3>Respondent / Family</h3>
      <div class="form-group"><label for="responde">Responde *</label><input id="responde" name="responde" type="text" maxlength="150" value="<?= e($data['responde']) ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label for="ginikanan_anak">Ginikanan / Anak</label><input id="ginikanan_anak" name="ginikanan_anak" type="text" maxlength="150" value="<?= e($data['ginikanan_anak']) ?>"></div>
        <div class="form-group"><label for="ginikanan_anak_cell">Cell #</label><input id="ginikanan_anak_cell" name="ginikanan_anak_cell" type="tel" inputmode="numeric" maxlength="11" pattern="^09\d{9}$" title="Enter a valid 11-digit mobile number starting with 09" value="<?= e($data['ginikanan_anak_cell']) ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label for="asawa_bana">Asawa / Bana</label><input id="asawa_bana" name="asawa_bana" type="text" maxlength="150" value="<?= e($data['asawa_bana']) ?>"></div>
        <div class="form-group"><label for="asawa_bana_cell">Cell #</label><input id="asawa_bana_cell" name="asawa_bana_cell" type="tel" inputmode="numeric" maxlength="11" pattern="^09\d{9}$" title="Enter a valid 11-digit mobile number starting with 09" value="<?= e($data['asawa_bana_cell']) ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <h3>Marriage</h3>
      <div class="form-group"><label>Unsang Kasala ang Nadawat? *</label><div class="choice-row">
        <?php foreach (['Simbahan', 'Sibil', 'Wala'] as $option): ?><label><input type="radio" name="kasal" value="<?= e($option) ?>" <?= $data['kasal'] === $option ? 'checked' : '' ?> required> <?= e($option) ?></label><?php endforeach; ?>
      </div></div>
      <div class="form-row">
        <div class="form-group"><label for="petsa_sa_kasal">Petsa sa Kasal <span data-marriage-required>*</span></label><input id="petsa_sa_kasal" name="petsa_sa_kasal" type="date" value="<?= e($data['petsa_sa_kasal']) ?>"></div>
        <div class="form-group"><label for="diin_kasal">Diin <span data-marriage-required>*</span></label><input id="diin_kasal" name="diin_kasal" type="text" maxlength="150" value="<?= e($data['diin_kasal']) ?>"></div>
      </div>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap;"><button type="submit" class="btn btn-primary">Generate Application Form</button><?php if ($form && $form['document_id']): ?><a href="<?= documentViewUrl((int) $form['document_id']) ?>" target="_blank" rel="noopener" class="btn btn-outline">Preview Current Form</a><?php endif; ?></div>
  </form>
</div>
<script>
(function () {
  var form = document.getElementById('funeralGeneratedForm');
  if (!form) return;
  var hilog = form.elements.sakramento_hilog;
  var kumpisal = form.elements.sakramento_kumpisal;
  var wala = form.elements.sakramento_wala;
  function syncSacraments(event) {
    if (event && event.target === wala && wala.checked) { hilog.checked = false; kumpisal.checked = false; }
    if (event && (event.target === hilog || event.target === kumpisal) && event.target.checked) wala.checked = false;
  }
  [hilog, kumpisal, wala].forEach(function (choice) { choice.addEventListener('change', syncSacraments); });
  var marriageChoices = form.querySelectorAll('input[name="kasal"]');
  var marriageDate = document.getElementById('petsa_sa_kasal');
  var marriagePlace = document.getElementById('diin_kasal');
  function syncMarriage() {
    var selected = form.querySelector('input[name="kasal"]:checked');
    var required = !!selected && selected.value !== 'Wala';
    marriageDate.required = required; marriagePlace.required = required;
    marriageDate.disabled = !!selected && selected.value === 'Wala'; marriagePlace.disabled = !!selected && selected.value === 'Wala';
    form.querySelectorAll('[data-marriage-required]').forEach(function (marker) { marker.style.display = required ? '' : 'none'; });
  }
  marriageChoices.forEach(function (choice) { choice.addEventListener('change', syncMarriage); });
  syncMarriage();
  form.addEventListener('submit', function (event) {
    if (!form.checkValidity()) return;
    if (!hilog.checked && !kumpisal.checked && !wala.checked) { event.preventDefault(); alert('Select the sacrament received, or select Wala.'); return; }
    window.open('about:blank', 'parishhubFuneralPdf');
  });
  <?php if ($previewDocumentId): ?>window.open(<?= json_encode(url('document.php?id=' . $previewDocumentId)) ?>, 'parishhubFuneralPdf');<?php endif; ?>
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
