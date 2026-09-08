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

<?php if (empty($announcements)): ?>
  <div class="empty-state"><div class="icon">📢</div><p>No announcements yet.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($announcements as $a): ?>
      <div class="card" style="display:flex; flex-direction:column;">
        <div class="flex-between" style="align-items:flex-start; gap:8px; margin-bottom:4px;">
          <h3 style="margin:0;"><?= e($a['title']) ?></h3>
          <?php if ($a['is_pinned']): ?><span class="badge badge-pending" style="flex-shrink:0;">Pinned</span><?php endif; ?>
        </div>
        <span class="text-muted" style="font-size:13px; margin-bottom:12px;"><?= formatDate($a['created_at']) ?></span>
        <p style="margin:0;"><?= e($a['content']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
