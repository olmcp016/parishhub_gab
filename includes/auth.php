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
 * Session key holding a fingerprint of the password hash the user signed in
 * with. Any password change (Settings, an Admin reset, Forgot Password) gives
 * the account a new hash, which ends every session still carrying the old
 * fingerprint (audit B-04). Never the password or the hash itself.
 */
const SESSION_PASSWORD_FINGERPRINT = 'auth_password_fingerprint';

function passwordFingerprint(string $passwordHash): string
{
    return hash('sha256', 'parishhub-session:' . $passwordHash);
}

/**
 * Signs in an account whose password, status and portal were already checked
 * by the login page. $user is its users row joined with roles.role_name.
 */
function signInUser(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id'   => (int) $user['user_id'],
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
        'email'     => $user['email'],
        'role_id'   => (int) $user['role_id'],
        'role_name' => $user['role_name'],
    ];
    $_SESSION[SESSION_PASSWORD_FINGERPRINT] = passwordFingerprint((string) $user['password']);
}

/**
 * Call after $userId's password was successfully changed to $newHash. When that
 * is the signed-in user's own account, this session stays signed in; their other
 * sessions still carry the old fingerprint and end on their next request.
 */
function refreshPasswordFingerprint(int $userId, string $newHash): void
{
    if ((int) (currentUser()['user_id'] ?? 0) === $userId) {
        $_SESSION[SESSION_PASSWORD_FINGERPRINT] = passwordFingerprint($newHash);
    }
}

/** True when the caller expects JSON back (fetch/XHR, the detail modal, ajax=1 forms). */
function wantsJsonResponse(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_POST['ajax'] ?? '') === '1'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/**
 * Re-checks the signed-in account against the database on every request
 * (audit B-04). The session alone is not trusted: the account must still
 * exist, be active, have the role it signed in with, and have the password
 * it signed in with. Otherwise the session ends. Runs when this file loads,
 * before the page does anything, so it covers every guard and every page
 * that reads currentUser() directly.
 */
function validateSessionUser(): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    $user = $_SESSION['user'];
    $fingerprint = $_SESSION[SESSION_PASSWORD_FINGERPRINT] ?? null;

    // Sessions from before the fingerprint existed must sign in again.
    if (!is_array($user) || !is_string($fingerprint) || $fingerprint === '') {
        endSignedInSession($user['role_name'] ?? null);
        return;
    }

    try {
        $stmt = db()->prepare(
            'SELECT u.status, u.role_id, r.role_name, u.password
             FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([(int) ($user['user_id'] ?? 0)]);
        $account = $stmt->fetch();
    } catch (Throwable $e) {
        // Exception class and SQLSTATE only: the message can name the database host and user.
        error_log('ParishHub: could not verify the signed-in account (' . get_class($e) . ' ' . $e->getCode() . ')');
        accountCheckUnavailable();
    }

    if (!$account
        || $account['status'] !== 'active'
        || (int) $account['role_id'] !== (int) ($user['role_id'] ?? 0)
        || $account['role_name'] !== ($user['role_name'] ?? null)
        || !hash_equals(passwordFingerprint((string) $account['password']), $fingerprint)
    ) {
        endSignedInSession($user['role_name'] ?? null);
    }
}

/**
 * Ends a revoked session: everything in it is cleared (including anything the
 * account could see, such as a temporary password notice) and it gets a new ID.
 * A GET then carries on as a signed-out visitor, so protected pages redirect to
 * login through their usual guard and session-status.php reports the change.
 * Any other request stops here, so no action runs on a revoked session.
 */
function endSignedInSession(?string $previousRole): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    if (!defined('SESSION_ENDED_THIS_REQUEST')) {
        define('SESSION_ENDED_THIS_REQUEST', true);
    }
    $message = 'Your session has ended. Please sign in again.';
    flash('error', $message);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return;
    }
    sendNoCacheHeaders();
    $loginUrl = in_array($previousRole, STAFF_ROLES, true) ? url('staff/login.php') : url('auth/login.php');
    if (wantsJsonResponse()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'session_expired' => true, 'message' => $message, 'redirect' => $loginUrl]);
        exit;
    }
    redirect($loginUrl);
}

/**
 * The account could not be checked (database unreachable). Nothing on this
 * request runs, but the session is kept: a brief outage is not a sign-out.
 */
function accountCheckUnavailable(): never
{
    sendNoCacheHeaders();
    http_response_code(503);
    header('Retry-After: 30');
    $message = 'ParishHub is temporarily unavailable. Please try again in a moment.';
    if (wantsJsonResponse()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'message' => $message]);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Temporarily unavailable</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:48px 16px;"><h1>Temporarily unavailable</h1><p>'
        . $message . '</p></body></html>';
    exit;
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
        $targetUrl = url('auth/login.php');
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || (function_exists('isDetailModalRequest') && isDetailModalRequest())) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'session_expired' => true, 'redirect' => $targetUrl]);
            exit;
        }
        if (!defined('SESSION_ENDED_THIS_REQUEST')) {
            flash('error', 'Your session has expired or you are not signed in. Please log in again.');
        }
        redirect($targetUrl);
    }
}

/** Usage: requireRole('Admin', 'Secretary'); */
function requireRole(string ...$roles): void
{
    sendNoCacheHeaders();
    if (!isLoggedIn()) {
        // If every required role is a staff/clergy role, send to the Staff Portal.
        $allStaff = !empty($roles) && count(array_diff($roles, STAFF_ROLES)) === 0;
        $targetUrl = $allStaff ? url('staff/login.php') : url('auth/login.php');
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || (function_exists('isDetailModalRequest') && isDetailModalRequest())) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'session_expired' => true, 'redirect' => $targetUrl]);
            exit;
        }
        if (!defined('SESSION_ENDED_THIS_REQUEST')) {
            flash('error', 'Your session has expired or you are not signed in. Please log in again.');
        }
        redirect($targetUrl);
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
 * Account" on admin/priests.php), then by an exact, case-insensitive email
 * match, which is persisted. An unlinked Admin (this parish's Admin is the
 * Parish Priest) falls back to the configured current Parish Priest
 * (settings.parish_priest_id) — not persisted, so it follows the setting when
 * the Parish Priest changes, and null when the setting is missing or invalid.
 */
function currentPriestId(): ?int
{
    $user = currentUser();
    $userId = $user['user_id'] ?? null;
    if (!$userId) return null;
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    $pdo = db();
    $stmt = $pdo->prepare('SELECT priest_id FROM priests WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $priestId = $stmt->fetchColumn();

    if (!$priestId && !empty($user['email'])) {
        $stmt = $pdo->prepare('SELECT priest_id FROM priests WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$user['email']]);
        $priestId = $stmt->fetchColumn();
        if ($priestId) {
            try {
                $pdo->prepare('UPDATE priests SET user_id = ? WHERE priest_id = ?')->execute([$userId, (int) $priestId]);
            } catch (Throwable $e) {}
        }
    }

    if (!$priestId && ($user['role_name'] ?? '') === 'Admin') {
        $priestId = currentParishPriestId();
    }

    return $cache[$userId] = ($priestId ? (int) $priestId : null);
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
 * The configured current Parish Priest (settings.parish_priest_id, chosen by the
 * Admin on admin/settings.php). Null when the setting is unset, not a priest, or
 * that priest is no longer active: callers then leave the Parish Priest line
 * blank or treat the Admin as unlinked — never guess a priest by name.
 *
 * @return array{priest_id:int, title:string, full_name:string}|null
 */
function currentParishPriest(): ?array
{
    try {
        $value = trim((string) db()->query("SELECT setting_value FROM settings WHERE setting_key = 'parish_priest_id'")->fetchColumn());
        return ctype_digit($value) ? activePriestById((int) $value) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function currentParishPriestId(): ?int
{
    return currentParishPriest()['priest_id'] ?? null;
}

/** @return array{priest_id:int, title:string, full_name:string}|null — only an active priest record. */
function activePriestById(int $priestId): ?array
{
    $stmt = db()->prepare("SELECT priest_id, title, full_name FROM priests WHERE priest_id = ? AND status = 'active'");
    $stmt->execute([$priestId]);
    $row = $stmt->fetch();
    return $row ? ['priest_id' => (int) $row['priest_id'], 'title' => (string) $row['title'], 'full_name' => (string) $row['full_name']] : null;
}

/**
 * Verified printed names (settings.priest_printed_names: JSON {priest_id: name}),
 * typed and confirmed by the Admin on admin/settings.php ("Certificate Signatory
 * Names"). Certificates and forms print only these — never a name assembled from
 * priests.title/full_name, whose contents vary (a name in one, a designation such
 * as "Parish Priest" in the other).
 *
 * @return array<int, string> priest_id => upper-case printed name
 */
function verifiedPriestNames(): array
{
    try {
        $json = (string) db()->query("SELECT setting_value FROM settings WHERE setting_key = 'priest_printed_names'")->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    $names = [];
    foreach ((json_decode($json, true) ?: []) as $id => $name) {
        if (ctype_digit((string) $id) && is_string($name) && trim($name) !== '') $names[(int) $id] = $name;
    }
    return $names;
}

/** A priest's verified printed name, or null when the Admin has not verified one. */
function priestPrintedName(int $priestId): ?string
{
    return verifiedPriestNames()[$priestId] ?? null;
}

/**
 * Active priests that have a verified printed name — the only ones that may be
 * chosen as Current Parish Priest or as a certificate signatory.
 *
 * @return array<int, array{priest_id:int, printed_name:string}>
 */
function signatoryPriests(): array
{
    $verified = verifiedPriestNames();
    $priests = [];
    foreach (db()->query("SELECT priest_id FROM priests WHERE status = 'active' ORDER BY priest_id")->fetchAll() as $row) {
        $id = (int) $row['priest_id'];
        if (isset($verified[$id])) $priests[] = ['priest_id' => $id, 'printed_name' => $verified[$id]];
    }
    usort($priests, static fn(array $a, array $b): int => strcmp($a['printed_name'], $b['printed_name']));
    return $priests;
}

/**
 * The current Parish Priest's verified printed name, or '' when no Current Parish
 * Priest is set or his name is not verified (the line stays blank; nothing is guessed).
 */
function currentParishPriestSignatory(): string
{
    $priest = currentParishPriest();
    return $priest ? (priestPrintedName($priest['priest_id']) ?? '') : '';
}

/**
 * Normalizes and checks a printed name typed by the Admin: trimmed, single-spaced,
 * upper-cased (Ñ kept). Returns [name, null] or [null, error message].
 *
 * @return array{0: ?string, 1: ?string}
 */
function normalizePriestPrintedName(string $input): array
{
    $name = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $input)), 'UTF-8');
    if ($name === '') return [null, 'is empty'];
    if (mb_strlen($name) > 80) return [null, 'is longer than 80 characters'];
    if (!preg_match("/^[\\p{L}][\\p{L}\\p{M} .,'\\-]*$/u", $name)) return [null, 'may contain only letters, spaces, periods, commas, apostrophes and hyphens'];
    $printable = @iconv('UTF-8', 'Windows-1252', $name);
    if ($printable === false || iconv('Windows-1252', 'UTF-8', $printable) !== $name) return [null, 'contains a character that cannot be printed on the certificates'];
    return [$name, null];
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
 * The email or phone typed on the public Check Status page, normalized:
 * an email is trimmed and lower-cased; a Philippine mobile number in any
 * common form (09…, +639…, 639…, with spaces or dashes) becomes 09XXXXXXXXX.
 * Anything else yields NULLs, which never match a booking.
 *
 * @return array{email: ?string, phone: ?string}
 */
function statusLookupContact(string $contact): array
{
    $contact = trim($contact);
    if (str_contains($contact, '@')) return ['email' => mb_strtolower($contact, 'UTF-8'), 'phone' => null];
    $digits = preg_replace('/[^0-9]/', '', $contact);
    return ['email' => null, 'phone' => preg_match('/^(?:63|0)(9[0-9]{9})$/', $digits, $m) ? '0' . $m[1] : null];
}

/** SQL expression giving a stored phone column in the same 09XXXXXXXXX form as statusLookupContact(). */
function statusLookupPhoneSql(string $column): string
{
    return "regexp_replace(regexp_replace(COALESCE($column, ''), '[^0-9]', '', 'g'), '^63', '0')";
}

/** Attempts older than this no longer count. */
const THROTTLE_WINDOW_SECONDS = 900;

/**
 * Reserves one attempt against a throttle key (an email, a reference code, a
 * contact) BEFORE the attempt is checked. Counters live in auth_throttle so
 * they survive cookie resets; see database/migration_auth_throttle.sql.
 *
 * One atomic upsert (audit B-05): concurrent requests for the same key queue on
 * its row for the length of this statement only, so exactly $maxAttempts get
 * through per window however many arrive at once, and no lock is held while the
 * caller checks a password. The attempt that reaches $maxAttempts starts the
 * lock and still goes ahead; later ones are refused until the lock expires.
 * Refused attempts are counted but never extend the lock. Never keyed by the
 * client IP: behind Render's proxy every visitor can share one address.
 * Attempts count until $windowSeconds pass without one, or a lock expires.
 *
 * @return array{allowed: bool, attempts: int, retry_after: int} retry_after is the seconds left on the lock
 */
function throttleReserve(string $key, int $maxAttempts, int $lockSeconds, int $windowSeconds = THROTTLE_WINDOW_SECONDS): array
{
    $counted = "CASE WHEN auth_throttle.locked_until > NOW() THEN LEAST(auth_throttle.failures + 1, 1000000)
                     WHEN auth_throttle.locked_until IS NOT NULL
                       OR auth_throttle.updated_at < NOW() - make_interval(secs => ?) THEN 1
                     ELSE auth_throttle.failures + 1 END";
    $stmt = db()->prepare(
        "INSERT INTO auth_throttle (throttle_key, failures, locked_until, updated_at)
         VALUES (?, 1, CASE WHEN ? <= 1 THEN NOW() + make_interval(secs => ?) END, NOW())
         ON CONFLICT (throttle_key) DO UPDATE SET
           failures = {$counted},
           locked_until = CASE WHEN auth_throttle.locked_until > NOW() THEN auth_throttle.locked_until
                               WHEN ({$counted}) >= ? THEN NOW() + make_interval(secs => ?)
                               ELSE NULL END,
           updated_at = NOW()
         RETURNING failures, GREATEST(0, COALESCE(CEIL(EXTRACT(EPOCH FROM (locked_until - NOW()))), 0))::int AS retry_after"
    );
    $stmt->execute([$key, $maxAttempts, $lockSeconds, $windowSeconds, $windowSeconds, $maxAttempts, $lockSeconds]);
    $row = $stmt->fetch();

    // Rare housekeeping on the attempt path only, never on ordinary page views.
    if (random_int(1, 100) === 1) {
        throttleCleanup();
    }

    $attempts = (int) $row['failures'];
    return ['allowed' => $attempts <= $maxAttempts, 'attempts' => $attempts, 'retry_after' => (int) $row['retry_after']];
}

/**
 * Reserves one attempt against each key in turn; stops at the first refusal.
 * The attempts already reserved for this request are handed back, so a refused
 * contact does not also use up its reference (unless that attempt started a
 * lock, which is kept).
 *
 * @return int 0 when every key allows the attempt, otherwise the seconds left on the refusing key's lock
 */
function throttleReserveAll(array $keys, int $maxAttempts, int $lockSeconds): int
{
    $reserved = [];
    foreach ($keys as $key) {
        $attempt = throttleReserve($key, $maxAttempts, $lockSeconds);
        if (!$attempt['allowed']) {
            foreach ($reserved as $reservedKey) {
                db()->prepare('UPDATE auth_throttle SET failures = failures - 1 WHERE throttle_key = ? AND failures > 0 AND locked_until IS NULL')
                    ->execute([$reservedKey]);
            }
            return max(1, $attempt['retry_after']);
        }
        $reserved[] = $key;
    }
    return 0;
}

/** Clears a key after a successful action (e.g. a correct password). */
function throttleClear(string $key): void
{
    db()->prepare('DELETE FROM auth_throttle WHERE throttle_key = ?')->execute([$key]);
}

/**
 * Deletes counters idle for a day that hold no active lock. Such a row no
 * longer affects any decision (attempts older than the window already count
 * as zero), so removing it changes nothing but the table size.
 */
function throttleCleanup(): void
{
    try {
        db()->exec("DELETE FROM auth_throttle WHERE updated_at < NOW() - INTERVAL '1 day' AND (locked_until IS NULL OR locked_until < NOW())");
    } catch (Throwable $e) {
        error_log('ParishHub: throttle cleanup failed (' . get_class($e) . ' ' . $e->getCode() . ')');
    }
}

/** Login throttle key: one per email, shared by both login portals and by unknown addresses. */
function loginThrottleKey(string $email): string
{
    return 'login_email:' . hash('sha256', strtolower(trim($email)));
}

/**
 * Throttle keys for a Check Status or guest payment lookup, shared by status.php
 * and guest-pay.php: one for the reference (guessing contacts for a known code)
 * and, when a contact was typed, one for the contact (guessing codes for a known
 * email or phone). Built from the input alone, so they say nothing about whether
 * a booking exists.
 *
 * @return list<string>
 */
function statusThrottleKeys(string $reference, ?string $contact = null): array
{
    $keys = ['status_ref:' . hash('sha256', strtoupper(trim($reference)))];
    if ($contact !== null) {
        $lookup = statusLookupContact($contact);
        $keys[] = 'status_contact:' . hash('sha256', $lookup['email'] ?? $lookup['phone'] ?? mb_strtolower(trim($contact), 'UTF-8'));
    }
    return $keys;
}

/** Verification emails per address (audit B-06): one per 10 minutes, five per 24 hours. */
const VERIFICATION_EMAIL_COOLDOWN_SECONDS = 600;
const VERIFICATION_EMAIL_DAILY_MAX = 5;
const VERIFICATION_EMAIL_DAY_SECONDS = 86400;

/** @return array{0: string, 1: string} the 10-minute and 24-hour throttle keys for an address */
function verificationEmailThrottleKeys(string $email): array
{
    $address = hash('sha256', strtolower(trim($email)));
    return ['verify_mail:' . $address, 'verify_mail_day:' . $address];
}

/**
 * Reserves one verification email for this address, shared by registration and
 * resend, before any token changes or email is sent. Keyed by the address, never
 * the IP. Both reservations are atomic, so concurrent requests cannot add up to
 * more than the limits.
 *
 * The 24-hour key counts every email until a full day passes without one, so
 * waiting out the 10-minute cooldown never resets it. Its lock starts at the
 * fifth email and lasts 24 hours; refused requests do not extend either lock.
 *
 * @return int 0 when an email may be sent, otherwise the seconds until the address may get another
 */
function verificationEmailThrottle(string $email): int
{
    [$cooldownKey, $dayKey] = verificationEmailThrottleKeys($email);
    $cooldown = throttleReserve($cooldownKey, 1, VERIFICATION_EMAIL_COOLDOWN_SECONDS);
    if (!$cooldown['allowed']) {
        return max(1, $cooldown['retry_after']);
    }
    $day = throttleReserve($dayKey, VERIFICATION_EMAIL_DAILY_MAX, VERIFICATION_EMAIL_DAY_SECONDS, VERIFICATION_EMAIL_DAY_SECONDS);
    if (!$day['allowed']) {
        return max(1, $day['retry_after']);
    }
    return 0;
}

/**
 * Hands back a reservation whose email could not be sent, so a delivery failure
 * (not something a requester controls for a valid address) does not cost the
 * person a try. A daily lock that this email started is kept.
 */
function verificationEmailNotSent(string $email): void
{
    [$cooldownKey, $dayKey] = verificationEmailThrottleKeys($email);
    throttleClear($cooldownKey);
    db()->prepare('UPDATE auth_throttle SET failures = failures - 1 WHERE throttle_key = ? AND failures > 0 AND locked_until IS NULL')
        ->execute([$dayKey]);
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

validateSessionUser();
