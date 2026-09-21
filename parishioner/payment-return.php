<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
requireRole('Parishioner');

/**
 * Where PayMongo's hosted checkout sends a parishioner back after paying an
 * approved service's fee (see parishioner/pay.php). The redirect is NOT
 * trusted as proof of payment — the checkout session is re-checked with
 * PayMongo before anything changes, and the "cancelled" flag alone never
 * changes anything (only PayMongo's real status, or the same browser session
 * that started the payment, can). Safe to reload — it only acts on a
 * still-pending payment. Unlike Mass Intentions, a failed/cancelled payment
 * never cancels the appointment itself: the parishioner just pays again.
 */
$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$appointmentId = (int) ($_GET['appointment_id'] ?? 0);
$cancelledReturn = isset($_GET['cancelled']);

$stmt = db()->prepare(
    "SELECT a.appointment_id, p.payment_id, p.payment_status, t.gateway_transaction_id
     FROM appointments a
     JOIN payments p ON p.appointment_id = a.appointment_id
     JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
     WHERE a.appointment_id = ? AND a.parishioner_id = ?
     ORDER BY p.payment_id DESC LIMIT 1"
);
$stmt->execute([$appointmentId, $parishionerId]);
$row = $stmt->fetch();

$outcome = 'unknown'; // paid | pending | failed | unknown
$message = 'We could not find that payment.';
$failMessage = 'The payment was not completed. No charge was made — you can try again from your appointment page.';

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
                error_log("Payment $paymentId confirmed by PayMongo but verification failed: {$result['message']}");
                $outcome = 'pending';
                $message = "Your payment was received by PayMongo. We're finishing up on our end — it will show as verified shortly.";
            }
        } elseif (in_array($localStatus, ['failed', 'cancelled'], true)) {
            markPaymentUnsuccessful($paymentId, $localStatus);
            $outcome = 'failed';
            $message = $failMessage;
        } elseif ($cancelledReturn) {
            if (!empty($_SESSION['pay_checkout'][$appointmentId])) {
                markPaymentUnsuccessful($paymentId, 'cancelled');
                db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$paymentId]);
                unset($_SESSION['pay_checkout'][$appointmentId]);
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

$active = 'appointments';
$pageTitle = 'Payment';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width:640px; margin:0 auto; text-align:center;">
  <div style="font-size:48px; margin-bottom:8px;"><?= $outcome === 'paid' ? '✔' : ($outcome === 'failed' ? '⚠' : '⏳') ?></div>
  <h3><?= $outcome === 'paid' ? 'Payment Received' : ($outcome === 'failed' ? 'Payment Not Completed' : 'Payment') ?></h3>
  <p style="color: var(--brown-mid);"><?= e($message) ?></p>
  <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap; margin-top:18px;">
    <?php if ($row): ?>
      <a href="<?= url('parishioner/appointment-detail.php?id=' . (int) $row['appointment_id']) ?>" class="btn btn-primary"><?= $outcome === 'failed' ? 'Try Again' : 'View Appointment' ?></a>
    <?php endif; ?>
    <a href="<?= url('parishioner/appointments.php') ?>" class="btn btn-outline">My Appointments</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
