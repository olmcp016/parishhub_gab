<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
$identity = requireParishionerOrGuest();
$parishionerId = $identity['parishioner_id'];
$isGuest = $identity['is_guest'];

/**
 * My Donations — a signed-in parishioner's donation history. Donate Now opens
 * the shared donation modal (includes/donation-modal.php) on this page.
 *
 * PayMongo checkouts started before the return URLs moved to Services still come
 * back here: they are handled the same way (includes/paymongo.php) and the donor
 * continues on Services, where the Thank You or a note is shown.
 */
if (isset($_GET['paymongo_return']) || isset($_GET['paymongo_cancelled'])) {
    finishDonationReturn((int) ($_GET['appointment_id'] ?? 0), isset($_GET['paymongo_cancelled']));
}

// Guests have no donation history page: send them to the donation form on Services.
if ($isGuest) {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    redirect(url('parishioner/services.php') . '?' . ($projectId > 0 ? 'project_id=' . $projectId : 'donate=1'));
}

// The account's own donations only.
$stmt = db()->prepare(
    "SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id
     WHERE a.parishioner_id = ? AND s.category = 'Donation'"
);
$stmt->execute([$parishionerId]);
$donationsPagination = paginate((int) $stmt->fetchColumn(), 10);

$stmt = db()->prepare(
    "SELECT a.appointment_id, a.created_at, d.purpose, d.project_id, pr.project_name, d.message, p.amount, p.payment_status, pm.method_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN donations d ON d.appointment_id = a.appointment_id
     LEFT JOIN projects pr ON pr.project_id = d.project_id
     LEFT JOIN payments p ON p.appointment_id = a.appointment_id
     LEFT JOIN payment_methods pm ON p.method_id = pm.method_id
     WHERE a.parishioner_id = ? AND s.category = 'Donation'
     ORDER BY a.created_at DESC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $parishionerId);
$stmt->bindValue(2, $donationsPagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(3, $donationsPagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$donations = $stmt->fetchAll();

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
// Donate Now opens the shared donation modal right here (includes/donation-modal.php);
// the donor picks General or a project. PayMongo returns to Services.
if ($donationEnabled) {
    $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
    $stmt->execute([$identity['user_id']]);
    $u = $stmt->fetch();
    $accountName = $u ? trim($u['firstname'] . ' ' . $u['lastname']) : '';
    $projects = db()->query('SELECT project_id, project_name FROM projects WHERE is_active = TRUE ORDER BY project_name')->fetchAll();
    $preselectedProjectId = null;
    $autoOpenDonate = false;
}

$active = 'donations';
$pageTitle = 'My Donations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>My Donations</h3>
    <?php if ($donationEnabled): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openDonateModal()">🤲 Donate Now</button>
    <?php endif; ?>
  </div>

  <?php if (empty($donations)): ?>
    <div class="empty-state">
      <div class="icon">🤲</div>
      <p>You haven't made any donations yet.</p>
      <?php if ($donationEnabled): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openDonateModal()">Donate to Our Parish</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Allocation</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($donations as $d): ?>
            <tr>
              <td><?= formatDate($d['created_at']) ?></td>
              <td><?= e(donationAllocationLabel($d)) ?></td>
              <td><?= money((float) $d['amount']) ?></td>
              <td><?= e($d['method_name'] ?? '—') ?></td>
              <td><?php if ($d['payment_status']): ?><span class="badge badge-<?= e($d['payment_status']) ?>"><?= e($d['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <td><a href="#" data-url="<?= url('parishioner/appointment-detail.php?id=' . $d['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= renderPagination($donationsPagination, url('parishioner/donations.php')) ?>
  <?php endif; ?>
</div>

<?php if ($donationEnabled): ?>
  <?php include __DIR__ . '/../includes/donation-modal.php'; ?>
  <script>
  // Done on a successful Cash / Pay Later confirmation (the only time that
  // screen shows) reloads the history once, so the new pending donation
  // appears; it is on the first page (newest first). A plain GET, so nothing
  // is submitted again. ✕, Esc and closing an unfinished form do not reload.
  document.querySelector('#donateConfirmView .btn-primary').addEventListener('click', function () {
    window.location.href = <?= json_encode(url('parishioner/donations.php')) ?>;
  });
  </script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
