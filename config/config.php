<?php
/**
 * PARISHHUB — Database Configuration
 * Edit these constants to match your local MySQL / phpMyAdmin setup.
 * Default values match a typical XAMPP/WAMP/MAMP installation.
 */

// Optional local override (gitignored) — put putenv('DB_PASS=...') etc. in
// config/config.local.php to supply secrets without editing this tracked file.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

define('DB_HOST', getenv('DB_HOST') ?: 'aws-0-ap-southeast-1.pooler.supabase.com');
define('DB_PORT', getenv('DB_PORT') ?: '6543');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres.gzyupwzalamtnehaywwh');
// The password must be provided via the environment variable DB_PASS.
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SUPABASE_URL', rtrim((string) (getenv('SUPABASE_URL') ?: ''), '/'));
define('SUPABASE_SECRET_KEY', (string) (getenv('SUPABASE_SECRET_KEY') ?: ''));
define('SUPABASE_DOCUMENT_BUCKET', getenv('SUPABASE_DOCUMENT_BUCKET') ?: 'parish-documents');
// If Supabase credentials are present (as they are in production), default to
// durable object storage. Local disk remains available only when explicitly
// selected or when no Supabase storage credentials are configured.
$configuredDocumentDriver = strtolower(trim((string) getenv('DOCUMENT_STORAGE_DRIVER')));
define(
    'DOCUMENT_STORAGE_DRIVER',
    $configuredDocumentDriver !== ''
        ? $configuredDocumentDriver
        : (SUPABASE_URL !== '' && SUPABASE_SECRET_KEY !== '' ? 'supabase' : 'local')
);

define('SESSION_DRIVER', strtolower(trim((string) (getenv('SESSION_DRIVER') ?: 'files'))));
define('SESSION_GC_MAXLIFETIME', max(300, (int) (getenv('SESSION_GC_MAXLIFETIME') ?: 7200)));

/**
 * Database-backed sessions for deployments where local PHP session files are
 * not durable (for example, Render containers). The handler is opt-in so a
 * local installation does not require the session table until it selects the
 * database driver.
 */
final class ParishHubDatabaseSessionHandler implements SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string
    {
        $stmt = db()->prepare(
            'SELECT session_data FROM app_sessions WHERE session_id = ? AND expires_at > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();
        if ($data === false) return '';

        // PHP may skip write() for an unchanged read-only session when
        // session.lazy_write is enabled. Refresh the sliding expiry here so
        // normal navigation keeps an active session alive.
        db()->prepare(
            "UPDATE app_sessions
             SET last_activity = CURRENT_TIMESTAMP,
                 expires_at = CURRENT_TIMESTAMP + (? * INTERVAL '1 second')
             WHERE session_id = ?"
        )->execute([SESSION_GC_MAXLIFETIME, $id]);
        return (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = db()->prepare(
            "INSERT INTO app_sessions (session_id, session_data, last_activity, expires_at)
             VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))
             ON CONFLICT (session_id) DO UPDATE SET
                 session_data = EXCLUDED.session_data,
                 last_activity = CURRENT_TIMESTAMP,
                 expires_at = EXCLUDED.expires_at"
        );
        return $stmt->execute([$id, $data, SESSION_GC_MAXLIFETIME]);
    }

    public function destroy(string $id): bool
    {
        $stmt = db()->prepare('DELETE FROM app_sessions WHERE session_id = ?');
        return $stmt->execute([$id]);
    }

    public function validateId(string $id): bool
    {
        $stmt = db()->prepare(
            'SELECT 1 FROM app_sessions WHERE session_id = ? AND expires_at > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = db()->prepare(
            "UPDATE app_sessions
             SET last_activity = CURRENT_TIMESTAMP,
                 expires_at = CURRENT_TIMESTAMP + (? * INTERVAL '1 second')
             WHERE session_id = ?"
        );
        return $stmt->execute([SESSION_GC_MAXLIFETIME, $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = db()->prepare('DELETE FROM app_sessions WHERE expires_at <= CURRENT_TIMESTAMP');
        $stmt->execute();
        return $stmt->rowCount();
    }
}

define('APP_NAME', 'PARISHHUB');
define('APP_URL', 'http://localhost/parishhub'); // change to match your local path
define('MAX_UPLOAD_MB', 5);
define('MAX_DOCUMENT_UPLOAD_MB', 2);
define('BASE_PATH', dirname(__DIR__)); // project root, e.g. .../parishhub-php

// Configure the cookie before session_start(). A root path is required because
// ParishHub pages live in several directories (/auth, /parishioner, etc.).
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) SESSION_GC_MAXLIFETIME);
$sessionIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $sessionIsHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (SESSION_DRIVER === 'database') {
    session_set_save_handler(new ParishHubDatabaseSessionHandler(), true);
}

// Session must be started before anything else touches $_SESSION.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

/**
 * PDO connection (shared, lazily created)
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Supabase's connection string here uses port 6543 — PgBouncer in
            // transaction-pooling mode, which does not reliably support native
            // (server-side) prepared statements once a transaction issues
            // enough distinct prepares: it fails with a generic "current
            // transaction is aborted" error with no useful diagnostic. Emulated
            // (client-side) prepares avoid this entirely and are the standard,
            // safe (still fully parameterized, no SQL-injection risk) setting
            // for PDO against a transaction pooler.
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
    }
    return $pdo;
}
