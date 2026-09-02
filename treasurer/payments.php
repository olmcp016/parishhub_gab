<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    verifyCsrf();
    $appointmentId = (int) $_POST['appointment_id'];
    $ref = trim($_POST['reference_number'] ?? '') ?: ('CASH-' . time());
    db()->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date, verified_by, verified_at)
         VALUES (?, ?, ?, ?, 'verified', NOW(), ?, NOW())"
    )->execute([$appointmentId, $ref, $_POST['amount'], $_POST['method_id'], $userId]);
    db()->prepare("UPDATE appointments SET status_id = 4 WHERE appointment_id = ?")->execute([$appointmentId]);
    logActivity($userId, "Recorded manual payment for appointment #$appointmentId", 'Payments');
    flash('success', 'Payment recorded.');
    redirect(url('treasurer/payments.php'));
}

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT p.*, pm.method_name, u.firstname, u.lastname, s.service_name, a.appointment_date
        FROM payments p
        JOIN payment_methods pm ON p.method_id = pm.method_id
        JOIN appointments a ON p.appointment_id = a.appointment_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN services s ON a.service_id = s.service_id
        WHERE 1=1";
$params = [];
if ($statusFilter) { $sql .= ' AND p.payment_status = ?'; $params[] = $statusFilter; }
if ($search) {
    $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR p.reference_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= ' ORDER BY p.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$active = 'payments';
$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Record Manual (Cash) Payment</h3></div>
  <form method="POST" action="<?= url('treasurer/payments.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="manual">
    <div class="form-group"><label>Appointment ID</label><input type="number" name="appointment_id" required></div>
    <div class="form-group"><label>Amount</label><input type="number" name="amount" step="0.01" required></div>
    <div class="form-group">
      <label>Method</label>
      <select name="method_id" required>
        <option value="1">Cash</option>
        <option value="2">GCash</option>
        <option value="3">Maya</option>
        <option value="4">Bank Transfer</option>
        <option value="5">Credit/Debit Card</option>
      </select>
    </div>
    <div class="form-group"><label>Reference # (optional)</label><input type="text" name="reference_number"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Record</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Payments</h3></div>

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
    <div class="form-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Name or reference #"></div>
    <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Search</button></div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Ref #</th><th>Parishioner</th><th>Service</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= e($p['reference_number']) ?></td>
            <td><?= e($p['firstname']) ?> <?= e($p['lastname']) ?></td>
            <td><?= e($p['service_name']) ?></td>
            <td><?= money($p['amount']) ?></td>
            <td><?= e($p['method_name']) ?></td>
            <td><span class="badge badge-<?= e($p['payment_status']) ?>"><?= e($p['payment_status']) ?></span></td>
            <td><a href="<?= url('treasurer/payment-detail.php?id=' . $p['payment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($payments)): ?><p class="text-muted text-center mt-3">No payments found.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
