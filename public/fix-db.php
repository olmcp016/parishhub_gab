<?php
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$stmt = $pdo->query("
    UPDATE appointments 
    SET status_id = 5 
    WHERE appointment_id IN (
        SELECT a.appointment_id 
        FROM appointments a
        JOIN payments p ON a.appointment_id = p.appointment_id
        JOIN services s ON a.service_id = s.service_id
        WHERE p.payment_status = 'verified' 
        AND a.status_id IN (2, 4)
        AND s.category IN ('Mass Intention', 'Donation')
    )
");

echo "Success! Updated " . $stmt->rowCount() . " old stuck records to Completed/Approved status to match the new automated flow.";
