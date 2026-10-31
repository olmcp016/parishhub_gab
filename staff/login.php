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

    $fail = function (string $field, string $message) use ($isAjax, $email) {
        if ($isAjax) {
            echo json_encode(['ok' => false, 'field' => $field, 'message' => $message]);
            exit;
        }
        keepOldInput(['email' => $email]);
        flash('error', $message);
        redirect(url('staff/login.php'));
    };

    // Brute-force protection — same logic and same counter as parishioner login.
    $emailKey = loginThrottleKey($email);
    $attempt  = throttleReserve($emailKey, 5, 15 * 60);
    if (!$attempt['allowed']) {
        $fail('email', 'Too many failed attempts. Please try again in ' . ceil($attempt['retry_after'] / 60) . ' minute(s).');
    }

    $stmt = db()->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $fail('email', 'Email is invalid or not found.');
    }

    // Block Parishioners from using the Staff Portal.
    if ($user['role_name'] === 'Parishioner') {
        $fail('email', 'This portal is for Staff and Priests only.');
    }

    if ($user['email_verified_at'] === null) {
        $fail('email', 'Please verify your email first before logging in.');
    }

    if ($user['status'] !== 'active') {
        $fail('email', 'Your account is not active. Please contact the parish administrator.');
    }

    if (!password_verify($password, $user['password'])) {
        if ($attempt['attempts'] >= 5) {
            $fail('password', 'Too many failed attempts. You are locked out for 15 minutes.');
        }
        $fail('password', 'Incorrect password. Please try again. (' . (5 - $attempt['attempts']) . ' attempts left)');
    }

    throttleClear($emailKey);

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

    signInUser($user);

    logActivity((int) $user['user_id'], "{$user['firstname']} {$user['lastname']} logged in via Staff Portal", 'Auth');

    $destination = redirectForRole($user['role_name']);
    if ($isAjax) {
        echo json_encode(['ok' => true, 'redirect' => $destination]);
        exit;
    }

    flash('success', 'Welcome, ' . $user['firstname'] . '. You are now signed in to the Staff Portal.');
    redirect($destination);
}

$pageTitle = 'Staff & Priest Portal';
include __DIR__ . '/../includes/header.php';
?>
<script>
  if (window.top !== window.self) {
    window.top.location.href = window.self.location.href;
  }
</script>

<style>
/* ── Staff portal accents ────────────────────────────────── */
.auth-split-panel.panel--staff::before {
  background: linear-gradient(160deg,
    rgba(15,20,30,0.85) 0%,
    rgba(30,24,12,0.80) 60%,
    rgba(50,35,10,0.72) 100%);
}
.staff-btn-submit {
  background: linear-gradient(135deg, #2c1e0f, #5c3d10) !important;
  border-color: #3b2a0e !important;
  color: #fff !important;
}
.staff-btn-submit:hover {
  background: linear-gradient(135deg, #3b2a0e, #7a5015) !important;
}
.staff-footer-link {
  margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border, #e8e0d5);
  text-align: center; font-size: 13px; color: var(--text-muted, #9a8b72);
}
.staff-footer-link a {
  color: var(--accent, #7a5c1e); font-weight: 600; text-decoration: none;
}
.staff-footer-link a:hover { text-decoration: underline; }
</style>

<div class="auth-split">

  <!-- ── Left panel ─────────────────────────────────── -->
  <div class="auth-split-panel panel--staff" style="background-image: url('<?= url('public/img/login_bg.jpg') ?>');">
    <a href="<?= url('index.php') ?>" style="display:contents; text-decoration:none; color:inherit;" title="Back to Home">
      <div class="panel-brand">
        <span class="crest"><?= crestMarkup() ?></span>
        <span class="panel-brand-titles">
          <span class="panel-brand-text">Our Lady of Mt. Carmel Parish</span>
          <span class="panel-brand-sub">Administration &amp; Priest Portal</span>
        </span>
      </div>
    </a>

    <div class="panel-bottom">
      <blockquote class="panel-quote">
        "Let all things be done decently and in order."
        <cite>1 Corinthians 14:40</cite>
      </blockquote>
      <p class="panel-foot">Authorized access only for Parish Staff, Administration, and Priests. All activity is logged and monitored.</p>
    </div>
  </div>

  <!-- ── Right: form ────────────────────────────────── -->
  <div class="auth-split-form">
    <a href="<?= url('index.php') ?>" class="auth-back-link">← Back to Homepage</a>

    <div class="auth-card">
      <div class="form-heading">
        <h1 style="font-size:26px; line-height:1.2; margin-bottom:6px;">Parish Staff<br>&amp; Priest Portal</h1>
        <p class="subtitle">Sign in with your parish-issued credentials to access the administration system.</p>
      </div>

      <?php include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('staff/login.php') ?>" id="staffLoginForm" novalidate>
        <?= csrfField() ?>
        <div class="form-group">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" name="email" value="<?= oldInput('email') ?>"
              required placeholder="your@parish.email"
              autocomplete="email" autofocus>
          </div>
        </div>
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:baseline;">
            <label>Password</label>
            <button type="button" id="staffForgotPwLink"
              style="background:none;border:none;padding:0;font-size:13px;color:var(--accent,#7a5c1e);cursor:pointer;text-decoration:underline;font-family:inherit;">
              Forgot Password?
            </button>
          </div>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="lock"></i></span>
            <input type="password" name="password" id="staffPwInput"
              required placeholder="••••••••" autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="parishToggle('staffPwInput', this)">
              <i data-lucide="eye"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block staff-btn-submit">
          Sign In to Staff Portal →
        </button>
      </form>

      <div class="staff-footer-link">
        Are you a parishioner?
        <a href="<?= url('auth/login.php') ?>">Log in here →</a>
      </div>
    </div>
  </div>
</div>

<!-- ── FORGOT PASSWORD MODAL (same flow, same API endpoint) ──────── -->
<dialog id="staffForgotPwModal" style="max-width:440px;width:92%;padding:0;border:none;border-radius:14px;box-shadow:0 12px 52px rgba(0,0,0,0.22);overflow:visible;">
  <div style="padding:32px 30px 26px;position:relative;">
    <button type="button" id="staffForgotPwClose" aria-label="Close"
      style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;color:#7a6a54;cursor:pointer;line-height:1;">✕</button>

    <div id="sfpStepDots" style="display:flex;justify-content:center;gap:8px;margin-bottom:20px;">
      <span class="fp-dot fp-dot-active" data-step="1"></span>
      <span class="fp-dot" data-step="2"></span>
      <span class="fp-dot" data-step="3"></span>
    </div>

    <!-- Step 1: Email -->
    <div id="sfpStep1">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Forgot your password?</h2>
      <p style="margin:0 0 22px;font-size:14px;color:#7a6a54;line-height:1.5;">Enter your registered email and we'll send a 6-digit verification code.</p>
      <div class="form-group">
        <label for="sfpEmailInput">Email Address</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="mail"></i></span>
          <input type="email" id="sfpEmailInput" placeholder="your@parish.email" autocomplete="email">
        </div>
        <p class="field-error" id="sfpEmailError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <button type="button" id="sfpSendBtn" class="btn btn-primary btn-block" style="margin-top:8px;">Send Verification Code</button>
    </div>

    <!-- Step 2: OTP -->
    <div id="sfpStep2" style="display:none;">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Enter verification code</h2>
      <p style="margin:0 0 6px;font-size:14px;color:#7a6a54;line-height:1.5;">A 6-digit code was sent to <strong id="sfpEmailDisplay"></strong>.</p>
      <p style="margin:0 0 22px;font-size:13px;color:#9a8b72;">Check your spam folder if it doesn't arrive within a minute.</p>
      <div class="form-group">
        <label for="sfpOtpInput">6-Digit Code</label>
        <input type="text" id="sfpOtpInput" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
          autocomplete="one-time-code"
          style="text-align:center;font-size:28px;letter-spacing:10px;font-family:monospace;padding:12px 16px;border:1px solid var(--border,#ddd);border-radius:6px;width:100%;box-sizing:border-box;">
        <p class="field-error" id="sfpOtpError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <button type="button" id="sfpVerifyBtn" class="btn btn-primary btn-block" style="margin-top:8px;">Verify Code</button>
      <div style="text-align:center;margin-top:14px;">
        <button type="button" id="sfpResendBtn" class="btn btn-outline btn-sm" disabled>
          Resend Code (<span id="sfpResendTimer">60</span>s)
        </button>
      </div>
      <button type="button" id="sfpBackToEmail"
        style="background:none;border:none;color:#7a5c1e;font-size:13px;cursor:pointer;text-decoration:underline;display:block;margin:10px auto 0;">
        ← Change email address
      </button>
    </div>

    <!-- Step 3: New Password -->
    <div id="sfpStep3" style="display:none;">
      <h2 style="margin:0 0 6px;font-size:20px;color:#3b2f1e;">Set new password</h2>
      <p style="margin:0 0 22px;font-size:14px;color:#7a6a54;line-height:1.5;">Choose a strong password for your account.</p>
      <div class="form-group">
        <label for="sfpPwInput">New Password</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="lock"></i></span>
          <input type="password" id="sfpPwInput" placeholder="At least 8 characters" autocomplete="new-password">
          <button type="button" class="toggle-pw" onclick="parishToggle('sfpPwInput', this)"><i data-lucide="eye"></i></button>
        </div>
        <p class="field-error" id="sfpPwError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <div class="form-group">
        <label for="sfpConfirmInput">Confirm Password</label>
        <div class="input-wrap">
          <span class="input-icon"><i data-lucide="lock"></i></span>
          <input type="password" id="sfpConfirmInput" placeholder="Repeat your new password" autocomplete="new-password">
          <button type="button" class="toggle-pw" onclick="parishToggle('sfpConfirmInput', this)"><i data-lucide="eye"></i></button>
        </div>
        <p class="field-error" id="sfpConfirmError" style="display:none;color:#c62828;font-size:13px;margin:6px 0 0;"></p>
      </div>
      <p style="font-size:12px;color:#9a8b72;margin:4px 0 16px;">At least 8 characters with a letter and a number.</p>
      <button type="button" id="sfpResetBtn" class="btn btn-primary btn-block">Update Password</button>
    </div>

    <!-- Step 4: Success -->
    <div id="sfpStep4" style="display:none;text-align:center;padding:12px 0;">
      <div style="width:60px;height:60px;background:#e8f5ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:28px;line-height:1;">✅</div>
      <h2 style="margin:0 0 10px;font-size:20px;color:#1d4429;">Password Updated!</h2>
      <p style="color:#4a5568;font-size:14px;line-height:1.7;margin:0 0 24px;">Your password has been changed successfully. You may now sign in.</p>
      <button type="button" id="sfpDoneBtn" class="btn btn-primary" style="min-width:140px;">Sign In Now</button>
    </div>
  </div>
</dialog>

<style>
.fp-dot { width:9px;height:9px;border-radius:50%;background:#ddd;display:inline-block;transition:background .2s; }
.fp-dot.fp-dot-active { background:var(--accent,#7a5c1e); }
</style>

<script>
function parishToggle(id, btn) {
  var input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
  btn.innerHTML = input.type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
  if (window.lucide) lucide.createIcons();
}

// ── AJAX login form ────────────────────────────────────────────────────
(function () {
  var form    = document.getElementById('staffLoginForm');
  var emailEl = form.querySelector('[name="email"]');
  var passEl  = form.querySelector('[name="password"]');
  var btn     = form.querySelector('button[type="submit"]');

  clearFieldErrorOnInput(form, ['[name="email"]', '[name="password"]']);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var firstInvalid = validateRequiredFields(form, [
      ['[name="email"]',    'your email address'],
      ['[name="password"]', 'your password'],
    ]);
    if (firstInvalid) { firstInvalid.focus(); return; }

    btn.disabled = true;
    btn.textContent = 'Signing in…';

    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(new FormData(form)),
    })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json.ok) { window.location.href = json.redirect; return; }
        btn.disabled = false;
        btn.textContent = 'Sign In to Staff Portal →';
        var target = json.field === 'password' ? passEl : emailEl;
        showFieldError(target, json.message || 'Login failed. Please try again.');
        target.focus();
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Sign In to Staff Portal →';
        showFieldError(emailEl, 'Could not connect. Please check your internet and try again.');
      });
  });
})();

// ── FORGOT PASSWORD MODAL ────────────────────────────────────────────────
(function () {
  var modal     = document.getElementById('staffForgotPwModal');
  var openBtn   = document.getElementById('staffForgotPwLink');
  var closeBtn  = document.getElementById('staffForgotPwClose');
  var csrfToken = document.querySelector('#staffLoginForm [name="csrf_token"]').value;
  var apiUrl    = <?= json_encode(url('auth/forgot-password.php')) ?>;

  var currentStep = 1;
  var userEmail   = '';
  var resendTimer = null;

  var steps = [null,
    document.getElementById('sfpStep1'),
    document.getElementById('sfpStep2'),
    document.getElementById('sfpStep3'),
    document.getElementById('sfpStep4'),
  ];
  var dots = document.querySelectorAll('#staffForgotPwModal .fp-dot');

  function goStep(n) {
    currentStep = n;
    steps.forEach(function (el, i) { if (el) el.style.display = (i === n) ? '' : 'none'; });
    dots.forEach(function (d) {
      var s = parseInt(d.dataset.step, 10);
      d.classList.toggle('fp-dot-active', s === n || s < n);
    });
    var dotRow = document.getElementById('sfpStepDots');
    if (dotRow) dotRow.style.display = (n === 4) ? 'none' : '';
  }

  function fieldError(el, msg) { el.textContent = msg; el.style.display = msg ? '' : 'none'; }
  function clearErrors() {
    ['sfpEmailError','sfpOtpError','sfpPwError','sfpConfirmError'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.textContent = ''; el.style.display = 'none'; }
    });
  }

  function post(data, onDone) {
    data.csrf_token = csrfToken;
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
    fetch(apiUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString() })
      .then(function (r) { return r.json(); })
      .then(onDone)
      .catch(function () { onDone({ ok:false, message:'Connection error. Please try again.' }); });
  }

  openBtn.addEventListener('click', function () {
    clearErrors(); goStep(1);
    ['sfpEmailInput','sfpOtpInput','sfpPwInput','sfpConfirmInput'].forEach(function (id) {
      var el = document.getElementById(id); if (el) el.value = '';
    });
    stopResendTimer();
    modal.showModal();
    setTimeout(function () { document.getElementById('sfpEmailInput').focus(); }, 50);
  });

  function closeModal() { modal.close(); stopResendTimer(); }
  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  // Step 1
  document.getElementById('sfpSendBtn').addEventListener('click', function () {
    clearErrors();
    var email = document.getElementById('sfpEmailInput').value.trim();
    if (!email) { fieldError(document.getElementById('sfpEmailError'), 'Please enter your email address.'); return; }
    var btn = this; btn.disabled = true; btn.textContent = 'Sending…';
    post({ action:'send_otp', email:email }, function (json) {
      btn.disabled = false; btn.textContent = 'Send Verification Code';
      if (!json.ok) { fieldError(document.getElementById('sfpEmailError'), json.message || 'Failed to send code.'); return; }
      userEmail = email;
      document.getElementById('sfpEmailDisplay').textContent = email;
      goStep(2); document.getElementById('sfpOtpInput').value = '';
      startResendTimer(60);
      setTimeout(function () { document.getElementById('sfpOtpInput').focus(); }, 50);
    });
  });
  document.getElementById('sfpEmailInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('sfpSendBtn').click(); }
  });

  // Step 2
  document.getElementById('sfpVerifyBtn').addEventListener('click', function () {
    clearErrors();
    var otp = document.getElementById('sfpOtpInput').value.replace(/\D/g,'');
    if (otp.length !== 6) { fieldError(document.getElementById('sfpOtpError'), 'Please enter the full 6-digit code.'); return; }
    var btn = this; btn.disabled = true; btn.textContent = 'Verifying…';
    post({ action:'verify_otp', email:userEmail, otp:otp }, function (json) {
      btn.disabled = false; btn.textContent = 'Verify Code';
      if (!json.ok) {
        if (json.expired) { stopResendTimer(); document.getElementById('sfpResendBtn').disabled = false; document.getElementById('sfpResendBtn').textContent = 'Resend Code'; }
        fieldError(document.getElementById('sfpOtpError'), json.message || 'Verification failed.'); return;
      }
      stopResendTimer(); goStep(3);
      setTimeout(function () { document.getElementById('sfpPwInput').focus(); }, 50);
    });
  });
  document.getElementById('sfpOtpInput').addEventListener('input', function () { this.value = this.value.replace(/\D/g,'').slice(0,6); });
  document.getElementById('sfpOtpInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('sfpVerifyBtn').click(); }
  });
  document.getElementById('sfpResendBtn').addEventListener('click', function () {
    clearErrors(); var btn = this; btn.disabled = true;
    post({ action:'send_otp', email:userEmail }, function (json) {
      if (!json.ok) { fieldError(document.getElementById('sfpOtpError'), json.message || 'Could not resend.'); btn.disabled = false; return; }
      document.getElementById('sfpOtpInput').value = ''; startResendTimer(60);
    });
  });
  document.getElementById('sfpBackToEmail').addEventListener('click', function () {
    stopResendTimer(); goStep(1);
    setTimeout(function () { document.getElementById('sfpEmailInput').focus(); }, 50);
  });

  // Step 3
  document.getElementById('sfpResetBtn').addEventListener('click', function () {
    clearErrors();
    var pw = document.getElementById('sfpPwInput').value;
    var cpw = document.getElementById('sfpConfirmInput').value;
    if (pw.length < 8) { fieldError(document.getElementById('sfpPwError'), 'Password must be at least 8 characters.'); return; }
    if (!/[A-Za-z]/.test(pw) || !/[0-9]/.test(pw)) { fieldError(document.getElementById('sfpPwError'), 'Must include at least one letter and one number.'); return; }
    if (pw !== cpw) { fieldError(document.getElementById('sfpConfirmError'), 'Passwords do not match.'); return; }
    var btn = this; btn.disabled = true; btn.textContent = 'Updating…';
    post({ action:'reset_password', password:pw, confirm_password:cpw }, function (json) {
      btn.disabled = false; btn.textContent = 'Update Password';
      if (!json.ok) {
        if (json.expired) { goStep(1); return; }
        fieldError(json.field === 'confirm_password' ? document.getElementById('sfpConfirmError') : document.getElementById('sfpPwError'), json.message || 'Failed.');
        return;
      }
      goStep(4);
    });
  });

  document.getElementById('sfpDoneBtn').addEventListener('click', function () {
    closeModal();
    setTimeout(function () {
      var emailInput = document.querySelector('#staffLoginForm [name="email"]');
      if (emailInput) { emailInput.value = userEmail; emailInput.focus(); }
    }, 100);
  });

  function startResendTimer(seconds) {
    var btn = document.getElementById('sfpResendBtn');
    var left = seconds;
    btn.disabled = true;
    btn.innerHTML = 'Resend Code (<span id="sfpResendTimer">' + left + '</span>s)';
    resendTimer = setInterval(function () {
      left--;
      var t = document.getElementById('sfpResendTimer');
      if (t) t.textContent = left;
      if (left <= 0) { stopResendTimer(); btn.disabled = false; btn.textContent = 'Resend Code'; }
    }, 1000);
  }
  function stopResendTimer() { if (resendTimer) { clearInterval(resendTimer); resendTimer = null; } }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
