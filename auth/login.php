<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
guestOnly();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($isAjax) {
        header('Content-Type: application/json');
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Helper to send a response — JSON for AJAX, flash+redirect otherwise.
    $fail = function (string $field, string $message) use ($isAjax, $email) {
        if ($isAjax) {
            echo json_encode(['ok' => false, 'field' => $field, 'message' => $message]);
            exit;
        }
        keepOldInput(['email' => $email]);
        flash('error', $message);
        redirect(url('auth/login.php'));
    };

    // Brute-force Protection
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockoutKey = "lockout_{$ip}";
    $attemptsKey = "attempts_{$ip}";

    if (isset($_SESSION[$lockoutKey]) && time() < $_SESSION[$lockoutKey]) {
        $timeLeft = ceil(($_SESSION[$lockoutKey] - time()) / 60);
        $fail('email', "Too many failed attempts. Please try again in {$timeLeft} minute(s).");
    } elseif (isset($_SESSION[$lockoutKey]) && time() >= $_SESSION[$lockoutKey]) {
        unset($_SESSION[$lockoutKey]);
        unset($_SESSION[$attemptsKey]);
    }

    $stmt = db()->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $fail('email', 'Email is invalid or not found.');
    }

    if ($user['email_verified_at'] === null) {
        $resendUrl = url('auth/resend-verification.php?email=' . urlencode($email));
        $fail('email', 'Please verify your email before logging in. <a href="'.$resendUrl.'" style="text-decoration:underline;font-weight:bold;color:inherit;">Click here to resend the verification link</a>.');
    }

    if ($user['status'] !== 'active') {
        $fail('email', 'Your account is not active. Please contact the parish office.');
    }

    if (!password_verify($password, $user['password'])) {
        $_SESSION[$attemptsKey] = ($_SESSION[$attemptsKey] ?? 0) + 1;
        $attemptsLeft = 5 - $_SESSION[$attemptsKey];
        if ($_SESSION[$attemptsKey] >= 5) {
            $_SESSION[$lockoutKey] = time() + (5 * 60); // 5 minutes lockout
            $fail('password', 'Too many failed attempts. You are locked out for 5 minutes.');
        }
        $fail('password', "Incorrect password. Please try again. ({$attemptsLeft} attempts left)");
    }

    // Login success - reset attempts
    unset($_SESSION[$attemptsKey]);
    unset($_SESSION[$lockoutKey]);

    if (!empty($user['must_change_password'])) {
        $_SESSION['force_change_password_user'] = [
            'user_id' => $user['user_id'],
            'email'   => $user['email'],
        ];
        if ($isAjax) {
            echo json_encode(['ok' => true, 'redirect' => url('auth/force_change_password.php')]);
            exit;
        }
        redirect(url('auth/force_change_password.php'));
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id'   => (int) $user['user_id'],
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
        'email'     => $user['email'],
        'role_id'   => (int) $user['role_id'],
        'role_name' => $user['role_name'],
    ];

    logActivity((int) $user['user_id'], "{$user['firstname']} {$user['lastname']} logged in", 'Auth');

    $destination = redirectForRole($user['role_name']);
    if ($isAjax) {
        echo json_encode(['ok' => true, 'redirect' => $destination]);
        exit;
    }

    flash('success', 'Welcome back, ' . $user['firstname'] . '!');
    redirect($destination);
}

// Intercept the post-registration success flash so it shows as a modal
// instead of the standard auto-hiding alert that users miss.
$__regModal = null;
if (!empty($_SESSION['flash']['success'])) {
    foreach ($_SESSION['flash']['success'] as $i => $msg) {
        if (str_contains($msg, 'verification link')) {
            $__regModal = strip_tags($msg, '<strong><b>');
            array_splice($_SESSION['flash']['success'], $i, 1);
            if (empty($_SESSION['flash']['success'])) {
                unset($_SESSION['flash']['success']);
            }
            break;
        }
    }
}

$pageTitle = 'Log In';
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
      <blockquote class="panel-quote">
        "Ask, and it will be given to you; seek, and you will find; knock, and it will be opened to you."
        <cite>Matthew 7:7</cite>
      </blockquote>
      <p class="panel-foot">Log in to submit requests, track appointments, and manage your parish services.</p>
    </div>
  </div>

  <div class="auth-split-form">
    <a href="<?= url('index.php') ?>" class="auth-back-link">← Back to Homepage</a>

    <div class="auth-card">
      <div class="form-heading">
        <h1>Welcome back</h1>
        <p class="subtitle">Log in to manage your parish requests</p>
      </div>

      <?php // $__flash was already populated once by header.php's own getFlash() call —
            // calling getFlash() again here would find it already drained and empty. ?>
      <?php include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/login.php') ?>" id="loginForm" novalidate>
        <?= csrfField() ?>
        <div class="form-group">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" name="email" value="<?= oldInput('email') ?>" required placeholder="you@example.com" autocomplete="email" autofocus>
          </div>
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:baseline;">
            <label>Password</label>
            <button type="button" id="forgotPwLink" style="background:none;border:none;padding:0;font-size:13px;color:var(--accent,#7a5c1e);cursor:pointer;text-decoration:underline;font-family:inherit;">Forgot Password?</button>
          </div>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="lock"></i></span>
            <input type="password" name="password" id="pwInput" required placeholder="••••••••" autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="parishToggle('pwInput', this)"><i data-lucide="eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Login →</button>
      </form>

      <div class="auth-footer">
        Don't have an account? <a href="<?= url('auth/register.php') ?>">Register here</a>
      </div>
    </div>
  </div>
</div>

<!-- ── FORGOT PASSWORD MODAL ─────────────────────────────────────────────── -->
<dialog id="forgotPwModal" style="max-width:440px;width:92%;padding:0;border:none;border-radius:14px;box-shadow:0 12px 52px rgba(0,0,0,0.22);overflow:visible;">
  <div style="padding:32px 30px 26px;position:relative;">
    <button type="button" id="forgotPwClose" aria-label="Close" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;color:#7a6a54;cursor:pointer;line-height:1;">✕</button>

    <!-- Step indicator dots -->
    <div id="fpStepDots" style="display:flex;justify-content:center;gap:8px;margin-bottom:20px;">
      <span class="fp-dot fp-dot-active" data-step="1"></span>
      <span class="fp-dot" data-step="2"></span>
      <span class="fp-dot" data-step="3"></span>
    </div>

    <!-- ── STEP 1: Email ── -->
    <div id="fpStep1">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Forgot your password?</h2>
      <p style="margin:0 0 22px;font-size:14px;color:#7a6a54;line-height:1.5;">Enter your registered email address and we'll send you a 6-digit verification code.</p>
      <div class="form-group" id="fpEmailGroup">
        <label for="fpEmailInput">Email Address</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="mail"></i></span>
          <input type="email" id="fpEmailInput" name="fp_email" placeholder="you@example.com" autocomplete="email">
        </div>
        <p class="field-error" id="fpEmailError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <button type="button" id="fpSendBtn" class="btn btn-primary btn-block" style="margin-top:8px;">Send Verification Code</button>
    </div>

    <!-- ── STEP 2: OTP ── -->
    <div id="fpStep2" style="display:none;">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Enter verification code</h2>
      <p style="margin:0 0 6px;font-size:14px;color:#7a6a54;line-height:1.5;">A 6-digit code was sent to <strong id="fpEmailDisplay"></strong>.</p>
      <p style="margin:0 0 22px;font-size:13px;color:#9a8b72;">Check your spam folder if you don't see it within a minute.</p>
      <div class="form-group" id="fpOtpGroup">
        <label for="fpOtpInput">6-Digit Code</label>
        <input type="text" id="fpOtpInput" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
          autocomplete="one-time-code"
          style="text-align:center;font-size:28px;letter-spacing:10px;font-family:monospace;padding:12px 16px;border:1px solid var(--border,#ddd);border-radius:6px;width:100%;box-sizing:border-box;">
        <p class="field-error" id="fpOtpError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <button type="button" id="fpVerifyBtn" class="btn btn-primary btn-block" style="margin-top:8px;">Verify Code</button>
      <div style="text-align:center;margin-top:14px;">
        <button type="button" id="fpResendBtn" class="btn btn-outline btn-sm" disabled>
          Resend Code (<span id="fpResendTimer">60</span>s)
        </button>
      </div>
      <button type="button" id="fpBackToEmail" style="background:none;border:none;color:#7a5c1e;font-size:13px;cursor:pointer;text-decoration:underline;display:block;margin:10px auto 0;">← Change email address</button>
    </div>

    <!-- ── STEP 3: New Password ── -->
    <div id="fpStep3" style="display:none;">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Set new password</h2>
      <p style="margin:0 0 22px;font-size:14px;color:#7a6a54;line-height:1.5;">Choose a strong password for your account.</p>
      <div class="form-group" id="fpPwGroup">
        <label for="fpPwInput">New Password</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="lock"></i></span>
          <input type="password" id="fpPwInput" placeholder="At least 8 characters" autocomplete="new-password">
          <button type="button" class="toggle-pw" onclick="parishToggle('fpPwInput', this)"><i data-lucide="eye"></i></button>
        </div>
        <p class="field-error" id="fpPwError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <div class="form-group" id="fpConfirmGroup">
        <label for="fpConfirmInput">Confirm Password</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="lock"></i></span>
          <input type="password" id="fpConfirmInput" placeholder="Repeat your new password" autocomplete="new-password">
          <button type="button" class="toggle-pw" onclick="parishToggle('fpConfirmInput', this)"><i data-lucide="eye"></i></button>
        </div>
        <p class="field-error" id="fpConfirmError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <p style="font-size:12px;color:#9a8b72;margin:4px 0 16px;">Must be at least 8 characters and include a letter and a number.</p>
      <button type="button" id="fpResetBtn" class="btn btn-primary btn-block">Update Password</button>
    </div>

    <!-- ── STEP 4: Success ── -->
    <div id="fpStep4" style="display:none;text-align:center;padding:12px 0;">
      <div style="width:60px;height:60px;background:#e8f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:28px;line-height:1;">✅</div>
      <h2 style="margin:0 0 10px;font-size:20px;color:#1d4429;">Password Updated!</h2>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;margin:0 0 24px;">Your password has been changed successfully. You can now log in with your new password.</p>
      <button type="button" id="fpDoneBtn" class="btn btn-primary" style="min-width:140px;">Log In Now</button>
    </div>
  </div>
</dialog>

<style>
.fp-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: #ddd; display: inline-block; transition: background .2s;
}
.fp-dot.fp-dot-active { background: var(--accent, #7a5c1e); }
</style>

<?php if ($__regModal): ?>
<dialog id="regSuccessModal" style="max-width:460px;width:90%;padding:0;border:none;border-radius:14px;box-shadow:0 10px 48px rgba(0,0,0,0.20);">
  <div style="padding:36px 32px 28px;text-align:center;">
    <div style="width:64px;height:64px;background:#e8f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:30px;line-height:1;">✅</div>
    <h2 style="margin:0 0 10px;font-size:20px;color:#1d4429;">Registration Successful!</h2>
    <p style="color:#4a5568;font-size:14px;line-height:1.7;margin:0 0 20px;">
      <?= $__regModal ?>
    </p>
    <div style="background:#f0faf5;border:1px solid #b2dfcc;border-radius:8px;padding:14px 16px;margin:0 0 24px;text-align:left;">
      <p style="margin:0;font-size:13px;color:#276245;line-height:1.6;">
        <strong>Next steps:</strong><br>
        1. Open your email inbox (check your <strong>Spam / Junk</strong> folder too).<br>
        2. Click the <em>"Verify My Email"</em> button in the email.<br>
        3. Return here and log in.
      </p>
    </div>
    <button type="button" id="regSuccessOk" class="btn btn-primary" style="min-width:120px;">Got it, thanks!</button>
  </div>
</dialog>
<?php endif; ?>
<script src="<?= url('public/js/validation.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/validation.js') ?>"></script>
<script>
function parishToggle(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  if(window.lucide) { lucide.createIcons(); }
}

(function () {
  var form    = document.getElementById('loginForm');
  var emailEl = form.querySelector('[name="email"]');
  var passEl  = form.querySelector('[name="password"]');
  var btn     = form.querySelector('button[type="submit"]');

  clearFieldErrorOnInput(form, ['[name="email"]', '[name="password"]']);

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    // Client-side empty checks first.
    var firstInvalid = validateRequiredFields(form, [
      ['[name="email"]',    'your email address'],
      ['[name="password"]', 'your password'],
    ]);
    if (firstInvalid) { firstInvalid.focus(); return; }

    btn.disabled = true;
    btn.textContent = 'Logging in…';

    var data = new URLSearchParams(new FormData(form));

    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: data,
    })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json.ok) {
          window.location.href = json.redirect;
          return;
        }
        btn.disabled = false;
        btn.textContent = 'Login →';
        var target = json.field === 'password' ? passEl : emailEl;
        showFieldError(target, json.message || 'Login failed. Please try again.');
        target.focus();
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Login →';
        showFieldError(emailEl, 'Could not connect. Please check your internet and try again.');
      });
  });
})();

// ── FORGOT PASSWORD MODAL ────────────────────────────────────────────────────
(function () {
  var modal      = document.getElementById('forgotPwModal');
  var openBtn    = document.getElementById('forgotPwLink');
  var closeBtn   = document.getElementById('forgotPwClose');
  var csrfToken  = document.querySelector('#loginForm [name="csrf_token"]').value;
  var apiUrl     = <?= json_encode(url('auth/forgot-password.php')) ?>;

  // State
  var currentStep = 1;
  var userEmail   = '';
  var resendTimer = null;

  // DOM refs
  var steps    = [null,
    document.getElementById('fpStep1'),
    document.getElementById('fpStep2'),
    document.getElementById('fpStep3'),
    document.getElementById('fpStep4'),
  ];
  var dots = document.querySelectorAll('.fp-dot');

  function goStep(n) {
    currentStep = n;
    steps.forEach(function (el, i) { if (el) el.style.display = i === n ? '' : 'none'; });
    dots.forEach(function (dot) {
      var s = parseInt(dot.dataset.step, 10);
      dot.classList.toggle('fp-dot-active', s === n || s < n);
    });
    // Hide step 4 dot when on success
    if (n === 4) { document.getElementById('fpStepDots').style.display = 'none'; }
    else { document.getElementById('fpStepDots').style.display = ''; }
  }

  function fieldError(errEl, msg) {
    errEl.textContent = msg;
    errEl.style.display = msg ? '' : 'none';
  }

  function clearErrors() {
    ['fpEmailError','fpOtpError','fpPwError','fpConfirmError'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.textContent = ''; el.style.display = 'none'; }
    });
  }

  function post(data, onDone) {
    data.csrf_token = csrfToken;
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
    fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(onDone)
      .catch(function () {
        onDone({ ok: false, message: 'Connection error. Please check your internet and try again.' });
      });
  }

  // Open modal
  openBtn.addEventListener('click', function () {
    clearErrors();
    goStep(1);
    document.getElementById('fpEmailInput').value = '';
    document.getElementById('fpOtpInput').value = '';
    document.getElementById('fpPwInput').value = '';
    document.getElementById('fpConfirmInput').value = '';
    stopResendTimer();
    modal.showModal();
    setTimeout(function () { document.getElementById('fpEmailInput').focus(); }, 50);
  });

  function closeModal() {
    modal.close();
    stopResendTimer();
  }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  // ── Step 1: Send OTP ────────────────────────────────────────────────────
  document.getElementById('fpSendBtn').addEventListener('click', function () {
    clearErrors();
    var email = document.getElementById('fpEmailInput').value.trim();
    if (!email) {
      fieldError(document.getElementById('fpEmailError'), 'Please enter your email address.');
      document.getElementById('fpEmailInput').focus();
      return;
    }
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    post({ action: 'send_otp', email: email }, function (json) {
      btn.disabled = false;
      btn.textContent = 'Send Verification Code';
      if (!json.ok) {
        fieldError(document.getElementById('fpEmailError'), json.message || 'Failed to send code. Please try again.');
        return;
      }
      userEmail = email;
      document.getElementById('fpEmailDisplay').textContent = email;
      goStep(2);
      document.getElementById('fpOtpInput').value = '';
      startResendTimer(60);
      setTimeout(function () { document.getElementById('fpOtpInput').focus(); }, 50);
    });
  });

  // Allow pressing Enter on email input
  document.getElementById('fpEmailInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('fpSendBtn').click(); }
  });

  // ── Step 2: Verify OTP ──────────────────────────────────────────────────
  document.getElementById('fpVerifyBtn').addEventListener('click', function () {
    clearErrors();
    var otp = document.getElementById('fpOtpInput').value.replace(/\D/g, '');
    if (otp.length !== 6) {
      fieldError(document.getElementById('fpOtpError'), 'Please enter the full 6-digit code.');
      document.getElementById('fpOtpInput').focus();
      return;
    }
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Verifying…';
    post({ action: 'verify_otp', email: userEmail, otp: otp }, function (json) {
      btn.disabled = false;
      btn.textContent = 'Verify Code';
      if (!json.ok) {
        if (json.expired) {
          stopResendTimer();
          document.getElementById('fpResendBtn').disabled = false;
          document.getElementById('fpResendBtn').textContent = 'Resend Code';
        }
        fieldError(document.getElementById('fpOtpError'), json.message || 'Verification failed. Please try again.');
        return;
      }
      stopResendTimer();
      goStep(3);
      setTimeout(function () { document.getElementById('fpPwInput').focus(); }, 50);
    });
  });

  // OTP input: only allow digits
  document.getElementById('fpOtpInput').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
  });
  document.getElementById('fpOtpInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('fpVerifyBtn').click(); }
  });

  // Resend OTP
  document.getElementById('fpResendBtn').addEventListener('click', function () {
    clearErrors();
    var btn = this;
    btn.disabled = true;
    post({ action: 'send_otp', email: userEmail }, function (json) {
      if (!json.ok) {
        fieldError(document.getElementById('fpOtpError'), json.message || 'Could not resend. Please try again.');
        btn.disabled = false;
        return;
      }
      document.getElementById('fpOtpInput').value = '';
      startResendTimer(60);
    });
  });

  // Back to email
  document.getElementById('fpBackToEmail').addEventListener('click', function () {
    stopResendTimer();
    goStep(1);
    setTimeout(function () { document.getElementById('fpEmailInput').focus(); }, 50);
  });

  // ── Step 3: Reset Password ───────────────────────────────────────────────
  document.getElementById('fpResetBtn').addEventListener('click', function () {
    clearErrors();
    var pw  = document.getElementById('fpPwInput').value;
    var cpw = document.getElementById('fpConfirmInput').value;
    if (pw.length < 8) {
      fieldError(document.getElementById('fpPwError'), 'Password must be at least 8 characters.');
      document.getElementById('fpPwInput').focus();
      return;
    }
    if (!/[A-Za-z]/.test(pw) || !/[0-9]/.test(pw)) {
      fieldError(document.getElementById('fpPwError'), 'Password must include at least one letter and one number.');
      document.getElementById('fpPwInput').focus();
      return;
    }
    if (pw !== cpw) {
      fieldError(document.getElementById('fpConfirmError'), 'Passwords do not match.');
      document.getElementById('fpConfirmInput').focus();
      return;
    }
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Updating…';
    post({ action: 'reset_password', password: pw, confirm_password: cpw }, function (json) {
      btn.disabled = false;
      btn.textContent = 'Update Password';
      if (!json.ok) {
        if (json.expired) { goStep(1); return; }
        var errEl = json.field === 'confirm_password'
          ? document.getElementById('fpConfirmError')
          : document.getElementById('fpPwError');
        fieldError(errEl, json.message || 'Failed to update password. Please try again.');
        return;
      }
      goStep(4);
    });
  });

  // Success: close and focus login form
  document.getElementById('fpDoneBtn').addEventListener('click', function () {
    closeModal();
    setTimeout(function () {
      var emailInput = document.querySelector('#loginForm [name="email"]');
      if (emailInput) { emailInput.value = userEmail; emailInput.focus(); }
    }, 100);
  });

  // ── Resend timer ─────────────────────────────────────────────────────────
  function startResendTimer(seconds) {
    var btn     = document.getElementById('fpResendBtn');
    var timerEl = document.getElementById('fpResendTimer');
    var left    = seconds;
    btn.disabled = true;
    timerEl.textContent = left;
    btn.innerHTML = 'Resend Code (<span id="fpResendTimer">' + left + '</span>s)';
    resendTimer = setInterval(function () {
      left -= 1;
      var t = document.getElementById('fpResendTimer');
      if (t) t.textContent = left;
      if (left <= 0) {
        stopResendTimer();
        btn.disabled = false;
        btn.textContent = 'Resend Code';
      }
    }, 1000);
  }

  function stopResendTimer() {
    if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
  }
})();

<?php if ($__regModal): ?>
(function () {
  var modal = document.getElementById('regSuccessModal');
  var okBtn = document.getElementById('regSuccessOk');
  if (modal) {
    modal.showModal();
    okBtn.addEventListener('click', function () { modal.close(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });
  }
})();
<?php endif; ?>
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
