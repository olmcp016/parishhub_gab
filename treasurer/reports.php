<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    $period = 'monthly';
}

$periodSql = match ($period) {
    'daily' => 'DATE(payment_date)',
    'weekly' => 'YEARWEEK(payment_date, 1)',
    'yearly' => 'YEAR(payment_date)',
    default => "DATE_FORMAT(payment_date, '%Y-%m')",
};

$incomeTrend = db()->query(
    "SELECT $periodSql AS period, SUM(amount) AS total
     FROM payments WHERE payment_status='verified' GROUP BY period ORDER BY period DESC LIMIT 14"
)->fetchAll();

$byMethod = db()->query(
    "SELECT pm.method_name, SUM(p.amount) AS total, COUNT(*) AS count
     FROM payments p JOIN payment_methods pm ON p.method_id = pm.method_id
     WHERE p.payment_status='verified' GROUP BY pm.method_name ORDER BY total DESC"
)->fetchAll();

$byService = db()->query(
    "SELECT s.service_name, s.category, SUM(p.amount) AS total, COUNT(*) AS count
     FROM payments p JOIN appointments a ON p.appointment_id = a.appointment_id JOIN services s ON a.service_id = s.service_id
     WHERE p.payment_status='verified' GROUP BY s.service_name, s.category ORDER BY total DESC"
)->fetchAll();

$totalIncome = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified'")->fetchColumn();

$active = 'reports';
$pageTitle = 'Financial Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="flex-between mb-3 no-print">
  <div></div>
  <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print Report</button>
</div>

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Total Verified Income (All Time)</div><div class="stat-value"><?= money((float) $totalIncome) ?></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Income Summary — <?= ucfirst($period) ?></h3>
    <form method="GET" class="no-print">
      <select name="period" onchange="this.form.submit()">
        <option value="daily" <?= $period==='daily'?'selected':'' ?>>Daily</option>
        <option value="weekly" <?= $period==='weekly'?'selected':'' ?>>Weekly</option>
        <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Monthly</option>
        <option value="yearly" <?= $period==='yearly'?'selected':'' ?>>Yearly</option>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= ucfirst($period) ?></th><th>Income</th></tr></thead>
      <tbody>
        <?php foreach ($incomeTrend as $r): ?>
          <tr><td><?= e($period === 'daily' ? formatDate($r['period']) : formatReportPeriod($period, $r['period'])) ?></td><td><?= money((float) $r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($incomeTrend)): ?><p class="text-muted text-center mt-3">No verified income yet.</p><?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Revenue by Payment Method</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($byMethod as $r): ?>
          <tr><td><?= e($r['method_name']) ?></td><td><?= $r['count'] ?></td><td><?= money((float) $r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Service Type Collection</h3></div>
  <p class="helper-text" style="margin-top:-6px;">Income collected, broken down by sacrament/service type — including Mass Intentions and Donations.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Category</th><th>Transactions</th><th>Total Collected</th></tr></thead>
      <tbody>
        <?php foreach ($byService as $r): ?>
          <tr><td><?= e($r['service_name']) ?></td><td><?= e($r['category']) ?></td><td><?= $r['count'] ?></td><td><?= money((float) $r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
