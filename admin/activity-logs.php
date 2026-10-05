<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Routine Parishioner logins are noise for a staff/system audit trail — a
// Parishioner's own "logged in" entry doesn't reflect a system transaction
// or staff action, so it's excluded here. Staff logins (Admin/Secretary/
// Cashier) still show, since those ARE meaningful for an admin audit.
$sql = "SELECT l.*, u.firstname, u.lastname, r.role_name FROM activity_logs l
        LEFT JOIN users u ON l.user_id = u.user_id
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE NOT (l.module = 'Auth' AND r.role_name = 'Parishioner')";
$params = [];
if ($dateFrom) { $sql .= ' AND l.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $sql .= ' AND l.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }

$countStmt = db()->prepare(str_replace('SELECT l.*, u.firstname, u.lastname, r.role_name', 'SELECT COUNT(*)', $sql));
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), 10);

$sql .= ' ORDER BY l.created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
$stmt->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$paginationUrl = url('admin/activity-logs.php') . '?' . http_build_query(array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo]));



$active = 'logs';
$pageTitle = 'Activity Logs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>System Activity Log</h3></div>
  <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:24px;">
    <div class="form-group" style="flex: 1 1 200px; margin-bottom:0;"><label>From Date</label><input type="date" name="date_from" value="<?= e($dateFrom) ?>" style="width:100%; box-sizing:border-box;"></div>
    <div class="form-group" style="flex: 1 1 200px; margin-bottom:0;"><label>To Date</label><input type="date" name="date_to" value="<?= e($dateTo) ?>" style="width:100%; box-sizing:border-box;"></div>
    <div class="form-group" style="flex: 0 0 auto; margin-bottom:0;"><button type="submit" class="btn btn-primary" style="padding:10px 24px; height:42px;">Filter</button></div>
    <?php if ($dateFrom || $dateTo): ?>
      <div class="form-group" style="flex: 0 0 auto; margin-bottom:0;"><a href="<?= url('admin/activity-logs.php') ?>" class="btn btn-outline" style="padding:10px 24px; height:42px; display:inline-flex; align-items:center;">Clear</a></div>
    <?php endif; ?>
  </form>

  <div class="table-wrap mb-3">
    <table>
      <thead><tr><th>User</th><th>Action</th><th>Section</th><th>Date &amp; Time</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= e($l['firstname'] ? $l['firstname'] . ' ' . $l['lastname'] : 'System') ?></td>
            <td><?= e($l['action']) ?></td>
            <td><?= e($l['module'] ?? '—') ?></td>
            <td><?= date('M j, Y g:i A', strtotime($l['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($logs)): ?><p class="text-muted text-center mt-3">No activity recorded yet.</p><?php else: ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
