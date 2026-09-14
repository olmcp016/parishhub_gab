<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

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
            "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name
             FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             JOIN appointment_status st ON a.status_id = st.status_id
             LEFT JOIN priests p ON a.priest_id = p.priest_id
             WHERE a.guest_reference = ? AND (a.guest_email = ? OR a.guest_phone = ?)"
        );
        $stmt->execute([$code, $contact, $contact]);
    } else {
        $stmt = db()->prepare(
            "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name
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

    if ($appointment) {
        $stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $intention = $stmt->fetch() ?: null;

        $stmt = db()->prepare('SELECT * FROM donations WHERE appointment_id = ?');
        $stmt->execute([$appointment['appointment_id']]);
        $donation = $stmt->fetch() ?: null;

        $stmt = db()->prepare(
            "SELECT p.*, pm.method_name FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id WHERE p.appointment_id = ?"
        );
        $stmt->execute([$appointment['appointment_id']]);
        $payment = $stmt->fetch() ?: null;
    }
}

$pageTitle = 'Check Status';
$__user = currentUser();
include __DIR__ . '/includes/header.php';
?>

<nav class="public-nav">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <div class="links">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('about.php') ?>">About</a>
    <a href="<?= url('parishioner/services.php') ?>">Services</a>
    <a href="<?= url('parishioner/calendar.php') ?>">Calendar</a>
    <a href="<?= url('parishioner/announcements.php') ?>">Announcements</a>
    <?php if ($__user): ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-primary btn-sm">Dashboard</a>
    <?php else: ?>
      <a href="<?= url('auth/login.php') ?>">Sign In</a>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Get Started</a>
    <?php endif; ?>
  </div>
</nav>

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
        <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
      </div>
      <p><strong>Reference:</strong> <?= e($appointment['guest_reference']) ?></p>
      <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
      <?php if ($appointment['category'] !== 'Donation'): ?>
        <p><strong>Priest:</strong> <?= e($appointment['priest_name'] ?? 'Not yet assigned') ?></p>
        <p><strong>Fee:</strong> <?= feeLabel((float) $appointment['fee']) ?></p>
      <?php endif; ?>
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
        <p><strong>Status:</strong> <span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e($payment['payment_status']) ?></span></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
