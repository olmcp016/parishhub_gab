<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paymongo.php';

/**
 * Guest equivalent of parishioner/pay.php — for a guest booking's regular
 * (non-Mass-Intention, non-Donation) service, once it's Approved. A guest
 * has no login, so "ownership" is proven by knowing the random guest_reference
 * code (shown on their confirmation screen / needed to look themselves up on
 * status.php) rather than a session — same trust boundary status.php itself
 * already uses. The fee is always derived server-side from the service
 * record, never trusted from the client.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('status.php'));
}
verifyCsrf();

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$reference = strtoupper(trim($_POST['ref'] ?? ''));
$statusUrl = url('status.php') . '?ref=' . urlencode($reference);

if ($reference === '') {
    flash('error', 'Missing reference code.');
    redirect(url('status.php'));
}

$stmt = db()->prepare(
    "SELECT a.appointment_id, s.fee, s.category, s.service_name, a.guest_email
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.appointment_id = ? AND a.guest_reference = ? AND st.status_name = 'Approved'
       AND s.category NOT IN ('Mass Intention', 'Donation')"
);
$stmt->execute([$appointmentId, $reference]);
$appointment = $stmt->fetch();

if (!$appointment) {
    flash('error', 'Appointment not found or not eligible for payment.');
    redirect($statusUrl);
}

$amount = (float) $appointment['fee'];

// A payment already in progress or completed blocks a second one. A failed
// or cancelled payment — or an online checkout that was started but never
// finished — does not: the guest can simply try again.
$stmt = db()->prepare(
    "SELECT p.payment_id, p.method_id, p.payment_status, t.gateway_transaction_id
     FROM payments p LEFT JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
     WHERE p.appointment_id = ? ORDER BY p.payment_id DESC LIMIT 1"
);
$stmt->execute([$appointmentId]);
$existing = $stmt->fetch();
if ($existing) {
    $unfinishedOnline = (int) $existing['method_id'] === 7 && $existing['payment_status'] === 'pending';
    if ($unfinishedOnline && $existing['gateway_transaction_id']) {
        $session = paymongoGetCheckoutSession($existing['gateway_transaction_id']);
        if ($session['ok'] && paymongoStatusToLocal($session['status']) === 'verified') {
            verifyPaymentAndIssueReceipt((int) $existing['payment_id'], null, $existing['gateway_transaction_id']);
            flash('success', 'Your online payment was already received — thank you!');
            redirect($statusUrl);
        }
        markPaymentUnsuccessful((int) $existing['payment_id'], 'cancelled');
        db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$existing['payment_id']]);
    } elseif (!in_array($existing['payment_status'], ['failed', 'cancelled'], true)) {
        flash('error', 'A payment has already been submitted for this appointment.');
        redirect($statusUrl);
    }
}

$payMode = $_POST['pay_mode'] ?? '';
$methodId = null;
$reference2 = null;
if ($payMode === 'online') {
    $methodId = 7; // PayMongo (Online)
    if (!($amount > 0)) {
        flash('error', 'There is nothing to pay online for this appointment.');
        redirect($statusUrl);
    }
} elseif ($payMode === 'cash') {
    $methodId = 1;
} elseif ($payMode === 'manual') {
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $reference2 = trim($_POST['payment_reference'] ?? '');
    if (!in_array($methodId, [2, 3, 4], true) || strlen($reference2) < 4) {
        flash('error', 'Please choose GCash, Maya, or Bank Transfer and enter the payment reference number of your completed payment.');
        redirect($statusUrl);
    }
} else {
    flash('error', 'Please choose how you would like to pay.');
    redirect($statusUrl);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->execute([$appointmentId, $reference2, $amount, $methodId]);
    $paymentId = $pdo->lastInsertId();

    if ($payMode === 'online') {
        $returnBase = absoluteUrl('guest-payment-return.php') . '?appointment_id=' . $appointmentId . '&ref=' . urlencode($reference);
        $checkout = paymongoCreateCheckoutSession(
            $amount,
            $appointment['service_name'],
            $returnBase . '&paid=1',
            $returnBase . '&cancelled=1',
            $appointment['guest_email'] ?: null
        );
        if (!$checkout['ok'] || !$checkout['checkout_url']) {
            $pdo->rollBack();
            error_log('PayMongo checkout session creation failed (guest service payment): ' . json_encode($checkout['raw'] ?? []));
            flash('error', 'Could not start the online payment (' . ($checkout['error'] ?: 'please try again') . '). Nothing was charged.');
            redirect($statusUrl);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (payment_id, gateway, gateway_transaction_id, status, raw_response) VALUES (?, 'paymongo', ?, 'pending', ?)"
        );
        $stmt->execute([$paymentId, $checkout['session_id'], json_encode($checkout['raw'])]);
        $pdo->commit();
        $_SESSION['guest_pay_checkout'][$appointmentId] = true; // lets only THIS browser cancel it on return
        logActivity(null, "Guest started an online payment for appointment #$appointmentId via PayMongo", 'Payments');
        redirect($checkout['checkout_url']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    flash('error', 'Failed to submit your payment. Please try again.');
    redirect($statusUrl);
}

logActivity(null, "Guest submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our cashier shortly.');
redirect($statusUrl);
