<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paymongo.php';

/**
 * Guest equivalent of parishioner/payment-return.php — where PayMongo's
 * hosted checkout sends a guest back after paying an approved service's fee
 * (see guest-pay.php). Same trust model as guest-pay.php: the guest_reference
 * code is the credential, not a login. The redirect itself is never trusted
 * as proof of payment — the checkout session is always re-checked with
 * PayMongo first. Safe to reload. A failed/cancelled payment never cancels
 * the appointment — the guest can just pay again from status.php.
 */
$appointmentId = (int) ($_GET['appointment_id'] ?? 0);
$reference = strtoupper(trim($_GET['ref'] ?? ''));
$cancelledReturn = isset($_GET['cancelled']);
$statusUrl = url('status.php') . '?ref=' . urlencode($reference);

// Proof that this browser started the checkout (set in guest-pay.php). The
// reference in this URL alone is not enough to read or change a payment
// (audit H-02). Without the marker, PayMongo's webhook still reconciles it.
$hasCheckoutProof = !empty($_SESSION['guest_pay_checkout'][$appointmentId]);

$row = null;
if ($hasCheckoutProof) {
    $stmt = db()->prepare(
        "SELECT a.appointment_id, p.payment_id, p.payment_status, t.gateway_transaction_id
         FROM appointments a
         JOIN payments p ON p.appointment_id = a.appointment_id
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE a.appointment_id = ? AND a.guest_reference = ?
         ORDER BY p.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId, $reference]);
    $row = $stmt->fetch();
}

$outcome = 'unknown'; // paid | pending | failed | unknown
$message = 'We could not find that payment.';
$failMessage = 'The payment was not completed. No charge was made — you can try again from your status page.';
if (!$hasCheckoutProof) {
    $outcome = 'pending';
    $message = "We're confirming your payment with PayMongo. Check your status page in a moment.";
}

if ($row) {
    $paymentId = (int) $row['payment_id'];

    // PayMongo decides (audit B-07). A cancel link only cancels once the checkout
    // is confirmed expired; otherwise every unresolved checkout of this
    // appointment is re-checked, since an older one may have been paid too.
    $cancelOutcome = null;
    if ($cancelledReturn && $row['payment_status'] === 'pending') {
        $cancelOutcome = paymongoCancelPayment($paymentId);
        if ($cancelOutcome !== 'pending' && $cancelOutcome !== 'error') unset($_SESSION['guest_pay_checkout'][$appointmentId]);
    } else {
        paymongoSyncAppointment($appointmentId);
    }
    $current = paymongoPaymentRow($paymentId);
    $status = $current['payment_status'] ?? 'pending';

    if ($status === 'verified') {
        $outcome = 'paid';
    } elseif (in_array($status, ['failed', 'cancelled'], true)) {
        $outcome = 'failed';
        $message = $failMessage;
    } elseif (paymongoNeedsReconciliation($paymentId)) {
        $outcome = 'pending';
        $message = "Your payment was received by PayMongo. We're finishing up on our end — the parish office will review it shortly.";
    } elseif ($cancelOutcome !== null) {
        $outcome = 'pending';
        $message = 'Your payment is still being processed by PayMongo, so it could not be cancelled. If it completes, it will show as paid on your status page.';
    } else {
        $outcome = 'pending';
        $message = "We're still waiting for PayMongo to confirm your payment. If you completed it, refresh this page in a moment.";
    }

    if ($outcome === 'paid') {
        $message = 'Thank you! Your payment was received and verified — an official receipt has been issued. Please wait for your schedule to be confirmed.';
    }
}

$pageTitle = 'Payment';
$__user = currentUser();
$publicNavActive = '';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/public-nav.php'; ?>

<div style="max-width:640px; margin:0 auto; padding: 100px 20px 60px;">
  <div class="card" style="text-align:center;">
    <div style="font-size:48px; margin-bottom:8px;"><?= $outcome === 'paid' ? '✔' : ($outcome === 'failed' ? '⚠' : '⏳') ?></div>
    <h3><?= $outcome === 'paid' ? 'PAYMENT SUCCESSFUL' : ($outcome === 'failed' ? 'Payment Not Completed' : 'Payment') ?></h3>
    <p style="color: var(--brown-mid);"><?= e($message) ?></p>
    <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap; margin-top:18px;">
      <?php if ($row): ?>
        <a href="<?= e($statusUrl) ?>" class="btn btn-primary"><?= $outcome === 'failed' ? 'Try Again' : 'View Status' ?></a>
      <?php else: ?>
        <a href="<?= url('status.php') ?>" class="btn btn-primary">Check Status</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
