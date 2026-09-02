<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$userCount = db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$appointmentCount = db()->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$yearlyRevenue = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
$pendingCount = db()->query('SELECT COUNT(*) FROM appointments WHERE status_id = 1')->fetchColumn();

$byRole = db()->query(
    "SELECT r.role_name, COUNT(*) AS total FROM users u JOIN roles r ON u.role_id = r.role_id GROUP BY r.role_name"
)->fetchAll();

$recentLogs = db()->query(
    "SELECT l.*, u.firstname, u.lastname FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id
     ORDER BY l.created_at DESC LIMIT 10"
)->fetchAll();

$active = 'dashboard';
$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value"><?= (int)$userCount ?></div></div>
  <div class="stat-card"><div class="stat-label">Total Appointments</div><div class="stat-value"><?= (int)$appointmentCount ?></div></div>
  <div class="stat-card"><div class="stat-label">Yearly Revenue</div><div class="stat-value"><?= money((float)$yearlyRevenue) ?></div></div>
  <div class="stat-card"><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= (int)$pendingCount ?></div></div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1.4fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Users by Role</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Role</th><th>Count</th></tr></thead>
        <tbody>
          <?php foreach ($byRole as $r): ?>
            <tr><td><?= e($r['role_name']) ?></td><td><?= $r['total'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Recent Activity</h3>
      <a href="<?= url('admin/activity-logs.php') ?>" class="btn btn-outline btn-sm">View All Logs</a>
    </div>
    <?php foreach ($recentLogs as $l): ?>
      <div class="mb-2" style="border-bottom:1px solid var(--cream-dark); padding-bottom:8px;">
        <strong><?= e($l['firstname'] ? $l['firstname'] . ' ' . $l['lastname'] : 'System') ?></strong> — <?= e($l['action']) ?>
        <div class="text-muted" style="font-size:12px;"><?= formatDateTime($l['created_at']) ?> · <?= e($l['module']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Quick Management</h3></div>
  <div class="flex gap-3" style="flex-wrap:wrap;">
    <a href="<?= url('admin/users.php') ?>" class="btn btn-outline">👥 Manage Users</a>
    <a href="<?= url('admin/priests.php') ?>" class="btn btn-outline">✝️ Manage Priests</a>
    <a href="<?= url('admin/services.php') ?>" class="btn btn-outline">🕊️ Manage Services</a>
    <a href="<?= url('secretary/appointments.php') ?>" class="btn btn-outline">📋 Appointments</a>
    <a href="<?= url('treasurer/payments.php') ?>" class="btn btn-outline">💰 Payments</a>
    <a href="<?= url('admin/settings.php') ?>" class="btn btn-outline">⚙️ Settings</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
