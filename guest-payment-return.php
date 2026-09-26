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

$outcome = 'unknown'; // paid | pending | failed | unknown
$message = 'We could not find that payment.';
$failMessage = 'The payment was not completed. No charge was made — you can try again from your status page.';

if ($row) {
    $paymentId = (int) $row['payment_id'];

    if ($row['payment_status'] === 'verified') {
        $outcome = 'paid';
    } elseif (in_array($row['payment_status'], ['failed', 'cancelled'], true)) {
        $outcome = 'failed';
        $message = $failMessage;
    } else {
        $session = paymongoGetCheckoutSession($row['gateway_transaction_id']);
        $localStatus = $session['ok'] ? paymongoStatusToLocal($session['status']) : 'pending';
        db()->prepare('UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ?')
            ->execute([$session['ok'] ? $session['status'] : 'unknown', json_encode($session['raw'] ?? []), $paymentId]);

        if ($localStatus === 'verified') {
            $result = verifyPaymentAndIssueReceipt($paymentId, null, $row['gateway_transaction_id']);
            if ($result['ok']) {
                $outcome = 'paid';
            } else {
                error_log("Guest payment $paymentId confirmed by PayMongo but verification failed: {$result['message']}");
                $outcome = 'pending';
                $message = "Your payment was received by PayMongo. We're finishing up on our end — it will show as verified shortly.";
            }
        } elseif (in_array($localStatus, ['failed', 'cancelled'], true)) {
            markPaymentUnsuccessful($paymentId, $localStatus);
            $outcome = 'failed';
            $message = $failMessage;
        } elseif ($cancelledReturn) {
            if (!empty($_SESSION['guest_pay_checkout'][$appointmentId])) {
                markPaymentUnsuccessful($paymentId, 'cancelled');
                db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$paymentId]);
                unset($_SESSION['guest_pay_checkout'][$appointmentId]);
            }
            $outcome = 'failed';
            $message = $failMessage;
        } else {
            $outcome = 'pending';
            $message = "We're still waiting for PayMongo to confirm your payment. If you completed it, refresh this page in a moment.";
        }
    }

    if ($outcome === 'paid') {
        $message = 'Thank you! Your payment was received and verified — an official receipt has been issued. Please wait for your schedule to be confirmed.';
    }
}

$pageTitle = 'Payment';
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

<div style="max-width:640px; margin:0 auto; padding: 28px 20px 60px;">
  <div class="card" style="text-align:center;">
    <div style="font-size:48px; margin-bottom:8px;"><?= $outcome === 'paid' ? '✔' : ($outcome === 'failed' ? '⚠' : '⏳') ?></div>
    <h3><?= $outcome === 'paid' ? 'Payment Received' : ($outcome === 'failed' ? 'Payment Not Completed' : 'Payment') ?></h3>
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
