<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/requirements-review.php';
requireRole('Secretary', 'Admin');

// Requirements review queues: distinct pending appointments (Mass Intentions and
// Donations are the Cashier's), by appointments.requirements_review_status.
$reviewEnabled = requirementsReviewAvailable();
$reviewCounts = ['pending_review' => 0, 'awaiting_revision' => 0, 'ready_for_rereview' => 0];
$reviewBase = "FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.status_id = 1 AND s.category NOT IN ('Mass Intention', 'Donation')";
if ($reviewEnabled) {
    $reviewRows = db()->query("SELECT COALESCE(a.requirements_review_status, 'pending_review') AS review_status, COUNT(DISTINCT a.appointment_id) AS total $reviewBase GROUP BY 1")->fetchAll();
    foreach ($reviewRows as $row) $reviewCounts[$row['review_status']] = (int) $row['total'];
} else {
    $reviewCounts['pending_review'] = (int) db()->query("SELECT COUNT(DISTINCT a.appointment_id) $reviewBase")->fetchColumn();
}

$pendingCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.status_id = 1 AND s.category != 'Donation'")->fetchColumn();
$todayCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_date::date = CURRENT_DATE AND s.category != 'Donation'")->fetchColumn();
$weekCount = db()->query("SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE EXTRACT(ISOYEAR FROM a.appointment_date)::int * 100 + EXTRACT(WEEK FROM a.appointment_date)::int = EXTRACT(ISOYEAR FROM CURRENT_DATE)::int * 100 + EXTRACT(WEEK FROM CURRENT_DATE)::int AND s.category != 'Donation'")->fetchColumn();

$recent = db()->query(
    "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, st.status_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE s.category != 'Donation'
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();

$active = 'dashboard';
$pageTitle = 'Secretary Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= (int)$pendingCount ?></div></div>
  <div class="stat-card"><div class="stat-label">Today's Appointments</div><div class="stat-value"><?= (int)$todayCount ?></div></div>
  <div class="stat-card"><div class="stat-label">This Week</div><div class="stat-value"><?= (int)$weekCount ?></div></div>
</div>

<?php if (!$reviewEnabled || !emailDeliveryConfigured()): ?>
  <div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2; margin-bottom:16px;">
    <?php if (!$reviewEnabled): ?><strong>Requirements review is not active yet:</strong> the database update (database/migration_requirements_review.sql) has not been run, so revision requests and the Awaiting Revision / Ready for Re-Review queues are unavailable.<br><?php endif; ?>
    <?php if (!emailDeliveryConfigured()): ?><strong>Email is not configured on this server:</strong> revision-request emails to guests and parishioners cannot be sent until BREVO_API_KEY and BREVO_SENDER_EMAIL are set.<?php endif; ?>
  </div>
<?php endif; ?>

<?php // Before the migration there are no real counts: show a dash, never a made-up 0.
$reviewCount = fn(string $key) => $reviewEnabled ? (string) $reviewCounts[$key] : '—'; ?>
<div class="stat-grid">
  <a class="stat-card stat-card-link review-pending" href="<?= url('secretary/appointments.php?status=' . urlencode('review:pending_review')) ?>"><div class="stat-label">Pending Review</div><div class="stat-value"><?= $reviewCount('pending_review') ?></div></a>
  <a class="stat-card stat-card-link review-awaiting" href="<?= url('secretary/appointments.php?status=' . urlencode('review:awaiting_revision')) ?>"><div class="stat-label">Awaiting Revision</div><div class="stat-value"><?= $reviewCount('awaiting_revision') ?></div></a>
  <a class="stat-card stat-card-link review-ready" href="<?= url('secretary/appointments.php?status=' . urlencode('review:ready_for_rereview')) ?>"><div class="stat-label">Ready for Re-Review</div><div class="stat-value"><?= $reviewCount('ready_for_rereview') ?></div></a>
</div>

<div class="card">
  <div class="card-header">
    <h3>Recent Appointment Requests</h3>
    <a href="<?= url('secretary/appointments.php') ?>" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Parishioner</th><th>Service</th><th>Date</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent as $a): ?>
          <tr>
            <td>#<?= $a['appointment_id'] ?></td>
            <td><?= $a['guest_name'] ? e($a['guest_name']) . ' <span class="text-muted">(guest)</span>' : e($a['firstname']) . ' ' . e($a['lastname']) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= formatDate($a['appointment_date']) ?></td>
            <td>
              <?php if ($a['category'] === 'Mass Intention'): ?>
                <?php $miStatus = massIntentionStatusDisplay($a['status_name'], true, currentUser()['role_name'] === 'Secretary'); ?>
                <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
              <?php else: ?>
                <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
              <?php endif; ?>
              <?php if ((int) $a['status_id'] === 1): ?>
                <?php if (in_array($a['requirements_review_status'] ?? null, ['awaiting_revision', 'ready_for_rereview'], true)):
                  $reviewBadge = requirementsReviewState($a['requirements_review_status'], ['rejected' => 0, 'pending' => 0, 'approved' => 0]); ?>
                  <span class="badge badge-<?= e($reviewBadge['badge']) ?>"><?= e($reviewBadge['label']) ?></span>
                <?php elseif (!in_array($a['category'], ['Mass Intention', 'Donation'], true)): ?>
                  <span class="badge badge-pending">Unreviewed</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" data-title="<?= e('Appointment #' . $a['appointment_id'] . ' — ' . $a['service_name']) ?>">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/secretary-modal-shells.php'; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
