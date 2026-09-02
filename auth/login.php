<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
guestOnly();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        flash('error', 'Invalid email or password.');
        redirect(url('auth/login.php'));
    }

    if ($user['status'] !== 'active') {
        flash('error', 'Your account is not active. Please contact the parish office.');
        redirect(url('auth/login.php'));
    }

    if (!password_verify($password, $user['password'])) {
        flash('error', 'Invalid email or password.');
        redirect(url('auth/login.php'));
    }

    $_SESSION['user'] = [
        'user_id'   => (int) $user['user_id'],
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
        'email'     => $user['email'],
        'role_id'   => (int) $user['role_id'],
        'role_name' => $user['role_name'],
    ];

    logActivity((int) $user['user_id'], "{$user['firstname']} {$user['lastname']} logged in", 'Auth');

    flash('success', 'Welcome back, ' . $user['firstname'] . '!');
    redirect(redirectForRole($user['role_name']));
}

$pageTitle = 'Log In';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel">
    <div class="panel-brand">
      <span class="crest"><?= crestMarkup() ?></span>
      <span class="panel-brand-text">PARISHHUB</span>
    </div>
    <blockquote class="panel-quote">
      "Ask, and it will be given to you; seek, and you will find; knock, and it will be opened to you."
      <cite>Matthew 7:7</cite>
    </blockquote>
    <p class="panel-foot">Sign in to submit requests, track appointments, and manage your parish services.</p>
  </div>

  <div class="auth-split-form">
    <div class="auth-card">
      <h1 style="text-align:left; font-size: 28px;">Welcome back</h1>
      <p class="subtitle" style="text-align:left; margin-bottom: 28px;">Sign in to manage your parish requests</p>

      <?php $__flash = getFlash(); include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/login.php') ?>">
        <?= csrfField() ?>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" required placeholder="you@example.com">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Log In</button>
      </form>

      <div class="auth-footer">
        Don't have an account? <a href="<?= url('auth/register.php') ?>">Register here</a>
      </div>

     
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
