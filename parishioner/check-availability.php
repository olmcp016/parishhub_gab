<?php
/**
 * PARISHHUB — live availability pre-check for the booking modal.
 *
 * Called via fetch() from parishioner/services.php whenever the parishioner
 * changes service/schedule-type/date/time/priest, to give instant feedback
 * before they submit. This is feedback only, not the authority: book.php
 * re-runs the exact same scheduling.php functions inside its transaction
 * right before insert, so there is exactly one validation ruleset.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireParishionerOrGuest();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$serviceId = isset($input['service_id']) ? (int) $input['service_id'] : null;
$category = trim($input['category'] ?? '');
$scheduleType = $input['schedule_type'] ?? null;
$date = trim($input['appointment_date'] ?? '');
$time = trim($input['appointment_time'] ?? '');
$priestId = isset($input['priest_id']) && $input['priest_id'] !== '' ? (int) $input['priest_id'] : null;
$dateOfDeath = $input['date_of_death'] ?? null;
$excludeAppointmentId = isset($input['exclude_appointment_id']) ? (int) $input['exclude_appointment_id'] : null;

$response = [
    'ok' => true,
    'valid' => null,
    'message' => '',
    'forced_time' => null,
    'regular_slots' => [],
    'available_priests' => [],
];

if ($serviceId && in_array($scheduleType, ['Regular', 'Special'], true)) {
    if ($scheduleType === 'Regular') {
        $response['regular_slots'] = regularSlotsForService($serviceId);
    }
}

if ($category !== '' && $date !== '' && $time !== '') {
    $check = validateBooking($category, $date, $time, $dateOfDeath ?: null, $scheduleType ?: null, $serviceId);
    $response['valid'] = $check['valid'];
    $response['message'] = $check['message'];
    $response['forced_time'] = $check['forcedTime'];

    if ($check['valid'] && $serviceId && $category !== 'Mass Intention') {
        $effectiveTime = $check['forcedTime'] ?? $time;
        if (serviceSlotIsBooked($serviceId, $date, $effectiveTime, $excludeAppointmentId)) {
            $response['valid'] = false;
            $response['message'] = 'That exact date and time is already booked for this service. Please choose another slot.';
        }
    }
}

if ($date !== '' && $time !== '') {
    $response['available_priests'] = availablePriestsFor($date, $time, $excludeAppointmentId);
}

echo json_encode($response);
