<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$daily = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND DATE(payment_date) = CURDATE()")->fetchColumn();
$weekly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND YEARWEEK(payment_date,1) = YEARWEEK(CURDATE(),1)")->fetchColumn();
$monthly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
$yearly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
$pendingCount = db()->query("SELECT COUNT(*) FROM payments WHERE payment_status='pending'")->fetchColumn();

$recent = db()->query(
    "SELECT p.*, u.firstname, u.lastname, s.service_name
     FROM payments p
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN services s ON a.service_id = s.service_id
     ORDER BY p.created_at DESC LIMIT 8"
)->fetchAll();

$active = 'dashboard';
$pageTitle = 'Treasurer Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Today's Revenue</div><div class="stat-value"><?= money((float)$daily) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Week</div><div class="stat-value"><?= money((float)$weekly) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Month</div><div class="stat-value"><?= money((float)$monthly) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Year</div><div class="stat-value"><?= money((float)$yearly) ?></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Pending Verifications (<?= (int)$pendingCount ?>)</h3>
    <a href="<?= url('treasurer/payments.php?status=pending') ?>" class="btn btn-outline btn-sm">View All</a>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Recent Payments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Parishioner</th><th>Service</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent as $p): ?>
          <tr>
            <td><?= e($p['firstname']) ?> <?= e($p['lastname']) ?></td>
            <td><?= e($p['service_name']) ?></td>
            <td><?= money($p['amount']) ?></td>
            <td><span class="badge badge-<?= e($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
            <td><a href="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
