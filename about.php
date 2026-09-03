<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/scheduling.php';

$settingsRows = db()->query('SELECT * FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

$pageTitle = 'About the Parish';
$__user = currentUser();
include __DIR__ . '/includes/header.php';
?>

<nav class="public-nav">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <div class="links">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('about.php') ?>">About</a>
    <?php if ($__user): ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-primary btn-sm">Dashboard</a>
    <?php else: ?>
      <a href="<?= url('auth/login.php') ?>">Log in</a>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Register</a>
    <?php endif; ?>
  </div>
</nav>

<section class="hero" style="min-height: auto;">
  <div class="hero-pattern"></div>
  <div class="hero-content">
    <span class="hero-eyebrow">About</span>
    <h1 class="hero-title" style="font-size: clamp(34px, 4.5vw, 52px);">A parish office, <em>brought online.</em></h1>
    <p class="hero-subtitle"><?= e($settings['parish_name'] ?? 'Our parish') ?> built PARISHHUB to replace paper logbooks and repeat trips to the office with a clear, trackable way to request Sacraments and parish services.</p>
  </div>
</section>
<?php $archTone = 'dark'; include __DIR__ . '/includes/arch-divider.php'; ?>

<section class="section" style="max-width: 820px;">
  <p style="font-size: 16px; line-height: 1.8; color: var(--brown-mid);">
    Every request — a Baptism, a Wedding, a Mass Intention for a loved one — used to mean lining up at the office, not always knowing which documents to bring or when to come back. PARISHHUB keeps that same careful, personal review process our secretary and priests have always done, but lets you submit your request, upload your requirements, and pay from home — then follow its progress from submission to confirmation.
  </p>
</section>

<section class="section" style="padding-top: 0;">
  <div class="section-eyebrow">How the Office Works</div>
  <h2 class="section-title text-center">Three people, one clear process</h2>
  <p class="section-lede">Each request passes through the same hands it always has — just with a clearer paper trail.</p>

  <div class="step-flow">
    <div class="step-item">
      <div class="step-num">S</div>
      <h4>The Secretary</h4>
      <p>Arranges your date on the parish calendar, checks your requirements are complete, and coordinates the assigned priest's availability before approving your request.</p>
    </div>
    <div class="step-item">
      <div class="step-num">T</div>
      <h4>The Treasurer</h4>
      <p>Verifies your payment once your request is approved and issues an official receipt. From there, you simply wait for your confirmed appointment.</p>
    </div>
    <div class="step-item">
      <div class="step-num">P</div>
      <h4>The Priest</h4>
      <p>Confirms his own availability for the proposed date and time — for services without a fixed schedule, you may even propose a date directly.</p>
    </div>
  </div>
</section>

<section class="section" style="padding-top: 0; max-width: 900px;">
  <div class="section-eyebrow">Reference</div>
  <h2 class="section-title text-center">Sacrament &amp; Mass scheduling</h2>
  <p class="section-lede">Some services follow a fixed monthly schedule; others are arranged directly with the office. Here's how each one works.</p>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Schedule</th></tr></thead>
      <tbody>
        <tr><td><strong>Baptism</strong></td><td><?= e(schedulingPolicyText('Baptism')) ?></td></tr>
        <tr><td><strong>Wedding</strong></td><td><?= e(schedulingPolicyText('Wedding')) ?></td></tr>
        <tr><td><strong>Confirmation</strong></td><td><?= e(schedulingPolicyText('Confirmation')) ?></td></tr>
        <tr><td><strong>Funeral Mass</strong></td><td><?= e(schedulingPolicyText('Funeral')) ?></td></tr>
        <tr><td><strong>House Blessing</strong></td><td><?= e(schedulingPolicyText('Blessing')) ?></td></tr>
        <tr><td><strong>Mass Intention</strong></td><td><?= e(schedulingPolicyText('Mass Intention')) ?></td></tr>
      </tbody>
    </table>
  </div>
  <p class="helper-text mt-3">The parish office is closed every Monday afternoon and all day Tuesday. Requests aren't scheduled during this time.</p>
</section>

<?php $archTone = 'light'; include __DIR__ . '/includes/arch-divider.php'; ?>

<footer class="public-footer">
  <div class="footer-bottom" style="border-top:none; padding-top:0;">
    &copy; <?= date('Y') ?> <?= e($settings['parish_name'] ?? 'PARISHHUB') ?>. Serving our parish community with faith and technology.
  </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>
