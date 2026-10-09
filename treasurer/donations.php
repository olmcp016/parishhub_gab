<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$userId = currentUser()['user_id'];
$purposes = ['Church Maintenance', 'Charity', 'Mass / Parish Activities', 'Other / Not Specified'];
$methods = [1 => 'Cash', 2 => 'GCash', 3 => 'Maya', 4 => 'Bank Transfer', 5 => 'Credit/Debit Card', 6 => 'PayPal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_donations') {
    verifyCsrf();
    $enabled = !empty($_POST['donation_enabled']) ? '1' : '0';
    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('donation_enabled', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")->execute([$enabled]);
    logActivity($userId, 'Updated donation feature setting (' . ($enabled === '1' ? 'enabled' : 'disabled') . ')', 'Donations');
    flash('success', 'Donation settings updated.');
    redirect(url('treasurer/donations.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    verifyCsrf();

    $amount = (float) ($_POST['amount'] ?? 0);
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $purpose = in_array($_POST['purpose'] ?? '', $purposes, true) ? $_POST['purpose'] : $purposes[0];
    $donorName = trim($_POST['donor_name'] ?? '') ?: null;
    $donorEmail = trim($_POST['donor_email'] ?? '') ?: null;
    $reference = trim($_POST['reference_number'] ?? '') ?: null;
    $selectedParishionerId = (int) ($_POST['parishioner_id'] ?? 0);

    if (!($amount > 0)) {
        flash('error', 'Please enter a valid donation amount (more than zero).');
        redirect(url('treasurer/donations.php'));
    }
    if (!isset($methods[$methodId])) {
        flash('error', 'Please choose a payment method.');
        redirect(url('treasurer/donations.php'));
    }
    // Non-cash donations need the reference number of the transfer or card
    // payment. Cash gets a generated one. Same rule as verifyPaymentAndIssueReceipt()
    // for staff verification (audit H-06).
    if ($methodId !== 1 && $reference === null) {
        flash('error', 'Enter the reference number of this payment before recording the donation.');
        redirect(url('treasurer/donations.php'));
    }

    if ($selectedParishionerId) {
        $parishionerId = $selectedParishionerId;
    } else {
        $parishionerId = db()->query(
            "SELECT p.parishioner_id FROM parishioners p JOIN users u ON p.user_id = u.user_id WHERE u.email = 'walkin-donor@parishhub.internal'"
        )->fetchColumn();
    }

    $donationServiceId = db()->query("SELECT service_id FROM services WHERE category = 'Donation' AND is_active = TRUE LIMIT 1")->fetchColumn();

    if (!$parishionerId || !$donationServiceId) {
        flash('error', 'Unable to record donation right now — please contact the developer.');
        redirect(url('treasurer/donations.php'));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the reference so two recordings cannot share it (audit H-06).
        if ($reference === null) {
            $reference = 'CASH-' . strtoupper(bin2hex(random_bytes(6)));
        }
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['payref:' . $reference]);
        $dupRef = $pdo->prepare('SELECT 1 FROM payments WHERE reference_number = ? LIMIT 1');
        $dupRef->execute([$reference]);
        if ($dupRef->fetchColumn()) {
            $pdo->rollBack();
            flash('error', 'This reference number is already used by another payment. Check the reference and try again.');
            redirect(url('treasurer/donations.php'));
        }

        // Staff recorded this in person — it's already confirmed, so it's
        // inserted straight to Payment Verified with a real receipt, no
        // separate verification step needed.
        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, approved_by, approved_at)
             VALUES (?, ?, NULL, CURRENT_DATE, CURRENT_TIME, 4, ?, NOW())"
        );
        $stmt->execute([$parishionerId, $donationServiceId, $userId]);
        $appointmentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO donations (appointment_id, donor_name, donor_email, purpose) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$appointmentId, $donorName, $donorEmail, $purpose]);

        $stmt = $pdo->prepare(
            "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date, verified_by, verified_at)
             VALUES (?, ?, ?, ?, 'verified', NOW(), ?, NOW())"
        );
        $stmt->execute([$appointmentId, $reference, $amount, $methodId, $userId]);
        $paymentId = $pdo->lastInsertId();

        $receiptNumber = 'OR-' . date('Y') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO official_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)")
            ->execute([$paymentId, $receiptNumber, $userId]);

        if ($selectedParishionerId) {
            $puid = $pdo->prepare('SELECT user_id FROM parishioners WHERE parishioner_id = ?');
            $puid->execute([$selectedParishionerId]);
            $puid = $puid->fetchColumn();
            if ($puid) {
                $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'payment', 'Donation Recorded', ?)")
                    ->execute([$puid, "A donation of " . money($amount) . " was recorded on your behalf by our parish office. Receipt $receiptNumber issued. Thank you!"]);
            }
        }

        $pdo->commit();
        syncWeeklyDonationAnnouncement($userId);
        logActivity($userId, "Recorded manual donation #$appointmentId ($receiptNumber)", 'Donations');
        flash('success', "Donation recorded. Receipt $receiptNumber generated.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        flash('error', 'Failed to record donation. Please try again.');
    }
    redirect(url('treasurer/donations.php'));
}

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

$parishioners = db()->query(
    "SELECT p.parishioner_id, u.firstname, u.lastname FROM parishioners p
     JOIN users u ON p.user_id = u.user_id
     WHERE u.email != 'walkin-donor@parishhub.internal'
     ORDER BY u.lastname, u.firstname"
)->fetchAll();

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT a.appointment_id, a.created_at, d.donor_name, d.donor_email, d.purpose, d.message,
               p.amount, p.payment_status, p.payment_id, pm.method_name
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN donations d ON d.appointment_id = a.appointment_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        LEFT JOIN payment_methods pm ON p.method_id = pm.method_id
        WHERE s.category = 'Donation'";
$params = [];
if ($statusFilter) { $sql .= ' AND p.payment_status = ?'; $params[] = $statusFilter; }
if ($search) {
    $sql .= ' AND (d.donor_name LIKE ? OR d.donor_email LIKE ? OR d.purpose LIKE ?)';
    $lk = likeSafe($search); $params[] = $lk; $params[] = $lk; $params[] = $lk;
}
$countSql = str_replace(
    'SELECT a.appointment_id, a.created_at, d.donor_name, d.donor_email, d.purpose, d.message,
               p.amount, p.payment_status, p.payment_id, pm.method_name',
    'SELECT COUNT(*)',
    $sql
);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$pagination = paginate((int) $countStmt->fetchColumn(), 10);

$sql .= ' ORDER BY a.created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
$stmt->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$donations = $stmt->fetchAll();

$paginationUrl = url('treasurer/donations.php') . '?' . http_build_query(array_filter(['status' => $statusFilter, 'search' => $search]));

$totalVerified = db()->query(
    "SELECT COALESCE(SUM(p.amount),0) FROM payments p
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN services s ON a.service_id = s.service_id
     WHERE s.category = 'Donation' AND p.payment_status = 'verified'"
)->fetchColumn();

$active = 'donations';
$pageTitle = 'Donations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
    <h3 style="margin:0;">Accept Online Donations</h3>
    <span class="badge <?= $donationEnabled ? 'badge-verified' : 'badge-cancelled' ?>" style="font-size:12px; padding:4px 10px;">
      <?= $donationEnabled ? 'ON' : 'OFF' ?>
    </span>
  </div>
  <p class="helper-text" style="margin-top:0; margin-bottom:16px;">
    Controls whether parishioners see the &ldquo;Donate Now&rdquo; button on their dashboard and can submit online donations.
  </p>
  <form method="POST" action="<?= url('treasurer/donations.php') ?>" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="toggle_donations">
    <label style="display:flex; align-items:center; gap:8px; font-weight:500; font-size:14px; cursor:pointer; text-transform:none;">
      <input type="checkbox" name="donation_enabled" value="1" <?= $donationEnabled ? 'checked' : '' ?> style="width:auto;">
      Enable the Donate button for parishioners
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
  </form>
</div>

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Total Verified Donations</div><div class="stat-value"><?= money((float) $totalVerified) ?></div></div>
  <div class="stat-card light"><div class="stat-label">Total Donations Received</div><div class="stat-value"><?= count($donations) ?></div></div>
</div>

<div class="card">
  <div class="card-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="var f = document.getElementById('recordDonationForm'), i = document.getElementById('recordDonationIcon'); if (f.style.display === 'none') { f.style.display = 'block'; i.setAttribute('data-lucide', 'chevron-up'); } else { f.style.display = 'none'; i.setAttribute('data-lucide', 'chevron-down'); } lucide.createIcons();">
    <h3 style="margin: 0;">Record a Donation</h3>
    <button type="button" class="btn btn-outline btn-sm" style="display:flex; align-items:center; gap:6px;"><i data-lucide="chevron-down" id="recordDonationIcon" style="width:16px; height:16px;"></i> Toggle Form</button>
  </div>
  <div id="recordDonationForm" style="display: none; padding-top: 16px;">
    <p class="helper-text" style="margin-top:0; margin-bottom:16px;">For cash or in-person donations, or any offering received outside the website. This is recorded as already verified — no separate confirmation step needed.</p>
    <form method="POST" action="<?= url('treasurer/donations.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="manual">
      
      <div class="form-group" style="margin-bottom: 16px;">
        <label>Registered Parishioner (optional)</label>
        <input type="hidden" name="parishioner_id" id="donationParishionerIdInput" value="">
        <div class="flex gap-2" style="align-items:center; flex-wrap:wrap; height:47.5px;">
          <span id="donationParishionerDisplay" class="text-muted" style="min-width:160px;">Walk-in / Not a member</span>
          <button type="button" class="btn btn-outline btn-sm" style="height:32px; padding: 0 12px;" onclick="openParishionerPicker()">Select</button>
          <button type="button" class="btn btn-outline btn-sm" id="donationParishionerClearBtn" style="display:none; height:32px; padding: 0 12px;" onclick="clearParishionerPicker()">Clear</button>
        </div>
      </div>

      <div style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;">
        <div class="form-group" style="flex: 1 1 200px; margin-bottom: 0;">
          <label>Donor Name (optional)</label>
          <input type="text" name="donor_name" placeholder="Leave blank for Anonymous">
        </div>
        <div class="form-group" style="flex: 1 1 200px; margin-bottom: 0;">
          <label>Email (optional)</label>
          <input type="email" name="donor_email">
        </div>
      </div>

      <div style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;">
        <div class="form-group" style="flex: 1 1 120px; margin-bottom: 0;">
          <label>Amount</label>
          <input type="number" name="amount" min="0.01" step="0.01" required>
        </div>
        <div class="form-group" style="flex: 1 1 150px; margin-bottom: 0;">
          <label>Purpose</label>
          <select name="purpose">
            <?php foreach ($purposes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex: 1 1 150px; margin-bottom: 0;">
          <label>Payment Method</label>
          <select name="method_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($methods as $id => $label): ?><option value="<?= $id ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="flex: 3 1 200px; margin-bottom: 0;">
          <label>Reference # (optional)</label>
          <input type="text" name="reference_number">
        </div>
        <div class="form-group" style="flex: 0 0 auto; margin-bottom: 0;">
          <button type="submit" class="btn btn-primary" style="height:47.5px; padding: 0 24px;">Record</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Donations</h3></div>

  <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom: 24px;">
    <div class="form-group" style="flex: 1 1 150px; margin-bottom:0;">
      <label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
        <option value="verified" <?= $statusFilter==='verified'?'selected':'' ?>>Verified</option>
        <option value="failed" <?= $statusFilter==='failed'?'selected':'' ?>>Failed</option>
        <option value="refunded" <?= $statusFilter==='refunded'?'selected':'' ?>>Refunded</option>
        <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option>
      </select>
    </div>
    <div class="form-group" style="flex: 3 1 300px; margin-bottom:0;"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Donor name, email, or purpose"></div>
    <div class="form-group" style="flex: 0 0 auto; margin-bottom:0;"><button class="btn btn-primary" style="height:47.5px; padding: 0 24px;">Search</button></div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Donor</th><th>Purpose</th><th>Amount</th><th>Payment Method</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($donations as $d): ?>
          <tr>
            <td><?= formatDate($d['created_at']) ?></td>
            <td><?= e($d['donor_name'] ?: 'Anonymous') ?></td>
            <td><?= e($d['purpose']) ?></td>
            <td><?= money((float) $d['amount']) ?></td>
            <td><?= e($d['method_name'] ?? '—') ?></td>
            <td><?php if ($d['payment_status']): ?><span class="badge badge-<?= e($d['payment_status']) ?>"><?= e($d['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td>
              <?php if ($d['payment_id']): ?>
                <a href="<?= url('treasurer/payment-detail.php?id=' . $d['payment_id']) ?>"
                  data-url="<?= url('treasurer/payment-detail.php?id=' . $d['payment_id']) ?>"
                  data-title="Donation Details" class="btn btn-outline btn-sm js-view-modal">View</a>
              <?php else: ?>
                <span class="text-muted" title="No payment record is available for this donation.">No payment record</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($donations)): ?><p class="text-muted text-center mt-3">No donations found.</p><?php else: ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>

<dialog class="modal" id="parishionerPickerModal">
  <div class="modal-head">
    <h3>Select Parishioner</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('parishionerPickerModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <div class="form-group"><input type="text" id="parishionerPickerSearch" placeholder="Search by name or email..." autofocus></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Email</th><th></th></tr></thead>
        <tbody id="parishionerPickerResults"></tbody>
      </table>
    </div>
  </div>
</dialog>

<script>
function openParishionerPicker() {
  document.getElementById('parishionerPickerModal').showModal();
  loadParishionerResults('');
  document.getElementById('parishionerPickerSearch').focus();
}
function clearParishionerPicker() {
  document.getElementById('donationParishionerIdInput').value = '';
  document.getElementById('donationParishionerDisplay').textContent = 'Walk-in / Not a member';
  document.getElementById('donationParishionerClearBtn').style.display = 'none';
}
function selectParishioner(id, name) {
  document.getElementById('donationParishionerIdInput').value = id;
  document.getElementById('donationParishionerDisplay').textContent = name;
  document.getElementById('donationParishionerClearBtn').style.display = '';
  document.getElementById('parishionerPickerModal').close();
}
var parishionerSearchTimer = null;
function loadParishionerResults(q) {
  var tbody = document.getElementById('parishionerPickerResults');
  tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Searching…</td></tr>';
  fetch('<?= url('secretary/parishioner-search.php') ?>?q=' + encodeURIComponent(q))
    .then(function (res) { return res.json(); })
    .then(function (rows) {
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-muted">No matching parishioners.</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(function (r) {
        return '<tr><td>' + escapeHtmlLocal(r.name) + '</td><td>' + escapeHtmlLocal(r.email) + '</td>'
          + '<td><button type="button" class="btn btn-outline btn-sm js-select-parishioner" data-id="' + r.parishioner_id + '" data-name="' + escapeHtmlLocal(r.name) + '">Select</button></td></tr>';
      }).join('');
    })
    .catch(function () {
      tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Could not load results.</td></tr>';
    });
}
function escapeHtmlLocal(s) {
  var d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('parishionerPickerSearch').addEventListener('input', function () {
    var q = this.value;
    clearTimeout(parishionerSearchTimer);
    parishionerSearchTimer = setTimeout(function () { loadParishionerResults(q); }, 250);
  });
  document.getElementById('parishionerPickerResults').addEventListener('click', function (e) {
    var btn = e.target.closest('.js-select-parishioner');
    if (!btn) return;
    selectParishioner(btn.dataset.id, btn.dataset.name);
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
