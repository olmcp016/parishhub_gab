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

/**
 * Tells the browser (and any intermediate proxy) never to cache this
 * response or serve it from the back/forward cache. Without this, hitting
 * Back after logout can redisplay a page that was rendered while the user
 * was still authenticated — the server never gets a chance to re-check the
 * (now-dead) session, because the browser never re-requests the page.
 */
function sendNoCacheHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function requireLogin(): void
{
    sendNoCacheHeaders();
    if (!isLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect(url('auth/login.php'));
    }
}

/** Usage: requireRole('Admin', 'Secretary'); */
function requireRole(string ...$roles): void
{
    requireLogin();
    autoCancelExpiredUnpaidAppointments();
    if (!in_array($_SESSION['user']['role_name'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../errors/403.php';
        exit;
    }
}

/** The priest_id linked to the logged-in Priest account, or null if somehow unlinked. */
function currentPriestId(): ?int
{
    $userId = currentUser()['user_id'] ?? null;
    if (!$userId) return null;
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $stmt = db()->prepare('SELECT priest_id FROM priests WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $cache[$userId] = ($stmt->fetchColumn() ?: null);
}

function guestOnly(): void
{
    if (isLoggedIn()) {
        redirect(redirectForRole($_SESSION['user']['role_name']));
    }
}

/**
 * The shared, login-disabled placeholder parishioner row (see
 * database/migration_guest_access.sql) that satisfies appointments'
 * NOT NULL parishioner_id FK for a booking/donation submitted with no
 * account — the real identity lives in appointments.guest_name/
 * guest_email/guest_phone instead. Looked up by email rather than a
 * hardcoded id so it stays correct regardless of row order.
 */
function guestParishionerId(): int
{
    static $id = null;
    if ($id === null) {
        $stmt = db()->prepare(
            'SELECT p.parishioner_id FROM parishioners p JOIN users u ON p.user_id = u.user_id WHERE u.email = ?'
        );
        $stmt->execute(['guest@parishhub.internal']);
        $id = (int) $stmt->fetchColumn();
    }
    return $id;
}

/**
 * Generates a short, unique, human-typeable reference code for a guest
 * booking/donation (e.g. "PH-A3F9K2") — shown on confirmation and used
 * later to look up status without an account (see status.php).
 */
function generateGuestReference(): string
{
    do {
        $code = 'PH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = db()->prepare('SELECT 1 FROM appointments WHERE guest_reference = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

/**
 * True when the visitor should see the normal logged-in Parishioner
 * dashboard chrome (sidebar/topbar); false for a guest or anyone else,
 * who gets the lightweight public-shell-start.php/public-shell-end.php
 * chrome instead. Used by every page that works for both.
 */
function usesParishionerShell(): bool
{
    return isLoggedIn() && currentUser()['role_name'] === 'Parishioner';
}

/**
 * The softer counterpart to requireRole('Parishioner') for pages that
 * must also work for an anonymous visitor with no account (browsing
 * services/calendar/announcements, booking, entering a Mass Intention,
 * donating). Does NOT redirect to login. Returns the identity to book
 * under: a real parishioner_id for a logged-in Parishioner, or the
 * shared guest placeholder otherwise. A logged-in user of any OTHER
 * role is sent to their own dashboard — these pages are the public/
 * parishioner-facing ones, not a staff view.
 *
 * @return array{is_guest: bool, parishioner_id: int, user_id: ?int}
 */
function requireParishionerOrGuest(): array
{
    sendNoCacheHeaders();
    if (isLoggedIn()) {
        $user = currentUser();
        if ($user['role_name'] !== 'Parishioner') {
            redirect(redirectForRole($user['role_name']));
        }
        $stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        return ['is_guest' => false, 'parishioner_id' => (int) $stmt->fetchColumn(), 'user_id' => (int) $user['user_id']];
    }
    return ['is_guest' => true, 'parishioner_id' => guestParishionerId(), 'user_id' => null];
}

function redirectForRole(string $role): string
{
    switch ($role) {
        case 'Admin':      return url('admin/dashboard.php');
        case 'Secretary':  return url('secretary/dashboard.php');
        case 'Treasurer':  return url('treasurer/dashboard.php');
        case 'Priest':     return url('priest/dashboard.php');
        default:           return url('parishioner/dashboard.php');
    }
}

/** Build an absolute-from-root URL that works regardless of subfolder depth */
function url(string $path = ''): string
{
    return rtrim(baseUrl(), '/') . '/' . ltrim($path, '/');
}

/**
 * A fully-qualified (scheme+host) version of url() — needed whenever a URL
 * is handed to an external party (e.g. a payment gateway's success/cancel
 * redirect) rather than used in our own HTML, where a path-relative url()
 * is fine because the browser resolves it against our own origin. Handing
 * an external site a bare path breaks: it resolves against THEIR origin
 * instead of ours.
 */
function absoluteUrl(string $path = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . url($path);
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

/**
 * Stashes the current $_POST across a redirect (e.g. after a server-side
 * validation failure) so the form that sent it can re-populate itself
 * instead of making the person retype everything — see oldInput().
 */
function keepOldInput(array $data): void
{
    $_SESSION['old_input'] = $data;
}

/**
 * Reads back one field stashed by keepOldInput(), HTML-escaped and ready to
 * drop into a value="..." attribute. Lazily loads (and clears) the stashed
 * array on first call per request, so several oldInput() calls on the same
 * page all see it and it doesn't leak into the next unrelated form.
 */
function oldInput(string $key, string $default = ''): string
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);
    }
    return e($old[$key] ?? $default);
}
