<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('parishioner/appointments.php'));
}
verifyCsrf();

$userId = currentUser()['user_id'];
$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$methodId = $_POST['method_id'] ?? 1;
$referenceNumber = trim($_POST['reference_number'] ?? '');
$amount = $_POST['amount'] ?? 0;

$stmt = db()->prepare(
    "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
     VALUES (?, ?, ?, ?, 'pending', NOW())"
);
$stmt->execute([$appointmentId, $referenceNumber, $amount, $methodId]);

logActivity($userId, "Submitted payment for appointment #$appointmentId", 'Payments');
flash('success', 'Payment submitted! It will be verified by our treasurer shortly.');
redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
