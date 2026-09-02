<?php
/**
 * Utility: generate a bcrypt hash for a plaintext password.
 *
 * CLI usage:      php database/hash-password.php "YourPassword123"
 * Browser usage:  http://localhost/parishhub/database/hash-password.php?password=YourPassword123
 *
 * Paste the resulting hash directly into the `password` column of the
 * `users` table via phpMyAdmin if you're setting up accounts manually.
 */

$plain = null;

if (php_sapi_name() === 'cli') {
    $plain = $argv[1] ?? null;
} else {
    $plain = $_GET['password'] ?? null;
}

if (!$plain) {
    $msg = "Usage:\n  CLI:     php hash-password.php \"YourPassword\"\n  Browser: hash-password.php?password=YourPassword\n";
    if (php_sapi_name() === 'cli') {
        echo $msg;
    } else {
        header('Content-Type: text/plain');
        echo $msg;
    }
    exit;
}

$hash = password_hash($plain, PASSWORD_BCRYPT);

$output = "Plain password : $plain\nBcrypt hash    : $hash\n\nPaste this hash into the users.password column via phpMyAdmin.\n";

if (php_sapi_name() === 'cli') {
    echo $output;
} else {
    header('Content-Type: text/plain');
    echo $output;
}
