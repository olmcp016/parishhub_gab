<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document-storage.php';
require_once __DIR__ . '/../includes/wedding-forms.php';
require_once __DIR__ . '/../includes/baptism-forms.php';
require_once __DIR__ . '/../includes/document-validation.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$id = (int) ($_GET['id'] ?? 0);
$isAjax = isDetailModalRequest();
$redirectUrl = url('parishioner/appointment-detail.php?id=' . $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same silent-post_max_size-wipe protection as the booking form — see book.php for details.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        $limit = ini_get('post_max_size');
        respondAjaxOrRedirect($isAjax, false, "Your uploaded files were too large for the server to accept (total limit is $limit). Please upload smaller files or fewer at a time.", $redirectUrl);
    }

    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $stmt = db()->prepare(
            "UPDATE appointments SET status_id = 7, cancelled_reason = ? WHERE appointment_id = ? AND parishioner_id = ?"
        );
        $stmt->execute([$_POST['reason'] ?? 'Cancelled by parishioner', $id, $parishionerId]);
        logActivity($userId, "Cancelled appointment #$id", 'Appointments');
        // Cancelling closes the request entirely — send them back to the list
        // either way (a JSON response wouldn't have anything left to refresh).
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Appointment cancelled.', 'redirect' => url('parishioner/appointments.php')]);
            exit;
        }
        flash('success', 'Appointment cancelled.');
        redirect(url('parishioner/appointments.php'));
    }

    if ($action === 'upload_documents') {
        // Confirm this appointment actually belongs to the logged-in parishioner
        $stmt = db()->prepare(
            "SELECT a.appointment_id, a.status_id, s.requirements FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             WHERE a.appointment_id = ? AND a.parishioner_id = ?"
        );
        $stmt->execute([$id, $parishionerId]);
        $appt = $stmt->fetch();
        if (!$appt) {
            respondAjaxOrRedirect($isAjax, false, 'Appointment not found.', url('parishioner/appointments.php'));
        }
        $wasRejected = (int) $appt['status_id'] === 3;

        $requirementsList = parseRequirementsList($appt['requirements']);
        $pendingUploads = [];
        $skippedFiles = [];

        foreach ($requirementsList as $i => $label) {
            $field = "req_doc_$i";
            $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $skippedFiles[] = $_FILES[$field]['name'];
                continue;
            }
            $pendingUploads[] = ['file' => $_FILES[$field], 'label' => $label];
        }
        if (!empty($_FILES['documents']['name'][0])) {
            foreach ($_FILES['documents']['name'] as $i => $name) {
                if ($name === '') continue;
                $err = $_FILES['documents']['error'][$i];
                if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $skippedFiles[] = $name;
                    continue;
                }
                $pendingUploads[] = [
                    'file' => [
                        'name' => $name,
                        'type' => $_FILES['documents']['type'][$i],
                        'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                        'error' => $err,
                        'size' => $_FILES['documents']['size'][$i],
                    ],
                    'label' => null,
                ];
            }
        }

        foreach ($pendingUploads as $upload) {
            $result = validateUploadedFile($upload['file']);
            if (!$result['valid']) {
                $label = $upload['label'] ? ('"' . $upload['label'] . '": ') : '';
                respondAjaxOrRedirect($isAjax, false, $label . documentValidationMessage($result['reason']), $redirectUrl);
            }
        }

        $uploaded = 0;
        foreach ($pendingUploads as $upload) {
            $file = $upload['file'];
            try { $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION)); } catch (Throwable $e) { $stored = null; }
            if ($stored) {
                try {
                    $stmt = db()->prepare(
                        "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified) VALUES (?, ?, ?, ?, ?, 'pending', FALSE)"
                    );
                    $stmt->execute([$id, $file['name'], $stored['key'], $stored['mime'], $upload['label']]);
                } catch (Throwable $e) {
                    try { documentStorageDelete($stored['key']); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); }
                    throw $e;
                }
                $newDocumentId = (int) $pdo->lastInsertId();
                if ($upload['label']) {
                    $pdo->prepare("UPDATE uploaded_documents SET superseded_by = ? WHERE appointment_id = ? AND requirement_label = ? AND review_status = 'rejected' AND superseded_by IS NULL AND document_id <> ?")
                        ->execute([$newDocumentId, $id, $upload['label'], $newDocumentId]);
                }
                $uploaded++;
            }
        }

        $messages = [];
        $anySuccess = false;
        if ($uploaded > 0) {
            $anySuccess = true;
            // A rejected request goes back into the Secretary's review queue
            // once the parishioner has corrected/added documents — the whole
            // point of "Update Documents" is fixing the SAME request rather
            // than starting a brand new booking from scratch.
            if ($wasRejected) {
                db()->prepare("UPDATE appointments SET status_id = 1 WHERE appointment_id = ?")->execute([$id]);
                db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Documents Updated', ?)")
                    ->execute([$userId, "Your updated documents for appointment #$id have been submitted and are back under review."]);
                $secretaries = db()->query("SELECT user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name IN ('Secretary', 'Admin') AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                $notify = db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Documents Updated', ?)");
                foreach ($secretaries as $secretaryId) {
                    $notify->execute([(int) $secretaryId, "Appointment #$id has updated documents and is ready for review."]);
                }
                logActivity($userId, "Resubmitted documents for previously rejected appointment #$id", 'Appointments');
                $messages[] = "$uploaded document(s) uploaded — your request is back under review.";
            } else {
                logActivity($userId, "Uploaded $uploaded document(s) for appointment #$id", 'Appointments');
                $messages[] = "$uploaded document(s) uploaded successfully.";
            }
        }
        if (!empty($skippedFiles)) {
            $limit = ini_get('upload_max_filesize');
            $messages[] = 'The following file(s) were too large (max ' . $limit . ' each) and were NOT uploaded: ' . implode(', ', $skippedFiles) . '.';
        }
        if ($uploaded === 0 && empty($skippedFiles)) {
            $messages[] = 'No files were selected.';
        }
        respondAjaxOrRedirect($isAjax, $anySuccess, implode(' ', $messages), $redirectUrl);
    }
}

$stmt = db()->prepare(
    "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, st.status_name, p.full_name AS priest_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     LEFT JOIN priests p ON a.priest_id = p.priest_id
     WHERE a.appointment_id = ? AND a.parishioner_id = ?"
);
$stmt->execute([$id, $parishionerId]);
$appointment = $stmt->fetch();

if (!$appointment) {
    flash('error', 'Appointment not found.');
    redirect(url('parishioner/appointments.php'));
}

$stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
$stmt->execute([$id]);
$intention = $stmt->fetch() ?: null;

$stmt = db()->prepare('SELECT * FROM donations WHERE appointment_id = ?');
$stmt->execute([$id]);
$donation = $stmt->fetch() ?: null;

$stmt = db()->prepare(
    "SELECT p.*, pm.method_name FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id WHERE p.appointment_id = ? ORDER BY p.payment_id DESC LIMIT 1"
);
$stmt->execute([$id]);
$payment = $stmt->fetch() ?: null;
// An online (PayMongo) payment that was started but never finished, or one that
// failed/was cancelled, can simply be retried — it's not a completed payment.
$onlineUnfinished = $payment && (int) $payment['method_id'] === 7 && $payment['payment_status'] === 'pending';
$canPay = $appointment['status_name'] === 'Approved'
    && (!$payment || $onlineUnfinished || in_array($payment['payment_status'], ['failed', 'cancelled'], true));

$stmt = db()->prepare('SELECT * FROM uploaded_documents WHERE appointment_id = ? ORDER BY uploaded_at DESC, document_id DESC');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();
$generatedForms = [];
if (($appointment['category'] ?? '') === 'Wedding') {
    $stmt = db()->prepare('SELECT g.*, d.document_id, d.review_status, d.rejection_reason FROM generated_wedding_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.appointment_id = ? ORDER BY g.form_type');
    $stmt->execute([$id]); $generatedForms = $stmt->fetchAll();
} elseif (($appointment['category'] ?? '') === 'Baptism') {
    $stmt = db()->prepare('SELECT g.*, d.document_id, d.review_status, d.rejection_reason FROM generated_baptism_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.appointment_id = ? ORDER BY g.form_type');
    $stmt->execute([$id]); $generatedForms = $stmt->fetchAll();
}

$active = 'appointments';
$pageTitle = 'Appointment #' . $appointment['appointment_id'];
if (!$isAjax) {
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/dash-start.php';
}
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;" class="detail-grid">
  <div class="card">
    <div class="card-header">
      <h3><?= e($appointment['service_name']) ?></h3>
      <div class="flex gap-2">
        <?php if ($appointment['schedule_type']): ?>
          <span class="badge badge-<?= strtolower($appointment['schedule_type']) ?>"><?= e($appointment['schedule_type']) ?></span>
        <?php endif; ?>
        <?php if ($appointment['category'] === 'Mass Intention'): $miStatus = massIntentionStatusDisplay($appointment['status_name'], $payment && !((int) $payment['method_id'] === 7 && $payment['payment_status'] !== 'verified')); ?>
          <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
        <?php else: ?>
          <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
    <p><strong>Priest:</strong> <?= e($appointment['priest_name'] ?? 'Not yet assigned') ?></p>
    <?php if ($appointment['category'] === 'Wedding'): ?>
      <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Wedding Forms</h4>
      <?php foreach ($generatedForms as $gf): $status = $gf['review_status'] ?? 'pending'; ?>
        <p><strong><?= e(weddingFormDefinition($gf['form_type'])['title']) ?></strong><br>
          Status: <?= e(weddingFormStatusLabel($gf['status'])) ?>
          <a class="btn btn-outline btn-sm" href="<?= url('wedding-form.php?appointment_id=' . $id . '&form_type=' . urlencode($gf['form_type'])) ?>">Edit / View</a>
          <?php if ($gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View PDF</a><?php endif; ?>
          <?php if ($gf['rejection_reason']): ?><br><span class="text-muted">Reason: <?= e($gf['rejection_reason']) ?></span><?php endif; ?>
        </p>
      <?php endforeach; ?>
      <?php if (!$generatedForms): ?><p class="text-muted">Wedding forms can be completed from the booking documents section.</p><?php endif; ?>
    <?php elseif ($appointment['category'] === 'Baptism'): ?>
      <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Baptism Forms</h4>
      <?php $baptismTitles = ['katin_awan_bunyag' => 'KATIN-AWAN SA BUNYAG', 'cluster_clearance_baptism_sponsor' => 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR']; ?>
      <?php foreach ($generatedForms as $gf): ?>
        <p><strong><?= e($baptismTitles[$gf['form_type']] ?? $gf['form_type']) ?></strong><br>
          Status: <?= e($gf['review_status'] ?? 'Pending Review') ?>
          <?php if ($gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View PDF</a><a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . (int) $gf['document_id'] . '&download=1') ?>">Download PDF</a><?php endif; ?>
        </p>
      <?php endforeach; ?>
      <?php if (!$generatedForms): ?><p class="text-muted">Baptism forms are not available.</p><?php endif; ?>
    <?php endif; ?>
    <p><strong>Fee:</strong>
      <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification
      <?php elseif (!empty($appointment['fee_snapshot'])): ?><?= feeLabel((float) (json_decode($appointment['fee_snapshot'], true)['total'] ?? 0)) ?>
      <?php else: ?><?= feeLabel((float) $appointment['fee']) ?><?php endif; ?>
    </p>
    <?php if ($appointment['category'] === 'Funeral' && $appointment['date_of_death']): ?>
      <p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['remarks']): ?><p><strong>Remarks:</strong> <?= e($appointment['remarks']) ?></p><?php endif; ?>
    <?php if (!empty($appointment['location_address'])): ?>
      <p><strong>Address to Bless:</strong> <?= nl2br(e($appointment['location_address'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($appointment['contact_phone'])): ?>
      <p><strong>Contact Phone:</strong> <?= e($appointment['contact_phone']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>

    <?php if ($appointment['status_name'] === 'Pending'): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Our secretary is reviewing your request and required documents. You'll be notified once it's approved.
      </div>
    <?php elseif ($appointment['status_name'] === 'Rejected'): ?>
      <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;">
        <strong>This request was not approved.</strong>
        <?php if ($appointment['rejection_reason']): ?>
          <br><strong>Reason:</strong> <?= e($appointment['rejection_reason']) ?>
        <?php endif; ?>
      </div>
    <?php elseif ($appointment['status_name'] === 'Approved' && $payment && $payment['payment_status'] === 'pending'): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Your payment has been submitted and is awaiting verification by our cashier. Once verified, please wait for your appointment to be confirmed — you'll receive a notification.
      </div>
    <?php elseif ($appointment['status_name'] === 'Approved' && !$payment): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Your request has been approved! Please settle your payment below, then wait for your appointment to be confirmed.
      </div>
    <?php elseif ($appointment['status_name'] === 'Payment Verified'): ?>
      <div class="alert alert-success">
        Your payment has been verified. Please wait for your appointment to be confirmed — you'll receive a notification.
      </div>
    <?php endif; ?>

    <?php if ($intention): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Mass Intention Details</h4>
      <p><strong>Type:</strong> <?= e($intention['intention_type']) ?></p>
      <p><strong>Offerer:</strong> <?= e($intention['offerer_name']) ?></p>
      <p><strong>Intention For:</strong> <?= e($intention['intention_for']) ?></p>
      <?php if ($intention['message']): ?><p><strong>Message:</strong> <?= e($intention['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if ($donation): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Donation Details</h4>
      <p><strong>Donor:</strong> <?= e($donation['donor_name'] ?: 'Anonymous') ?></p>
      <p><strong>Purpose:</strong> <?= e($donation['purpose']) ?></p>
      <?php if ($donation['message']): ?><p><strong>Message:</strong> <?= e($donation['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($appointment['status_name'], ['Pending', 'Approved'], true)): ?>
      <form method="POST" action="<?= url('parishioner/appointment-detail.php?id=' . $id) ?>" class="mt-3" id="cancelApptForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="reason" value="Cancelled by parishioner">
        <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('cancelApptModal').showModal()">Cancel Appointment</button>
      </form>
    <?php endif; ?>
  </div>

<!-- Cancel confirm modal -->
<dialog id="cancelApptModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Cancel Appointment?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to cancel this appointment?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('cancelApptModal').close()">No, Keep It</button>
    <button type="button" class="btn btn-danger" onclick="document.getElementById('cancelApptModal').close(); document.getElementById('cancelApptForm').submit();">Yes, Cancel</button>
  </div>
</dialog>

  <div>
    <div class="card">
      <div class="card-header"><h3>Payment</h3></div>
      <?php if ($payment): ?>
        <p><strong>Amount:</strong> <?= money($payment['amount']) ?></p>
        <p><strong>Method:</strong> <?= e($payment['method_name']) ?></p>
        <?php if ($payment['reference_number']): ?><p><strong>Reference #:</strong> <?= e($payment['reference_number']) ?></p><?php endif; ?>
        <p><strong>Status:</strong> <span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e($payment['payment_status']) ?></span></p>
        <?php if ($onlineUnfinished): ?>
          <p class="text-muted" style="font-size:13px;">Your online payment was started but not completed yet. You can pay again below.</p>
        <?php elseif ($payment['payment_status'] === 'pending'): ?>
          <p class="text-muted" style="font-size:13px;">Awaiting verification by our cashier.</p>
        <?php elseif ($payment['payment_status'] === 'verified'): ?>
          <p class="text-muted" style="font-size:13px;">✔ Verified — please wait for your schedule to be confirmed.</p>
        <?php elseif (in_array($payment['payment_status'], ['failed', 'cancelled'], true)): ?>
          <p class="text-muted" style="font-size:13px;">This payment was not completed. You can try again below.</p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($canPay): ?>
        <form method="POST" action="<?= url('parishioner/pay.php') ?>" id="payForm" data-plain-submit="1" <?= $payment ? 'class="mt-3"' : '' ?>>
          <?= csrfField() ?>
          <input type="hidden" name="appointment_id" value="<?= $id ?>">
          <?php if ($appointment['category'] === 'Mass Intention'): ?>
            <div class="form-group">
              <label>Amount (voluntary offering) — must be more than ₱0</label>
              <input type="number" name="amount" min="0.01" step="0.01" placeholder="e.g. 500" required>
            </div>
          <?php else: ?>
            <div class="form-group">
              <label>Amount</label>
              <input type="number" value="<?= e((string)$appointment['fee']) ?>" step="0.01" readonly disabled>
            </div>
          <?php endif; ?>
          <div class="form-group">
            <label>How would you like to pay?</label>
            <label class="radio-option" style="display:block; margin-bottom:8px;">
              <input type="radio" name="pay_mode" value="online" checked>
              <strong>Pay Online Now</strong> — GCash, Maya, or Card via PayMongo (secure)
            </label>
            <label class="radio-option" style="display:block;">
              <input type="radio" name="pay_mode" value="cash">
              Cash (Pay at Parish Office)
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-block" id="paySubmitBtn">Pay Online Now</button>
          <p class="helper-text mt-2" id="payHint">You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there.</p>
        </form>
        <script>
        (function () {
          var form = document.getElementById('payForm');
          function update() {
            var mode = form.querySelector('input[name="pay_mode"]:checked').value;
            document.getElementById('paySubmitBtn').textContent = mode === 'online' ? 'Pay Online Now' : 'Submit (Pay at Parish Office)';
            document.getElementById('payHint').textContent = mode === 'online'
              ? "You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there."
              : 'Please bring your payment to the parish office. Our cashier will verify it.';
          }
          form.querySelectorAll('input[name="pay_mode"]').forEach(function (r) { r.addEventListener('change', update); });
          update();
        })();
        </script>
      <?php elseif (!$payment): ?>
        <p class="text-muted">Payment will be available once your appointment is approved.</p>
      <?php endif; ?>
    </div>

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
    <?php
      $requirementsList = !empty($appointment['requirements_snapshot']) ? (json_decode($appointment['requirements_snapshot'], true) ?: []) : parseRequirementsList($appointment['requirements']);
      $documentsByLabel = [];
      $extraDocuments = [];
      foreach ($documents as $d) {
          if ($d['requirement_label'] && in_array($d['requirement_label'], $requirementsList, true)) {
              $documentsByLabel[$d['requirement_label']][] = $d;
          } else {
              $extraDocuments[] = $d;
          }
      }
      // Rejected is included so a parishioner can correct/add the missing
      // documents without starting a brand new booking — see the
      // "wasRejected" handling above, which puts the request back under
      // review as soon as they upload something.
      $canUpload = $appointment['status_name'] === 'Rejected';
    ?>
    <div class="card">
      <div class="card-header"><h3><?= $appointment['status_name'] === 'Rejected' ? 'Update Documents' : 'Required Documents' ?></h3></div>
      <?php if ($appointment['status_name'] === 'Rejected'): ?>
        <p class="helper-text" style="margin-top:-6px;">Upload the corrected/missing document(s) below — your request will go back to our secretary for review, no need to start a new booking.</p>
      <?php endif; ?>
      <?php if (empty($requirementsList)): ?>
        <p class="text-muted">No specific documents are required for this service.</p>
      <?php else: ?>
        <?php foreach ($requirementsList as $i => $label): ?>
          <?php $matches = $documentsByLabel[$label] ?? []; ?>
          <div class="form-group doc-req-row">
            <label>
              <?= e($label) ?>
              <?php if (empty($matches)): ?>
                <span class="badge badge-rejected">Missing</span>
          <?php elseif (($matches[0]['review_status'] ?? ($matches[0]['verified'] ? 'approved' : 'pending')) === 'approved'): ?>
                <span class="badge badge-verified">Verified/Accepted</span>
              <?php else: ?>
                <span class="badge badge-pending">Uploaded — Pending Review</span>
              <?php endif; ?>
            </label>
            <?php foreach ($matches as $d): ?>
              <p style="margin:2px 0;">
                <a href="<?= documentViewUrl((int) $d['document_id']) ?>" target="_blank" rel="noopener">View Current File: <?= e($d['file_name']) ?></a>
              </p>
            <?php endforeach; ?>
            <?php if ($canUpload): ?>
              <label class="helper-text" style="display:block; margin-top:8px;">Replace this document</label>
              <input type="file" form="uploadDocsForm" name="req_doc_<?= $i ?>" accept=".pdf,.jpg,.jpeg,.png">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($extraDocuments)): ?>
        <h4 style="margin-top:16px;">Additional Documents</h4>
        <ul style="list-style:none; padding:0; margin:0;">
          <?php foreach ($extraDocuments as $d): ?>
            <li class="mb-2">
              <a href="<?= documentViewUrl((int) $d['document_id']) ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px;">
                <?php if (isImageFile($d['file_name'])): ?>
                  <img src="<?= documentViewUrl((int) $d['document_id']) ?>" alt="<?= e($d['file_name']) ?>" style="width:40px; height:40px; object-fit:cover; border-radius:6px; border:1px solid var(--cream-dark);">
                <?php else: ?>
                  <span style="display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; background:var(--cream); border-radius:6px; font-size:16px;">📄</span>
                <?php endif; ?>
                <span><?= e($d['file_name']) ?> <?= $d['verified'] ? '✔ Verified' : '(pending review)' ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($canUpload): ?>
        <form method="POST" action="<?= url('parishioner/appointment-detail.php?id=' . $id) ?>" enctype="multipart/form-data" class="mt-3" id="uploadDocsForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="upload_documents">
          <div class="form-group">
            <label>Additional Documents (optional)</label>
            <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total. Files must be readable, correctly formatted (PDF/JPG/PNG), and portrait-oriented — these are checked automatically before final staff review.</p>
          </div>
          <button type="submit" class="btn btn-outline btn-sm"><?= $appointment['status_name'] === 'Rejected' ? 'Update Documents' : 'Upload' ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isAjax): ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
