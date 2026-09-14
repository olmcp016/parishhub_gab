<?php
/**
 * Page chrome for a guest (no account) visitor on a page that also works
 * for logged-in Parishioners — services.php, calendar.php, announcements.php,
 * donations.php. A logged-in Parishioner still gets the normal sidebar
 * dashboard (see dash-start.php); this is only reached when nobody is
 * logged in. Reuses the exact `.public-nav`/`.public-footer` classes and
 * markup already established on index.php/about.php — no new CSS.
 */
?>
<nav class="public-nav">
  <div class="brand"><span class="crest-mark"><?= crestMarkup() ?></span> PARISHHUB</div>
  <div class="links">
    <a href="<?= url('index.php') ?>">Home</a>
    <a href="<?= url('about.php') ?>">About</a>
    <a href="<?= url('parishioner/services.php') ?>" class="<?= ($active ?? '') === 'services' ? 'active' : '' ?>">Services</a>
    <a href="<?= url('parishioner/calendar.php') ?>" class="<?= ($active ?? '') === 'calendar' ? 'active' : '' ?>">Calendar</a>
    <a href="<?= url('parishioner/announcements.php') ?>" class="<?= ($active ?? '') === 'announcements' ? 'active' : '' ?>">Announcements</a>
    <a href="<?= url('status.php') ?>">Check Status</a>
    <a href="<?= url('auth/login.php') ?>">Sign In</a>
    <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-sm">Get Started</a>
  </div>
</nav>

<div style="max-width:1100px; margin:0 auto; padding: 28px 20px 60px;">
  <?php include __DIR__ . '/flash.php'; ?>
  <?php if (isset($pageTitle)): ?><h1 style="margin:0 0 20px;"><?= e($pageTitle) ?></h1><?php endif; ?>
