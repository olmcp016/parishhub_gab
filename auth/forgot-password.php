<?php
/**
 * PARISHHUB — Forgot Password AJAX endpoint.
 *
 * Three POST actions, all return JSON:
 *   send_otp      — validate email exists, generate & email 6-digit OTP
 *   verify_otp    — confirm OTP, issue a short-lived session reset permit
 *   reset_password — accept new password, hash & store it
 *
 * Security:
 *   - CSRF-protected on every action
 *   - OTP is 6 random digits, bcrypt-hashed in DB
 *   - OTP expires in 10 minutes; max 5 wrong attempts before invalidated
 *   - One OTP per email (UPSERT); 60-second cooldown between resends
 *   - reset_password requires session permit set by verify_otp (no skipping)
 *   - Reset permit itself expires in 10 minutes
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

guestOnly();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

verifyCsrf();

$action = trim($_POST['action'] ?? '');
$pdo    = db();

// ── SEND OTP ─────────────────────────────────────────────────────────────────
if ($action === 'send_otp') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
        exit;
    }

    // Rate-limit: one OTP request per 60 seconds per email
    $rateSql = $pdo->prepare('SELECT created_at FROM password_reset_otps WHERE email = ?');
    $rateSql->execute([$email]);
    $existing = $rateSql->fetch();
    if ($existing) {
        $elapsed = time() - (int) strtotime($existing['created_at']);
        if ($elapsed < 60) {
            $wait = 60 - $elapsed;
            echo json_encode([
                'ok'      => false,
                'message' => "Please wait {$wait} second(s) before requesting a new code.",
                'wait'    => $wait,
            ]);
            exit;
        }
    }

    // Look up a verified, active parishioner account
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.firstname, u.lastname
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.email = ?
           AND r.role_name = 'Parishioner'
           AND u.email_verified_at IS NOT NULL
           AND u.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode([
            'ok'    => false,
            'field' => 'email',
            'message' => 'No active Parishioner account found with that email address.',
        ]);
        exit;
    }

    // Generate OTP
    $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash    = password_hash($otp, PASSWORD_BCRYPT);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $pdo->prepare(
        'INSERT INTO password_reset_otps (email, otp_hash, expires_at, attempts)
         VALUES (?, ?, ?, 0)
         ON CONFLICT (email) DO UPDATE
           SET otp_hash   = EXCLUDED.otp_hash,
               expires_at = EXCLUDED.expires_at,
               attempts   = 0,
               created_at = NOW()'
    )->execute([$email, $hash, $expires]);

    $firstname = htmlspecialchars($user['firstname']);
    $bodyHtml =
        "<p>Hello <strong>{$firstname}</strong>,</p>"
        . '<p>We received a request to reset your <strong>PARISHHUB</strong> account password. '
        . 'Use the verification code below to continue. It expires in <strong>10 minutes</strong>.</p>'
        . '<div style="text-align:center;margin:28px 0;">'
        . '<span style="font-family:monospace;font-size:36px;font-weight:700;letter-spacing:10px;'
        . 'color:#3b2f1e;background:#f7f2e8;padding:14px 28px;border-radius:8px;'
        . 'border:2px solid #c9a84c;display:inline-block;">'
        . htmlspecialchars($otp)
        . '</span></div>'
        . '<p>If you did not request this password reset, you can safely ignore this email — '
        . 'your password will remain unchanged.</p>';

    $html = emailTemplate('Password Reset Code', $bodyHtml);
    sendEmail(
        $email,
        $user['firstname'] . ' ' . $user['lastname'],
        'Your PARISHHUB Password Reset Code',
        $html
    );

    echo json_encode(['ok' => true]);
    exit;
}

// ── VERIFY OTP ───────────────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $otp   = preg_replace('/\D/', '', trim($_POST['otp'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($otp) !== 6) {
        echo json_encode(['ok' => false, 'message' => 'Please enter the 6-digit code sent to your email.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT otp_hash, expires_at, attempts FROM password_reset_otps WHERE email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode([
            'ok'      => false,
            'expired' => true,
            'message' => 'No active code found for that email. Please request a new one.',
        ]);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM password_reset_otps WHERE email = ?')->execute([$email]);
        echo json_encode([
            'ok'      => false,
            'expired' => true,
            'message' => 'This code has expired. Please request a new one.',
        ]);
        exit;
    }

    if ((int) $row['attempts'] >= 5) {
        $pdo->prepare('DELETE FROM password_reset_otps WHERE email = ?')->execute([$email]);
        echo json_encode([
            'ok'      => false,
            'expired' => true,
            'message' => 'Too many incorrect attempts. Please request a new code.',
        ]);
        exit;
    }

    if (!password_verify($otp, $row['otp_hash'])) {
        $pdo->prepare(
            'UPDATE password_reset_otps SET attempts = attempts + 1 WHERE email = ?'
        )->execute([$email]);
        $left = max(0, 5 - ((int) $row['attempts'] + 1));
        echo json_encode([
            'ok'      => false,
            'message' => "Incorrect code. {$left} attempt(s) remaining.",
        ]);
        exit;
    }

    // OTP valid — issue session reset permit (10 more minutes to set the new password)
    $_SESSION['password_reset'] = [
        'email'   => $email,
        'expires' => time() + 600,
    ];
    $pdo->prepare('DELETE FROM password_reset_otps WHERE email = ?')->execute([$email]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── RESET PASSWORD ───────────────────────────────────────────────────────────
if ($action === 'reset_password') {
    $pr = $_SESSION['password_reset'] ?? null;

    if (!$pr || $pr['expires'] < time()) {
        unset($_SESSION['password_reset']);
        echo json_encode([
            'ok'      => false,
            'expired' => true,
            'message' => 'Your session has expired. Please start again.',
        ]);
        exit;
    }

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        echo json_encode(['ok' => false, 'field' => 'password',
            'message' => 'Password must be at least 8 characters long.']);
        exit;
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        echo json_encode(['ok' => false, 'field' => 'password',
            'message' => 'Password must contain at least one letter and one number.']);
        exit;
    }
    if ($password !== $confirm) {
        echo json_encode(['ok' => false, 'field' => 'confirm_password',
            'message' => 'Passwords do not match.']);
        exit;
    }

    $email = $pr['email'];
    $stmt  = $pdo->prepare(
        "SELECT user_id FROM users WHERE email = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        unset($_SESSION['password_reset']);
        echo json_encode(['ok' => false, 'message' => 'Account not found.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare(
        "UPDATE users SET password = ?, must_change_password = 0, updated_at = NOW() WHERE user_id = ?"
    )->execute([$hash, $userId]);

    logActivity((int) $userId, 'Password reset via Forgot Password', 'Auth');
    unset($_SESSION['password_reset']);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
