<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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
$methodId = (int) ($_POST['method_id'] ?? 1);

// Ownership + eligibility check: only the owning parishioner can pay, only
// while Approved, and only once (fee is derived server-side, never trusted
// from the client, to prevent price tampering).
$stmt = db()->prepare(
    "SELECT a.appointment_id, s.fee, s.category
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

// Mass Intentions have no fixed fee — it's a voluntary offering the
// parishioner sets themselves. Every other service keeps the fee
// server-derived from the service record, never trusted from the client.
if ($appointment['category'] === 'Mass Intention') {
    $amount = (float) ($_POST['amount'] ?? 0);
    if ($amount <= 0) {
        flash('error', 'Please enter a valid offering amount.');
        redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
    }
} else {
    $amount = (float) $appointment['fee'];
}

$stmt = db()->prepare('SELECT 1 FROM payments WHERE appointment_id = ?');
$stmt->execute([$appointmentId]);
if ($stmt->fetch()) {
    flash('error', 'A payment has already been submitted for this appointment.');
    redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
}

$validMethodIds = [1, 2, 3, 4, 5];
if (!in_array($methodId, $validMethodIds, true)) {
    $methodId = 1;
}

$stmt = db()->prepare(
    "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
     VALUES (?, NULL, ?, ?, 'pending', NOW())"
);
$stmt->execute([$appointmentId, $amount, $methodId]);

logActivity($userId, "Submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our treasurer shortly.');
redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
