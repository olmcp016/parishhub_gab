<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$sql = "SELECT l.*, u.firstname, u.lastname FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id WHERE 1=1";
$params = [];
if ($dateFrom) { $sql .= ' AND l.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $sql .= ' AND l.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$sql .= ' ORDER BY l.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$active = 'logs';
$pageTitle = 'Activity Logs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>System Activity Log</h3></div>
  <form method="GET" class="form-row mb-3">
    <div class="form-group"><label>From Date</label><input type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
    <div class="form-group"><label>To Date</label><input type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Filter</button></div>
    <?php if ($dateFrom || $dateTo): ?>
      <div class="form-group" style="align-self:end;"><a href="<?= url('admin/activity-logs.php') ?>" class="btn btn-outline">Clear</a></div>
    <?php endif; ?>
  </form>
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
