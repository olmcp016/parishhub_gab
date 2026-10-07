<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
// Event details (title, date, time, location, priest) are public information
// displayed on the parish calendar — no role check needed.

header('Content-Type: application/json');

$id = (int) ($_GET['event_id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_id']);
    exit;
}

$stmt = db()->prepare(
    "SELECT e.event_id, e.title, e.description, e.event_date, e.event_time,
            e.location_id, e.priest_id,
            l.name AS location_name,
            p.title AS priest_title, p.full_name AS priest_name
     FROM events e
     LEFT JOIN locations l ON e.location_id = l.location_id
     LEFT JOIN priests p ON e.priest_id = p.priest_id
     WHERE e.event_id = ?"
);
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo json_encode(['error' => 'Event not found']);
    exit;
}

echo json_encode($event);
