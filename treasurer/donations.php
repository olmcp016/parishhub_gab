<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT a.appointment_id, a.created_at, d.donor_name, d.donor_email, d.purpose, d.message,
               p.amount, p.payment_status, p.payment_id, pm.method_name
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN donations d ON d.appointment_id = a.appointment_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        LEFT JOIN payment_methods pm ON p.method_id = pm.method_id
        WHERE s.category = 'Donation'";
$params = [];
if ($statusFilter) { $sql .= ' AND p.payment_status = ?'; $params[] = $statusFilter; }
if ($search) {
    $sql .= ' AND (d.donor_name LIKE ? OR d.donor_email LIKE ? OR d.purpose LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= ' ORDER BY a.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$donations = $stmt->fetchAll();

$totalVerified = db()->query(
    "SELECT COALESCE(SUM(p.amount),0) FROM payments p
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN services s ON a.service_id = s.service_id
     WHERE s.category = 'Donation' AND p.payment_status = 'verified'"
)->fetchColumn();

$active = 'donations';
$pageTitle = 'Donations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Total Verified Donations</div><div class="stat-value"><?= money((float) $totalVerified) ?></div></div>
  <div class="stat-card light"><div class="stat-label">Total Donations Received</div><div class="stat-value"><?= count($donations) ?></div></div>
</div>

<div class="card">
  <div class="card-header"><h3>Donations</h3></div>

  <form method="GET" class="form-row mb-3">
    <div class="form-group">
      <label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
        <option value="verified" <?= $statusFilter==='verified'?'selected':'' ?>>Verified</option>
        <option value="failed" <?= $statusFilter==='failed'?'selected':'' ?>>Failed</option>
        <option value="refunded" <?= $statusFilter==='refunded'?'selected':'' ?>>Refunded</option>
      </select>
    </div>
    <div class="form-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Donor name, email, or purpose"></div>
    <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Search</button></div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Donor</th><th>Purpose</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($donations as $d): ?>
          <tr>
            <td><?= formatDate($d['created_at']) ?></td>
            <td><?= e($d['donor_name'] ?: 'Anonymous') ?></td>
            <td><?= e($d['purpose']) ?></td>
            <td><?= money((float) $d['amount']) ?></td>
            <td><?= e($d['method_name'] ?? '—') ?></td>
            <td><?php if ($d['payment_status']): ?><span class="badge badge-<?= e($d['payment_status']) ?>"><?= e($d['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td>
              <?php if ($d['payment_id']): ?>
                <a href="<?= url('treasurer/payment-detail.php?id=' . $d['payment_id']) ?>" class="btn btn-outline btn-sm">View</a>
              <?php else: ?>
                <a href="<?= url('secretary/appointment-detail.php?id=' . $d['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($donations)): ?><p class="text-muted text-center mt-3">No donations found.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
