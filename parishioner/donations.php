<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT a.appointment_id, a.created_at, d.purpose, d.message, p.amount, p.payment_status, pm.method_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN donations d ON d.appointment_id = a.appointment_id
     LEFT JOIN payments p ON p.appointment_id = a.appointment_id
     LEFT JOIN payment_methods pm ON p.method_id = pm.method_id
     WHERE a.parishioner_id = ? AND s.category = 'Donation'
     ORDER BY a.created_at DESC"
);
$stmt->execute([$parishionerId]);
$donations = $stmt->fetchAll();

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

$active = 'donations';
$pageTitle = 'My Donations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>My Donations</h3>
    <?php if ($donationEnabled): ?>
      <a href="<?= url('parishioner/donate.php') ?>" class="btn btn-primary btn-sm">🤲 Donate Now</a>
    <?php endif; ?>
  </div>

  <?php if (empty($donations)): ?>
    <div class="empty-state">
      <div class="icon">🤲</div>
      <p>You haven't made any donations yet.</p>
      <?php if ($donationEnabled): ?>
        <a href="<?= url('parishioner/donate.php') ?>" class="btn btn-primary btn-sm">Donate to Our Parish</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Purpose</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($donations as $d): ?>
            <tr>
              <td><?= formatDate($d['created_at']) ?></td>
              <td><?= e($d['purpose']) ?></td>
              <td><?= money((float) $d['amount']) ?></td>
              <td><?= e($d['method_name'] ?? '—') ?></td>
              <td><?php if ($d['payment_status']): ?><span class="badge badge-<?= e($d['payment_status']) ?>"><?= e($d['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <td><a href="<?= url('parishioner/appointment-detail.php?id=' . $d['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
