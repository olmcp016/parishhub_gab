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

// Group into date buckets (in the app's Asia/Manila timezone, consistent
// with how the rest of the app computes "today") so the template can
// render one date header per day instead of one flat table.
$logsByDate = [];
foreach ($logs as $l) {
    $dateKey = date('Y-m-d', strtotime($l['created_at']));
    $logsByDate[$dateKey][] = $l;
}

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
  <?php foreach ($logsByDate as $dateKey => $dayLogs): ?>
    <h4 class="activity-date-heading"><?= e(strtoupper(date('F j, Y', strtotime($dateKey)))) ?></h4>
    <div class="table-wrap mb-3">
      <table>
        <thead><tr><th>User</th><th>Action</th><th>Module</th><th>IP Address</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($dayLogs as $l): ?>
            <tr>
              <td><?= e($l['firstname'] ? $l['firstname'] . ' ' . $l['lastname'] : 'System') ?></td>
              <td><?= e($l['action']) ?></td>
              <td><?= e($l['module'] ?? '—') ?></td>
              <td><?= e($l['ip_address'] ?? '—') ?></td>
              <td><?= date('g:i A', strtotime($l['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
  <?php if (empty($logs)): ?><p class="text-muted text-center mt-3">No activity recorded yet.</p><?php else: ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
