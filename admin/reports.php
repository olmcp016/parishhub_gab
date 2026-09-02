<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$revenueByMonth = db()->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
     FROM payments WHERE payment_status='verified' GROUP BY month ORDER BY month DESC LIMIT 12"
)->fetchAll();

$topServices = db()->query(
    "SELECT s.service_name, COUNT(*) AS total FROM appointments a JOIN services s ON a.service_id = s.service_id
     GROUP BY s.service_name ORDER BY total DESC LIMIT 10"
)->fetchAll();

$cancelledCount = db()->query('SELECT COUNT(*) FROM appointments WHERE status_id = 7')->fetchColumn();
$completedCount = db()->query('SELECT COUNT(*) FROM appointments WHERE status_id = 6')->fetchColumn();

$active = 'reports';
$pageTitle = 'System Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Completed Appointments</div><div class="stat-value"><?= (int)$completedCount ?></div></div>
  <div class="stat-card light"><div class="stat-label">Cancelled Appointments</div><div class="stat-value"><?= (int)$cancelledCount ?></div></div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Monthly Revenue</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Month</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($revenueByMonth as $r): ?>
            <tr><td><?= e($r['month']) ?></td><td><?= money($r['total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Top Requested Services</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($topServices as $r): ?>
            <tr><td><?= e($r['service_name']) ?></td><td><?= $r['total'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
