<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$logs = db()->query(
    "SELECT l.*, u.firstname, u.lastname FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id
     ORDER BY l.created_at DESC LIMIT 200"
)->fetchAll();

$active = 'logs';
$pageTitle = 'Activity Logs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>System Activity Log</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Action</th><th>Module</th><th>IP Address</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= e($l['firstname'] ? $l['firstname'] . ' ' . $l['lastname'] : 'System') ?></td>
            <td><?= e($l['action']) ?></td>
            <td><?= e($l['module'] ?? '—') ?></td>
            <td><?= e($l['ip_address'] ?? '—') ?></td>
            <td><?= formatDateTime($l['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($logs)): ?><p class="text-muted text-center mt-3">No activity recorded yet.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
