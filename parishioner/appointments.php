<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT a.*, s.service_name, s.fee, st.status_name, p.full_name AS priest_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     LEFT JOIN priests p ON a.priest_id = p.priest_id
     WHERE a.parishioner_id = ?
     ORDER BY a.appointment_date DESC"
);
$stmt->execute([$parishionerId]);
$appointments = $stmt->fetchAll();

$active = 'appointments';
$pageTitle = 'My Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>My Appointments</h3>
    <a href="<?= url('parishioner/book.php') ?>" class="btn btn-primary btn-sm">+ New Booking</a>
  </div>

  <?php if (empty($appointments)): ?>
    <div class="empty-state">
      <div class="icon">📅</div>
      <p>You haven't booked any appointments yet.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Service</th><th>Date & Time</th><th>Priest</th><th>Fee</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td>#<?= $a['appointment_id'] ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?> · <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><?= e($a['priest_name'] ?? '—') ?></td>
              <td><?= money($a['fee']) ?></td>
              <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
              <td><a href="<?= url('parishioner/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
