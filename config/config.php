<?php
/**
 * PARISHHUB — Database Configuration
 * Edit these constants to match your local MySQL / phpMyAdmin setup.
 * Default values match a typical XAMPP/WAMP/MAMP installation.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'aws-0-ap-southeast-1.pooler.supabase.com');
define('DB_PORT', getenv('DB_PORT') ?: '6543');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres.gzyupwzalamtnehaywwh');
// The password must be provided via the environment variable DB_PASS.
define('DB_PASS', getenv('DB_PASS') ?: '');

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
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
