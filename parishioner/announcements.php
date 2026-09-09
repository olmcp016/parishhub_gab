<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$announcements = db()->query(
    "SELECT * FROM announcements WHERE status = 'published' ORDER BY is_pinned DESC, created_at DESC"
)->fetchAll();

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
      <?php $isDonorCard = isWeeklyDonorAnnouncementTitle($a['title']); ?>
      <div class="card <?= $isDonorCard ? 'card-clickable' : '' ?>" style="display:flex; flex-direction:column;"
           <?php if ($isDonorCard): ?>onclick="document.getElementById('donorModal').showModal()"<?php endif; ?>>
        <div class="flex-between" style="align-items:flex-start; gap:8px; margin-bottom:4px;">
          <h3 style="margin:0;"><?= e($a['title']) ?></h3>
          <?php if ($a['is_pinned']): ?><span class="badge badge-pending" style="flex-shrink:0;">Pinned</span><?php endif; ?>
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
