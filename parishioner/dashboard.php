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

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

$stmt = db()->prepare(
    "SELECT a.*, s.service_name, st.status_name, p.full_name AS priest_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     LEFT JOIN priests p ON a.priest_id = p.priest_id
     WHERE a.parishioner_id = ? AND a.appointment_date >= CURDATE() AND s.category != 'Donation'
     ORDER BY a.appointment_date ASC LIMIT 5"
);
$stmt->execute([$parishionerId]);
$upcoming = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT
       SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) AS pending,
       SUM(CASE WHEN status_id IN (2,4,5) THEN 1 ELSE 0 END) AS active,
       SUM(CASE WHEN status_id = 6 THEN 1 ELSE 0 END) AS completed
     FROM appointments a JOIN services s ON a.service_id = s.service_id
     WHERE a.parishioner_id = ? AND s.category != 'Donation'"
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

  <div style="display:flex; flex-direction:column; gap:22px;">
    <div class="card">
      <div class="card-header">
        <h3>Announcements</h3>
        <a href="<?= url('parishioner/announcements.php') ?>" class="btn btn-outline btn-sm">View All</a>
      </div>
      <?php if (empty($announcements)): ?>
        <div class="empty-state">
          <div class="icon">📢</div>
          <p>No announcements yet.</p>
        </div>
      <?php else: ?>
        <?php foreach ($announcements as $i => $a): ?>
          <div style="padding: 14px 0; <?= $i > 0 ? 'border-top: 1px solid var(--cream-dark);' : 'padding-top: 0;' ?>">
            <div class="flex-between" style="align-items:flex-start; gap:8px;">
              <strong style="font-size:14px; line-height:1.3;"><?= e($a['title']) ?></strong>
              <?php if ($a['is_pinned']): ?><span class="badge badge-pending" style="flex-shrink:0;">Pinned</span><?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:12px; margin: 4px 0 6px;"><?= formatDate($a['created_at']) ?></p>
            <p style="font-size:13.5px; line-height:1.5; color: var(--brown-mid); margin:0;"><?= e(mb_strlen($a['content']) > 110 ? mb_substr($a['content'],0,110).'…' : $a['content']) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>Services</h3></div>
      <p style="font-size:13.5px; color: var(--brown-mid); margin: 0 0 14px;">Browse our sacraments and parish offerings, or book an appointment anytime.</p>
      <a href="<?= url('parishioner/services.php') ?>" class="btn btn-outline btn-block mb-3">View All Services</a>

      <?php if ($donationEnabled): ?>
        <div style="background: linear-gradient(135deg, var(--cream-dark), #faf0d0); border-radius: 10px; padding: 18px; text-align:center;">
          <div style="font-size:26px; margin-bottom:6px;">🤲</div>
          <h4 style="margin:0 0 4px;">Donate to Our Parish</h4>
          <p style="font-size:12.5px; color: var(--brown-mid); margin:0 0 14px;">Support our ministries with a voluntary offering.</p>
          <a href="<?= url('parishioner/donate.php') ?>" class="btn btn-primary btn-block">Donate Now</a>
        </div>
      <?php endif; ?>
    </div>
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
