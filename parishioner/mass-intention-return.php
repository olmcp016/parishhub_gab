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
    // below is the only thing that changes a payment's state.
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
                if ($row['parishioner_id']) {
                    $stmt = db()->prepare('SELECT u.user_id FROM parishioners par JOIN users u ON par.user_id = u.user_id WHERE par.parishioner_id = ? AND u.email != ?');
                    $stmt->execute([$row['parishioner_id'], 'guest@parishhub.internal']);
                    if ($uid = $stmt->fetchColumn()) {
                        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Mass Intention Submitted', ?)")
                            ->execute([$uid, "Your Mass Intention (#$appointmentId) and payment were received. Our Cashier will confirm it shortly."]);
                    }
                }
            } else {
                error_log("Mass Intention payment $paymentId confirmed by PayMongo but verification failed: {$result['message']}");
                $outcome = 'pending';
                $message = "Your payment was received by PayMongo. We're finishing up on our end — your Mass Intention will appear for our Cashier shortly.";
            }
        } elseif (in_array($localStatus, ['failed', 'cancelled'], true)) {
            markPaymentUnsuccessful($paymentId, $localStatus);
            db()->prepare("UPDATE appointments SET status_id = 7, cancelled_reason = 'Online payment was not completed' WHERE appointment_id = ? AND status_id = 2")->execute([$appointmentId]);
            $outcome = 'failed';
            $message = $failMessage;
        } elseif ($cancelledReturn) {
            // Only the browser session that actually started this checkout can
            // cancel it locally (recorded in book.php) — so nobody can cancel
            // someone else's unfinished payment just by editing a URL.
            if (!empty($_SESSION['mi_checkout'][$appointmentId])) {
                markPaymentUnsuccessful($paymentId, 'cancelled');
                db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$paymentId]);
                db()->prepare("UPDATE appointments SET status_id = 7, cancelled_reason = 'Online payment was not completed' WHERE appointment_id = ? AND status_id = 2")->execute([$appointmentId]);
                unset($_SESSION['mi_checkout'][$appointmentId]);
            }
            $outcome = 'failed';
            $message = $failMessage;
        } else {
            $outcome = 'pending';
            $message = "We're still waiting for PayMongo to confirm your payment. If you completed it, refresh this page in a moment.";
        }
    }

    if ($outcome === 'paid') {
        $message = 'Thank you! Your offering was received and your Mass Intention has been submitted. Our Cashier will confirm it — once approved, it is confirmed for the Mass.';
        if ($row['guest_reference']) {
            $message .= ' Your reference code is ' . $row['guest_reference'] . ' — save it to check its status anytime.';
        }
    }
}

$isGuestView = !usesParishionerShell();
$active = 'services';
$pageTitle = 'Mass Intention Payment';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . ($isGuestView ? 'public-shell-start.php' : 'dash-start.php');
?>

<div class="card" style="max-width:640px; margin:0 auto; text-align:center;">
  <div style="font-size:48px; margin-bottom:8px;"><?= $outcome === 'paid' ? '✔' : ($outcome === 'failed' ? '⚠' : '⏳') ?></div>
  <h3><?= $outcome === 'paid' ? 'Payment Received' : ($outcome === 'failed' ? 'Payment Not Completed' : 'Mass Intention Payment') ?></h3>
  <p style="color: var(--brown-mid);"><?= e($message) ?></p>
  <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap; margin-top:18px;">
    <?php if ($row && $outcome !== 'failed'): ?>
      <?php if ($row['guest_reference']): ?>
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
