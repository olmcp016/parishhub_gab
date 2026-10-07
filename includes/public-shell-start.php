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
<?php $publicNavActive = $publicNavActive ?? ($active ?? ''); include __DIR__ . '/public-nav.php'; ?>

<div style="max-width:<?= htmlspecialchars($shellMaxWidth ?? '1100px') ?>; margin:0 auto; padding: 28px 20px 60px;">
  <?php include __DIR__ . '/flash.php'; ?>
  <?php if (isset($pageTitle)): ?><h1 style="margin:0 0 20px;"><?= e($pageTitle) ?></h1><?php endif; ?>
