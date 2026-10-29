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

<div style="max-width:<?= htmlspecialchars($shellMaxWidth ?? '1100px') ?>; margin:0 auto; padding: 100px 20px 60px;">
  <?php include __DIR__ . '/flash.php'; ?>
  <?php if (isset($pageTitle) && !empty($pageHasOwnHeading)): // the page prints its own visible <h1>: no second heading here ?>
  <?php elseif (isset($pageTitle) && !empty($hidePageHeading)): // nav bar already names the page: keep the heading for screen readers only ?>
    <h1 style="position:absolute; width:1px; height:1px; margin:-1px; padding:0; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0;"><?= e($pageTitle) ?></h1>
  <?php elseif (isset($pageTitle)): ?><h1 style="margin:0 0 20px;"><?= e($pageTitle) ?></h1><?php endif; ?>
