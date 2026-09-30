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
            $calculation = calculateServiceFee($appointment['category'], $appointment['schedule_type'], $appointment['pss_classification'], $sponsors);
            if (!$calculation) {
                flash('error', 'The applicable service fee could not be determined. Please contact the parish office.');
                redirect($detailUrl);
            }
            $amount = $calculation['total'];
            db()->prepare('UPDATE appointments SET fee_snapshot = ? WHERE appointment_id = ? AND fee_snapshot IS NULL')
                ->execute([json_encode($calculation), $appointmentId]);
        }
    } else {
        $amount = (float) $appointment['fee'];
    }
}

// A payment already in progress or completed blocks a second one. A failed
// or cancelled payment — or an online checkout that was started but never
// finished — does not: the parishioner can simply try again.
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
        // Make sure it wasn't actually paid before starting over.
        $session = paymongoGetCheckoutSession($existing['gateway_transaction_id']);
        if ($session['ok'] && paymongoStatusToLocal($session['status']) === 'verified') {
            verifyPaymentAndIssueReceipt((int) $existing['payment_id'], null, $existing['gateway_transaction_id']);
            flash('success', 'Your online payment was already received — thank you!');
            redirect($detailUrl);
        }
        markPaymentUnsuccessful((int) $existing['payment_id'], 'cancelled');
        db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$existing['payment_id']]);
    } elseif (!in_array($existing['payment_status'], ['failed', 'cancelled'], true)) {
        flash('error', 'A payment has already been submitted for this appointment.');
        redirect($detailUrl);
    }
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

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->execute([$appointmentId, $reference, $amount, $methodId]);
    $paymentId = $pdo->lastInsertId();

    if ($payMode === 'online') {
        // Real-time PayMongo checkout — the browser is sent to PayMongo's own
        // hosted page (GCash / Maya / card). The payment only counts once
        // PayMongo confirms it (see parishioner/payment-return.php); nothing
        // is marked paid just because this button was clicked.
        $returnBase = absoluteUrl('parishioner/payment-return.php') . '?appointment_id=' . $appointmentId;
        $checkout = paymongoCreateCheckoutSession(
            $amount,
            $appointment['service_name'],
            $returnBase . '&paid=1',
            $returnBase . '&cancelled=1',
            currentUser()['email'] ?? null
        );
        if (!$checkout['ok'] || !$checkout['checkout_url']) {
            $pdo->rollBack();
            error_log('PayMongo checkout session creation failed (service payment): ' . json_encode($checkout['raw'] ?? []));
            flash('error', 'Could not start the online payment (' . ($checkout['error'] ?: 'please try again') . '). Nothing was charged.');
            redirect($detailUrl);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (payment_id, gateway, gateway_transaction_id, status, raw_response) VALUES (?, 'paymongo', ?, 'pending', ?)"
        );
        $stmt->execute([$paymentId, $checkout['session_id'], json_encode($checkout['raw'])]);
        $pdo->commit();
        $_SESSION['pay_checkout'][$appointmentId] = true; // lets only THIS browser cancel it on return
        logActivity($userId, "Started an online payment for appointment #$appointmentId via PayMongo", 'Payments');
        redirect($checkout['checkout_url']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    flash('error', 'Failed to submit your payment. Please try again.');
    redirect($detailUrl);
}

logActivity($userId, "Submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our cashier shortly.');
redirect($detailUrl);
