<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/service-fees.php';
requireRole('Parishioner');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('parishioner/appointments.php'));
}
verifyCsrf();

$userId = currentUser()['user_id'];

$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$detailUrl = url('parishioner/appointment-detail.php?id=' . $appointmentId);

// Ownership + eligibility check: only the owning parishioner can pay, only
// while Approved, and the fee is derived server-side, never trusted from the
// client, to prevent price tampering.
$stmt = db()->prepare(
    "SELECT a.appointment_id, a.schedule_type, a.pss_classification, a.sponsor_count, a.wedding_sponsor_count, a.fee_snapshot,
            s.fee, s.category, s.service_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.appointment_id = ? AND a.parishioner_id = ? AND st.status_name = 'Approved'"
);
$stmt->execute([$appointmentId, $parishionerId]);
$appointment = $stmt->fetch();

if (!$appointment) {
    flash('error', 'Appointment not found or not eligible for payment.');
    redirect(url('parishioner/appointments.php'));
}

// Mass Intentions have no fixed fee — a voluntary offering (must be more
// than ₱0) the parishioner sets themselves. Every other service keeps the
// fee server-derived from the service record.
if ($appointment['category'] === 'Mass Intention') {
    $rawAmount = $_POST['amount'] ?? '';
    $amount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
    if (!($amount > 0) || $amount > 1000000) {
        flash('error', 'Payment is required before submitting a Mass Intention. Please enter a valid amount.');
        redirect($detailUrl);
    }
} else {
    $variableCategory = in_array($appointment['category'], ['Baptism', 'Wedding', 'Funeral', 'Wake'], true);
    if ($variableCategory && $appointment['pss_classification'] !== null) {
        if ($appointment['pss_classification'] === 'pending_verification') {
            flash('error', 'The parish office must verify the PSS classification before payment can be submitted.');
            redirect($detailUrl);
        }
        if (!in_array($appointment['pss_classification'], ['pss', 'non_pss'], true)) {
            flash('error', 'The appointment has an invalid pricing classification. Please contact the parish office.');
            redirect($detailUrl);
        }
        $snapshot = $appointment['fee_snapshot'] ? json_decode($appointment['fee_snapshot'], true) : null;
        if (is_array($snapshot) && isset($snapshot['total'])) {
            $amount = (float) $snapshot['total'];
        } else {
            $sponsors = $appointment['category'] === 'Wedding'
                ? (int) $appointment['wedding_sponsor_count']
                : (int) $appointment['sponsor_count'];
            $precanaType = $appointment['category'] === 'Wedding' ? (precanaForAppointment($appointmentId)['precana_type'] ?? null) : null;
            $calculation = calculateServiceFee($appointment['category'], $appointment['schedule_type'], $appointment['pss_classification'], $sponsors, $precanaType);
            if (!$calculation) {
                flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
                redirect($detailUrl);
            }
            $amount = $calculation['total'];
            db()->prepare('UPDATE appointments SET fee_snapshot = ? WHERE appointment_id = ? AND fee_snapshot IS NULL')
                ->execute([json_encode($calculation), $appointmentId]);
        }
    } else {
        // The fee frozen at approval (the current service fee for older approvals).
        $amount = flatFeeAmountDue($appointment);
        if ($amount === null) {
            flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
            redirect($detailUrl);
        }
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
    redirect($detailUrl);
}

$payMode = $_POST['pay_mode'] ?? '';
$methodId = null;
$reference = null;
if ($payMode === 'online') {
    $methodId = 7; // PayMongo (Online)
    if (!($amount > 0)) {
        flash('error', 'There is nothing to pay online for this appointment.');
        redirect($detailUrl);
    }
} elseif ($payMode === 'cash') {
    $methodId = 1;
} elseif ($payMode === 'manual') {
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $reference = trim($_POST['payment_reference'] ?? '');
    if (!in_array($methodId, [2, 3, 4], true) || strlen($reference) < 4) {
        flash('error', 'Please choose GCash, Maya, or Bank Transfer and enter the payment reference number of your completed payment.');
        redirect($detailUrl);
    }
} else {
    flash('error', 'Please choose how you would like to pay.');
    redirect($detailUrl);
}

// One payment start at a time per appointment, and never a second checkout
// while an earlier one may still take money (audit B-07). A payment already
// submitted or completed blocks a new one; a failed or cancelled one does not.
$lease = paymentLeaseAcquire($appointmentId);
if ($lease === null) {
    flash('error', 'A payment for this appointment is already being started. Please wait a moment, then check this page again.');
    redirect($detailUrl);
}
register_shutdown_function('paymentLeaseRelease', $appointmentId, $lease);

$prepared = paymongoPrepareNewPayment($appointmentId, $amount, $payMode === 'online');
if ($prepared['action'] === 'reuse') {
    // The same checkout is still open for this amount: back to it, not a second one.
    $_SESSION['pay_checkout'][$appointmentId] = true;
    redirect($prepared['url']);
}
if ($prepared['action'] === 'stop') {
    flash($prepared['type'], $prepared['message']);
    redirect($detailUrl);
}

if ($payMode === 'online') {
    // Real-time PayMongo checkout — the browser is sent to PayMongo's own
    // hosted page (GCash / Maya / card). The payment only counts once
    // PayMongo confirms it (see parishioner/payment-return.php); nothing
    // is marked paid just because this button was clicked.
    $returnBase = absoluteUrl('parishioner/payment-return.php') . '?appointment_id=' . $appointmentId;
    $started = paymongoStartCheckout($appointmentId, $amount, $appointment['service_name'], $returnBase . '&paid=1', $returnBase . '&cancelled=1', currentUser()['email'] ?? null);
    if (!$started['ok']) {
        flash('error', 'Could not start the online payment (' . ($started['error'] ?: 'please try again') . '). Nothing was charged.');
        redirect($detailUrl);
    }
    $_SESSION['pay_checkout'][$appointmentId] = true; // lets only THIS browser cancel it on return
    logActivity($userId, "Started an online payment for appointment #$appointmentId via PayMongo", 'Payments');
    redirect($started['checkout_url']);
}

try {
    db()->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    )->execute([$appointmentId, $reference, $amount, $methodId]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    flash('error', 'Failed to submit your payment. Please try again.');
    redirect($detailUrl);
}

logActivity($userId, "Submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our cashier shortly.');
redirect($detailUrl);
