<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$monthlyTrend = db()->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
     FROM payments WHERE payment_status='verified' GROUP BY month ORDER BY month DESC LIMIT 12"
)->fetchAll();

$byMethod = db()->query(
    "SELECT pm.method_name, SUM(p.amount) AS total, COUNT(*) AS count
     FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id
     WHERE p.payment_status='verified' GROUP BY pm.method_name"
)->fetchAll();

$byService = db()->query(
    "SELECT s.service_name, SUM(p.amount) AS total
     FROM payments p JOIN appointments a ON p.appointment_id = a.appointment_id JOIN services s ON a.service_id = s.service_id
     WHERE p.payment_status='verified' GROUP BY s.service_name ORDER BY total DESC"
)->fetchAll();

$active = 'reports';
$pageTitle = 'Financial Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Revenue by Month</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Month</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($monthlyTrend as $r): ?>
            <tr><td><?= e($r['month']) ?></td><td><?= money($r['total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Revenue by Payment Method</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($byMethod as $r): ?>
            <tr><td><?= e($r['method_name']) ?></td><td><?= $r['count'] ?></td><td><?= money($r['total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Revenue by Service</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Total Revenue</th></tr></thead>
      <tbody>
        <?php foreach ($byService as $r): ?>
          <tr><td><?= e($r['service_name']) ?></td><td><?= money($r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
