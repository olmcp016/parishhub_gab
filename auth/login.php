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

    $stmt = db()->prepare(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $fail('email', 'Email is invalid or not found.');
        exit;
    }

    if ($user['email_verified_at'] === null) {
        $fail('email', 'Please verify your email address before logging in. Check your inbox for the verification link.');
        exit;
    }

    if ($user['status'] !== 'active') {
        $fail('email', 'Your account is not active. Please contact the parish office.');
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        $fail('password', 'Incorrect password. Please try again.');
        exit;
    }

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
          <label>Password</label>
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
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
