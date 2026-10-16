<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$projects = db()->query(
    "SELECT pr.*,
       COALESCE((SELECT SUM(p.amount) FROM payments p
                 JOIN appointments a ON p.appointment_id = a.appointment_id
                 JOIN donations d ON d.appointment_id = a.appointment_id
                 WHERE d.project_id = pr.project_id AND p.payment_status = 'verified'), 0) AS raised,
       (SELECT COUNT(*) FROM payments p
                 JOIN appointments a ON p.appointment_id = a.appointment_id
                 JOIN donations d ON d.appointment_id = a.appointment_id
                 WHERE d.project_id = pr.project_id AND p.payment_status = 'verified') AS donor_count
     FROM projects pr WHERE pr.is_active = TRUE ORDER BY pr.created_at DESC"
)->fetchAll();

// "Donate to This Project" opens the shared donation modal right here
// (includes/donation-modal.php), on that project; PayMongo returns to Services.
$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
if ($donationEnabled) {
    $isGuest = false;
    $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
    $stmt->execute([currentUser()['user_id']]);
    $u = $stmt->fetch();
    $accountName = $u ? trim($u['firstname'] . ' ' . $u['lastname']) : '';
    // The modal lists $projects: the same active projects shown on this page.
    $preselectedProjectId = null; // chosen per button, in openDonateModal()
    $autoOpenDonate = false;
}

$active = 'projects';
$pageTitle = 'Ongoing Projects';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php if (empty($projects)): ?>
  <div class="empty-state"><div class="icon">🏗️</div><p>No ongoing projects right now.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($projects as $p): ?>
      <?php
        $raised = (float) $p['raised'];
        $target = (float) $p['target_amount'];
        $remaining = max(0, $target - $raised);
        $progress = $target > 0 ? min(100, round($raised / $target * 100, 1)) : 0;
      ?>
      <div class="card">
        <h3 style="margin:0 0 6px;"><?= e($p['project_name']) ?></h3>
        <p style="font-size:13.5px; color:var(--brown-mid);"><?= e($p['description'] ?? '') ?></p>
        <div style="background:var(--cream-dark); border-radius:6px; height:12px; overflow:hidden; margin:10px 0;">
          <div style="background:var(--gold); height:100%; width:<?= $progress ?>%;"></div>
        </div>
        <p style="font-size:14px; margin:4px 0;"><strong class="text-gold"><?= money($raised) ?></strong> raised of <?= money($target) ?> <span class="text-muted">(<?= $progress ?>%)</span></p>
        <p style="font-size:13px; margin:4px 0; color:var(--brown-mid);">Remaining: <?= money($remaining) ?> · <?= (int) $p['donor_count'] ?> donation(s) so far</p>
        <?php if ($donationEnabled): ?>
          <button type="button" class="btn btn-primary btn-block mt-2" onclick="openDonateModal(<?= (int) $p['project_id'] ?>)">Donate to This Project</button>
        <?php else: ?>
          <p class="helper-text mt-2" style="text-align:center;">Online donations are currently paused.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($donationEnabled && !empty($projects)): ?>
  <?php include __DIR__ . '/../includes/donation-modal.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
