<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$daily = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND payment_date::date = CURRENT_DATE")->fetchColumn();
$weekly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND EXTRACT(ISOYEAR FROM payment_date)::int * 100 + EXTRACT(WEEK FROM payment_date)::int = EXTRACT(ISOYEAR FROM CURRENT_DATE)::int * 100 + EXTRACT(WEEK FROM CURRENT_DATE)::int")->fetchColumn();
$monthly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND EXTRACT(MONTH FROM payment_date) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();
$yearly = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='verified' AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();
$pendingCount = db()->query("SELECT COUNT(*) FROM payments WHERE payment_status='pending'")->fetchColumn();

$pendingPayments = db()->query(
    "SELECT p.*, u.firstname, u.lastname, a.guest_name, s.service_name
     FROM payments p
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN services s ON a.service_id = s.service_id
     WHERE p.payment_status='pending'
     ORDER BY p.created_at ASC LIMIT 5"
)->fetchAll();

$recent = db()->query(
    "SELECT p.*, u.firstname, u.lastname, a.guest_name, s.service_name
     FROM payments p
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN services s ON a.service_id = s.service_id
     ORDER BY p.created_at DESC LIMIT 8"
)->fetchAll();

$active = 'dashboard';
$pageTitle = 'Cashier Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Today's Income</div><div class="stat-value"><?= money((float)$daily) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Week's Income</div><div class="stat-value"><?= money((float)$weekly) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Month's Income</div><div class="stat-value"><?= money((float)$monthly) ?></div></div>
  <div class="stat-card"><div class="stat-label">This Year's Income</div><div class="stat-value"><?= money((float)$yearly) ?></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Pending Verifications (<?= (int)$pendingCount ?>)</h3>
    <?php if ($pendingCount > 0): ?>
      <a href="<?= url('treasurer/payments.php?status=pending') ?>" class="btn btn-outline btn-sm">View All</a>
    <?php endif; ?>
  </div>
  <?php if ($pendingCount > 0): ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Parishioner</th><th>Service</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($pendingPayments as $p): ?>
          <tr>
            <td><?= $p['guest_name'] ? e($p['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($p['firstname']) . ' ' . e($p['lastname']) ?></td>
            <td><?= e($p['service_name']) ?></td>
            <td><?= money($p['amount']) ?></td>
            <td><span class="badge badge-<?= e($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
            <td><a href="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>"
              data-url="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>"
              data-title="Payment Details" class="btn btn-outline btn-sm js-view-modal">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div style="padding: 24px; text-align: center; color: var(--brown-light);">No pending verifications right now.</div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Recent Payments</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Parishioner</th><th>Service</th><th>Amount</th><th>Status</th><th>View</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $p): ?>
          <tr>
            <td><?= $p['guest_name'] ? e($p['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($p['firstname']) . ' ' . e($p['lastname']) ?></td>
            <td><?= e($p['service_name']) ?></td>
            <td><?= money($p['amount']) ?></td>
            <td><span class="badge badge-<?= e($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
            <td><a href="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>"
              data-url="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>"
              data-title="Payment Details" class="btn btn-outline btn-sm js-view-modal">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
