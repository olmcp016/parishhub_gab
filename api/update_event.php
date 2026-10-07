<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin', 'Treasurer');

header('Content-Type: application/json');

$token = $_POST['csrf_token'] ?? '';
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$id       = (int) ($_POST['event_id'] ?? 0);
$time     = trim($_POST['event_time'] ?? '');
$priestId = ($_POST['priest_id'] !== '' && $_POST['priest_id'] !== null) ? (int) $_POST['priest_id'] : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_id']);
    exit;
}

if ($priestId) {
    $check = db()->prepare("SELECT 1 FROM priests WHERE priest_id = ? AND status = 'active'");
    $check->execute([$priestId]);
    if (!$check->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid priest selected']);
        exit;
    }
}

db()->prepare("UPDATE events SET event_time = ?, priest_id = ? WHERE event_id = ?")
    ->execute([$time ?: null, $priestId, $id]);

$userId = currentUser()['user_id'];
logActivity($userId, "Updated event #$id (time/priest)", 'Calendar');

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

echo json_encode(['success' => true, 'event' => $event]);
