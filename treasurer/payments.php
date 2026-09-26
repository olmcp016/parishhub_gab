<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    verifyCsrf();
    $appointmentId = (int) $_POST['appointment_id'];
    $ref = trim($_POST['reference_number'] ?? '') ?: ('CASH-' . time());
    $manualAmount = is_numeric($_POST['amount'] ?? '') ? (float) $_POST['amount'] : 0.0;

    // A manual payment can only be recorded against a real appointment that is
    // approved and still awaiting payment (nothing paid or pending yet), for an
    // amount above zero — never over a rejected/cancelled/already-paid one.
    $stmt = db()->prepare(
        "SELECT a.status_id, (SELECT COUNT(*) FROM payments p WHERE p.appointment_id = a.appointment_id AND p.payment_status IN ('pending', 'verified')) AS open_payments
         FROM appointments a WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $target = $stmt->fetch();
    if (!$target) {
        flash('error', "Appointment #$appointmentId was not found.");
        redirect(url('treasurer/payments.php'));
    }
    if (!($manualAmount > 0)) {
        flash('error', 'Please enter a payment amount greater than zero.');
        redirect(url('treasurer/payments.php'));
    }
    if ((int) $target['status_id'] !== 2 || (int) $target['open_payments'] > 0) {
        flash('error', "Appointment #$appointmentId is not awaiting payment (it may be unapproved, rejected, cancelled, or already paid). If a payment is pending, verify it from Transaction History instead.");
        redirect(url('treasurer/payments.php'));
    }
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
$methodFilter = $_GET['method_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT p.*, pm.method_name, u.firstname, u.lastname, a.guest_name, s.service_name, a.appointment_date
        FROM payments p
        JOIN payment_methods pm ON p.method_id = pm.method_id
        JOIN appointments a ON p.appointment_id = a.appointment_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN services s ON a.service_id = s.service_id
        WHERE 1=1";
$params = [];
if ($statusFilter) { $sql .= ' AND p.payment_status = ?'; $params[] = $statusFilter; }
if ($methodFilter) { $sql .= ' AND p.method_id = ?'; $params[] = $methodFilter; }
if ($dateFrom) { $sql .= ' AND p.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $sql .= ' AND p.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
if ($search) {
    $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR a.guest_name LIKE ? OR p.reference_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$countSql = str_replace(
    'SELECT p.*, pm.method_name, u.firstname, u.lastname, a.guest_name, s.service_name, a.appointment_date',
    'SELECT COUNT(*)',
    $sql
);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), 10);

$sql .= ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
$stmt->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

$paginationUrl = url('treasurer/payments.php') . '?' . http_build_query(array_filter([
    'status' => $statusFilter, 'method_id' => $methodFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search,
]));

$paymentMethods = db()->query('SELECT * FROM payment_methods ORDER BY method_id')->fetchAll();

$active = 'payments';
$pageTitle = currentUser()['role_name'] === 'Admin' ? 'Payment & Transaction Overview' : 'Transaction History';
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
        <option value="6">PayPal</option>
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
        <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option>
      </select>
    </div>
    <div class="form-group">
      <label>Payment Method</label>
      <select name="method_id" onchange="this.form.submit()">
        <option value="">All Methods</option>
        <?php foreach ($paymentMethods as $m): ?>
          <option value="<?= $m['method_id'] ?>" <?= (string)$methodFilter===(string)$m['method_id']?'selected':'' ?>><?= e($m['method_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>From Date</label><input type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
    <div class="form-group"><label>To Date</label><input type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
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
            <td><?= $p['guest_name'] ? e($p['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($p['firstname']) . ' ' . e($p['lastname']) ?></td>
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
  <?php if (empty($payments)): ?><p class="text-muted text-center mt-3">No payments found.</p><?php else: ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
