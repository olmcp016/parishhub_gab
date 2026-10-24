<?php $__user = currentUser(); $__photoUrl = null; if ($__user && !empty($__user['user_id'])) { $photoStmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?'); $photoStmt->execute([(int) $__user['user_id']]); $photoPath = $photoStmt->fetchColumn(); if ($photoPath && is_file(__DIR__ . '/../' . ltrim($photoPath, '/'))) $__photoUrl = url(ltrim($photoPath, '/')); } ?>
<?php
// Notification bell (website notifications) for the Secretary (new bookings, updated
// requirements) and the Parishioner (booking/payment updates). Same bell, same
// script; each role reads only its own feed (secretary/ or parishioner/notifications-feed.php).
$__bellRole = $__user['role_name'] ?? '';
$__showBell = $__user && in_array($__bellRole, ['Secretary', 'Parishioner'], true);
$__bellFeedUrl = $__bellRole === 'Parishioner' ? url('parishioner/notifications-feed.php') : url('secretary/notifications-feed.php');
$__unread = 0;
if ($__showBell) {
    try {
        if ($__bellRole === 'Parishioner') {
            // Same filter as the bell feed and View All (routine notifications are not counted).
            require_once __DIR__ . '/secretary-notifications.php';
            $__unread = parishionerUnreadNotificationCount(parishionerVisibleNotifications((int) $__user['user_id']));
        } else {
            // Auto-clean duplicate notification spam
            try {
                db()->query("
                    DELETE FROM notifications n1
                    USING notifications n2
                    WHERE n1.notification_id < n2.notification_id
                      AND n1.user_id = n2.user_id
                      AND n1.title = n2.title
                      AND n1.message = n2.message
                      AND n1.is_read = FALSE
                ");
            } catch (Throwable $e) {}
            $bellStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE');
            $bellStmt->execute([(int) $__user['user_id']]);
            $__unread = (int) $bellStmt->fetchColumn();
        }
    } catch (Throwable $e) { /* bell just starts at 0 */ }
}
?>
<header class="topbar">
  <div class="flex gap-3" style="align-items:center;">
    <button class="menu-toggle" id="menuToggle">☰</button>
  </div>
  <div class="flex gap-3" style="align-items:center;">
    <?php if ($__showBell): ?>
      <div class="notif-bell" id="notifBell" data-feed-url="<?= e($__bellFeedUrl) ?>" data-csrf="<?= e(csrfToken()) ?>">
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
          <?php if ($__bellRole === 'Parishioner'): ?>
            <a class="notif-bell-all" href="<?= e(url('parishioner/notifications.php')) ?>">View All Notifications</a>
          <?php endif; ?>
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
