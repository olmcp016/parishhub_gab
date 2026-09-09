<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$dateFilter = $_GET['date'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status_id,
               st.status_name, u.firstname, u.lastname,
               mi.intention_type, mi.offerer_name, mi.intention_for, mi.message,
               p.payment_status
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN mass_intentions mi ON mi.appointment_id = a.appointment_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN appointment_status st ON a.status_id = st.status_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE s.category = 'Mass Intention'";
$params = [];
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
  <form method="GET" class="form-row mb-3">
    <div class="form-group"><label>Mass Date</label><input type="date" name="date" value="<?= e($dateFilter) ?>"></div>
    <div class="form-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Parishioner, offerer, or intention for..."></div>
    <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Filter</button></div>
    <?php if ($dateFilter || $search): ?>
      <div class="form-group" style="align-self:end;"><a href="<?= url('secretary/mass-intentions.php') ?>" class="btn btn-outline">Clear</a></div>
    <?php endif; ?>
  </form>

  <?php if (empty($intentions)): ?>
    <p class="text-muted text-center mt-3">No Mass Intentions found<?= $dateFilter ? ' for this date' : '' ?>.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Offerer</th><th>Intention For</th><th>Requested By</th><th>Payment</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($intentions as $row): ?>
            <tr>
              <td><?= formatDate($row['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($row['appointment_time'])) ?></td>
              <td><?= e($row['intention_type']) ?></td>
              <td><?= e($row['offerer_name']) ?></td>
              <td><?= e($row['intention_for']) ?></td>
              <td><?= e($row['firstname']) ?> <?= e($row['lastname']) ?></td>
              <td><?php if ($row['payment_status']): ?><span class="badge badge-<?= e($row['payment_status']) ?>"><?= e($row['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <td><span class="badge badge-<?= badgeClass($row['status_name']) ?>"><?= e($row['status_name']) ?></span></td>
              <td><a href="<?= url('secretary/appointment-detail.php?id=' . $row['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
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

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
