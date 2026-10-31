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
// phone used for the booking must match too. Attempts share the status lookup
// throttle keys (audit B-05), so this cannot be used to guess references.
if ($contact === '') {
    flash('error', 'Enter the email or phone number you gave when booking, to pay for this appointment.');
    redirect(url('status.php') . '?ref=' . urlencode($reference));
}
$throttleKeys = statusThrottleKeys($reference, $contact);
$lockedFor = throttleReserveAll($throttleKeys, 10, 15 * 60);
if ($lockedFor > 0) {
    flash('error', 'Too many attempts. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).');
    redirect($statusUrl);
}

// Same normalized email/phone matching as status.php, but guest fields only:
// a registered Parishioner's booking (no guest email/phone) is paid after logging in.
$lookup = statusLookupContact($contact);
$stmt = db()->prepare(
    "SELECT a.appointment_id, a.schedule_type, a.pss_classification, a.sponsor_count, a.wedding_sponsor_count, a.fee_snapshot,
            s.fee, s.category, s.service_name, a.guest_email
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.appointment_id = ? AND a.guest_reference = ?
       AND (LOWER(TRIM(a.guest_email)) = ? OR " . statusLookupPhoneSql('a.guest_phone') . " = ?)
       AND st.status_name = 'Approved' AND s.category NOT IN ('Mass Intention', 'Donation')"
);
$stmt->execute([$appointmentId, $reference, $lookup['email'], $lookup['phone']]);
$appointment = $stmt->fetch();

if (!$appointment) {
    flash('error', 'Appointment not found or not eligible for payment.');
    redirect($statusUrl);
}
foreach ($throttleKeys as $throttleKey) {
    throttleClear($throttleKey);
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
        $precanaType = $appointment['category'] === 'Wedding' ? (precanaForAppointment($appointmentId)['precana_type'] ?? null) : null;
        $calculation = calculateServiceFee($appointment['category'], $appointment['schedule_type'], $appointment['pss_classification'], $sponsors, $precanaType);
        if (!$calculation) {
            flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
            redirect($statusUrl);
        }
        $amount = $calculation['total'];
        db()->prepare('UPDATE appointments SET fee_snapshot = ? WHERE appointment_id = ? AND fee_snapshot IS NULL')->execute([json_encode($calculation), $appointmentId]);
    }
} else {
    // The fee frozen at approval (the current service fee for older approvals).
    $amount = flatFeeAmountDue($appointment);
    if ($amount === null) {
        flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
        redirect($statusUrl);
    }
}

// Nothing to pay (a verified ₱0 Baptism, Wedding or Funeral Mass, or any ₱0
// total): no payment record of any kind — Cash, manual or PayMongo — is ever created.
if ($amount <= 0) {
    if (feeTotalNeedsNoPayment($appointment['category'], $amount)) {
        flash('success', 'No payment is required for this ' . ($appointment['category'] === 'Funeral' ? 'Funeral Mass' : $appointment['category'])
            . ' (' . NO_PAYMENT_REQUIRED_LABEL . '). The Parish Office will confirm your appointment.');
    } else {
        flash('error', 'There is nothing to pay for this appointment.');
    }
    redirect($statusUrl);
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

// One payment start at a time per appointment, and never a second checkout
// while an earlier one may still take money (audit B-07). A payment already
// submitted or completed blocks a new one; a failed or cancelled one does not.
$lease = paymentLeaseAcquire($appointmentId);
if ($lease === null) {
    flash('error', 'A payment for this appointment is already being started. Please wait a moment, then check this page again.');
    redirect($statusUrl);
}
register_shutdown_function('paymentLeaseRelease', $appointmentId, $lease);

$prepared = paymongoPrepareNewPayment($appointmentId, $amount, $payMode === 'online');
if ($prepared['action'] === 'reuse') {
    // The same checkout is still open for this amount: back to it, not a second one.
    $_SESSION['guest_pay_checkout'][$appointmentId] = true;
    redirect($prepared['url']);
}
if ($prepared['action'] === 'stop') {
    flash($prepared['type'], $prepared['message']);
    redirect($statusUrl);
}

if ($payMode === 'online') {
    $returnBase = absoluteUrl('guest-payment-return.php') . '?appointment_id=' . $appointmentId . '&ref=' . urlencode($reference);
    $started = paymongoStartCheckout($appointmentId, $amount, $appointment['service_name'], $returnBase . '&paid=1', $returnBase . '&cancelled=1', $appointment['guest_email'] ?: null);
    if (!$started['ok']) {
        flash('error', 'Could not start the online payment (' . ($started['error'] ?: 'please try again') . '). Nothing was charged.');
        redirect($statusUrl);
    }
    $_SESSION['guest_pay_checkout'][$appointmentId] = true; // lets only THIS browser cancel it on return
    logActivity(null, "Guest started an online payment for appointment #$appointmentId via PayMongo", 'Payments');
    redirect($started['checkout_url']);
}

try {
    db()->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    )->execute([$appointmentId, $reference2, $amount, $methodId]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    flash('error', 'Failed to submit your payment. Please try again.');
    redirect($statusUrl);
}

logActivity(null, "Guest submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our cashier shortly.');
redirect($statusUrl);
