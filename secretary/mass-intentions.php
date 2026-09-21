<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin', 'Treasurer');

$dateFilter = $_GET['date'] ?? '';
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$viewerRole = currentUser()['role_name'];
$isSecretaryViewer = $viewerRole === 'Secretary';
$isCashierViewer = $viewerRole === 'Treasurer';
$showPayment = !$isSecretaryViewer; // Cashier/Admin only — payments are the Cashier's job

$sql = "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status_id, a.guest_name,
               st.status_name, u.firstname, u.lastname,
               mi.intention_type, mi.offerer_name, mi.intention_for, mi.message,
               p.payment_id, p.payment_status, p.amount, p.method_id
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN mass_intentions mi ON mi.appointment_id = a.appointment_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN appointment_status st ON a.status_id = st.status_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE s.category = 'Mass Intention'";
$params = [];

if ($isSecretaryViewer) {
    // The Secretary's list is what gets read at Mass, so it only carries
    // intentions the Cashier has already approved (confirmed or completed) —
    // never one that is unpaid or still awaiting the Cashier. They see no
    // payment detail at all.
    $sql .= ' AND a.status_id IN (5, 6)';
} elseif ($isCashierViewer) {
    // Only Mass Intentions with a real payment transaction enter the
    // Cashier's list: a recorded payment of more than ₱0, and — for an online
    // (PayMongo) payment — only once PayMongo has actually confirmed it. An
    // unpaid, ₱0, or abandoned-checkout intention never shows as awaiting
    // Cashier approval.
    $sql .= " AND p.payment_id IS NOT NULL AND p.amount > 0
              AND (p.method_id <> 7 OR p.payment_status = 'verified')";
}
if ($showPayment && $statusFilter !== '') {
    $statusSql = [
        'pending' => 'a.status_id IN (2, 4)',
        'approved' => 'a.status_id IN (5, 6)',
        'rejected' => 'a.status_id = 3',
    ];
    if (isset($statusSql[$statusFilter])) {
        $sql .= ' AND ' . $statusSql[$statusFilter];
    }
}
if ($dateFilter) { $sql .= ' AND a.appointment_date = ?'; $params[] = $dateFilter; }
if ($search) {
    $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR mi.offerer_name LIKE ? OR mi.intention_for LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= ' ORDER BY a.appointment_date ASC, a.appointment_time ASC, mi.intention_id ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$intentions = $stmt->fetchAll();

// Group by date+time for the printable, read-during-Mass view.
$grouped = [];
foreach ($intentions as $row) {
    if (!in_array((int) $row['status_id'], [5, 6], true)) continue; // only approved intentions are ever read at Mass
    $key = $row['appointment_date'] . ' ' . $row['appointment_time'];
    $grouped[$key]['date'] = $row['appointment_date'];
    $grouped[$key]['time'] = $row['appointment_time'];
    $grouped[$key]['rows'][] = $row;
}

$active = 'mass-intentions';
$pageTitle = 'Mass Intentions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="flex-between mb-3 no-print">
  <h2 style="margin:0; font-family: var(--font-heading);">Mass Intentions</h2>
  <?php if ($dateFilter): ?>
    <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print for Mass</button>
  <?php endif; ?>
</div>

<div class="card no-print">
  <?php if ($isSecretaryViewer): ?>
    <p class="helper-text" style="margin-top:0;">Shows Mass Intentions the Cashier has approved — the ones to be read at Mass.</p>
  <?php endif; ?>
  <form method="GET" class="form-row mb-3">
    <div class="form-group"><label>Mass Date</label><input type="date" name="date" value="<?= e($dateFilter) ?>"></div>
    <?php if ($showPayment): ?>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="">All</option>
          <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending Cashier Verification</option>
          <option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option>
          <option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>Rejected</option>
        </select>
      </div>
    <?php endif; ?>
    <div class="form-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Parishioner, offerer, or intention for..."></div>
    <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Filter</button></div>
    <?php if ($dateFilter || $search || $statusFilter): ?>
      <div class="form-group" style="align-self:end;"><a href="<?= url('secretary/mass-intentions.php') ?>" class="btn btn-outline">Clear</a></div>
    <?php endif; ?>
  </form>

  <?php if (empty($intentions)): ?>
    <p class="text-muted text-center mt-3">No Mass Intentions found<?= $dateFilter ? ' for this date' : '' ?>.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Time</th><th>Type</th><th>Offerer</th><th>Intention For</th>
            <?php if ($showPayment): ?><th>Message</th><?php endif; ?>
            <th>Requested By</th>
            <?php if ($showPayment): ?><th>Amount</th><th>Payment</th><?php endif; ?>
            <th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($intentions as $row): ?>
            <?php [$miLabel, $miClass] = massIntentionStatusDisplay($row['status_name'], $row['payment_id'] ? true : false, $isSecretaryViewer); ?>
            <tr>
              <td><?= formatDate($row['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($row['appointment_time'])) ?></td>
              <td><?= e($row['intention_type']) ?></td>
              <td><?= e($row['offerer_name']) ?></td>
              <td><?= e($row['intention_for']) ?></td>
              <?php if ($showPayment): ?><td style="max-width:220px;"><?= e($row['message'] ?: '—') ?></td><?php endif; ?>
              <td><?= $row['guest_name'] ? e($row['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($row['firstname']) . ' ' . e($row['lastname']) ?></td>
              <?php if ($showPayment): ?>
                <td><?= $row['payment_id'] ? money((float) $row['amount']) : '<span class="text-muted">—</span>' ?></td>
                <td><?php if ($row['payment_status']): ?><span class="badge badge-<?= e($row['payment_status']) ?>"><?= e($row['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <?php endif; ?>
              <td><span class="badge badge-<?= $miClass ?>"><?= e($miLabel) ?></span></td>
              <td>
                <?php if ($isCashierViewer): ?>
                  <a href="<?= url('treasurer/payment-detail.php?id=' . $row['payment_id']) ?>" class="btn btn-outline btn-sm"><?= in_array((int) $row['status_id'], [2, 4], true) ? 'Review Payment' : 'View Payment' ?></a>
                <?php else: ?>
                  <a href="<?= url('secretary/appointment-detail.php?id=' . $row['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= url('secretary/appointment-detail.php?id=' . $row['appointment_id']) ?>" data-title="<?= e('Mass Intention — ' . formatDate($row['appointment_date']) . ' ' . date('g:i A', strtotime($row['appointment_time']))) ?>">View</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($dateFilter && !empty($grouped)): ?>
  <div class="print-only" style="display:none;">
    <?php foreach ($grouped as $group): ?>
      <div class="mass-print-block">
        <h2 class="mass-print-title">Mass Intentions — <?= formatDate($group['date']) ?> at <?= date('g:i A', strtotime($group['time'])) ?></h2>
        <ol class="mass-print-list">
          <?php foreach ($group['rows'] as $row): ?>
            <li><?= e(massIntentionReadingLine($row['intention_type'], $row['offerer_name'], $row['intention_for'])) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$isCashierViewer): ?>
<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
