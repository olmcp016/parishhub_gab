<?php
/**
 * Lightweight session heartbeat for auth-sync.js — polled when a tab
 * regains focus so it can detect a session that expired server-side while
 * the tab sat idle (not just a login/logout that happened in another tab,
 * which the localStorage broadcast already covers).
 */
require_once __DIR__ . '/../includes/auth.php';

sendNoCacheHeaders();
header('Content-Type: application/json');

$user = currentUser();
echo json_encode([
    'loggedIn' => $user !== null,
    'userId'   => $user ? (int) $user['user_id'] : null,
    'role'     => $user['role_name'] ?? null,
]);
