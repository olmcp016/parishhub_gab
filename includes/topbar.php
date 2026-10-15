<?php $__user = currentUser(); $__photoUrl = null; if ($__user && !empty($__user['user_id'])) { $photoStmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?'); $photoStmt->execute([(int) $__user['user_id']]); $photoPath = $photoStmt->fetchColumn(); if ($photoPath && is_file(__DIR__ . '/../' . ltrim($photoPath, '/'))) $__photoUrl = url(ltrim($photoPath, '/')); } ?>
<?php
// Secretary-only notification bell (website notifications: new bookings, updated requirements).
$__showBell = $__user && ($__user['role_name'] ?? '') === 'Secretary';
$__unread = 0;
if ($__showBell) {
    try {
        $bellStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE');
        $bellStmt->execute([(int) $__user['user_id']]);
        $__unread = (int) $bellStmt->fetchColumn();
    } catch (Throwable $e) { /* bell just starts at 0 */ }
}
?>
<header class="topbar">
  <div class="flex gap-3" style="align-items:center;">
    <button class="menu-toggle" id="menuToggle">☰</button>
  </div>
  <div class="flex gap-3" style="align-items:center;">
    <?php if ($__showBell): ?>
      <div class="notif-bell" id="notifBell" data-feed-url="<?= e(url('secretary/notifications-feed.php')) ?>" data-csrf="<?= e(csrfToken()) ?>">
        <button type="button" class="notif-bell-button" id="notifBellButton" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
          <i data-lucide="bell" style="width:20px; height:20px;"></i>
          <span class="notif-bell-badge" id="notifBellBadge"<?= $__unread ? '' : ' hidden' ?>><?= $__unread > 99 ? '99+' : $__unread ?></span>
        </button>
        <div class="notif-bell-panel" id="notifBellPanel" hidden>
          <div class="notif-bell-head">
            <strong>Notifications</strong>
            <button type="button" class="notif-bell-readall" id="notifBellReadAll">Mark all as read</button>
          </div>
          <div class="notif-bell-list" id="notifBellList"><p class="text-muted notif-bell-empty">Loading…</p></div>
        </div>
      </div>
    <?php endif; ?>
    <div class="user-chip">
      <?php if ($__photoUrl): ?><img class="avatar avatar-photo" src="<?= e($__photoUrl) ?>" alt="Profile photo">
      <?php else: ?><div class="avatar"><?= e(mb_strtoupper(mb_substr($__user['firstname'],0,1) . mb_substr($__user['lastname'],0,1))) ?></div><?php endif; ?>
      <div>
        <div style="font-size:13.5px; font-weight:600;"><?= e($__user['firstname']) ?> <?= e($__user['lastname']) ?></div>
        <span class="role-badge"><?= e(roleLabel($__user['role_name'])) ?></span>
      </div>
    </div>
  </div>
</header>
<?php if ($__showBell): ?>
<script src="<?= url('public/js/notification-bell.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/notification-bell.js') ?>" defer></script>
<?php endif; ?>
