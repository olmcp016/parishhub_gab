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

/** Roles that belong to the internal parish administration (Staff & Clergy). */
const STAFF_ROLES = ['Admin', 'Secretary', 'Treasurer', 'Priest'];

function requireLogin(): void
{
    sendNoCacheHeaders();
    if (!isLoggedIn()) {
        flash('error', 'Your session has expired or you are not signed in. Please log in again.');
        redirect(url('auth/login.php'));
    }
}

/** Usage: requireRole('Admin', 'Secretary'); */
function requireRole(string ...$roles): void
{
    sendNoCacheHeaders();
    if (!isLoggedIn()) {
        flash('error', 'Your session has expired or you are not signed in. Please log in again.');
        // If every required role is a staff/clergy role, send to the Staff Portal.
        $allStaff = !empty($roles) && count(array_diff($roles, STAFF_ROLES)) === 0;
        redirect($allStaff ? url('staff/login.php') : url('auth/login.php'));
    }
    autoCancelExpiredUnpaidAppointments();
    if (!in_array($_SESSION['user']['role_name'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../errors/403.php';
        exit;
    }
}

/** Array-accepting counterpart to requireRole() — same checks. Usage: requireAnyRole(['Priest', 'Admin']); */
function requireAnyRole(array $roles): void
{
    requireRole(...$roles);
}

/**
 * The priest_id linked to the logged-in account, or null if unlinked.
 * Matches by user_id first (the normal Priest-login link, and also how an
 * Admin who is also a serving priest gets linked — see "Link to My Admin
 * Account" on admin/priests.php). Falls back to an exact, case-insensitive
 * email match only when that priest row has no user_id of its own (so it
 * never steals a different account's login), and persists that link once
 * found so the lookup is a one-time cost, not a guess repeated on every call.
 */
function currentPriestId(): ?int
{
    $user = currentUser();
    $userId = $user['user_id'] ?? null;
    if (!$userId) return null;
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    $pdo = db();
    $stmt = $pdo->prepare('SELECT priest_id FROM priests WHERE user_id = ?');
    $stmt->execute([$userId]);
    $priestId = $stmt->fetchColumn();

    if (!$priestId && !empty($user['email'])) {
        $stmt = $pdo->prepare('SELECT priest_id FROM priests WHERE user_id IS NULL AND LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$user['email']]);
        $priestId = $stmt->fetchColumn();
        if ($priestId) {
            $pdo->prepare('UPDATE priests SET user_id = ? WHERE priest_id = ? AND user_id IS NULL')->execute([$userId, $priestId]);
        }
    }

    return $cache[$userId] = ($priestId ?: null);
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
    // 8 hex characters (about 4.3 billion values). The earlier 6-character
    // form was small enough to guess by brute force (audit H-02).
    do {
        $code = 'PH-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = db()->prepare('SELECT 1 FROM appointments WHERE guest_reference = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

/**
 * Failure counter for a throttle key (an email, an IP, or a feature name).
 * Stored in auth_throttle so it survives cookie resets; see
 * database/migration_auth_throttle.sql.
 *
 * Returns the seconds left on an active lockout, or 0 when the key is free.
 */
function throttleSecondsLeft(string $key): int
{
    $stmt = db()->prepare(
        'SELECT CEIL(EXTRACT(EPOCH FROM (locked_until - NOW())))::int FROM auth_throttle
         WHERE throttle_key = ? AND locked_until > NOW()'
    );
    $stmt->execute([$key]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/** Failures older than this no longer count, so a shared office IP does not stay half-locked. */
const THROTTLE_WINDOW_SECONDS = 900;

/**
 * Records one failed attempt and returns the failures now counted in the
 * window. Reaching $maxFailures locks the key for $lockSeconds. Further
 * failures during a lockout do not extend it. One atomic upsert.
 */
function throttleRecordFailure(string $key, int $maxFailures, int $lockSeconds): int
{
    $counted = "CASE WHEN (auth_throttle.locked_until IS NOT NULL AND auth_throttle.locked_until <= NOW())
                      OR auth_throttle.updated_at < NOW() - make_interval(secs => ?)
                 THEN 1 ELSE auth_throttle.failures + 1 END";
    $stmt = db()->prepare(
        "INSERT INTO auth_throttle (throttle_key, failures, locked_until, updated_at)
         VALUES (?, 1, NULL, NOW())
         ON CONFLICT (throttle_key) DO UPDATE SET
           failures = {$counted},
           locked_until = CASE WHEN ({$counted}) >= ?
                               THEN CASE WHEN auth_throttle.locked_until > NOW() THEN auth_throttle.locked_until
                                         ELSE NOW() + make_interval(secs => ?) END
                               ELSE NULL END,
           updated_at = NOW()
         RETURNING failures"
    );
    $stmt->execute([$key, THROTTLE_WINDOW_SECONDS, THROTTLE_WINDOW_SECONDS, $maxFailures, $lockSeconds]);
    return (int) $stmt->fetchColumn();
}

/** Clears a key after a successful action (e.g. a correct password). */
function throttleClear(string $key): void
{
    db()->prepare('DELETE FROM auth_throttle WHERE throttle_key = ?')->execute([$key]);
}

/**
 * True when this browser may see an appointment's guest reference code. The
 * code is the only credential for the guest status page (status.php), so it
 * must never be printed to someone who merely knows an appointment ID.
 *
 * Granted when the browser started that checkout (session marker set in
 * book.php / donate.php), or when the logged-in parishioner owns it.
 */
function canViewGuestReference(int $appointmentId): bool
{
    if (!empty($_SESSION['mi_checkout'][$appointmentId]) || !empty($_SESSION['donation_checkout'][$appointmentId])) {
        return true;
    }
    $user = currentUser();
    if (!$user || $user['role_name'] !== 'Parishioner') {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         WHERE a.appointment_id = ? AND p.user_id = ?'
    );
    $stmt->execute([$appointmentId, $user['user_id']]);
    return (bool) $stmt->fetchColumn();
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
        $roleFolders = ['admin', 'secretary', 'treasurer', 'parishioner', 'priest', 'auth', 'staff'];
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

/**
 * Where a finished booking lands, with the Thank You dialog open: Check Status
 * for guests, Services for logged-in parishioners (so they can book another
 * service right away). The reference code travels in the session, not the
 * URL, so the status lookup form is not pre-filled and the code is shown once.
 */
function bookingReturnUrl(bool $isGuest): string
{
    return url($isGuest ? 'status.php' : 'parishioner/services.php');
}

function rememberBookingThankYou(string $reference): void
{
    $_SESSION['booking_thank_you'] = $reference;
}

/** Returns the reference to show in the Thank You dialog, once, then clears it. */
function pullBookingThankYou(): ?string
{
    $reference = $_SESSION['booking_thank_you'] ?? null;
    unset($_SESSION['booking_thank_you']);
    return $reference ? (string) $reference : null;
}

/**
 * The booking behind a Thank You reference, for the parishioner Thank You
 * dialog — only when it is one of THIS parishioner's own bookings (never a
 * donation), with its current status from the database. Null otherwise, and
 * then the dialog shows the code alone.
 *
 * @return array{appointment_id: int, service_name: string, category: string, status_name: string}|null
 */
function parishionerBookingThankYou(?string $reference, ?int $parishionerId): ?array
{
    if (!$reference || !$parishionerId) return null;
    $stmt = db()->prepare(
        "SELECT a.appointment_id, s.service_name, s.category, st.status_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN appointment_status st ON a.status_id = st.status_id
         WHERE a.guest_reference = ? AND a.parishioner_id = ? AND s.category != 'Donation'
         ORDER BY a.appointment_id DESC LIMIT 1"
    );
    $stmt->execute([$reference, $parishionerId]);
    return $stmt->fetch() ?: null;
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
