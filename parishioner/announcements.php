<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

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
include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (empty($announcements)): ?>
  <div class="empty-state"><div class="icon">📢</div><p>No announcements yet.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($announcements as $a): ?>
      <?php $isDonorCard = isCurrentWeeklyDonorAnnouncement($a['title']); ?>
      <?php $annStatus = announcementStatus($a['start_date'], $a['end_date']); ?>
      <div class="card <?= $isDonorCard ? 'card-clickable' : '' ?>" style="display:flex; flex-direction:column;"
           <?php if ($isDonorCard): ?>onclick="document.getElementById('donorModal').showModal()"<?php endif; ?>>
        <?php if ($a['image']): ?>
          <img src="<?= documentUrl($a['image']) ?>" alt="" style="width:100%; max-height:180px; object-fit:cover; border-radius:10px; margin-bottom:10px;">
        <?php endif; ?>
        <div class="flex-between" style="align-items:flex-start; gap:8px; margin-bottom:4px;">
          <h3 style="margin:0;"><?= e($a['title']) ?></h3>
          <div class="flex gap-2" style="flex-shrink:0;">
            <?php if ($annStatus === 'Upcoming'): ?><span class="badge badge-regular">Upcoming</span><?php endif; ?>
            <?php if ($a['is_pinned']): ?><span class="badge badge-pending">Pinned</span><?php endif; ?>
          </div>
        </div>
        <span class="text-muted" style="font-size:13px; margin-bottom:12px;"><?= formatDate($a['created_at']) ?></span>
        <p style="margin:0; white-space: pre-line;"><?= e($a['content']) ?></p>
        <?php if ($isDonorCard): ?><div class="card-click-hint">🤲 Click to view the donor list →</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
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

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
