<?php
/**
 * PARISHHUB — small JSON parishioner search, for pickers that need to stay
 * usable even with a large parishioner list (e.g. the Cashier's "Select
 * Parishioner" donation picker) instead of dumping every parishioner into
 * one giant <select> or one client-side JSON blob.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin', 'Treasurer');

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$sql = "SELECT p.parishioner_id, u.firstname, u.lastname, u.email
        FROM parishioners p JOIN users u ON p.user_id = u.user_id
        WHERE u.email != 'walkin-donor@parishhub.internal'";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= " ORDER BY u.lastname, u.firstname LIMIT 20";

$stmt = db()->prepare($sql);
$stmt->execute($params);
echo json_encode(array_map(fn($p) => [
    'parishioner_id' => (int) $p['parishioner_id'],
    'name' => trim($p['lastname'] . ', ' . $p['firstname']),
    'email' => $p['email'],
], $stmt->fetchAll()));
