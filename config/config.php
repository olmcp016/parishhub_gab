<?php
/**
 * PARISHHUB — Database Configuration
 * Edit these constants to match your local MySQL / phpMyAdmin setup.
 * Default values match a typical XAMPP/WAMP/MAMP installation.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'parishhub');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'PARISHHUB');
define('APP_URL', 'http://localhost/parishhub'); // change to match your local path
define('MAX_UPLOAD_MB', 5);
define('BASE_PATH', dirname(__DIR__)); // project root, e.g. .../parishhub-php

// Session must be started before anything else touches $_SESSION
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
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;background:#fbe3de;border-radius:10px;color:#a3341f;">
                <h2>Database Connection Failed</h2>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Check <code>config/config.php</code> and make sure the <strong>parishhub</strong> database
                has been created (via phpMyAdmin or by running <code>install.php</code>).</p>
                </div>');
        }
    }
    return $pdo;
}
