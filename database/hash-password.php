<?php
/**
 * Utility: generate a bcrypt hash for a plaintext password.
 *
 * CLI usage: php database/hash-password.php "YourPassword123"
 *
 * Paste the resulting hash directly into the `password` column of the
 * `users` table if you're setting up accounts manually.
 */

// CLI-only utility. The `database/` folder is also blocked at the web
// server level (see database/.htaccess), but this is a second layer of
// defense in case that folder ever gets served (e.g. a non-Apache host) —
// without it, this would be an unauthenticated bcrypt oracle usable for a
// trivial CPU-exhaustion DoS.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: this script is CLI-only.');
}

$plain = $argv[1] ?? null;

if (!$plain) {
    echo "Usage: php hash-password.php \"YourPassword\"\n";
    exit;
}

$hash = password_hash($plain, PASSWORD_BCRYPT);

echo "Plain password : $plain\nBcrypt hash    : $hash\n\nPaste this hash into the users.password column.\n";
