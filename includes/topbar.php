<?php $__user = currentUser(); ?>
<header class="topbar">
  <div class="flex gap-3" style="align-items:center;">
    <button class="menu-toggle" id="menuToggle">☰</button>
    <h1><?= e($pageTitle ?? APP_NAME) ?></h1>
  </div>
  <div class="user-chip">
    <div class="avatar"><?= e(mb_substr($__user['firstname'],0,1)) ?><?= e(mb_substr($__user['lastname'],0,1)) ?></div>
    <div>
      <div style="font-size:13.5px; font-weight:600;"><?= e($__user['firstname']) ?> <?= e($__user['lastname']) ?></div>
      <span class="role-badge"><?= e($__user['role_name']) ?></span>
    </div>
  </div>
</header>
