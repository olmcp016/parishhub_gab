<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
guestOnly();

$email = $_GET['email'] ?? '';
if (!$email) {
    redirect(url('auth/login.php'));
}

$stmt = db()->prepare('SELECT user_id, firstname, lastname, email_verified_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    flash('error', 'Account not found.');
    redirect(url('auth/login.php'));
}

if ($user['email_verified_at'] !== null) {
    flash('success', 'This account is already verified! You can log in.');
    redirect(url('auth/login.php'));
}

$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

db()->prepare('UPDATE users SET verification_token = ?, token_expires_at = ? WHERE user_id = ?')
    ->execute([$token, $expires, $user['user_id']]);

$verifyUrl = absoluteUrl('auth/verify-email.php') . '?token=' . urlencode($token);
$bodyHtml  = '<p>Hello <strong>' . htmlspecialchars($user['firstname']) . '</strong>,</p>'
           . '<p>You requested a new verification link for the Parish Service Portal. '
           . 'Please verify your email address by clicking the button below. '
           . 'This link expires in <strong>24 hours</strong>.</p>';

$html   = emailTemplate('Verify Your Email Address', $bodyHtml, $verifyUrl, 'Verify My Email');
$result = sendEmail($email, $user['firstname'] . ' ' . $user['lastname'], 'Verify your ParishHub email address', $html);

if (!$result['ok']) {
    flash('error', 'Failed to resend verification email. Please make sure your email is active.');
    redirect(url('auth/login.php'));
}

// Emulate the exact same success flash message as register.php so the Modal triggers on login.php
flash('success', 'verification link has been sent to <strong>' . htmlspecialchars($email) . '</strong>.');
redirect(url('auth/login.php'));
