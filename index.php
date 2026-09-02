<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$stmt = db()->query("SELECT * FROM announcements WHERE status='published' ORDER BY is_pinned DESC, created_at DESC LIMIT 3");
$announcements = $stmt->fetchAll();

$stmt = db()->query("SELECT * FROM services WHERE is_active=1 ORDER BY category, service_name LIMIT 6");
$services = $stmt->fetchAll();

$serviceCount = (int) db()->query("SELECT COUNT(*) FROM services WHERE is_active=1")->fetchColumn();
$priestCount = (int) db()->query("SELECT COUNT(*) FROM priests WHERE status='active'")->fetchColumn();

$settingsRows = db()->query('SELECT * FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

$pageTitle = 'Welcome';
$__user = currentUser();
include __DIR__ . '/includes/header.php';
?>

<nav class="public-nav">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <div class="links">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('about.php') ?>">About</a>
    <a href="#services">Services</a>
    <?php if ($__user): ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-primary btn-sm">Dashboard</a>
    <?php else: ?>
      <a href="<?= url('auth/login.php') ?>">Log in</a>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Register</a>
    <?php endif; ?>
  </div>
</nav>

<section class="hero">
  <span class="hero-eyebrow">Parish Office, Online</span>
  <h1>Book your Sacraments — <em>without the line at the parish office.</em></h1>
  <p><?= e($settings['parish_name'] ?? 'Our parish') ?> now takes appointments for Baptism, Wedding, Mass Intentions, and more — online. Submit your request, track it from review to confirmation, and know exactly when to show up.</p>
  <div class="hero-actions">
    <?php if (!$__user): ?>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary">Create an account</a>
      <a href="<?= url('auth/login.php') ?>" class="btn btn-outline" style="color:var(--gold-light); border-color: var(--gold-light);">Log in</a>
    <?php else: ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-primary">Go to your dashboard</a>
    <?php endif; ?>
  </div>

  <div class="hero-stats">
    <div class="stat">
      <div class="stat-num"><?= $serviceCount ?></div>
      <div class="stat-label">Sacraments &amp; Services</div>
    </div>
    <div class="stat">
      <div class="stat-num"><?= $priestCount ?></div>
      <div class="stat-label"><?= $priestCount === 1 ? 'Priest' : 'Priests' ?> Serving</div>
    </div>
    <div class="stat">
      <div class="stat-num">24/7</div>
      <div class="stat-label">Online Booking</div>
    </div>
  </div>
</section>
<?php $archTone = 'dark'; include __DIR__ . '/includes/arch-divider.php'; ?>

<section class="section">
  <div class="section-eyebrow">The Process</div>
  <h2 class="section-title text-center">From request to confirmation</h2>
  <p class="section-lede">Every booking follows the same clear path — no guesswork about what happens after you submit.</p>

  <div class="step-flow">
    <div class="step-item">
      <div class="step-num">1</div>
      <h4>Choose &amp; book</h4>
      <p>Pick a service, propose a date and time, and upload any requirements you already have on hand.</p>
    </div>
    <div class="step-item">
      <div class="step-num">2</div>
      <h4>Secretary reviews</h4>
      <p>Our office checks your requirements, confirms priest availability, and approves your request.</p>
    </div>
    <div class="step-item">
      <div class="step-num">3</div>
      <h4>Pay &amp; get confirmed</h4>
      <p>Settle the fee online or in person, and we'll confirm your appointment and notify you directly.</p>
    </div>
  </div>
</section>

<?php if (!empty($announcements)): ?>
<section class="section" id="announcements" style="padding-top:0;">
  <div class="section-eyebrow">From the Office</div>
  <h2 class="section-title text-center">Latest announcements</h2>
  <div class="grid-3 mt-4">
    <?php foreach ($announcements as $a): ?>
      <div class="card">
        <h3><?= e($a['title']) ?></h3>
        <p class="text-muted" style="font-size: 13px;"><?= formatDate($a['created_at']) ?></p>
        <p style="font-size: 14.5px;"><?= e(mb_strlen($a['content']) > 140 ? mb_substr($a['content'],0,140) . '…' : $a['content']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section" id="services" style="background: var(--cream-dark); border-radius: 20px; max-width: 1150px;">
  <div class="section-eyebrow">What We Offer</div>
  <h2 class="section-title text-center">Sacraments &amp; services</h2>
  <p class="section-lede">Each service follows the parish's own scheduling policy — some are fixed dates, others are arranged directly with the office.</p>
  <div class="grid-3">
    <?php foreach ($services as $s): ?>
      <div class="service-card">
        <div class="service-eyebrow"><?= e($s['category']) ?></div>
        <h3><?= e($s['service_name']) ?></h3>
        <p><?= e($s['description']) ?></p>
        <div class="service-fee"><?= money($s['fee']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php $archTone = 'light'; include __DIR__ . '/includes/arch-divider.php'; ?>

<footer class="public-footer">
  <div class="footer-grid">
    <div class="footer-col">
      <div class="footer-brand"><span class="crest-mark" style="width:28px;height:28px;font-size:14px;"><?= crestMarkup() ?></span> PARISHHUB</div>
      <p><?= e($settings['parish_name'] ?? 'Our Parish') ?><br><?= e($settings['parish_address'] ?? '') ?></p>
    </div>
    <div class="footer-col">
      <h5>Quick Links</h5>
      <a href="<?= url('index.php') ?>">Home</a>
      <a href="<?= url('about.php') ?>">About the Parish</a>
      <a href="#services">Services</a>
      <a href="<?= url('auth/register.php') ?>">Create an Account</a>
    </div>
    <div class="footer-col">
      <h5>Contact</h5>
      <a href="tel:<?= e($settings['contact_number'] ?? '') ?>"><?= e($settings['contact_number'] ?? 'N/A') ?></a>
      <a href="mailto:<?= e($settings['contact_email'] ?? '') ?>"><?= e($settings['contact_email'] ?? 'N/A') ?></a>
    </div>
    <div class="footer-col">
      <h5>Office &amp; Mass Schedule</h5>
      <p><?= e($settings['office_hours'] ?? '') ?></p>
      <p><?= e($settings['mass_schedule'] ?? '') ?></p>
    </div>
  </div>
  <div class="footer-bottom">
    &copy; <?= date('Y') ?> <?= e($settings['parish_name'] ?? 'PARISHHUB') ?>. Serving our parish community with faith and technology.
  </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>
