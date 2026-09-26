<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Priest');

$priestId = currentPriestId();
$today = date('Y-m-d');

$todayCount = 0;
$weekCount = 0;
$upcoming = [];
if ($priestId) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND appointment_date = ? AND status_id NOT IN (3, 7)");
    $stmt->execute([$priestId, $today]);
    $todayCount = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND appointment_date >= ? AND appointment_date < ? AND status_id NOT IN (3, 7)");
    $stmt->execute([$priestId, $today, date('Y-m-d', strtotime('+7 days'))]);
    $weekCount = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name, st.status_name,
                COALESCE(u.firstname || ' ' || u.lastname, a.guest_name) AS parishioner_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN appointment_status st ON a.status_id = st.status_id
         LEFT JOIN parishioners par ON a.parishioner_id = par.parishioner_id
         LEFT JOIN users u ON par.user_id = u.user_id
         WHERE a.priest_id = ? AND a.appointment_date >= ? AND a.status_id NOT IN (3, 7)
         ORDER BY a.appointment_date, a.appointment_time LIMIT 10"
    );
    $stmt->execute([$priestId, $today]);
    $upcoming = $stmt->fetchAll();
}

$active = 'dashboard';
$pageTitle = 'Priest Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (!$priestId): ?>
  <div class="alert" style="background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;">
    Your account isn't linked to a priest record yet. Please contact the parish office.
  </div>
<?php else: ?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Today's Appointments</div><div class="stat-value"><?= $todayCount ?></div></div>
  <div class="stat-card"><div class="stat-label">This Week</div><div class="stat-value"><?= $weekCount ?></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Your Upcoming Schedule</h3>
    <a href="<?= url('priest/calendar.php') ?>" class="btn btn-outline btn-sm">View Calendar</a>
  </div>
  <?php if (empty($upcoming)): ?>
    <div class="empty-state"><div class="icon">📅</div><p>No upcoming appointments assigned to you.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Parishioner</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($upcoming as $a): ?>
            <tr>
              <td><?= formatDate($a['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= e($a['parishioner_name'] ?: '—') ?></td>
              <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Today's Mass Intentions</h3></div>
  <p class="helper-text" style="margin-top:-6px;">See the full list, including upcoming ones, under <a href="<?= url('priest/mass-intentions.php') ?>">Mass Intentions</a>.</p>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
