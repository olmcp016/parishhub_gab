<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
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
$priestId = (($_POST['priest_id'] ?? '') !== '') ? (int) $_POST['priest_id'] : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_id']);
    exit;
}

// Times are stored as TIME; reject anything that is not H:i (audit H-05).
if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid time.']);
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

$pdo = db();
$current = $pdo->prepare('SELECT event_date, event_time, priest_id FROM events WHERE event_id = ?');
$current->execute([$id]);
$existing = $current->fetch();
if (!$existing) {
    http_response_code(404);
    echo json_encode(['error' => 'Event not found']);
    exit;
}

$newTime = $time !== '' ? $time : null;
$oldTime = $existing['event_time'] !== null ? substr($existing['event_time'], 0, 5) : null;
$oldPriest = $existing['priest_id'] !== null ? (int) $existing['priest_id'] : null;
$changed = $newTime !== $oldTime || $priestId !== $oldPriest;

// Moving a priest onto a time when they are already booked, unavailable, or
// scheduled for another event is refused (audit H-05). The check runs under
// the same advisory lock book.php takes, so it cannot race a booking.
// The event row still holds the old values here, so it never conflicts with itself.
$pdo->beginTransaction();
try {
    if ($changed && $priestId && $newTime) {
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')
            ->execute(['priest:' . $priestId . ':' . $existing['event_date']]);
        $availability = priestIsAvailable($priestId, $existing['event_date'], $newTime);
        if (!$availability['available']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => $availability['reason']]);
            exit;
        }
    }

    $pdo->prepare("UPDATE events SET event_time = ?, priest_id = ? WHERE event_id = ?")
        ->execute([$newTime, $priestId, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Event update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The event could not be updated. Please try again.']);
    exit;
}

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
