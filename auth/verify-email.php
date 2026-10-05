<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in and verified — send them home.
if (isLoggedIn()) {
    redirect(redirectForRole(currentUser()['role_name']));
}

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    flash('error', 'Invalid verification link. Please register again or contact the parish office.');
    redirect(url('auth/register.php'));
}

$stmt = db()->prepare(
    "SELECT user_id, firstname, email_verified_at, token_expires_at
     FROM users
     WHERE verification_token = ?
     LIMIT 1"
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    flash('error', 'This verification link is invalid or has already been used.');
    redirect(url('auth/login.php'));
}

if ($user['email_verified_at'] !== null) {
    flash('info', 'Your email is already verified. You can log in now.');
    redirect(url('auth/login.php'));
}

if ($user['token_expires_at'] !== null && strtotime($user['token_expires_at']) < time()) {
    // Expired — clear the stale token so the user knows to re-register.
    db()->prepare("UPDATE users SET verification_token = NULL, token_expires_at = NULL WHERE user_id = ?")
        ->execute([$user['user_id']]);
    flash('error', 'This verification link has expired (links are valid for 24 hours). Please register again.');
    redirect(url('auth/register.php'));
}

// All good — activate the account.
db()->prepare(
    "UPDATE users
     SET email_verified_at = NOW(),
         verification_token = NULL,
         token_expires_at   = NULL
     WHERE user_id = ?"
)->execute([$user['user_id']]);

flash('success', 'Your email has been verified! You can now log in, ' . htmlspecialchars($user['firstname']) . '.');
redirect(url('auth/login.php'));
