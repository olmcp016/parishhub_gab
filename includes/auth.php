<?php
/**
 * PARISHHUB — Auth & RBAC helpers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logo.php';

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect(url('auth/login.php'));
    }
}

/** Usage: requireRole('Admin', 'Secretary'); */
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user']['role_name'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../errors/403.php';
        exit;
    }
}

function guestOnly(): void
{
    if (isLoggedIn()) {
        redirect(redirectForRole($_SESSION['user']['role_name']));
    }
}

function redirectForRole(string $role): string
{
    switch ($role) {
        case 'Admin':      return url('admin/dashboard.php');
        case 'Secretary':  return url('secretary/dashboard.php');
        case 'Treasurer':  return url('treasurer/dashboard.php');
        default:           return url('parishioner/dashboard.php');
    }
}

/** Build an absolute-from-root URL that works regardless of subfolder depth */
function url(string $path = ''): string
{
    return rtrim(baseUrl(), '/') . '/' . ltrim($path, '/');
}

/** Detects the base path PARISHHUB is served from (works whether it's at / or /parishhub/) */
function baseUrl(): string
{
    static $base = null;
    if ($base === null) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        // Normalize: strip any trailing role subfolder (admin/, secretary/, etc.) to get project root
        $parts = explode('/', trim($scriptDir, '/'));
        $roleFolders = ['admin', 'secretary', 'treasurer', 'parishioner', 'auth'];
        if (!empty($parts) && in_array(end($parts), $roleFolders, true)) {
            array_pop($parts);
        }
        $base = '/' . implode('/', $parts);
    }
    return $base;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function getFlash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
