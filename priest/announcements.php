<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Priest');

/**
 * Read-only view of the announcements the Secretary manages in
 * secretary/announcements.php — same table, same visibility rules as the
 * public Announcements page (published and not past its end date), so edits
 * and deletions there show up here on the next load. No create/edit/delete.
 */
$stmt = db()->prepare(
    "SELECT a.*, TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) AS posted_by_name
     FROM announcements a
     LEFT JOIN users u ON u.user_id = a.posted_by
     WHERE a.status = 'published'
       AND (a.end_date IS NULL OR a.end_date >= ?)
     ORDER BY a.created_at DESC"
);
$stmt->execute([date('Y-m-d')]);
$announcements = $stmt->fetchAll();

// A prior week's auto-generated donor digest is stale once a new week starts (see parishioner/announcements.php).
$announcements = array_values(array_filter(
    $announcements,
    fn($a) => !isWeeklyDonorAnnouncementTitle($a['title']) || isCurrentWeeklyDonorAnnouncement($a['title'])
));

$active = 'announcements';
$pageTitle = 'Announcements';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (empty($announcements)): ?>
  <div class="empty-state"><div class="icon">📢</div><p>No announcements yet.</p></div>
<?php else: ?>
  <?php $donorModalId = null; include __DIR__ . '/../includes/announcement-cards.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
