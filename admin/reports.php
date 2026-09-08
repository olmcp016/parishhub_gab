<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$revenueByMonth = db()->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
     FROM payments WHERE payment_status='verified' GROUP BY month ORDER BY month DESC LIMIT 12"
)->fetchAll();

$revenueByMethod = db()->query(
    "SELECT pm.method_name, SUM(p.amount) AS total, COUNT(*) AS count
     FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id
     WHERE p.payment_status='verified' GROUP BY pm.method_name ORDER BY total DESC"
)->fetchAll();

$revenueByService = db()->query(
    "SELECT s.service_name, SUM(p.amount) AS total
     FROM payments p JOIN appointments a ON p.appointment_id = a.appointment_id JOIN services s ON a.service_id = s.service_id
     WHERE p.payment_status='verified' GROUP BY s.service_name ORDER BY total DESC"
)->fetchAll();

$totalIncome = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified'")->fetchColumn();
$cancelledCount = db()->query('SELECT COUNT(*) FROM appointments WHERE status_id = 7')->fetchColumn();
$completedCount = db()->query('SELECT COUNT(*) FROM appointments WHERE status_id = 6')->fetchColumn();

$active = 'reports';
$pageTitle = 'Financial Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="flex-between mb-3 no-print">
  <div></div>
  <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print / Export Report</button>
</div>

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Total Verified Income</div><div class="stat-value"><?= money((float) $totalIncome) ?></div></div>
  <div class="stat-card light"><div class="stat-label">Completed Appointments</div><div class="stat-value"><?= (int)$completedCount ?></div></div>
  <div class="stat-card light"><div class="stat-label">Cancelled Appointments</div><div class="stat-value"><?= (int)$cancelledCount ?></div></div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Income Summary — By Month</h3></div>
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
    <div class="card-header"><h3>Income Summary — By Payment Method</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($revenueByMethod as $r): ?>
            <tr><td><?= e($r['method_name']) ?></td><td><?= $r['count'] ?></td><td><?= money($r['total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Income Summary — By Service</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Total Revenue</th></tr></thead>
      <tbody>
        <?php foreach ($revenueByService as $r): ?>
          <tr><td><?= e($r['service_name']) ?></td><td><?= money($r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
