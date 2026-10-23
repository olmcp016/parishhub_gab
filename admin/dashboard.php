<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$userCount = db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$appointmentCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE s.category != 'Donation'")->fetchColumn();
$yearlyRevenue = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();
$pendingCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.status_id = 1 AND s.category != 'Donation'")->fetchColumn();

$byRole = db()->query(
    "SELECT r.role_name, COUNT(*) AS total FROM users u JOIN roles r ON u.role_id = r.role_id GROUP BY r.role_name"
)->fetchAll();

$recentLogs = db()->query(
    "SELECT l.*, u.firstname, u.lastname FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id
     ORDER BY l.created_at DESC LIMIT 10"
)->fetchAll();

// This Admin may also be a serving Priest (priests.user_id linked to this
// account) — surface their own duty schedule alongside the admin overview.
$priestId = currentPriestId();
$today = date('Y-m-d');
$priestTodayCount = 0;
$priestWeekCount = 0;
$priestUpcoming = [];
if ($priestId) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND appointment_date = ? AND status_id = 5");
    $stmt->execute([$priestId, $today]);
    $priestTodayCount = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND appointment_date >= ? AND appointment_date < ? AND status_id = 5");
    $stmt->execute([$priestId, $today, date('Y-m-d', strtotime('+7 days'))]);
    $priestWeekCount = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name, st.status_name,
                COALESCE(u.firstname || ' ' || u.lastname, a.guest_name) AS parishioner_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN appointment_status st ON a.status_id = st.status_id
         LEFT JOIN parishioners par ON a.parishioner_id = par.parishioner_id
         LEFT JOIN users u ON par.user_id = u.user_id
         WHERE a.priest_id = ? AND a.appointment_date >= ? AND a.status_id = 5
           AND s.category IN ('Wedding', 'Baptism', 'Funeral', 'Blessing')
         ORDER BY a.appointment_date, a.appointment_time, a.appointment_id LIMIT 10"
    );
    $stmt->execute([$priestId, $today]);
    $priestUpcoming = $stmt->fetchAll();
}

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
            <tr><td><?= e(roleLabel($r['role_name'])) ?></td><td><?= $r['total'] ?></td></tr>
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

<?php if ($priestId): ?>
<div class="card">
  <div class="card-header">
    <h3>✝️ My Priest Schedule &amp; Duties</h3>
    <div class="flex gap-2">
      <a href="<?= url('priest/calendar.php') ?>" class="btn btn-outline btn-sm">View My Calendar</a>
      <a href="<?= url('secretary/priest-unavailability.php') ?>" class="btn btn-outline btn-sm">Set My Unavailability / Off Days</a>
    </div>
  </div>
  <div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Today's Confirmed Appointments</div><div class="stat-value"><?= $priestTodayCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Confirmed This Week</div><div class="stat-value"><?= $priestWeekCount ?></div></div>
  </div>
  <?php if (empty($priestUpcoming)): ?>
    <div class="empty-state"><div class="icon">📅</div><p>No upcoming confirmed appointments assigned to you.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Parishioner</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($priestUpcoming as $a): ?>
            <tr>
              <td><?= formatDate($a['appointment_date']) ?></td>
              <td><?= $a['appointment_time'] ? date('g:i A', strtotime($a['appointment_time'])) : '—' ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= e($a['parishioner_name'] ?: '—') ?></td>
              <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="alert" style="background: var(--cream-dark); color: var(--brown-dark); border: 1px solid var(--border, #ddd);">
  To sync your serving schedule, link this Admin user to your Priest profile under <a href="<?= url('admin/priests.php') ?>">Manage Priests</a>.
</div>
<?php endif; ?>

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
