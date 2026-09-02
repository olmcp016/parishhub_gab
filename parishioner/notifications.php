<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);

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
      <div class="mb-3" style="border-bottom: 1px solid var(--cream-dark); padding-bottom: 12px;">
        <strong><?= e($n['title']) ?></strong>
        <p style="margin: 4px 0; font-size:13.5px;"><?= e($n['message']) ?></p>
        <span class="text-muted" style="font-size:12px;"><?= formatDateTime($n['created_at']) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
