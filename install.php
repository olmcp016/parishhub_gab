<?php
/**
 * PARISHHUB — Web Installer
 * Open this file in your browser (e.g. http://localhost/parishhub/install.php)
 * to create the database schema, seed lookup/sample data, and set working
 * demo passwords — all in one click. Safe to re-run (it will reset data
 * to a fresh seeded state).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$step = $_GET['step'] ?? 'confirm';
$error = null;
$success = false;
$defaultPassword = 'Password@123';

function runSqlFile(mysqli $link, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read $path");
    }
    if (!$link->multi_query($sql)) {
        throw new RuntimeException('SQL error: ' . $link->error);
    }
    // Drain all result sets (required for multi_query)
    do {
        if ($result = $link->store_result()) {
            $result->free();
        }
        if ($link->more_results()) {
            $link->next_result();
        } else {
            break;
        }
    } while (true);

    if ($link->errno) {
        throw new RuntimeException('SQL error: ' . $link->error);
    }
}

if ($step === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Connect WITHOUT selecting a database yet (schema.sql creates it)
        $link = new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int) DB_PORT);
        if ($link->connect_errno) {
            throw new RuntimeException('Connection failed: ' . $link->connect_error);
        }

        runSqlFile($link, __DIR__ . '/database/schema.sql');
        runSqlFile($link, __DIR__ . '/database/seed.sql');
        $link->close();

        // Now set real, working bcrypt password hashes for the 3 demo accounts via PDO
        $pdo = db();
        $hash = password_hash($defaultPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE user_id IN (1,2,3)');
        $stmt->execute([$hash]);

        $success = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PARISHHUB — Installer</title>
  <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card" style="max-width: 560px;">
    <div class="crest-lg"><?= crestMarkup() ?></div>
    <h1>PARISHHUB Installer</h1>

    <?php if ($step === 'confirm' && !$success): ?>
      <p class="subtitle">This will create the <code>parishhub</code> database and all 22 tables, seed lookup/sample data, and set working demo passwords.</p>
      <div class="alert alert-error">⚠ Running this will <strong>drop and recreate</strong> every PARISHHUB table. Only run this on a fresh setup, or if you intend to reset all data.</div>
      <p class="helper-text">Before continuing, make sure <code>config/config.php</code> has the correct <code>DB_HOST</code>, <code>DB_USER</code>, and <code>DB_PASS</code> for your MySQL/phpMyAdmin server.</p>
      <form method="POST" action="?step=run">
        <button type="submit" class="btn btn-primary btn-block">Install / Reset Database</button>
      </form>
      <div class="auth-footer"><a href="index.php">← Back to site</a></div>

    <?php elseif ($success): ?>
      <div class="alert alert-success">✔ Database installed successfully!</div>
      <p><strong>Login credentials</strong> (change these after first login):</p>
      <div class="demo-creds" style="margin-top:0;">
        Admin (Parish Priest): admin@parishhub.local / <?= e($defaultPassword) ?><br>
        Secretary: secretary@parishhub.local / <?= e($defaultPassword) ?><br>
        Treasurer: treasurer@parishhub.local / <?= e($defaultPassword) ?>
      </div>
      <p class="helper-text mt-3">Register a new Parishioner account from the <a href="auth/register.php">Register</a> page.</p>
      <a href="auth/login.php" class="btn btn-primary btn-block mt-3">Go to Login</a>
      <p class="text-muted mt-3" style="font-size:12px;">🔒 For security, delete or rename <code>install.php</code> once your site is set up.</p>

    <?php else: ?>
      <div class="alert alert-error">❌ Installation failed: <?= e($error) ?></div>
      <p class="helper-text">Double-check your database credentials in <code>config/config.php</code>, then try again.</p>
      <a href="?step=confirm" class="btn btn-outline btn-block">Try Again</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
