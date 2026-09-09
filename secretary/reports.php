<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['weekly', 'monthly', 'yearly'], true)) {
    $period = 'monthly';
}

$periodSql = match ($period) {
    'weekly' => 'YEARWEEK(appointment_date, 1)',
    'yearly' => 'YEAR(appointment_date)',
    default => "DATE_FORMAT(appointment_date, '%Y-%m')",
};

$trend = db()->query(
    "SELECT $periodSql AS period, COUNT(*) AS total
     FROM appointments
     GROUP BY period ORDER BY period DESC LIMIT 12"
)->fetchAll();

$byService = db()->query(
    "SELECT s.service_name, s.category, COUNT(*) AS total FROM appointments a JOIN services s ON a.service_id = s.service_id
     GROUP BY s.service_name, s.category ORDER BY total DESC"
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

<div class="flex-between mb-3 no-print">
  <div></div>
  <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print Report</button>
</div>

<div class="card">
  <div class="card-header">
    <h3>Appointments Generated — <?= ucfirst($period) ?></h3>
    <form method="GET" class="no-print">
      <select name="period" onchange="this.form.submit()">
        <option value="weekly" <?= $period==='weekly'?'selected':'' ?>>Weekly</option>
        <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Monthly</option>
        <option value="yearly" <?= $period==='yearly'?'selected':'' ?>>Yearly</option>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= ucfirst($period) ?></th><th>Appointments</th></tr></thead>
      <tbody>
        <?php foreach ($trend as $r): ?>
          <tr><td><?= e(formatReportPeriod($period, $r['period'])) ?></td><td><?= $r['total'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($trend)): ?><p class="text-muted text-center mt-3">No appointments yet.</p><?php endif; ?>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header"><h3>Services Summary</h3></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Category</th><th>Total Bookings</th></tr></thead>
        <tbody>
          <?php foreach ($byService as $r): ?>
            <tr><td><?= e($r['service_name']) ?></td><td><?= e($r['category']) ?></td><td><?= $r['total'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Appointment Summary — By Status</h3></div>
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
