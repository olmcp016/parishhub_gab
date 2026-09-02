<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$announcements = db()->query(
    "SELECT * FROM announcements WHERE status = 'published' ORDER BY is_pinned DESC, created_at DESC"
)->fetchAll();

$active = 'announcements';
$pageTitle = 'Announcements';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php foreach ($announcements as $a): ?>
  <div class="card">
    <div class="card-header">
      <h3><?= e($a['title']) ?> <?php if ($a['is_pinned']): ?><span class="badge badge-pending">Pinned</span><?php endif; ?></h3>
      <span class="text-muted" style="font-size:13px;"><?= formatDate($a['created_at']) ?></span>
    </div>
    <p><?= e($a['content']) ?></p>
  </div>
<?php endforeach; ?>

<?php if (empty($announcements)): ?>
  <div class="empty-state"><div class="icon">📢</div><p>No announcements yet.</p></div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
