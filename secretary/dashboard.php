<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$pendingCount = db()->query("SELECT COUNT(*) FROM appointments WHERE status_id = 1")->fetchColumn();
$todayCount = db()->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$weekCount = db()->query("SELECT COUNT(*) FROM appointments WHERE YEARWEEK(appointment_date, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();

$recent = db()->query(
    "SELECT a.*, s.service_name, u.firstname, u.lastname, st.status_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN appointment_status st ON a.status_id = st.status_id
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();

$active = 'dashboard';
$pageTitle = 'Secretary Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= (int)$pendingCount ?></div></div>
  <div class="stat-card"><div class="stat-label">Today's Appointments</div><div class="stat-value"><?= (int)$todayCount ?></div></div>
  <div class="stat-card"><div class="stat-label">This Week</div><div class="stat-value"><?= (int)$weekCount ?></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Recent Appointment Requests</h3>
    <a href="<?= url('secretary/appointments.php') ?>" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Parishioner</th><th>Service</th><th>Date</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent as $a): ?>
          <tr>
            <td>#<?= $a['appointment_id'] ?></td>
            <td><?= e($a['firstname']) ?> <?= e($a['lastname']) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= formatDate($a['appointment_date']) ?></td>
            <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
            <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
