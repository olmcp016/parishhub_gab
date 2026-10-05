<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['force_change_password_user'])) {
    redirect(url('auth/login.php'));
}

$user = $_SESSION['force_change_password_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        flash('error', 'Password must be at least 8 characters long.');
    } elseif ($newPassword !== $confirmPassword) {
        flash('error', 'Passwords do not match.');
    } else {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?')->execute([$hash, $user['user_id']]);
        
        unset($_SESSION['force_change_password_user']);
        
        logActivity($user['user_id'], "Changed password upon first login", 'Auth');
        flash('success', 'Password updated successfully. Please log in with your new password.');
        redirect(url('auth/login.php'));
    }
}

$pageTitle = 'Update Password';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel" style="background-image: url('<?= url('public/img/login_bg.jpg') ?>');">
    <a href="<?= url('index.php') ?>" style="display:contents; text-decoration:none; color:inherit;" title="Back to Home">
      <div class="panel-brand">
        <span class="crest"><?= crestMarkup() ?></span>
        <span class="panel-brand-titles">
          <span class="panel-brand-text">Our Lady of Mt. Carmel Parish</span>
          <span class="panel-brand-sub">Parish Service Portal</span>
        </span>
      </div>
    </a>
    <div class="panel-bottom">
      <p class="panel-foot">For your security, please create a new personalized password before continuing.</p>
    </div>
  </div>

  <div class="auth-split-form">
    <a href="<?= url('auth/login.php') ?>" class="auth-back-link">← Back to Login</a>

    <div class="auth-card">
      <div class="form-heading">
        <h1>Update Password</h1>
        <p class="subtitle">Please change the temporary password given to you.</p>
      </div>

      <?php include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/force_change_password.php') ?>">
        <?= csrfField() ?>
        
        <div class="form-group">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" value="<?= e($user['email']) ?>" disabled style="background:#f9fafb; cursor:not-allowed;">
          </div>
        </div>

        <div class="form-group">
          <label>New Password</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="lock"></i></span>
            <input type="password" name="new_password" id="npw" required minlength="8" placeholder="At least 8 characters" autofocus>
            <button type="button" class="toggle-pw" onclick="togglePwd('npw', this)"><i data-lucide="eye"></i></button>
          </div>
        </div>

        <div class="form-group">
          <label>Confirm New Password</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="lock"></i></span>
            <input type="password" name="confirm_password" id="cpw" required minlength="8" placeholder="Type it again">
            <button type="button" class="toggle-pw" onclick="togglePwd('cpw', this)"><i data-lucide="eye"></i></button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:10px;">Update Password & Continue</button>
      </form>
    </div>
  </div>
</div>
<script src="https://unpkg.com/lucide@latest"></script>
<script>
lucide.createIcons();
function togglePwd(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  lucide.createIcons();
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
