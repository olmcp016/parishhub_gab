<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document-validation.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same silent-post_max_size-wipe protection as the booking form — see book.php for details.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        $limit = ini_get('post_max_size');
        flash('error', "Your uploaded files were too large for the server to accept (total limit is $limit). Please upload smaller files or fewer at a time.");
        redirect(url('parishioner/appointment-detail.php?id=' . $id));
    }

    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $stmt = db()->prepare(
            "UPDATE appointments SET status_id = 7, cancelled_reason = ? WHERE appointment_id = ? AND parishioner_id = ?"
        );
        $stmt->execute([$_POST['reason'] ?? 'Cancelled by parishioner', $id, $parishionerId]);
        logActivity($userId, "Cancelled appointment #$id", 'Appointments');
        flash('success', 'Appointment cancelled.');
        redirect(url('parishioner/appointments.php'));
    }

    if ($action === 'upload_documents') {
        // Confirm this appointment actually belongs to the logged-in parishioner
        $stmt = db()->prepare(
            "SELECT a.appointment_id, s.requirements FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             WHERE a.appointment_id = ? AND a.parishioner_id = ?"
        );
        $stmt->execute([$id, $parishionerId]);
        $appt = $stmt->fetch();
        if (!$appt) {
            flash('error', 'Appointment not found.');
            redirect(url('parishioner/appointments.php'));
        }

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
                flash('error', DOCUMENT_VALIDATION_ERROR);
                redirect(url('parishioner/appointment-detail.php?id=' . $id));
            }
        }

        $uploaded = 0;
        $uploadDir = __DIR__ . '/../public/uploads/';
        foreach ($pendingUploads as $upload) {
            $file = $upload['file'];
            $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
            $dest = $uploadDir . $safeName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $stmt = db()->prepare(
                    "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$id, $file['name'], 'public/uploads/' . $safeName, $file['type'], $upload['label']]);
                $uploaded++;
            }
        }

        if ($uploaded > 0) {
            logActivity($userId, "Uploaded $uploaded document(s) for appointment #$id", 'Appointments');
            flash('success', "$uploaded document(s) uploaded successfully.");
        }
        if (!empty($skippedFiles)) {
            $limit = ini_get('upload_max_filesize');
            flash('error', 'The following file(s) were too large (max ' . $limit . ' each) and were NOT uploaded: ' . implode(', ', $skippedFiles) . '.');
        }
        if ($uploaded === 0 && empty($skippedFiles)) {
            flash('error', 'No files were selected.');
        }
        redirect(url('parishioner/appointment-detail.php?id=' . $id));
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

$stmt = db()->prepare('SELECT * FROM uploaded_documents WHERE appointment_id = ?');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

$active = 'appointments';
$pageTitle = 'Appointment #' . $appointment['appointment_id'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;">
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
    <p><strong>Fee:</strong> <?= feeLabel((float) $appointment['fee']) ?></p>
    <?php if ($appointment['category'] === 'Funeral' && $appointment['date_of_death']): ?>
      <p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['remarks']): ?><p><strong>Remarks:</strong> <?= e($appointment['remarks']) ?></p><?php endif; ?>
    <?php if ($appointment['rejection_reason']): ?><p><strong>Rejection Reason:</strong> <?= e($appointment['rejection_reason']) ?></p><?php endif; ?>
    <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>

    <?php if ($appointment['status_name'] === 'Pending'): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Our secretary is reviewing your request and required documents. You'll be notified once it's approved.
      </div>
    <?php elseif ($appointment['status_name'] === 'Rejected'): ?>
      <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;">
        This request was not approved<?= $appointment['rejection_reason'] ? ' — see the reason above.' : '.' ?>
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
      <form method="POST" action="<?= url('parishioner/appointment-detail.php?id=' . $id) ?>" class="mt-3" onsubmit="return confirm('Cancel this appointment?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="reason" value="Cancelled by parishioner">
        <button type="submit" class="btn btn-danger btn-sm">Cancel Appointment</button>
      </form>
    <?php endif; ?>
  </div>

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
        <form method="POST" action="<?= url('parishioner/pay.php') ?>" id="payForm" <?= $payment ? 'class="mt-3"' : '' ?>>
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
            <label class="radio-option" style="display:block; margin-bottom:8px;">
              <input type="radio" name="pay_mode" value="cash">
              Pay in cash at the parish office
            </label>
            <label class="radio-option" style="display:block;">
              <input type="radio" name="pay_mode" value="manual">
              I already paid by GCash / Maya / Bank Transfer — enter my reference number
            </label>
          </div>
          <div id="payManualFields" style="display:none;">
            <div class="form-group">
              <label>Payment Method</label>
              <select name="method_id">
                <option value="2">GCash</option>
                <option value="3">Maya</option>
                <option value="4">Bank Transfer</option>
              </select>
            </div>
            <div class="form-group">
              <label>Payment Reference Number</label>
              <input type="text" name="payment_reference" placeholder="Reference / transaction no.">
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block" id="paySubmitBtn">Pay Online Now</button>
          <p class="helper-text mt-2" id="payHint">You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there.</p>
        </form>
        <script>
        (function () {
          var form = document.getElementById('payForm');
          function update() {
            var mode = form.querySelector('input[name="pay_mode"]:checked').value;
            document.getElementById('payManualFields').style.display = mode === 'manual' ? 'block' : 'none';
            form.querySelector('[name="payment_reference"]').required = mode === 'manual';
            document.getElementById('paySubmitBtn').textContent = mode === 'online' ? 'Pay Online Now' : 'Submit Payment';
            document.getElementById('payHint').textContent = mode === 'online'
              ? "You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there."
              : 'After paying, please wait for our cashier to verify it, then wait for your schedule to be confirmed.';
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
      $requirementsList = parseRequirementsList($appointment['requirements']);
      $documentsByLabel = [];
      $extraDocuments = [];
      foreach ($documents as $d) {
          if ($d['requirement_label'] && in_array($d['requirement_label'], $requirementsList, true)) {
              $documentsByLabel[$d['requirement_label']][] = $d;
          } else {
              $extraDocuments[] = $d;
          }
      }
      $canUpload = in_array($appointment['status_name'], ['Pending', 'Approved'], true);
    ?>
    <div class="card">
      <div class="card-header"><h3>Required Documents</h3></div>
      <?php if (empty($requirementsList)): ?>
        <p class="text-muted">No specific documents are required for this service.</p>
      <?php else: ?>
        <?php foreach ($requirementsList as $i => $label): ?>
          <?php $matches = $documentsByLabel[$label] ?? []; ?>
          <div class="form-group doc-req-row">
            <label>
              <?= e($label) ?>
              <?php if (empty($matches)): ?>
                <span class="badge badge-cancelled">Missing</span>
              <?php elseif (array_reduce($matches, fn($carry, $d) => $carry || $d['verified'], false)): ?>
                <span class="badge badge-verified">Verified/Accepted</span>
              <?php else: ?>
                <span class="badge badge-pending">Uploaded — Pending Review</span>
              <?php endif; ?>
            </label>
            <?php foreach ($matches as $d): ?>
              <p style="margin:2px 0;">
                <a href="<?= documentUrl($d['file_path']) ?>" target="_blank" rel="noopener"><?= e($d['file_name']) ?></a>
              </p>
            <?php endforeach; ?>
            <?php if (empty($matches) && $canUpload): ?>
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
              <a href="<?= documentUrl($d['file_path']) ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px;">
                <?php if (isImageFile($d['file_name'])): ?>
                  <img src="<?= documentUrl($d['file_path']) ?>" alt="<?= e($d['file_name']) ?>" style="width:40px; height:40px; object-fit:cover; border-radius:6px; border:1px solid var(--cream-dark);">
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
          <button type="submit" class="btn btn-outline btn-sm">Upload</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
