<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/supporting-documents.php';
require_once __DIR__ . '/includes/generated-form-workflow.php';
require_once __DIR__ . '/includes/requirements-review.php';
require_once __DIR__ . '/includes/funeral-schedule.php';
require_once __DIR__ . '/includes/service-fees.php'; // feeTotalNeedsNoPayment(), NO_PAYMENT_REQUIRED_LABEL
require_once __DIR__ . '/includes/certificate-workflow.php';

/**
 * Public, no-account status lookup for a guest booking/Mass Intention/
 * donation — the guest equivalent of parishioner/appointment-detail.php,
 * read-only, keyed by the reference code shown on their confirmation
 * screen (see includes/auth.php's generateGuestReference()). The email or
 * phone used for the booking is required too, and failed lookups are
 * throttled per connection, so a reference code alone is not enough (H-02).
 */
// Set by a just-finished booking (see rememberBookingThankYou()); shown once in the Thank You dialog below.
$thankYouReference = pullBookingThankYou();
$reference = trim($_GET['ref'] ?? '');
$contact = trim($_GET['contact'] ?? '');
// Signed, expiring link from a revision-request email (includes/requirements-review.php):
// stands in for the email/phone, but only for that one booking and only until it expires.
$accessToken = trim((string) ($_GET['access'] ?? ''));
$searched = $reference !== '';
$appointment = null;
$notFound = false;
$lookupError = null;
// Filled in only when a booking is found (below); empty otherwise, so the page
// also works before a reference is entered or when nothing matches.
$payment = null;
$isRegisteredBooking = false;
$documents = [];
$generatedForms = [];
$intention = null;
$donation = null;
$officialCertificate = null;

if ($searched) {
    $code = strtoupper($reference);
    // The reference alone is no longer enough (audit H-02): the email or phone
    // used for the booking must match too, and failed lookups are throttled.
    $ipKey = 'status_ip:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $lockedFor = throttleSecondsLeft($ipKey);
    if ($contact === '' && $accessToken !== '' && $lockedFor > 0) {
        $lookupError = 'Too many lookups from this connection. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).';
    } elseif ($contact === '' && $accessToken !== '') {
        $stmt = db()->prepare(
            "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, st.status_name, p.full_name AS priest_name
             FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             JOIN appointment_status st ON a.status_id = st.status_id
             LEFT JOIN priests p ON a.priest_id = p.priest_id
             WHERE a.guest_reference = ?"
        );
        $stmt->execute([$code]);
        $candidate = $stmt->fetch() ?: null;
        if ($candidate && guestAccessTokenValid((int) $candidate['appointment_id'], $code, $accessToken)) {
            $appointment = $candidate;
        } else {
            throttleRecordFailure($ipKey, 10, 15 * 60);
            $lookupError = 'This booking link has expired or is not valid. Enter your reference code and the email or phone you used when booking.';
        }
    } elseif ($contact === '') {
        $lookupError = 'Enter the email or phone number you gave when booking, together with your reference code.';
    } elseif ($lockedFor > 0) {
        $lookupError = 'Too many lookups from this connection. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).';
    } else {
        // A guest booking matches its own guest email/phone. A registered
        // Parishioner's booking (guest fields empty) matches the linked
        // account's email/phone or the booking's contact phone — never for the
        // shared guest placeholder account, or its address would unlock every
        // guest booking. Email case and phone format are normalized.
        $lookup = statusLookupContact($contact);
        $stmt = db()->prepare(
            "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, st.status_name, p.full_name AS priest_name
             FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             JOIN appointment_status st ON a.status_id = st.status_id
             LEFT JOIN priests p ON a.priest_id = p.priest_id
             LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
             LEFT JOIN users u ON u.user_id = par.user_id
             WHERE a.guest_reference = ?
               AND (LOWER(TRIM(a.guest_email)) = ? OR " . statusLookupPhoneSql('a.guest_phone') . " = ?
                    OR (a.parishioner_id <> ? AND (LOWER(TRIM(u.email)) = ?
                        OR " . statusLookupPhoneSql('u.phone') . " = ? OR " . statusLookupPhoneSql('a.contact_phone') . " = ?)))"
        );
        $stmt->execute([$code, $lookup['email'], $lookup['phone'], guestParishionerId(), $lookup['email'], $lookup['phone'], $lookup['phone']]);
        $appointment = $stmt->fetch() ?: null;
        if (!$appointment) {
            throttleRecordFailure($ipKey, 10, 15 * 60);
        }
    }
    $notFound = $lookupError === null && !$appointment;
    if ($appointment) {
        if ($contact === '') {
            $contact = (string) ($appointment['guest_email'] ?: $appointment['guest_phone']);
        }
        // A registered Parishioner's booking found here is view-only: it gets its own
        // read-only verification (honored only by document.php for viewing), never
        // guest_status_verification, which the upload/form/revision pages accept.
        // Changes to an account's booking require logging in.
        $isRegisteredBooking = guestParishionerId() > 0 && (int) $appointment['parishioner_id'] !== guestParishionerId();
        $_SESSION[$isRegisteredBooking ? 'status_view_verification' : 'guest_status_verification'] = [
            'appointment_id' => (int) $appointment['appointment_id'],
            'expires_at' => time() + 900,
        ];
    }

    if ($appointment) {
        $stmt = db()->prepare("SELECT * FROM uploaded_documents WHERE appointment_id = ? AND superseded_by IS NULL AND (document_source IS NULL OR document_source <> 'generated') ORDER BY requirement_label, document_id");
        $stmt->execute([$appointment['appointment_id']]);
        $documents = $stmt->fetchAll();
        $generatedForms = [];
        if (($appointment['category'] ?? '') === 'Wedding') {
            $stmt = db()->prepare('SELECT g.*, d.document_id, d.review_status, d.rejection_reason FROM generated_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.service_category = \'Wedding\' AND g.appointment_id = ? ORDER BY g.form_type');
            $stmt->execute([$appointment['appointment_id']]); $generatedForms = $stmt->fetchAll();
        } elseif (($appointment['category'] ?? '') === 'Baptism') {
            $stmt = db()->prepare('SELECT g.*, d.document_id, d.review_status, d.rejection_reason FROM generated_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.service_category = \'Baptism\' AND g.appointment_id = ? ORDER BY g.form_type');
            $stmt->execute([$appointment['appointment_id']]); $generatedForms = $stmt->fetchAll();
        }
        $stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $intention = $stmt->fetch() ?: null;

        $stmt = db()->prepare('SELECT d.*, pr.project_name FROM donations d LEFT JOIN projects pr ON pr.project_id = d.project_id WHERE d.appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $donation = $stmt->fetch() ?: null;

        $stmt = db()->prepare(
            "SELECT p.*, pm.method_name FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id
             WHERE p.appointment_id = ? ORDER BY p.payment_id DESC LIMIT 1"
        );
        $stmt->execute([$appointment['appointment_id']]);
        $payment = $stmt->fetch() ?: null;

        // Official Parish Certificate (Certificate Request or a completed
        // sacrament's auto-issued copy) — guest equivalent of the same block
        // on parishioner/appointment-detail.php. document.php already honors
        // $_SESSION['guest_status_verification'] (set just above) for this
        // appointment, so the link below works for a guest with no account.
        $officialCertificate = issuedCertificateLoad((int) $appointment['appointment_id']);
    }
}

// A guest can pay for their own Approved regular-service appointment right
// here, with no login. The reference code and the booking's email or phone
// together are the credential (see guest-pay.php). Mass Intentions
// and Donations are excluded: those are paid at submission time, not here.
$onlineUnfinished = $payment && (int) $payment['method_id'] === 7 && $payment['payment_status'] === 'pending';
$appointmentSnapshot = $appointment && !empty($appointment['fee_snapshot']) ? (json_decode($appointment['fee_snapshot'], true) ?: []) : [];
$displayFee = array_key_exists('total', $appointmentSnapshot) ? (float) $appointmentSnapshot['total'] : (float) ($appointment['fee'] ?? 0);
$canGuestPay = $appointment
    && !$isRegisteredBooking // an account's booking is paid after logging in (parishioner/pay.php)
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

<div style="max-width:700px; margin:0 auto; padding: 100px 20px 60px;">
  <?php include __DIR__ . '/includes/flash.php'; ?>
  <?php // The nav bar already names this page: the heading stays for screen readers only (same as public-shell-start.php). ?>
  <h1 style="position:absolute; width:1px; height:1px; margin:-1px; padding:0; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0;">Check Your Status</h1>
  <p class="text-muted" style="margin:0 0 24px;">Look up a booking, Mass Intention, or donation using the reference code from your confirmation screen and the email or phone number used for it.</p>

  <div class="card">
    <form method="GET" action="<?= url('status.php') ?>">
      <div class="form-group">
        <label>Reference Code</label>
        <input type="text" name="ref" value="<?= e($reference) ?>" placeholder="PH-XXXXXXXX" required style="text-transform:uppercase;">
      </div>
      <div class="form-group">
        <label>Email or Phone Number used for the booking</label>
        <input type="text" name="contact" value="<?= e($contact) ?>" placeholder="Email or 09XXXXXXXXX" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Check Status</button>
    </form>
  </div>

  <?php if ($lookupError !== null): ?>
    <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2; margin-top:20px;">
      <?= e($lookupError) ?>
    </div>
  <?php elseif ($notFound): ?>
    <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2; margin-top:20px;">
      No matching record found. Double-check your reference code and the email or phone you gave when booking.
    </div>
  <?php elseif ($appointment): ?>
    <div class="card" style="margin-top:20px;" id="booking-requirements">
      <div class="card-header">
        <h3><?= e($appointment['service_name']) ?></h3>
        <?php if ($appointment['category'] === 'Mass Intention'): $miStatus = massIntentionStatusDisplay($appointment['status_name'], !empty($payment) && !((int) $payment['method_id'] === 7 && $payment['payment_status'] !== 'verified')); ?>
          <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
        <?php else: ?>
          <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
        <?php endif; ?>
      </div>
      <p><strong>Booking Reference:</strong> <?= e($appointment['guest_reference']) ?></p>
      <?php if ($isRegisteredBooking): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
          This booking belongs to a ParishHub account, so this page is view-only.
          <a href="<?= url('auth/login.php') ?>"><strong>Log in</strong></a> to upload documents, edit forms, or pay.
        </div>
      <?php endif; ?>
      <?php if (!empty($officialCertificate['document_id']) && in_array((int) $appointment['status_id'], [5, 6], true)): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
          <strong>📜 Official Certificate of <?= e($officialCertificate['certificate_type']) ?></strong>
          <?= ($officialCertificate['status'] ?? '') === 'claimed' ? 'was claimed.' : 'is ready.' ?>
          <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
            <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $officialCertificate['document_id']) ?>">View / Download Official Certificate</a>
          </div>
          <?php if (($officialCertificate['status'] ?? '') === 'ready_for_pickup'): ?>
            <p class="helper-text" style="margin:8px 0 0;">Your certified copy is ready for pick-up at the parish office. Please bring a valid ID to claim the stamped and dry-sealed original.</p>
          <?php elseif (($officialCertificate['status'] ?? '') === 'claimed'): ?>
            <p class="helper-text" style="margin:8px 0 0;">You've already claimed the stamped and dry-sealed original at the parish office<?= !empty($officialCertificate['claimed_at']) ? ' on ' . e(formatDate((string) $officialCertificate['claimed_at'])) : '' ?>. The link above is a digital copy for your records.</p>
          <?php else: ?>
            <p class="helper-text" style="margin:8px 0 0;">This is a digital preview. Visit the parish office to claim the stamped and dry-sealed original.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($appointment['status_name'] === 'Payment Verified'): ?>
        <p class="text-muted">Payment verified — awaiting appointment confirmation.</p>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Wedding'): ?>
        <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Wedding Forms</h4>
        <?php foreach ($generatedForms as $gf): $state = generatedFormWorkflowState($gf, $gf, false); ?>
          <div class="generated-form-item">
            <div class="generated-form-header">
              <span class="generated-form-title"><?= e(weddingFormDefinition($gf['form_type'])['title']) ?></span>
              <span class="generated-form-status">Status: <?= generatedFormStatusBadge($state) ?></span>
              <div class="text-muted" style="margin-top:4px; font-size:.9rem;"><?= e($state['description']) ?></div>
              <?php if ($gf['rejection_reason']): ?><div class="text-muted" style="margin-top:4px; font-size:0.9rem;">Reason: <?= e($gf['rejection_reason']) ?></div><?php endif; ?>
            </div>
            <div class="generated-form-actions">
              <?php if (!$isRegisteredBooking && $state['code'] !== 'approved' && (int) $appointment['status_id'] === 1): ?><a class="btn btn-outline btn-sm" href="<?= url('wedding-form.php?appointment_id=' . (int) $appointment['appointment_id'] . '&form_type=' . urlencode($gf['form_type'])) ?>"><?= e(generatedFormWorkflowActionLabel($state)) ?></a><?php endif; ?>
              <?php if ($gf['document_id']): ?>
                <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>"><?= $gf['form_type'] === 'matrimony_application' ? 'View Generated Form' : 'View PDF' ?></a>
                <a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . (int) $gf['document_id'] . '&download=1') ?>">Download PDF</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Baptism'): ?>
        <div style="background: #fffdf7; border: 1px solid rgba(197, 160, 89, 0.35); border-left: 4px solid var(--gold); padding: 12px 14px; border-radius: 8px; margin: 16px 0; color: var(--brown-dark); font-size: 13.5px; line-height: 1.55;">
          <div style="display: flex; gap: 10px; align-items: flex-start;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gold-dark); flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <div>
              <strong style="color: var(--brown-dark);">Note:</strong><br>
              Kinahanglan anaa na sa simbahan <strong>1 ka oras sa dili pa ang gitakdang oras</strong> sa bunyag kay adunay himoon nga <strong>Pre-Jordan Seminar</strong>
              <div style="margin-top: 8px; font-weight: 600; color: #855d14;">
                (Bayad: ₱50.00).
              </div>
            </div>
          </div>
        </div>
        <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Baptism Forms</h4>
        <?php foreach ($generatedForms as $gf): $state = generatedFormWorkflowState($gf, $gf, false); ?>
          <div class="generated-form-item">
            <div class="generated-form-header">
              <span class="generated-form-title"><?= e(baptismFormDefinition($gf['form_type'])['title']) ?></span>
              <span class="generated-form-status">Status: <?= generatedFormStatusBadge($state) ?></span>
              <div class="text-muted" style="margin-top:4px; font-size:.9rem;"><?= e($state['description']) ?></div>
              <?php if ($gf['rejection_reason']): ?><div class="text-muted" style="margin-top:4px; font-size:0.9rem;">Reason: <?= e($gf['rejection_reason']) ?></div><?php endif; ?>
            </div>
            <div class="generated-form-actions">
              <?php if (!$isRegisteredBooking && $state['code'] !== 'approved' && (int) $appointment['status_id'] === 1): ?><a class="btn btn-outline btn-sm" href="<?= url('baptism-draft-form.php?appointment_id=' . (int) $appointment['appointment_id'] . '&form_type=' . urlencode($gf['form_type'])) ?>"><?= e(generatedFormWorkflowActionLabel($state)) ?></a><?php endif; ?>
              <?php if ($gf['document_id']): ?>
                <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View PDF</a>
                <a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . (int) $gf['document_id'] . '&download=1') ?>">Download PDF</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Funeral'): ?>
        <?php
          $gfQuery = db()->prepare("SELECT f.*, d.review_status, d.verified FROM generated_forms f LEFT JOIN uploaded_documents d ON d.document_id = f.document_id WHERE f.service_category = 'Funeral' AND f.appointment_id = ? AND f.form_type = 'katin_awan_paglubong'");
          $gfQuery->execute([$appointment['appointment_id']]);
          $gf = $gfQuery->fetch() ?: null;
          $state = generatedFormWorkflowState($gf, $gf, false);
          $funeralApproved = $state['code'] === 'approved';
          // Editable while pending, and after approval when the Parish Office asked for an update (schedule changed).
          $katinEditable = !$isRegisteredBooking && !$funeralApproved && ((int) $appointment['status_id'] === 1 || (in_array((int) $appointment['status_id'], [2, 4], true) && $state['code'] === 'needs_revision'));
        ?>
        <hr style="border-color:var(--cream-dark); margin:18px 0;"><h4>Funeral Form</h4>
        <div class="generated-form-item">
          <div class="generated-form-header">
            <span class="generated-form-title">Katin-awan sa Paglubong</span>
            <span class="generated-form-status">Status: <?= generatedFormStatusBadge($state) ?></span>
            <div class="text-muted" style="margin-top:4px; font-size:.9rem;"><?= e($state['description']) ?></div>
            <?php if ($gf && $gf['rejection_reason']): ?><div class="text-muted" style="margin-top:4px; font-size:0.9rem;">Reason: <?= e($gf['rejection_reason']) ?></div><?php endif; ?>
            <?php $katinMismatch = $gf ? funeralKatinAwanScheduleMismatch($appointment, json_decode((string) ($gf['form_data'] ?? ''), true) ?: []) : null; ?>
            <?php if ($katinMismatch) renderFuneralKatinAwanMismatchAlert($katinMismatch, $state['code'] === 'needs_revision' ? 'requested' : ($katinEditable ? 'editable' : 'wait'), $appointment, ' margin:8px 0 0;'); ?>
          </div>
          <div class="generated-form-actions">
            <?php if ($katinEditable): ?><a class="btn btn-outline btn-sm" href="<?= url('funeral-form.php?appointment_id=' . (int) $appointment['appointment_id']) ?>"><?= e(generatedFormWorkflowActionLabel($state)) ?></a><?php endif; ?>
            <?php if ($gf && $gf['document_id']): ?>
              <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View Generated Form</a>
              <a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . (int) $gf['document_id'] . '&download=1') ?>">Download PDF</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php
        require_once __DIR__ . '/includes/appointment-approval.php';
        $isPastDateUnpaid = (int) ($appointment['status_id'] ?? 0) === 2
            && !empty($appointment['appointment_date'])
            && $appointment['appointment_date'] < date('Y-m-d')
            && !in_array($appointment['category'] ?? '', ['Mass Intention', 'Donation'], true)
            && !feeTotalNeedsNoPayment($appointment['category'] ?? '', $displayFee)
            && (empty($payment) || !in_array($payment['payment_status'] ?? '', ['pending', 'verified'], true));
        $paymentDeadline = ((int) ($appointment['status_id'] ?? 0) === 2 && !feeTotalNeedsNoPayment($appointment['category'] ?? '', $displayFee))
            ? appointmentPaymentDeadline($appointment)
            : null;
      ?>
      <?php if ($isPastDateUnpaid): ?>
        <div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; margin:14px 0;">
          <strong>⚠️ Scheduled Date Has Passed:</strong> Your appointment was scheduled for <?= formatDate($appointment['appointment_date']) ?> and remains unpaid. Per parish policy, your booking has <strong>not</strong> been cancelled. Please contact or visit the Parish Office to coordinate a new schedule with our Parish Secretary.
        </div>
      <?php endif; ?>
      <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?><?= $appointment['appointment_time'] ? ' at ' . date('g:i A', strtotime($appointment['appointment_time'])) : ' (To be scheduled)' ?></p>
      <?php if ($paymentDeadline && !$isPastDateUnpaid && (int) $appointment['status_id'] === 2): ?>
        <p><strong>Payment Deadline:</strong> <span style="color:#b45309; font-weight:600;"><?= e($paymentDeadline->format('F j, Y \a\t g:i A')) ?></span></p>
      <?php endif; ?>
      <?php if ($appointment['category'] === 'Funeral'): // burial and Funeral Mass are one schedule ?>
        <?php if (!empty($appointment['date_of_death'])): ?><p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?></p><?php endif; ?>
        <?php renderFuneralScheduleSummary($appointment); ?>
      <?php endif; ?>
      <?php if ($appointment['category'] !== 'Donation'): ?>
        <p><strong>Priest:</strong> <?= !empty($appointment['outside_priest_name']) ? e($appointment['outside_priest_name']) . ' <span class="badge badge-info" style="font-size:11px; margin-left:4px;">Preferred Priest</span>' : e($appointment['priest_name'] ?? 'Not yet assigned') ?></p>
        <p><strong>Fee:</strong>
          <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification
          <?php elseif ($appointmentSnapshot): ?><?= feeTotalNeedsNoPayment($appointment['category'], $displayFee) ? NO_PAYMENT_REQUIRED_LABEL : feeLabel($displayFee) ?>
          <?php else: ?><?= feeLabel((float) $appointment['fee']) ?><?php endif; ?>
          <?php if (array_key_exists('precana_fee', $appointmentSnapshot)): ?>
            <br><span class="text-muted" style="font-size:13px;">Includes Pre-Cana Seminar (<?= e((string) ($appointmentSnapshot['precana_type'] ?? '')) ?>): <?= (float) $appointmentSnapshot['precana_fee'] > 0 ? money((float) $appointmentSnapshot['precana_fee']) : 'No Fees' ?></span>
          <?php endif; ?>
        </p>
        <?php if ($appointment['category'] === 'Wedding'): ?>
          <?php renderPrecanaSummary($appointment); ?>
          <?php if (precanaCertificateDeferred($appointment)):
            // Parish Pre-Cana: the certificate is uploaded after the seminar, before confirmation.
            $precanaCertificate = precanaCertificateStatus((int) $appointment['appointment_id']);
            $canUploadCertificate = !$isRegisteredBooking && precanaCertificateOpen($appointment) && in_array($precanaCertificate['status'], ['missing', 'rejected'], true);
            $documents = array_values(array_filter($documents, static fn(array $d): bool => !precanaIsCertificateLabel($d['requirement_label']))); ?>
            <h4 style="margin-bottom:4px;"><?= e(PRECANA_CERTIFICATE_LABEL) ?></h4>
            <p class="helper-text" style="margin-top:0;">Upload it after you attend your Pre-Cana seminar. It is not needed for approval, but the Parish Office needs it before your Wedding is confirmed.</p>
            <?php renderSupportingDocumentCards([PRECANA_CERTIFICATE_LABEL], $precanaCertificate['document'] ? [PRECANA_CERTIFICATE_LABEL => $precanaCertificate['document']] : [], 'guest_appointment', (int) $appointment['appointment_id'], $canUploadCertificate); ?>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($documents): ?>
          <div class="card" style="margin-top:18px;">
            <div class="card-header"><h3>Submitted Documents</h3></div>
            <?php foreach ($documents as $document): ?>
              <?php $docStatus = $document['review_status'] ?? ($document['verified'] ? 'approved' : 'pending'); ?>
              <div style="padding:12px 0; border-bottom:1px solid var(--cream-dark);">
                <strong><?= e($document['requirement_label'] ?: $document['file_name']) ?></strong>
                <span class="badge badge-<?= $docStatus === 'approved' ? 'verified' : ($docStatus === 'rejected' ? 'rejected' : 'pending') ?>">
                  <?= $docStatus === 'approved' ? 'Approved' : ($docStatus === 'rejected' ? 'Needs Revision' : 'Pending Review') ?>
                </span>
                <div class="text-muted" style="font-size:13px; margin-top:4px;">File: <?= e($document['file_name']) ?> · <?= e(formatDate($document['uploaded_at'])) ?></div>
                <?php if ($docStatus === 'rejected' && $document['rejection_reason']): ?>
                  <p style="margin:6px 0;"><strong>Secretary's Note:</strong> <?= e($document['rejection_reason']) ?></p>
                <?php endif; ?>
                <div class="flex gap-2" style="margin-top:8px; flex-wrap:wrap;">
                  <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $document['document_id']) ?>">View Document</a>
                </div>
                <?php if (!$isRegisteredBooking && $docStatus === 'rejected' && (int) $appointment['status_id'] === 1): ?>
                  <?php $replacementLabel = $document['requirement_label'] ?: $document['file_name']; ?>
                  <?php renderSupportingDocumentCards([$replacementLabel], [$replacementLabel => $document], 'guest_appointment', (int) $appointment['appointment_id']); ?>
                <?php elseif ($docStatus === 'rejected' && (int) $appointment['status_id'] === 3): ?>
                  <p class="text-muted" style="margin:8px 0 0;">This booking was rejected as a whole. Please submit a new booking instead of replacing individual documents.</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($appointment['requester_name'])): ?><p><strong>Requested by:</strong> <?= e($appointment['requester_name']) ?></p><?php endif; ?>
      <?php if (!empty($appointment['patient_name'])): ?><p><strong>Sick Person's Name:</strong> <?= e($appointment['patient_name']) ?></p><?php endif; ?>
      <?php if (!empty($appointment['location_address'])): ?><p><strong>Address to Bless:</strong> <?= nl2br(e($appointment['location_address'])) ?></p><?php endif; ?>
      <?php if (!empty($appointment['contact_phone'])): ?><p><strong>Contact Phone:</strong> <?= e($appointment['contact_phone']) ?></p><?php endif; ?>
      <?php if ($appointment['rejection_reason']): ?><p><strong>Reason:</strong> <?= e($appointment['rejection_reason']) ?></p><?php endif; ?>
      <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>
      <?php if (!empty($appointment['remarks'])): ?><p><strong>Remarks / Details:</strong> <?= nl2br(e($appointment['remarks'])) ?></p><?php endif; ?>

      <?php if (!$isRegisteredBooking && requirementsReviewAvailable() && ($appointment['requirements_review_status'] ?? null) !== null && (int) $appointment['status_id'] === 1):
        $guestReviewItems = requirementsReviewItems((int) $appointment['appointment_id']);
        $guestReviewState = requirementsReviewState($appointment['requirements_review_status'], requirementsReviewCounts($guestReviewItems));
        $guestStillNeedsRevision = array_values(array_filter($guestReviewItems, static fn(array $i): bool => $i['review_status'] === 'rejected'));
      ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark); margin-top:16px;">
          <p style="margin-top:0;"><strong>Requirements status:</strong> <span class="badge badge-<?= e($guestReviewState['badge']) ?>"><?= e($guestReviewState['label']) ?></span></p>
          <?php if ($guestReviewState['code'] === 'awaiting_revision'): ?>
            <p style="margin:0 0 10px;">
              <?= $guestStillNeedsRevision
                  ? 'Update each item marked Needs Revision (' . e(implode(', ', array_map(static fn($i) => $i['label'], $guestStillNeedsRevision))) . '), then submit them for review.'
                  : 'All items marked Needs Revision have been updated. Submit them so the parish office can review them again.' ?>
            </p>
            <form method="POST" action="<?= url('guest-requirements-submit.php') ?>">
              <?= csrfField() ?><input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
              <button type="submit" class="btn btn-primary">Submit Updated Requirements</button>
            </form>
          <?php else: ?>
            <p style="margin:0;">Your updated requirements were submitted. The parish office will review them and email you if anything else is needed.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

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
        <p><strong>Allocation:</strong> <?= e(donationAllocationLabel($donation)) ?></p>
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
        <h4 id="booking-payment">Pay Now</h4>
        <form method="POST" action="<?= url('guest-pay.php') ?>" id="guestPayForm">
          <?= csrfField() ?>
          <input type="hidden" name="appointment_id" value="<?= $appointment['appointment_id'] ?>">
          <input type="hidden" name="ref" value="<?= e($appointment['guest_reference']) ?>">
          <input type="hidden" name="contact" value="<?= e($contact) ?>">
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

      <?php if (in_array((int) $appointment['status_id'], [2, 5], true)): ?>
        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 18px; margin:20px 0 10px; font-size:13.5px; color:#92400e; line-height:1.5;">
          <strong style="display:flex; align-items:center; gap:6px; font-size:14px; margin-bottom:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Emergency &amp; Rescheduling Advisory
          </strong>
          <p style="margin:0 0 10px;">
            We understand that unforeseen emergencies and unavoidable circumstances happen. If you or your family cannot make it on your scheduled appointment date, please notify the Parish Office as soon as possible.
            <strong>Your booking and payment will be fully honored and will not be forfeited.</strong> Our parish staff will assist you in rescheduling your service to another available date.
          </p>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', parishContactNumber())) ?>" class="btn btn-sm btn-outline" style="color:#92400e; border-color:#d97706; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
              Call Office: <?= e(parishContactNumber()) ?>
            </a>
            <a href="mailto:<?= e(parishContactEmail()) ?>?subject=<?= e(urlencode('Reschedule Request - ' . ($appointment['guest_reference'] ?: 'Appointment #' . $appointment['appointment_id']))) ?>" class="btn btn-sm btn-outline" style="color:#92400e; border-color:#d97706; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
              Email Office
            </a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php $thankYouIsGuest = true; include __DIR__ . "/includes/thank-you-modal.php"; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
