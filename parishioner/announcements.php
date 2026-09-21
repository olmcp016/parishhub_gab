<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
// Fully public — anyone can view parish announcements without an account.

// Compare against PHP's Asia/Manila "today" (config/config.php), not
// Postgres's own CURRENT_DATE — the DB session's timezone isn't guaranteed
// to match the parish's local time, which caused a real bug earlier this
// session for a different date comparison.
$stmt = db()->prepare(
    "SELECT * FROM announcements WHERE status = 'published'
       AND (end_date IS NULL OR end_date >= ?)
     ORDER BY is_pinned DESC, created_at DESC"
);
$stmt->execute([date('Y-m-d')]);
$announcements = $stmt->fetchAll();

// A prior week's auto-generated donor digest is stale once a new week starts —
// its modal only ever holds the CURRENT week's donors, so keeping it visible
// would show a card that either opens the wrong data or can't open at all.
$announcements = array_values(array_filter(
    $announcements,
    fn($a) => !isWeeklyDonorAnnouncementTitle($a['title']) || isCurrentWeeklyDonorAnnouncement($a['title'])
));

$weekDonors = getCurrentWeekDonors();

$active = 'announcements';
$pageTitle = 'Announcements';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-start.php' : 'public-shell-start.php');
?>

<?php if (empty($announcements)): ?>
  <div class="empty-state"><div class="icon">📢</div><p>No announcements yet.</p></div>
<?php else: ?>
  <?php $donorModalId = !empty($weekDonors) ? 'donorModal' : null; include __DIR__ . '/../includes/announcement-cards.php'; ?>
<?php endif; ?>

<?php if (!empty($weekDonors)): ?>
<dialog class="modal" id="donorModal">
  <div class="modal-head">
    <h3>This Week's Donors</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('donorModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Donor</th><th>Purpose</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($weekDonors as $d): ?>
            <tr>
              <td><?= e($d['donor_name'] ?: 'Anonymous') ?></td>
              <td><?= e($d['purpose']) ?></td>
              <td><?= money((float) $d['amount']) ?></td>
              <td><?= formatDate($d['verified_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</dialog>
<?php endif; ?>

<?php include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-end.php' : 'public-shell-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
