<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
guestOnly();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $gender = trim($_POST['gender'] ?? '');

    // Preserved across every validation-failure redirect below so the person
    // never has to retype the form (password fields are deliberately left
    // out — never echo those back, even to the same browser).
    $oldInputToKeep = compact('firstname', 'lastname', 'middlename', 'email', 'phone', 'address', 'birthdate', 'gender');

    if ($lastname === '' || $firstname === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your last name and first name.');
        redirect(url('auth/register.php'));
    }
    if ($email === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your email address.');
        redirect(url('auth/register.php'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Invalid email address format.');
        redirect(url('auth/register.php'));
    }
    if ($password === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter a password.');
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
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Password must contain at least one uppercase letter (A–Z) and one lowercase letter (a–z).');
        redirect(url('auth/register.php'));
    }
    if ($phone === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your phone number.');
        redirect(url('auth/register.php'));
    }
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Phone number must be exactly 11 digits starting with 09.');
        redirect(url('auth/register.php'));
    }
    if ($birthdate === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your birthdate.');
        redirect(url('auth/register.php'));
    }
    if ($gender === '' || !in_array($gender, ['Male', 'Female'], true)) {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please select your gender.');
        redirect(url('auth/register.php'));
    }
    if ($address === '') {
        keepOldInput($oldInputToKeep);
        flash('error', 'Please enter your address.');
        redirect(url('auth/register.php'));
    }

    $stmt = db()->prepare('SELECT user_id, email_verified_at FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($existing = $stmt->fetch()) {
        if ($existing['email_verified_at'] !== null) {
            keepOldInput($oldInputToKeep);
            flash('error', 'An account with that email already exists and is active.');
            redirect(url('auth/register.php'));
        } else {
            db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$existing['user_id']]);
        }
    }

    $hash  = password_hash($password, PASSWORD_BCRYPT);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            "INSERT INTO users
               (role_id, firstname, lastname, middlename, email, password, phone, address, birthdate, gender,
                status, verification_token, token_expires_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        );
        $stmt->execute([$firstname, $lastname, $middlename, $email, $hash, $phone, $address, $birthdate, $gender, $token, $expires]);
        $userId = db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)');
        $stmt->execute([$userId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log($e->getMessage());
        keepOldInput($oldInputToKeep);
        flash('error', 'Registration failed. Please try again.');
        redirect(url('auth/register.php'));
    }

    // Attempt to send the verification email BEFORE declaring success.
    // If delivery fails the account is useless — roll it back entirely so the
    // user can correct their address and try again with a clean slate.
    $verifyUrl = absoluteUrl('auth/verify-email.php') . '?token=' . urlencode($token);
    $bodyHtml  = '<p>Hello <strong>' . htmlspecialchars($firstname) . '</strong>,</p>'
               . '<p>Thank you for registering with the Parish Service Portal. '
               . 'Please verify your email address by clicking the button below. '
               . 'This link expires in <strong>24 hours</strong>.</p>';
    $html   = emailTemplate('Verify Your Email Address', $bodyHtml, $verifyUrl, 'Verify My Email');
    $result = sendEmail($email, "$firstname $lastname", 'Verify your ParishHub email address', $html);

    if (!$result['ok']) {
        // Roll back: delete the user row — ON DELETE CASCADE removes the parishioner row too.
        try {
            db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
        } catch (Throwable $ex) {
            error_log('Register rollback failed: ' . $ex->getMessage());
        }
        keepOldInput($oldInputToKeep);
        flash('error', 'Failed to send verification email. Please make sure your email address is active and valid, then try again.');
        redirect(url('auth/register.php'));
    }

    flash('success', 'Account created! A verification link has been sent to <strong>' . htmlspecialchars($email) . '</strong>. Please check your inbox (and spam folder) to activate your account.');
    redirect(url('auth/login.php'));
}

$pageTitle = 'Create an Account';
include __DIR__ . '/../includes/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel panel--register" style="background-image: url('<?= url('public/img/register_bg.jpg') ?>');">
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
        <div class="form-group">
          <label>First Name</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="user"></i></span>
            <input type="text" name="firstname" value="<?= oldInput('firstname') ?>" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label>Middle Name <span class="text-muted" style="font-weight:400;">(optional)</span></label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="user"></i></span>
            <input type="text" name="middlename" value="<?= oldInput('middlename') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Last Name</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="user"></i></span>
            <input type="text" name="lastname" value="<?= oldInput('lastname') ?>" required>
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
            <div class="pw-rules" id="pwRules" aria-live="polite">
              <span class="pw-rule" id="rule-len">At least 8 characters</span>
              <span class="pw-rule" id="rule-upper">One uppercase letter (A–Z)</span>
              <span class="pw-rule" id="rule-lower">One lowercase letter (a–z)</span>
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
              <input type="tel" name="phone" value="<?= oldInput('phone') ?>" pattern="09[0-9]{9}" maxlength="11" minlength="11" placeholder="09XXXXXXXXX" title="Must be exactly 11 digits starting with 09" required>
            </div>
          </div>
          <div class="form-group">
            <label>Birthdate</label>
            <div class="input-wrap">
              <span class="input-icon"><i data-lucide="calendar-heart"></i></span>
              <input type="date" name="birthdate" value="<?= oldInput('birthdate') ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Gender</label>
          <?php $__oldGender = oldInput('gender'); ?>
          <select name="gender" required>
            <option value="">Select</option>
            <option <?= $__oldGender === 'Male' ? 'selected' : '' ?>>Male</option>
            <option <?= $__oldGender === 'Female' ? 'selected' : '' ?>>Female</option>
          </select>
        </div>

        <div class="form-group">
          <label>Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="map-pin"></i></span>
            <input type="text" name="address" value="<?= oldInput('address') ?>" required>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block"><i data-lucide="user-plus" style="width:18px;height:18px;vertical-align:text-bottom;margin-right:6px;"></i> Create My Account</button>
      </form>

      <dialog id="confirmEmailModal" style="max-width:420px;width:90%;padding:0;border:none;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,0.18);">
        <div style="padding:36px 28px 28px;text-align:center;">
          <div style="font-size:42px;line-height:1;margin-bottom:16px;">✉️</div>
          <h3 style="margin:0 0 10px;font-size:20px;color:var(--brown-dark,#3b2f1e);">Confirm Your Email Address</h3>
          <p style="color:var(--text-muted,#6b5e4c);margin:0 0 14px;font-size:14px;">Are you sure this email address is correct?</p>
          <p id="confirmEmailDisplay" style="font-size:16px;font-weight:700;color:var(--brown-dark,#3b2f1e);word-break:break-all;background:var(--bg-secondary,#f5f0e8);padding:12px 16px;border-radius:8px;margin:0 0 10px;"></p>
          <p style="color:var(--text-muted,#6b5e4c);font-size:13px;margin:0 0 26px;line-height:1.5;">We will send a verification link to this address.<br>Make sure it's active and accessible.</p>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <button type="button" id="confirmEmailProceed" class="btn btn-primary btn-block">Yes, Proceed</button>
            <button type="button" id="confirmEmailEdit" class="btn btn-outline btn-block">Edit Email Address</button>
          </div>
        </div>
      </dialog>

      <div class="auth-footer">
        Already have an account? <a href="<?= url('auth/login.php') ?>">Log in here</a>
      </div>
    </div>
  </div>
</div>
<style>
.pw-rules { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
.pw-rule  { font-size: 12px; color: #9a8b6f; display: flex; align-items: center; gap: 6px; transition: color .15s; }
.pw-rule::before { content: '○'; font-size: 10px; }
.pw-rule.met { color: #2d8a4e; }
.pw-rule.met::before { content: '✓'; }
</style>
<script src="<?= url('public/js/validation.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/validation.js') ?>"></script>
<script>
function parishToggle(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  if(window.lucide) { lucide.createIcons(); }
}

// ── Real-time password strength indicator ──────────────────
(function () {
  var pwField = document.getElementById('pwField');
  if (!pwField) return;

  function setRule(id, met) {
    var el = document.getElementById(id);
    if (el) el.className = 'pw-rule' + (met ? ' met' : '');
  }

  pwField.addEventListener('input', function () {
    var v = this.value;
    setRule('rule-len',   v.length >= 8);
    setRule('rule-upper', /[A-Z]/.test(v));
    setRule('rule-lower', /[a-z]/.test(v));
  });
})();

// ── Form submit validation + email confirmation modal ──────
(function () {
  var form      = document.getElementById('registerForm');
  var modal     = document.getElementById('confirmEmailModal');
  var display   = document.getElementById('confirmEmailDisplay');
  var btnProceed = document.getElementById('confirmEmailProceed');
  var btnEdit    = document.getElementById('confirmEmailEdit');
  var confirmed  = false;

  var required = [
    ['[name="lastname"]',        'your last name'],
    ['[name="firstname"]',       'your first name'],
    ['[name="email"]',           'your email address'],
    ['[name="password"]',        'a password'],
    ['[name="confirm_password"]','your password again'],
    ['[name="phone"]',           'your phone number'],
    ['[name="birthdate"]',       'your birthdate'],
    ['[name="gender"]',          'your gender'],
    ['[name="address"]',         'your address'],
  ];
  clearFieldErrorOnInput(form, required.map(function (f) { return f[0]; }));

  form.addEventListener('submit', function (e) {
    // If already confirmed by the modal, let the form submit naturally.
    if (confirmed) return;

    e.preventDefault();

    var firstInvalid = validateRequiredFields(form, required);

    var email = form.querySelector('[name="email"]');
    if (email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      showFieldError(email, 'Please enter a valid email address.');
      firstInvalid = firstInvalid || email;
    }

    var pw  = form.querySelector('[name="password"]');
    var cpw = form.querySelector('[name="confirm_password"]');
    if (pw.value && pw.value.length < 8) {
      showFieldError(pw, 'Password must be at least 8 characters.');
      firstInvalid = firstInvalid || pw;
    }
    if (pw.value && (!/[A-Z]/.test(pw.value) || !/[a-z]/.test(pw.value))) {
      showFieldError(pw, 'Password must contain at least one uppercase and one lowercase letter.');
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
      firstInvalid.focus();
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    // All valid — show confirmation modal.
    display.textContent = email.value.trim();
    modal.showModal();
  });

  btnProceed.addEventListener('click', function () {
    modal.close();
    confirmed = true;
    form.submit();
  });

  btnEdit.addEventListener('click', function () {
    modal.close();
    var emailField = form.querySelector('[name="email"]');
    emailField.focus();
    emailField.select();
  });

  // Close on backdrop click.
  modal.addEventListener('click', function (e) {
    if (e.target === modal) { modal.close(); }
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
