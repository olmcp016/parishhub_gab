<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$byService = db()->query(
    "SELECT s.service_name, COUNT(*) AS total FROM appointments a JOIN services s ON a.service_id = s.service_id
     GROUP BY s.service_name ORDER BY total DESC"
)->fetchAll();

$byStatus = db()->query(
    "SELECT st.status_name, COUNT(*) AS total FROM appointments a JOIN appointment_status st ON a.status_id = st.status_id
     GROUP BY st.status_name"
)->fetchAll();

$active = 'reports';
$pageTitle = 'Appointment Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Most Requested Services</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Total Bookings</th></tr></thead>
        <tbody>
          <?php foreach ($byService as $r): ?>
            <tr><td><?= e($r['service_name']) ?></td><td><?= $r['total'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Appointments by Status</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Status</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($byStatus as $r): ?>
            <tr><td><span class="badge badge-<?= badgeClass($r['status_name']) ?>"><?= e($r['status_name']) ?></span></td><td><?= $r['total'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
