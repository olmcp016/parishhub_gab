<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';

/**
 * Where PayMongo's hosted checkout sends the parishioner (or guest) back to
 * after paying a Mass Intention offering. Public — a guest has no account.
 * The redirect is NOT trusted as proof of payment: the checkout session is
 * always re-checked server-side with PayMongo before anything changes
 * (same approach as parishioner/donations.php). Safe to reload — every
 * step only acts on a still-pending payment.
 */
$appointmentId = (int) ($_GET['appointment_id'] ?? 0);
$cancelledReturn = isset($_GET['cancelled']);

$stmt = db()->prepare(
    "SELECT a.appointment_id, a.status_id, a.guest_reference, a.parishioner_id, p.payment_id, p.payment_status, t.gateway_transaction_id
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id AND s.category = 'Mass Intention'
     JOIN payments p ON p.appointment_id = a.appointment_id
     JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
     WHERE a.appointment_id = ?
     ORDER BY p.payment_id DESC LIMIT 1"
);
$stmt->execute([$appointmentId]);
$row = $stmt->fetch();

$outcome = 'unknown'; // paid | pending | failed | unknown
$message = 'We could not find that Mass Intention.';

if ($row) {
    $paymentId = (int) $row['payment_id'];
    $failMessage = 'The payment was not completed, so your Mass Intention was not submitted. No charge was made — please try again.';

    // The "cancelled" flag in the URL is never trusted on its own (anyone
    // could add it to someone else's link) — PayMongo's real session status
    // is the only thing that changes a payment's state (audit B-07). Only the
    // browser session that started this checkout (recorded in book.php) may
    // ask for it to be cancelled, and only a checkout PayMongo confirms expired
    // is cancelled.
    $cancelOutcome = null;
    if ($cancelledReturn && $row['payment_status'] === 'pending' && !empty($_SESSION['mi_checkout'][$appointmentId])) {
        $cancelOutcome = paymongoCancelPayment($paymentId);
        if ($cancelOutcome !== 'pending' && $cancelOutcome !== 'error') unset($_SESSION['mi_checkout'][$appointmentId]);
    } else {
        paymongoSyncAppointment($appointmentId);
    }
    $current = paymongoPaymentRow($paymentId);
    $status = $current['payment_status'] ?? 'pending';

    if ($status === 'verified') {
        // Verified = Confirmed: the parishioner's bell gets Appointment Confirmed (status trigger), nothing more.
        $outcome = 'paid';
    } elseif (in_array($status, ['failed', 'cancelled'], true)) {
        // The checkout can no longer be paid, so the Mass Intention is not submitted.
        db()->prepare("UPDATE appointments SET status_id = 7, cancelled_reason = 'Online payment was not completed' WHERE appointment_id = ? AND status_id = 2")->execute([$appointmentId]);
        $outcome = 'failed';
        $message = $failMessage;
    } elseif (paymongoNeedsReconciliation($paymentId)) {
        $outcome = 'pending';
        $message = "Your payment was received by PayMongo. We're finishing up on our end — the parish office will review it shortly.";
    } elseif ($cancelOutcome !== null) {
        $outcome = 'pending';
        $message = 'Your payment is still being processed by PayMongo, so it could not be cancelled. If it completes, your Mass Intention will be submitted.';
    } else {
        $outcome = 'pending';
        $message = "We're still waiting for PayMongo to confirm your payment. If you completed it, refresh this page in a moment.";
    }

    if ($outcome === 'paid') {
        $message = 'Thank you! Your offering was received and your Mass Intention has been submitted. Our Cashier will confirm it — once approved, it is confirmed for the Mass.';
        // The reference is the guest's only credential, so it is shown only to
        // the browser that started this checkout or to the owning parishioner
        // (audit H-01). Anyone else who knows the appointment ID gets no code.
        if ($row['guest_reference'] && canViewGuestReference((int) $row['appointment_id'])) {
            $message .= ' Your reference code is ' . $row['guest_reference'] . ' — save it to check its status anytime.';
        }
    }
}

// A verified payment completes the booking: go to Check Status (guests) or My
// Appointments (parishioners), where the Thank You dialog opens. Only when the
// viewer may see the reference code (see canViewGuestReference()).
if ($row && $outcome === 'paid' && $row['guest_reference'] && canViewGuestReference((int) $row['appointment_id'])) {
    rememberBookingThankYou((string) $row['guest_reference']);
    redirect(bookingReturnUrl(!usesParishionerShell()));
}

$isGuestView = !usesParishionerShell();
$active = 'services';
$pageTitle = 'Mass Intention Payment';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . ($isGuestView ? 'public-shell-start.php' : 'dash-start.php');
?>

<div class="card" style="max-width:640px; margin:0 auto; text-align:center;">
  <div style="font-size:48px; margin-bottom:8px;"><?= $outcome === 'paid' ? '✔' : ($outcome === 'failed' ? '⚠' : '⏳') ?></div>
  <h3><?= $outcome === 'paid' ? 'PAYMENT SUCCESSFUL' : ($outcome === 'failed' ? 'Payment Not Completed' : 'Mass Intention Payment') ?></h3>
  <p style="color: var(--brown-mid);"><?= e($message) ?></p>
  <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap; margin-top:18px;">
    <?php if ($row && $outcome !== 'failed'): ?>
      <?php if ($isGuestView && $row['guest_reference'] && canViewGuestReference((int) $row['appointment_id'])): ?>
        <a href="<?= url('status.php?ref=' . urlencode($row['guest_reference'])) ?>" class="btn btn-outline">Check Status</a>
      <?php elseif (!$isGuestView): ?>
        <a href="<?= url('parishioner/appointment-detail.php?id=' . (int) $row['appointment_id']) ?>" class="btn btn-outline">View Mass Intention</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= url('parishioner/services.php') ?>" class="btn btn-primary"><?= $outcome === 'failed' ? 'Try Again' : 'Back to Services' ?></a>
  </div>
</div>

<?php include __DIR__ . '/../includes/' . ($isGuestView ? 'public-shell-end.php' : 'dash-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
