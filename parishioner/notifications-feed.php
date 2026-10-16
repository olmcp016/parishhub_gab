<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/secretary-notifications.php';

/**
 * JSON feed for the Parishioner notification bell (includes/topbar.php) — same
 * contract as secretary/notifications-feed.php:
 *   GET                       -> { unread, items: [...] }   (polled every 30s)
 *   POST action=read  id=N    -> marks one of the CURRENT user's notifications read
 *   POST action=read_all      -> marks all of them read
 * Only ever reads/updates the logged-in parishioner's own rows.
 */
requireRole('Parishioner');
header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = (int) currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh the page.']);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read') {
        markOwnNotificationRead((int) ($_POST['id'] ?? 0), $userId);
    } elseif ($action === 'read_all') {
        db()->prepare('UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE')->execute([$userId]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }
}

echo json_encode(['success' => true] + parishionerNotificationFeed($userId));
