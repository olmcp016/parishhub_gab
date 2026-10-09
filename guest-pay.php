<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paymongo.php';
require_once __DIR__ . '/includes/service-fees.php';

/**
 * Guest equivalent of parishioner/pay.php — for a guest booking's regular
 * (non-Mass-Intention, non-Donation) service, once it's Approved. A guest
 * has no login, so "ownership" is proven by the guest_reference code together
 * with the email or phone used for the booking (the same pair status.php
 * requires). Failed attempts are throttled. The fee is always derived server-side from the service
 * record, never trusted from the client.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('status.php'));
}
verifyCsrf();

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$reference = strtoupper(trim($_POST['ref'] ?? ''));
$contact = trim($_POST['contact'] ?? '');
$statusUrl = url('status.php') . '?ref=' . urlencode($reference) . '&contact=' . urlencode($contact);

if ($reference === '') {
    flash('error', 'Missing reference code.');
    redirect(url('status.php'));
}
// The reference alone is not proof of ownership (audit H-02). The email or
// phone used for the booking must match too. Failed attempts share the
// status lookup throttle, so this cannot be used to guess references.
$ipKey = 'status_ip:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
if ($contact === '') {
    flash('error', 'Enter the email or phone number you gave when booking, to pay for this appointment.');
    redirect(url('status.php') . '?ref=' . urlencode($reference));
}
$lockedFor = throttleSecondsLeft($ipKey);
if ($lockedFor > 0) {
    flash('error', 'Too many attempts from this connection. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).');
    redirect($statusUrl);
}

$stmt = db()->prepare(
    "SELECT a.appointment_id, a.schedule_type, a.pss_classification, a.sponsor_count, a.wedding_sponsor_count, a.fee_snapshot,
            s.fee, s.category, s.service_name, a.guest_email
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.appointment_id = ? AND a.guest_reference = ? AND (a.guest_email = ? OR a.guest_phone = ?)
       AND st.status_name = 'Approved' AND s.category NOT IN ('Mass Intention', 'Donation')"
);
$stmt->execute([$appointmentId, $reference, $contact, $contact]);
$appointment = $stmt->fetch();

if (!$appointment) {
    throttleRecordFailure($ipKey, 10, 15 * 60);
    flash('error', 'Appointment not found or not eligible for payment.');
    redirect($statusUrl);
}

$variableCategory = in_array($appointment['category'], ['Baptism', 'Wedding', 'Funeral', 'Wake'], true);
if ($variableCategory && $appointment['pss_classification'] !== null) {
    if ($appointment['pss_classification'] === 'pending_verification') {
        flash('error', 'The parish office must verify the PSS classification before payment can be submitted.');
        redirect($statusUrl);
    }
    $snapshot = $appointment['fee_snapshot'] ? json_decode($appointment['fee_snapshot'], true) : null;
    if (is_array($snapshot) && isset($snapshot['total'])) {
        $amount = (float) $snapshot['total'];
    } else {
        $sponsors = $appointment['category'] === 'Wedding' ? (int) $appointment['wedding_sponsor_count'] : (int) $appointment['sponsor_count'];
        $calculation = calculateServiceFee($appointment['category'], $appointment['schedule_type'], $appointment['pss_classification'], $sponsors);
        if (!$calculation) {
            flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
            redirect($statusUrl);
        }
        $amount = $calculation['total'];
        db()->prepare('UPDATE appointments SET fee_snapshot = ? WHERE appointment_id = ? AND fee_snapshot IS NULL')->execute([json_encode($calculation), $appointmentId]);
    }
} else {
    $amount = (float) $appointment['fee'];
}

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
            $result = verifyPaymentAndIssueReceipt((int) $existing['payment_id'], null, $existing['gateway_transaction_id']);
            flash(
                $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? 'Your online payment was already received — thank you!'
                    : "Your payment was received by PayMongo, but the appointment could not be updated. Please contact the parish office."
            );
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
