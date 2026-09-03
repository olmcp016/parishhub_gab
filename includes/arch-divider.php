<?php
/**
 * The site's signature visual motif: a row of pointed arches drawn as thin
 * linework, evoking a church nave arcade / cloister walk. Used to mark the
 * transition between the hero and the page content on public-facing pages.
 *
 * Usage: include this file, optionally after setting $archTone = 'dark' | 'light'
 * (dark = gold lines on the dark hero background; light = brown lines on cream).
 */
$__archTone = $archTone ?? 'dark';
$__archStroke = $__archTone === 'dark' ? '#c9a84c' : '#9e7c2a';
$__archBg = $__archTone === 'dark' ? '#1e1a0a' : '#f7f4ea';

$__archCount = 9;
$__archWidth = 1200 / $__archCount;
$__archHeight = 46;
$__radius = $__archWidth * 0.62;

$__paths = [];
for ($i = 0; $i < $__archCount; $i++) {
    $x0 = $i * $__archWidth;
    $xm = $x0 + $__archWidth / 2;
    $x1 = $x0 + $__archWidth;
    $__paths[] = "M{$x0},{$__archHeight} A{$__radius},{$__radius} 0 0 1 {$xm},2 A{$__radius},{$__radius} 0 0 1 {$x1},{$__archHeight}";
}
?>
<div class="arch-divider" style="background:<?= $__archBg ?>;">
  <svg viewBox="0 0 1200 <?= $__archHeight + 6 ?>" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <?php foreach ($__paths as $p): ?>
      <path d="<?= $p ?>" fill="none" stroke="<?= $__archStroke ?>" stroke-width="1.5" opacity="0.55" />
    <?php endforeach; ?>
    <line x1="0" y1="<?= $__archHeight ?>" x2="1200" y2="<?= $__archHeight ?>" stroke="<?= $__archStroke ?>" stroke-width="1.5" opacity="0.55" />
    <?php foreach ($__paths as $i => $p): $xm = $i * $__archWidth + $__archWidth / 2; ?>
      <circle cx="<?= $xm ?>" cy="2" r="2" fill="<?= $__archStroke ?>" opacity="0.75" />
    <?php endforeach; ?>
  </svg>
</div>
