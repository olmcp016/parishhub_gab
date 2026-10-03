<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';

/**
 * Public, no-account status lookup for a guest booking/Mass Intention/
 * donation — the guest equivalent of parishioner/appointment-detail.php,
 * read-only, keyed by the reference code shown on their confirmation
 * screen (see includes/auth.php's generateGuestReference()). The email/
 * phone field is an optional extra check when the guest supplied one;
 * the reference code alone is already a random, unguessable token.
 */
$reference = trim($_GET['ref'] ?? '');
$contact = trim($_GET['contact'] ?? '');
$searched = $reference !== '';
$appointment = null;
$notFound = false;

if ($searched) {
    $code = strtoupper($reference);
    if ($contact !== '') {
        $stmt = db()->prepare(
            "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, st.status_name, p.full_name AS priest_name
             FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             JOIN appointment_status st ON a.status_id = st.status_id
             LEFT JOIN priests p ON a.priest_id = p.priest_id
             WHERE a.guest_reference = ? AND (a.guest_email = ? OR a.guest_phone = ?)"
        );
        $stmt->execute([$code, $contact, $contact]);
    } else {
        $stmt = db()->prepare(
            "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, st.status_name, p.full_name AS priest_name
             FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             JOIN appointment_status st ON a.status_id = st.status_id
             LEFT JOIN priests p ON a.priest_id = p.priest_id
             WHERE a.guest_reference = ?"
        );
        $stmt->execute([$code]);
    }
    $appointment = $stmt->fetch() ?: null;
    $notFound = !$appointment;

    if ($appointment && $contact !== '') {
        $_SESSION['guest_status_verification'] = [
            'appointment_id' => (int) $appointment['appointment_id'],
            'expires_at' => time() + 900,
        ];
    }

    if ($appointment) {
        $stmt = db()->prepare('SELECT * FROM uploaded_documents WHERE appointment_id = ? AND superseded_by IS NULL ORDER BY requirement_label, document_id');
        $stmt->execute([$appointment['appointment_id']]);
        $documents = $stmt->fetchAll();
        $generatedForms = [];
        if (($appointment['category'] ?? '') === 'Wedding') {
            $stmt = db()->prepare('SELECT g.*, d.document_id, d.review_status, d.rejection_reason FROM generated_wedding_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.appointment_id = ? ORDER BY g.form_type');
            $stmt->execute([$appointment['appointment_id']]); $generatedForms = $stmt->fetchAll();
        }
        $stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $intention = $stmt->fetch() ?: null;

        $stmt = db()->prepare('SELECT * FROM donations WHERE appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $donation = $stmt->fetch() ?: null;

        $stmt = db()->prepare(
            "SELECT p.*, pm.method_name FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id
             WHERE p.appointment_id = ? ORDER BY p.payment_id DESC LIMIT 1"
        );
        $stmt->execute([$appointment['appointment_id']]);
        $payment = $stmt->fetch() ?: null;
    }
}

// A guest can pay for their own Approved regular-service appointment right
// here — no login needed, since the reference code they already had to know
// to reach this page IS the credential (see guest-pay.php). Mass Intentions
// and Donations are excluded: those are paid at submission time, not here.
$onlineUnfinished = $payment && (int) $payment['method_id'] === 7 && $payment['payment_status'] === 'pending';
$appointmentSnapshot = $appointment && !empty($appointment['fee_snapshot']) ? (json_decode($appointment['fee_snapshot'], true) ?: []) : [];
$displayFee = array_key_exists('total', $appointmentSnapshot) ? (float) $appointmentSnapshot['total'] : (float) ($appointment['fee'] ?? 0);
$canGuestPay = $appointment
    && $appointment['status_name'] === 'Approved'
    && !in_array($appointment['category'], ['Mass Intention', 'Donation'], true)
    && $appointment['pss_classification'] !== 'pending_verification'
    && $displayFee > 0
    && (!$payment || $onlineUnfinished || in_array($payment['payment_status'], ['failed', 'cancelled'], true));

$pageTitle = 'Check Status';
$__user = currentUser();
$publicNavActive = 'status';
include __DIR__ . '/includes/header.php';
?>
<?php include __DIR__ . '/includes/public-nav.php'; ?>

<div style="max-width:700px; margin:0 auto; padding: 28px 20px 60px;">
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <h1 style="margin:0 0 8px;">Check Your Status</h1>
  <p class="text-muted" style="margin-bottom:24px;">Look up a guest booking, Mass Intention, or donation using the reference code from your confirmation screen.</p>

  <div class="card">
    <form method="GET" action="<?= url('status.php') ?>">
      <div class="form-group">
        <label>Reference Code</label>
        <input type="text" name="ref" value="<?= e($reference) ?>" placeholder="PH-XXXXXX" required style="text-transform:uppercase;">
      </div>
      <div class="form-group">
        <label>Email or Phone Number (if you provided one)</label>
        <input type="text" name="contact" value="<?= e($contact) ?>" placeholder="Leave blank if you didn't provide one">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Check Status</button>
    </form>
  </div>

  <?php if ($notFound): ?>
    <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2; margin-top:20px;">
      No matching record found. Double-check your reference code and, if you entered one, your email/phone.
    </div>
  <?php elseif ($appointment): ?>
    <div class="card" style="margin-top:20px;">
      <div class="card-header">
        <h3><?= e($appointment['service_name']) ?></h3>
        <?php if ($appointment['category'] === 'Mass Intention'): $miStatus = massIntentionStatusDisplay($appointment['status_name'], !empty($payment) && !((int) $payment['method_id'] === 7 && $payment['payment_status'] !== 'verified')); ?>
          <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
        <?php else: ?>
          <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
        <?php endif; ?>
      </div>
      <p><strong>Reference:</strong> <?= e($appointment['guest_reference']) ?></p>
      <?php if ($appointment['status_name'] === 'Payment Verified'): ?>
        <p class="text-muted">Payment verified — awaiting appointment confirmation.</p>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Wedding'): ?>
        <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Wedding Forms</h4>
        <?php foreach ($generatedForms as $gf): ?>
          <p><strong><?= e(weddingFormDefinition($gf['form_type'])['title']) ?></strong><br>
          Status: <?= e(weddingFormStatusLabel($gf['status'])) ?>
          <a class="btn btn-outline btn-sm" href="<?= url('wedding-form.php?appointment_id=' . (int) $appointment['appointment_id'] . '&form_type=' . urlencode($gf['form_type'])) ?>"><?= $gf['form_type'] === 'matrimony_application' ? ($gf['status'] === 'rejected' ? 'Edit and Regenerate Form' : 'Edit Form') : 'Edit / View' ?></a>
          <?php if ($gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>"><?= $gf['form_type'] === 'matrimony_application' ? 'View Generated Form' : 'View PDF' ?></a><?php endif; ?>
          <?php if ($gf['rejection_reason']): ?><br><span class="text-muted">Reason: <?= e($gf['rejection_reason']) ?></span><?php endif; ?></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Funeral'): ?>
        <?php
          $gfQuery = db()->prepare("SELECT f.*, d.review_status, d.verified FROM generated_funeral_forms f LEFT JOIN uploaded_documents d ON d.document_id = f.document_id WHERE f.appointment_id = ? AND f.form_type = 'katin_awan_paglubong'");
          $gfQuery->execute([$appointment['appointment_id']]);
          $gf = $gfQuery->fetch() ?: null;
          $gfStatus = $gf ? $gf['status'] : 'draft';
        ?>
        <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Funeral Form</h4>
        <p><strong>Katin-awan sa Paglubong</strong><br>
        Status: <?php
          if ($gfStatus === 'draft') echo '<span class="badge badge-rejected">Missing</span>';
          elseif ($gfStatus === 'rejected') echo '<span class="badge badge-rejected">Rejected</span>';
          elseif ($gfStatus === 'generated' && (($gf['review_status'] ?? 'pending') === 'approved' || ($gf['verified'] ?? false))) echo '<span class="badge badge-verified">Verified/Accepted</span>';
          else echo '<span class="badge badge-pending">Generated — Pending Review</span>';
        ?>
        <a class="btn btn-outline btn-sm" href="<?= url('funeral-form.php?appointment_id=' . (int) $appointment['appointment_id']) ?>"><?= $gfStatus === 'rejected' ? 'Edit and Regenerate Form' : ($gfStatus === 'generated' ? 'Edit Form' : 'Fill Out Form') ?></a>
        <?php if ($gf && $gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View Generated Form</a><?php endif; ?>
        <?php if ($gf && $gf['rejection_reason']): ?><br><span class="text-muted">Reason: <?= e($gf['rejection_reason']) ?></span><?php endif; ?></p>
      <?php endif; ?>
      <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
      <?php if ($appointment['category'] !== 'Donation'): ?>
        <p><strong>Priest:</strong> <?= e($appointment['priest_name'] ?? 'Not yet assigned') ?></p>
        <p><strong>Fee:</strong>
          <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification
          <?php elseif ($appointmentSnapshot): ?><?= feeLabel($displayFee) ?>
          <?php else: ?><?= feeLabel((float) $appointment['fee']) ?><?php endif; ?>
        </p>
        <?php if ($documents && $contact !== ''): ?>
          <div class="card" style="margin-top:18px;">
            <div class="card-header"><h3>Submitted Documents</h3></div>
            <?php foreach ($documents as $document): ?>
              <?php $docStatus = $document['review_status'] ?? ($document['verified'] ? 'approved' : 'pending'); ?>
              <div style="padding:12px 0; border-bottom:1px solid var(--cream-dark);">
                <strong><?= e($document['requirement_label'] ?: $document['file_name']) ?></strong>
                <span class="badge badge-<?= $docStatus === 'approved' ? 'verified' : ($docStatus === 'rejected' ? 'rejected' : 'pending') ?>">
                  <?= $docStatus === 'approved' ? 'Approved' : ($docStatus === 'rejected' ? 'Needs Replacement' : 'Pending Review') ?>
                </span>
                <div class="text-muted" style="font-size:13px; margin-top:4px;">File: <?= e($document['file_name']) ?> · <?= e(formatDate($document['uploaded_at'])) ?></div>
                <?php if ($docStatus === 'rejected' && $document['rejection_reason']): ?>
                  <p style="margin:6px 0;"><strong>Secretary's Note:</strong> <?= e($document['rejection_reason']) ?></p>
                <?php endif; ?>
                <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
                  <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $document['document_id']) ?>">View Document</a>
                  <?php if ($docStatus === 'rejected'): ?>
                    <form method="POST" action="<?= url('guest-document-replace.php') ?>" enctype="multipart/form-data" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                      <?= csrfField() ?><input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>"><input type="hidden" name="document_id" value="<?= (int) $document['document_id'] ?>"><input type="hidden" name="ref" value="<?= e($reference) ?>">
                      <input type="file" name="replacement" accept=".pdf,.jpg,.jpeg,.png" required>
                      <button class="btn btn-primary btn-sm" type="submit">Replace Document</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($appointment['location_address'])): ?><p><strong>Address to Bless:</strong> <?= nl2br(e($appointment['location_address'])) ?></p><?php endif; ?>
      <?php if (!empty($appointment['contact_phone'])): ?><p><strong>Contact Phone:</strong> <?= e($appointment['contact_phone']) ?></p><?php endif; ?>
      <?php if ($appointment['rejection_reason']): ?><p><strong>Reason:</strong> <?= e($appointment['rejection_reason']) ?></p><?php endif; ?>
      <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>

      <?php if (!empty($intention)): ?>
        <hr style="border-color: var(--cream-dark); margin: 18px 0;">
        <h4>Mass Intention Details</h4>
        <p><strong>Type:</strong> <?= e($intention['intention_type']) ?></p>
        <p><strong>Offerer:</strong> <?= e($intention['offerer_name']) ?></p>
        <p><strong>Intention For:</strong> <?= e($intention['intention_for']) ?></p>
      <?php endif; ?>

      <?php if (!empty($donation)): ?>
        <hr style="border-color: var(--cream-dark); margin: 18px 0;">
        <h4>Donation Details</h4>
        <p><strong>Purpose:</strong> <?= e($donation['purpose']) ?></p>
      <?php endif; ?>

      <?php if (!empty($payment)): ?>
        <hr style="border-color: var(--cream-dark); margin: 18px 0;">
        <h4>Payment</h4>
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

      <?php if ($canGuestPay): ?>
        <hr style="border-color: var(--cream-dark); margin: 18px 0;">
        <h4>Pay Now</h4>
        <form method="POST" action="<?= url('guest-pay.php') ?>" id="guestPayForm">
          <?= csrfField() ?>
          <input type="hidden" name="appointment_id" value="<?= $appointment['appointment_id'] ?>">
          <input type="hidden" name="ref" value="<?= e($appointment['guest_reference']) ?>">
          <div class="form-group">
            <label>Amount</label>
            <input type="number" value="<?= e((string) $displayFee) ?>" step="0.01" readonly disabled>
          </div>
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
          <button type="submit" class="btn btn-primary btn-block" id="guestPaySubmitBtn">Pay Online Now</button>
          <p class="helper-text mt-2" id="guestPayHint">You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there.</p>
        </form>
        <script>
        (function () {
          var form = document.getElementById('guestPayForm');
          function update() {
            var mode = form.querySelector('input[name="pay_mode"]:checked').value;
            document.getElementById('guestPaySubmitBtn').textContent = mode === 'online' ? 'Pay Online Now' : 'Submit (Pay at Parish Office)';
            document.getElementById('guestPayHint').textContent = mode === 'online'
              ? "You'll be taken to PayMongo's secure page to pay. Once it's completed, our cashier and secretary take it from there."
              : 'Please bring your payment to the parish office. Our cashier will verify it.';
          }
          form.querySelectorAll('input[name="pay_mode"]').forEach(function (r) { r.addEventListener('change', update); });
          update();
        })();
        </script>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
