<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/secretary-notifications.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];

// Same filter as the bell and its unread count (routine notifications are hidden, never deleted).
$visibleNotifications = parishionerVisibleNotifications((int) $userId);
$pagination = paginate(count($visibleNotifications), 10);
$notifications = array_slice($visibleNotifications, $pagination['offset'], $pagination['limit']);

db()->prepare('UPDATE notifications SET is_read = TRUE WHERE user_id = ?')->execute([$userId]);

$active = 'notifications';
$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Notifications</h3></div>
  <?php if (empty($notifications)): ?>
    <div class="empty-state"><div class="icon">🔔</div><p>No notifications yet.</p></div>
  <?php else: ?>
    <?php foreach ($notifications as $n): ?>
      <?php // Appointment notifications open that appointment (the detail page checks ownership).
      $nAppointmentId = !empty($n['appointment_id']) ? (int) $n['appointment_id']
          : (preg_match('/\bappointment\s+#(\d+)/i', (string) $n['message'], $nm) ? (int) $nm[1] : 0); ?>
      <?php if ($nAppointmentId): ?><a href="<?= url('parishioner/appointment-detail.php?id=' . $nAppointmentId) ?>" style="display:block; color:inherit; text-decoration:none;"><?php endif; ?>
      <div class="mb-3" style="border-bottom: 1px solid var(--cream-dark); padding-bottom: 12px;">
        <strong><?= e($n['title']) ?></strong>
        <p style="margin: 4px 0; font-size:13.5px;"><?= e($n['message']) ?></p>
        <span class="text-muted" style="font-size:12px;"><?= formatDateTime($n['created_at']) ?></span>
      </div>
      <?php if ($nAppointmentId): ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?= renderPagination($pagination, url('parishioner/notifications.php')) ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
