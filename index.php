<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$stmt = db()->query("SELECT * FROM announcements WHERE status='published' ORDER BY is_pinned DESC, created_at DESC LIMIT 3");
$announcements = $stmt->fetchAll();

$stmt = db()->query("SELECT * FROM services WHERE is_active=TRUE ORDER BY category, service_name LIMIT 6");
$services = $stmt->fetchAll();

$serviceCount = (int) db()->query("SELECT COUNT(*) FROM services WHERE is_active=TRUE")->fetchColumn();
$priestCount = (int) db()->query("SELECT COUNT(*) FROM priests WHERE status='active'")->fetchColumn();

$settingsRows = db()->query('SELECT * FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

/** Small emoji-icon lookup per sacrament/service category — display only. */
function serviceCategoryIcon(string $category): string
{
    return match ($category) {
        'Mass Intention'  => '🕯️',
        'Baptism'         => '💧',
        'Wedding'         => '💍',
        'Blessing'        => '🙏',
        'Funeral'         => '✝️',
        'Confirmation'    => '☁️',
        'First Communion' => '🍞',
        default           => '📋',
    };
}

$pageTitle = 'Welcome';
$__user = currentUser();
include __DIR__ . '/includes/header.php';
?>
<noscript><style>.reveal{opacity:1!important;transform:none!important;}</style></noscript>

<nav class="public-nav">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <div class="links">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('about.php') ?>">About</a>
    <a href="#services">Services</a>
    <a href="#how">How It Works</a>
    <?php if ($__user): ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-primary btn-sm">Dashboard</a>
    <?php else: ?>
      <a href="<?= url('auth/login.php') ?>">Sign In</a>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Get Started</a>
    <?php endif; ?>
  </div>
</nav>

<section class="hero">
  <div class="hero-pattern"></div>
  <div class="hero-content">
    <span class="hero-eyebrow">✝ <?= e($settings['parish_address'] ?? $settings['parish_name'] ?? 'Our Parish Office') ?></span>
    <h1 class="hero-title">Your Parish, <em>Reimagined</em><br>for the Digital Age</h1>
    <p class="hero-subtitle">Submit sacrament requests, track your parish services, and connect with <?= e($settings['parish_name'] ?? 'our parish') ?> — all from one simple platform. No more lining up at the office for a form you could have filed from home.</p>
    <div class="hero-actions">
      <?php if (!$__user): ?>
        <a href="<?= url('auth/register.php') ?>" class="btn-hero-primary">Request a Service →</a>
        <a href="<?= url('auth/login.php') ?>" class="btn-hero-ghost">Sign In</a>
      <?php else: ?>
        <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn-hero-primary">Go to your dashboard →</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="hero-verse">
    <span class="verse-cross">✝</span>
    <p class="verse-text">"Ask, and it will be given to you; seek, and you will find; knock, and it will be opened to you."</p>
    <span class="verse-cite">— Matthew 7:7</span>
  </div>
</section>

<div class="stats-bar">
  <div class="stat-item">
    <span class="stat-num"><?= $serviceCount ?></span>
    <span class="stat-label">Sacraments &amp; Services</span>
  </div>
  <div class="stat-item">
    <span class="stat-num"><?= $priestCount ?></span>
    <span class="stat-label"><?= $priestCount === 1 ? 'Priest' : 'Priests' ?> Serving</span>
  </div>
  <div class="stat-item">
    <span class="stat-num">24/7</span>
    <span class="stat-label">Online Requests</span>
  </div>
  <div class="stat-item">
    <span class="stat-num">100%</span>
    <span class="stat-label">Free to Use</span>
  </div>
</div>

<?php if (!empty($announcements)): ?>
<section class="section" id="announcements">
  <div class="section-eyebrow">From the Office</div>
  <h2 class="section-title">Latest announcements</h2>
  <p class="section-lede">What's happening at the parish office right now.</p>
  <div class="grid-3">
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

<section class="section" id="services">
  <div class="section-eyebrow">Parish Services</div>
  <h2 class="section-title">Sacraments &amp; Offerings</h2>
  <p class="section-lede">Request any of our parish services online. Some have a fixed fee, while others welcome a voluntary offering from the heart.</p>

  <div class="grid-4">
    <?php foreach ($services as $s): ?>
      <div class="svc-card reveal">
        <span class="svc-icon"><?= serviceCategoryIcon($s['category']) ?></span>
        <div class="svc-eyebrow"><?= e($s['category']) ?></div>
        <h3><?= e($s['service_name']) ?></h3>
        <p><?= e($s['description']) ?></p>
        <?php if ((float) $s['fee'] > 0): ?>
          <span class="svc-fee fixed">🔒 Fixed: <?= money((float) $s['fee']) ?></span>
        <?php else: ?>
          <span class="svc-fee voluntary">🤲 Voluntary Offering</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="svc-card reveal" style="grid-column:span 2; background:linear-gradient(135deg,var(--cream-dark),#faf0d0); display:flex; flex-direction:column; justify-content:center;">
      <span class="svc-icon">📱</span>
      <h3>More Services Available</h3>
      <p>Create a free account to see every active service and submit your request online.</p>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-dark btn-sm" style="align-self:flex-start;">View All Services →</a>
    </div>
  </div>
</section>

<section class="how-section" id="how">
  <div class="section-eyebrow">How It Works</div>
  <h2 class="section-title">From request to confirmation</h2>
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

<section class="cta-section">
  <span class="cta-cross">✝</span>
  <h2 class="section-title">Ready to Get Started?</h2>
  <p class="section-lede" style="margin-bottom: 8px;">Join our digital parish community and request services from the comfort of your home.</p>
  <div class="cta-btns">
    <?php if (!$__user): ?>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-dark">Create Free Account</a>
      <a href="<?= url('auth/login.php') ?>" class="btn btn-outline">Sign In</a>
    <?php else: ?>
      <a href="<?= redirectForRole($__user['role_name']) ?>" class="btn btn-dark">Go to your dashboard →</a>
    <?php endif; ?>
  </div>
</section>

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
