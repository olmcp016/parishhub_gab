<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
guestOnly();

/**
 * "Resend verification email" (audit B-06). A GET only shows the form: sending
 * replaces the account's link, so it takes a POST with a valid CSRF token.
 * Every request gets the same answer, whether or not the address belongs to an
 * account, so the page cannot be used to find out who is registered. Only an
 * active Parishioner that is still unverified is sent anything, within the
 * per-address limits shared with registration.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        keepOldInput(['email' => $email]);
        flash('error', 'Please enter a valid email address.');
        redirect(url('auth/resend-verification.php'));
    }

    $stmt = db()->prepare(
        "SELECT u.user_id, u.firstname, u.lastname, u.verification_token, u.token_expires_at
         FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.email = ? AND r.role_name = 'Parishioner' AND u.status = 'active' AND u.email_verified_at IS NULL"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // The limit is reserved before the link changes, so a refused request
    // leaves the link already sent working.
    if ($user && verificationEmailThrottle($email) === 0) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $update = db()->prepare('UPDATE users SET verification_token = ?, token_expires_at = ? WHERE user_id = ? AND email_verified_at IS NULL');
        $update->execute([$token, $expires, $user['user_id']]);

        if ($update->rowCount() === 1) {
            $verifyUrl = absoluteUrl('auth/verify-email.php') . '?token=' . urlencode($token);
            $bodyHtml  = '<p>Hello <strong>' . htmlspecialchars($user['firstname']) . '</strong>,</p>'
                       . '<p>You requested a new verification link for the Parish Service Portal. '
                       . 'Please verify your email address by clicking the button below. '
                       . 'This link expires in <strong>24 hours</strong>.</p>';
            $html   = emailTemplate('Verify Your Email Address', $bodyHtml, $verifyUrl, 'Verify My Email');
            $result = sendEmail($email, $user['firstname'] . ' ' . $user['lastname'], 'Verify your ParishHub email address', $html);

            if (!$result['ok']) {
                // Put the previous link back, unless something changed the row since.
                db()->prepare('UPDATE users SET verification_token = ?, token_expires_at = ? WHERE user_id = ? AND verification_token = ?')
                    ->execute([$user['verification_token'], $user['token_expires_at'], $user['user_id'], $token]);
                verificationEmailNotSent($email);
            }
        } else {
            verificationEmailNotSent($email);
        }
    }

    flash('success', 'If an account with that email address is waiting for verification, a new verification link has been sent. Please check your inbox (and spam folder). You can ask for another link after 10 minutes.');
    redirect(url('auth/resend-verification.php'));
}

$pageTitle = 'Resend Verification Email';
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
      <p class="panel-foot">Verification links expire after 24 hours. A new link replaces the previous one.</p>
    </div>
  </div>

  <div class="auth-split-form">
    <a href="<?= url('auth/login.php') ?>" class="auth-back-link">← Back to Login</a>

    <div class="auth-card">
      <div class="form-heading">
        <h1>Resend Verification Email</h1>
        <p class="subtitle">Enter the email address you registered with and we'll send a new verification link.</p>
      </div>

      <?php include __DIR__ . '/../includes/flash.php'; ?>

      <form method="POST" action="<?= url('auth/resend-verification.php') ?>">
        <?= csrfField() ?>

        <div class="form-group">
          <label for="resendEmail">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon"><i data-lucide="mail"></i></span>
            <input type="email" name="email" id="resendEmail" value="<?= oldInput('email') ?>" required autofocus placeholder="you@example.com">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:10px;">Send Verification Link</button>
      </form>
    </div>
  </div>
</div>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
