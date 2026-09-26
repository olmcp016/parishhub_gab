<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
guestOnly();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;
    $birthdate = $_POST['birthdate'] ?: null;
    $gender = $_POST['gender'] ?: null;

    // Preserved across every validation-failure redirect below so the person
    // never has to retype the form (password fields are deliberately left
    // out — never echo those back, even to the same browser).
    $oldInputToKeep = compact('firstname', 'lastname', 'middlename', 'email', 'phone', 'address', 'birthdate', 'gender');

    if ($lastname === '' || $firstname === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your last name and first name.');
        redirect(url('auth/register.php'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Invalid email address format.');
        redirect(url('auth/register.php'));
    }
    if ($phone && !preg_match('/^09[0-9]{9}$/', $phone)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Phone number must be exactly 11 digits starting with 09.');
        redirect(url('auth/register.php'));
    }

    if ($password !== $confirm) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Passwords do not match. Please re-enter them.');
        redirect(url('auth/register.php'));
    }
    if (strlen($password) < 8) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Password must be at least 8 characters.');
        redirect(url('auth/register.php'));
    }

    $stmt = db()->prepare('SELECT user_id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        keepOldInput($oldInputToKeep);
        flash('error', 'An account with that email already exists.');
        redirect(url('auth/register.php'));
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            "INSERT INTO users (role_id, firstname, lastname, middlename, email, password, phone, address, birthdate, gender, status)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([$firstname, $lastname, $middlename, $email, $hash, $phone, $address, $birthdate, $gender]);
        $userId = db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)');
        $stmt->execute([$userId]);

        db()->commit();
        flash('success', 'Account created successfully! Please log in.');
        redirect(url('auth/login.php'));
    } catch (Throwable $e) {
        db()->rollBack();
        error_log($e->getMessage());
        keepOldInput($oldInputToKeep);
        flash('error', 'Registration failed. Please try again.');
        redirect(url('auth/register.php'));
    }
}

$pageTitle = 'Create an Account';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel" style="background-image: url('<?= url('public/img/register_bg.jpg') ?>');">
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
    <a href="<?= url('index.php') ?>" class="auth-back-link">← Back to Homepage</a>

    <div class="auth-card wide">
      <div class="form-heading">
        <h1>Create your account</h1>
        <p class="subtitle">Register as a parishioner to book services online</p>
      </div>

      <?php // $__flash was already populated once by header.php's own getFlash() call —
            // calling getFlash() again here would find it already drained and empty. ?>
      <?php include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/register.php') ?>" id="registerForm" novalidate>
        <?= csrfField() ?>
        <div class="form-row">
          <div class="form-group">
            <label>Last Name</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="user"></i></span>
              <input type="text" name="lastname" value="<?= oldInput('lastname') ?>" required autofocus>
            </div>
          </div>
          <div class="form-group">
            <label>First Name</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="user"></i></span>
              <input type="text" name="firstname" value="<?= oldInput('firstname') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Middle Name <span class="text-muted" style="font-weight:400;">(optional)</span></label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="user"></i></span>
              <input type="text" name="middlename" value="<?= oldInput('middlename') ?>">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" name="email" value="<?= oldInput('email') ?>" required autocomplete="off">
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
              <input type="tel" name="phone" value="<?= oldInput('phone') ?>" pattern="09[0-9]{9}" maxlength="11" minlength="11" placeholder="09XXXXXXXXX" title="Must be exactly 11 digits starting with 09">
            </div>
          </div>
          <div class="form-group">
            <label>Birthdate</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="calendar-heart"></i></span>
              <input type="date" name="birthdate" value="<?= oldInput('birthdate') ?>" max="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Gender</label>
          <?php $__oldGender = oldInput('gender'); ?>
          <select name="gender">
            <option value="">Select</option>
            <option <?= $__oldGender === 'Male' ? 'selected' : '' ?>>Male</option>
            <option <?= $__oldGender === 'Female' ? 'selected' : '' ?>>Female</option>
            <option <?= $__oldGender === 'Other' ? 'selected' : '' ?>>Other</option>
          </select>
        </div>

        <div class="form-group">
          <label>Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="map-pin"></i></span>
            <input type="text" name="address" value="<?= oldInput('address') ?>">
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
<script src="<?= url('public/js/validation.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/validation.js') ?>"></script>
<script>
function parishToggle(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  if(window.lucide) { lucide.createIcons(); }
}

(function () {
  var form = document.getElementById('registerForm');
  var required = [
    ['[name="lastname"]', 'your last name'],
    ['[name="firstname"]', 'your first name'],
    ['[name="email"]', 'your email address'],
    ['[name="password"]', 'a password'],
    ['[name="confirm_password"]', 'your password again'],
  ];
  clearFieldErrorOnInput(form, required.map(function (f) { return f[0]; }).concat(['[name="phone"]']));

  form.addEventListener('submit', function (e) {
    var firstInvalid = validateRequiredFields(form, required);

    var email = form.querySelector('[name="email"]');
    if (email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      showFieldError(email, 'Please enter a valid email address.');
      firstInvalid = firstInvalid || email;
    }

    var pw = form.querySelector('[name="password"]');
    var cpw = form.querySelector('[name="confirm_password"]');
    if (pw.value && pw.value.length < 8) {
      showFieldError(pw, 'Password must be at least 8 characters.');
      firstInvalid = firstInvalid || pw;
    }
    if (pw.value && cpw.value && pw.value !== cpw.value) {
      showFieldError(cpw, 'Passwords do not match.');
      firstInvalid = firstInvalid || cpw;
    }

    var phone = form.querySelector('[name="phone"]');
    if (phone.value.trim() && !/^09[0-9]{9}$/.test(phone.value.trim())) {
      showFieldError(phone, 'Phone number must be exactly 11 digits starting with 09.');
      firstInvalid = firstInvalid || phone;
    }

    if (firstInvalid) {
      e.preventDefault();
      firstInvalid.focus();
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
