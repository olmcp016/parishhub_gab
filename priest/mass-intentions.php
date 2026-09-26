<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Priest');

/**
 * Item 23: the priest sees only current/upcoming Mass Intentions (approved
 * — Confirmed or Completed-today — for reading at Mass), never a mixed-in
 * pile of every completed one ever. Older ones move to a separate Archive
 * tab instead of one long active list. No print button here (item 23
 * explicitly removes it from the Priest account) — printing the read-at-
 * Mass sheet stays a Cashier/Secretary function via the Cashier's own page.
 */
$today = date('Y-m-d');
$view = ($_GET['view'] ?? 'current') === 'archive' ? 'archive' : 'current';

$sql = "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status_id, a.guest_name,
               st.status_name, u.firstname, u.lastname,
               mi.intention_type, mi.offerer_name, mi.intention_for, mi.message
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN mass_intentions mi ON mi.appointment_id = a.appointment_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN appointment_status st ON a.status_id = st.status_id
        WHERE s.category = 'Mass Intention' AND a.status_id IN (5, 6)";

if ($view === 'current') {
    $sql .= " AND a.appointment_date >= ?";
    $params = [$today];
} else {
    $sql .= " AND a.appointment_date < ?";
    $params = [$today];
}
$sql .= " ORDER BY a.appointment_date " . ($view === 'current' ? 'ASC' : 'DESC') . ", a.appointment_time ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$intentions = $stmt->fetchAll();

$active = 'mass-intentions';
$pageTitle = 'Mass Intentions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>Mass Intentions</h3>
    <div class="flex gap-2">
      <a href="<?= url('priest/mass-intentions.php?view=current') ?>" class="btn btn-sm <?= $view === 'current' ? 'btn-primary' : 'btn-outline' ?>">Current &amp; Upcoming</a>
      <a href="<?= url('priest/mass-intentions.php?view=archive') ?>" class="btn btn-sm <?= $view === 'archive' ? 'btn-primary' : 'btn-outline' ?>">Archive</a>
    </div>
  </div>

  <?php if (empty($intentions)): ?>
    <p class="text-muted text-center mt-3">No <?= $view === 'archive' ? 'past' : 'current or upcoming' ?> Mass Intentions.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Offerer</th><th>Intention For</th><th>Message</th><th>Requested By</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($intentions as $row): ?>
            <tr>
              <td><?= formatDate($row['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($row['appointment_time'])) ?></td>
              <td><?= e($row['intention_type']) ?></td>
              <td><?= e($row['offerer_name']) ?></td>
              <td><?= e($row['intention_for']) ?></td>
              <td style="max-width:220px;"><?= e($row['message'] ?: '—') ?></td>
              <td><?= $row['guest_name'] ? e($row['guest_name']) . ' (guest)' : e($row['firstname']) . ' ' . e($row['lastname']) ?></td>
              <td><span class="badge badge-<?= badgeClass($row['status_name']) ?>"><?= e($row['status_name']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
