<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];

$stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$stmt->execute([$userId]);
$pagination = paginate((int) $stmt->fetchColumn(), 10);

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $userId);
$stmt->bindValue(2, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

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
