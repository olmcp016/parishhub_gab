<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

header('Content-Type: application/json; charset=utf-8');

$priestId = (int) ($_GET['priest_id'] ?? 0);
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit = 50;
if ($priestId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid priest.']);
    exit;
}

$stmt = db()->prepare("SELECT priest_id, title, full_name FROM priests WHERE priest_id = ? AND status = 'active'");
$stmt->execute([$priestId]);
$priest = $stmt->fetch();
if (!$priest) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Priest not found.']);
    exit;
}

$stmt = db()->prepare(
    "SELECT a.appointment_date, a.appointment_time, s.service_name
     FROM appointments a
     JOIN services s ON s.service_id = a.service_id
     WHERE a.priest_id = ?
       AND a.status_id NOT IN (3, 7)
       AND (a.appointment_date > CURRENT_DATE
            OR (a.appointment_date = CURRENT_DATE AND a.appointment_time >= CURRENT_TIME))
     ORDER BY a.appointment_date ASC, a.appointment_time ASC
     LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $priestId, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$appointments = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'priest' => ['title' => $priest['title'], 'full_name' => $priest['full_name']],
    'appointments' => $appointments,
    'offset' => $offset,
    'limit' => $limit,
    'has_more' => count($appointments) === $limit,
]);
