<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$stmt = db()->query("SELECT * FROM announcements WHERE status='published' ORDER BY is_pinned DESC, created_at DESC LIMIT 5");
$announcements = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT a.*, s.service_name, st.status_name, p.full_name AS priest_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     LEFT JOIN priests p ON a.priest_id = p.priest_id
     WHERE a.parishioner_id = ? AND a.appointment_date >= CURDATE()
     ORDER BY a.appointment_date ASC LIMIT 5"
);
$stmt->execute([$parishionerId]);
$upcoming = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT
       SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) AS pending,
       SUM(CASE WHEN status_id IN (2,4,5) THEN 1 ELSE 0 END) AS active,
       SUM(CASE WHEN status_id = 6 THEN 1 ELSE 0 END) AS completed
     FROM appointments WHERE parishioner_id = ?"
);
$stmt->execute([$parishionerId]);
$stats = $stmt->fetch() ?: ['pending' => 0, 'active' => 0, 'completed' => 0];

$active = 'dashboard';
$pageTitle = 'My Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Pending Requests</div><div class="stat-value"><?= (int)($stats['pending'] ?? 0) ?></div></div>
  <div class="stat-card"><div class="stat-label">Active Appointments</div><div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div></div>
  <div class="stat-card"><div class="stat-label">Completed Services</div><div class="stat-value"><?= (int)($stats['completed'] ?? 0) ?></div></div>
</div>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap:22px;" class="mb-4">
  <div class="card">
    <div class="card-header">
      <h3>Upcoming Appointments</h3>
      <a href="<?= url('parishioner/appointments.php') ?>" class="btn btn-outline btn-sm">View All</a>
    </div>
    <?php if (empty($upcoming)): ?>
      <div class="empty-state">
        <div class="icon">📅</div>
        <p>No upcoming appointments.</p>
        <a href="<?= url('parishioner/book.php') ?>" class="btn btn-primary btn-sm">Book a Service</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Service</th><th>Date</th><th>Priest</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($upcoming as $a): ?>
              <tr onclick="location.href='<?= url('parishioner/appointment-detail.php?id=' . $a['appointment_id']) ?>'" style="cursor:pointer;">
                <td><?= e($a['service_name']) ?></td>
                <td><?= formatDate($a['appointment_date']) ?> · <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                <td><?= e($a['priest_name'] ?? 'Not yet assigned') ?></td>
                <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span> <?php if ($a['status_name'] === 'Approved'): ?> <a href="<?= url('parishioner/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-primary btn-sm" style="margin-left:8px;" onclick="event.stopPropagation();">Proceed to Payment</a> <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><h3>Announcements</h3></div>
    <?php if (empty($announcements)): ?>
      <p class="text-muted">No announcements yet.</p>
    <?php endif; ?>
    <?php foreach ($announcements as $a): ?>
      <div class="mb-3">
        <strong><?= e($a['title']) ?></strong>
        <p class="text-muted" style="font-size:13px; margin: 4px 0;"><?= formatDate($a['created_at']) ?></p>
        <p style="font-size:13.5px;"><?= e(mb_strlen($a['content']) > 100 ? mb_substr($a['content'],0,100).'…' : $a['content']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Quick Actions</h3></div>
  <div class="flex gap-3" style="flex-wrap:wrap;">
    <a href="<?= url('parishioner/book.php') ?>" class="btn btn-primary">📝 Book Appointment</a>
    <a href="<?= url('parishioner/services.php') ?>" class="btn btn-outline">🕊️ View Services</a>
    <a href="<?= url('parishioner/calendar.php') ?>" class="btn btn-outline">🗓️ Parish Calendar</a>
    <a href="<?= url('parishioner/profile.php') ?>" class="btn btn-outline">👤 Edit Profile</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
