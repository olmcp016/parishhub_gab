<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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
        $stmt = db()->prepare('SELECT appointment_id FROM appointments WHERE appointment_id = ? AND parishioner_id = ?');
        $stmt->execute([$id, $parishionerId]);
        if (!$stmt->fetch()) {
            flash('error', 'Appointment not found.');
            redirect(url('parishioner/appointments.php'));
        }

        $uploaded = 0;
        $skippedFiles = [];
        if (!empty($_FILES['documents']['name'][0])) {
            $uploadDir = __DIR__ . '/../public/uploads/';
            foreach ($_FILES['documents']['name'] as $i => $name) {
                $err = $_FILES['documents']['error'][$i];
                if ($err !== UPLOAD_ERR_OK) {
                    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                        $skippedFiles[] = $name;
                    }
                    continue;
                }
                $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $dest = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $dest)) {
                    $stmt = db()->prepare(
                        "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$id, $name, 'public/uploads/' . $safeName, $_FILES['documents']['type'][$i]]);
                    $uploaded++;
                }
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
    "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name
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

$stmt = db()->prepare(
    "SELECT p.*, pm.method_name FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id WHERE p.appointment_id = ?"
);
$stmt->execute([$id]);
$payment = $stmt->fetch() ?: null;

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
      <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
    </div>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
    <p><strong>Priest:</strong> <?= e($appointment['priest_name'] ?? 'Not yet assigned') ?></p>
    <p><strong>Fee:</strong> <?= money($appointment['fee']) ?></p>
    <?php if ($appointment['category'] === 'Funeral' && $appointment['date_of_death']): ?>
      <p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['remarks']): ?><p><strong>Remarks:</strong> <?= e($appointment['remarks']) ?></p><?php endif; ?>
    <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>

    <?php if ($appointment['status_name'] === 'Pending'): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Our secretary is reviewing your request and required documents. You'll be notified once it's approved.
      </div>
    <?php elseif ($appointment['status_name'] === 'Approved' && $payment && $payment['payment_status'] === 'pending'): ?>
      <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);">
        Your payment has been submitted and is awaiting verification by our treasurer. Once verified, please wait for your appointment to be confirmed — you'll receive a notification.
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
        <p><strong>Reference #:</strong> <?= e($payment['reference_number']) ?></p>
        <p><strong>Status:</strong> <span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e($payment['payment_status']) ?></span></p>
        <?php if ($payment['payment_status'] === 'pending'): ?>
          <p class="text-muted" style="font-size:13px;">Awaiting verification by our treasurer.</p>
        <?php elseif ($payment['payment_status'] === 'verified'): ?>
          <p class="text-muted" style="font-size:13px;">✔ Verified — please wait for your schedule to be confirmed.</p>
        <?php endif; ?>
      <?php elseif ($appointment['status_name'] === 'Approved'): ?>
        <form method="POST" action="<?= url('parishioner/pay.php') ?>">
          <?= csrfField() ?>
          <input type="hidden" name="appointment_id" value="<?= $id ?>">
          <div class="form-group">
            <label>Amount</label>
            <input type="number" name="amount" value="<?= e((string)$appointment['fee']) ?>" step="0.01" required>
          </div>
          <div class="form-group">
            <label>Payment Method</label>
            <select name="method_id" required>
              <option value="1">Cash (pay at parish office)</option>
              <option value="2">GCash</option>
              <option value="3">Maya</option>
              <option value="4">Bank Transfer</option>
              <option value="5">Credit/Debit Card</option>
            </select>
          </div>
          <div class="form-group">
            <label>Reference Number</label>
            <input type="text" name="reference_number" placeholder="Transaction reference" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Submit Payment</button>
          <p class="helper-text mt-2">After paying, please wait for our treasurer to verify it, then wait for your schedule to be confirmed.</p>
        </form>
      <?php else: ?>
        <p class="text-muted">Payment will be available once your appointment is approved.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>Uploaded Documents</h3></div>
      <?php if (empty($documents)): ?>
        <p class="text-muted">No documents uploaded yet.</p>
      <?php else: ?>
        <ul style="list-style:none; padding:0; margin:0;">
          <?php foreach ($documents as $d): ?>
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

      <?php if (in_array($appointment['status_name'], ['Pending', 'Approved'], true)): ?>
        <form method="POST" action="<?= url('parishioner/appointment-detail.php?id=' . $id) ?>" enctype="multipart/form-data" class="mt-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="upload_documents">
          <div class="form-group">
            <label>Upload More Documents</label>
            <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total.</p>
          </div>
          <button type="submit" class="btn btn-outline btn-sm">Upload</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
