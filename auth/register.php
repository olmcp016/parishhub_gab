<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
guestOnly();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;
    $birthdate = $_POST['birthdate'] ?: null;
    $gender = $_POST['gender'] ?: null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email address format.');
        redirect(url('auth/register.php'));
    }
    if ($phone && !preg_match('/^09[0-9]{9}$/', $phone)) {
        flash('error', 'Phone number must be exactly 11 digits starting with 09.');
        redirect(url('auth/register.php'));
    }

    if ($password !== $confirm) {
        flash('error', 'Passwords do not match.');
        redirect(url('auth/register.php'));
    }
    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
        redirect(url('auth/register.php'));
    }

    $stmt = db()->prepare('SELECT user_id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        flash('error', 'An account with that email already exists.');
        redirect(url('auth/register.php'));
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            "INSERT INTO users (role_id, firstname, lastname, email, password, phone, address, birthdate, gender, status)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([$firstname, $lastname, $email, $hash, $phone, $address, $birthdate, $gender]);
        $userId = db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)');
        $stmt->execute([$userId]);

        db()->commit();
        flash('success', 'Account created successfully! Please log in.');
        redirect(url('auth/login.php'));
    } catch (Throwable $e) {
        db()->rollBack();
        error_log($e->getMessage());
        flash('error', 'Registration failed. Please try again.');
        redirect(url('auth/register.php'));
    }
}

$pageTitle = 'Create an Account';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel">
    <div class="panel-brand">
      <span class="crest"><?= crestMarkup() ?></span>
      <span class="panel-brand-text">PARISHHUB</span>
      <span class="panel-brand-sub">Parish Service Portal</span>
    </div>
    <div class="panel-bottom">
      <div class="panel-pills">
        <span class="pill"><i data-lucide="flame"></i> Mass Intentions</span>
        <span class="pill"><i data-lucide="droplet"></i> Baptism</span>
        <span class="pill"><i data-lucide="heart"></i> Wedding</span>
        <span class="pill"><i data-lucide="heart-handshake"></i> Blessing</span>
        <span class="pill"><i data-lucide="cross"></i> Funeral</span>
        <span class="pill">☁️ Confirmation</span>
      </div>
      <blockquote class="panel-quote">
        "Where two or three gather in my name, there am I with them."
        <cite>Matthew 18:20</cite>
      </blockquote>
      <p class="panel-foot">Create an account to book Sacraments, submit requirements, and pay online — all from home.</p>
    </div>
  </div>

  <div class="auth-split-form">
    <div class="auth-card wide">
      <div class="form-heading">
        <h1>Create your account</h1>
        <p class="subtitle">Register as a parishioner to book services online</p>
      </div>

      <?php $__flash = getFlash(); include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/register.php') ?>">
        <?= csrfField() ?>
        <div class="form-row">
          <div class="form-group">
            <label>First Name</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="user"></i></span>
              <input type="text" name="firstname" required autofocus>
            </div>
          </div>
          <div class="form-group">
            <label>Last Name</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="user"></i></span>
              <input type="text" name="lastname" required>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" name="email" required autocomplete="off">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Password</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="lock"></i></span>
              <input type="password" name="password" id="pwField" required minlength="8" autocomplete="new-password">
              <button type="button" class="toggle-pw" onclick="parishToggle('pwField', this)"><i data-lucide="eye"></i></button>
            </div>
          </div>
          <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="lock"></i></span>
              <input type="password" name="confirm_password" id="cpwField" required minlength="8" autocomplete="new-password">
              <button type="button" class="toggle-pw" onclick="parishToggle('cpwField', this)"><i data-lucide="eye"></i></button>
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Phone Number</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="smartphone"></i></span>
              <input type="tel" name="phone" pattern="09[0-9]{9}" maxlength="11" minlength="11" placeholder="09XXXXXXXXX" title="Must be exactly 11 digits starting with 09">
            </div>
          </div>
          <div class="form-group">
            <label>Birthdate</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="calendar-heart"></i></span>
              <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Gender</label>
            <select name="gender">
              <option value="">Select</option>
              <option>Male</option>
              <option>Female</option>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Address</label>
            <div class="input-wrap">
              <span class="input-icon">🏠</span>
              <input type="text" name="address">
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block"><i data-lucide="user-plus" style="width:18px;height:18px;vertical-align:text-bottom;margin-right:6px;"></i> Create My Account</button>
      </form>

      <div class="auth-footer">
        Already have an account? <a href="<?= url('auth/login.php') ?>">Log in here</a>
      </div>
    </div>
  </div>
</div>
<script>
function parishToggle(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  if(window.lucide) { lucide.createIcons(); }
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
