<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$pendingCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.status_id = 1 AND s.category != 'Donation'")->fetchColumn();
$todayCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_date = CURDATE() AND s.category != 'Donation'")->fetchColumn();
$weekCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE YEARWEEK(a.appointment_date, 1) = YEARWEEK(CURDATE(), 1) AND s.category != 'Donation'")->fetchColumn();

$recent = db()->query(
    "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, st.status_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE s.category != 'Donation'
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
            <td><?= $a['guest_name'] ? e($a['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($a['firstname']) . ' ' . e($a['lastname']) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= formatDate($a['appointment_date']) ?></td>
            <td>
              <?php if ($a['category'] === 'Mass Intention'): ?>
                <?php $miStatus = massIntentionStatusDisplay($a['status_name'], true, currentUser()['role_name'] === 'Secretary'); ?>
                <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
              <?php else: ?>
                <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
              <?php endif; ?>
            </td>
            <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" data-title="<?= e('Appointment #' . $a['appointment_id'] . ' — ' . $a['service_name']) ?>">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
