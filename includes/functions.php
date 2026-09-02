<?php
/**
 * PARISHHUB — Shared helper functions
 */

function logActivity(?int $userId, string $action, string $module = 'General'): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, action, module, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $module,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }
}

function badgeClass(string $statusName): string
{
    return strtolower(str_replace(' ', '-', $statusName));
}

function money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function formatDate(?string $date): string
{
    if (!$date) return '—';
    return date('M j, Y', strtotime($date));
}

function formatDateTime(?string $dt): string
{
    if (!$dt) return '—';
    return date('M j, Y g:i A', strtotime($dt));
}

/** Simple CSRF token helper */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Session expired or invalid request token. Please go back and try again.');
    }
}

/**
 * Builds the correct, web-accessible URL for an uploaded document.
 * Files are physically stored under public/uploads/, but older rows may
 * have been saved with just "uploads/<name>" (missing the "public/"
 * prefix) — this normalizes either form so links always resolve.
 */
function documentUrl(string $filePath): string
{
    $filePath = ltrim($filePath, '/');
    if (!str_starts_with($filePath, 'public/')) {
        $filePath = 'public/' . $filePath;
    }
    return url($filePath);
}

/** True if the filename's extension is a browser-viewable image type. */
function isImageFile(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}
