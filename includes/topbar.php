<?php $__user = currentUser(); $__photoUrl = null; if ($__user && !empty($__user['user_id'])) { $photoStmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?'); $photoStmt->execute([(int) $__user['user_id']]); $photoPath = $photoStmt->fetchColumn(); if ($photoPath && is_file(__DIR__ . '/../' . ltrim($photoPath, '/'))) $__photoUrl = url(ltrim($photoPath, '/')); } ?>
<header class="topbar">
  <div class="flex gap-3" style="align-items:center;">
    <button class="menu-toggle" id="menuToggle">☰</button>
  </div>
  <div class="user-chip">
    <?php if ($__photoUrl): ?><img class="avatar avatar-photo" src="<?= e($__photoUrl) ?>" alt="Profile photo">
    <?php else: ?><div class="avatar"><?= e(mb_strtoupper(mb_substr($__user['firstname'],0,1) . mb_substr($__user['lastname'],0,1))) ?></div><?php endif; ?>
    <div>
      <div style="font-size:13.5px; font-weight:600;"><?= e($__user['firstname']) ?> <?= e($__user['lastname']) ?></div>
      <span class="role-badge"><?= e(roleLabel($__user['role_name'])) ?></span>
    </div>
  </div>
</header>
