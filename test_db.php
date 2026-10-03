<?php
require 'includes/db.php';
try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death, schedule_type, pss_claim, pss_classification, sponsor_count, wedding_sponsor_count, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot) VALUES (1, 1, NULL, '2027-01-01', '10:00:00', 1, NULL, '2026-01-01', 'Regular', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
    $stmt->execute();
    echo "Success\n";
    $pdo->rollBack();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
