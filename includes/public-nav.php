<?php
$publicNavActive = $publicNavActive ?? '';
$publicNavUser = $__user ?? currentUser();
?>
<nav class="public-nav" aria-label="Public navigation">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <button type="button" class="public-nav-toggle" aria-label="Open navigation" aria-expanded="false">☰</button>
  <div class="links">
    <a href="<?= url('index.php') ?>" class="<?= $publicNavActive === 'home' ? 'active' : '' ?>"<?= $publicNavActive === 'home' ? ' aria-current="page"' : '' ?>>Home</a>
    <a href="<?= url('about.php') ?>" class="<?= $publicNavActive === 'about' ? 'active' : '' ?>"<?= $publicNavActive === 'about' ? ' aria-current="page"' : '' ?>>About</a>
    <a href="<?= url('parishioner/services.php') ?>" class="<?= $publicNavActive === 'services' ? 'active' : '' ?>"<?= $publicNavActive === 'services' ? ' aria-current="page"' : '' ?>>Services</a>
    <a href="<?= url('parishioner/calendar.php') ?>" class="<?= $publicNavActive === 'calendar' ? 'active' : '' ?>"<?= $publicNavActive === 'calendar' ? ' aria-current="page"' : '' ?>>Calendar</a>
    <a href="<?= url('parishioner/announcements.php') ?>" class="<?= $publicNavActive === 'announcements' ? 'active' : '' ?>"<?= $publicNavActive === 'announcements' ? ' aria-current="page"' : '' ?>>Announcements</a>
    <a href="<?= url('status.php') ?>" class="<?= $publicNavActive === 'status' ? 'active' : '' ?>"<?= $publicNavActive === 'status' ? ' aria-current="page"' : '' ?>>Check Status</a>
    <?php if ($publicNavUser): ?>
      <a href="<?= redirectForRole($publicNavUser['role_name']) ?>" class="btn btn-primary btn-sm">Dashboard</a>
      <form method="POST" action="<?= url('auth/logout.php') ?>" class="public-nav-logout">
        <?= csrfField() ?>
        <button type="submit" class="nav-logout-btn">Logout</button>
      </form>
    <?php else: ?>
      <a href="<?= url('auth/login.php') ?>">Sign In</a>
      <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Get Started</a>
    <?php endif; ?>
  </div>
</nav>
<script>
(function () {
  var nav = document.currentScript.previousElementSibling;
  if (!nav) return;
  var toggle = nav.querySelector('.public-nav-toggle');
  if (!toggle) return;
  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
  });
})();
</script>
