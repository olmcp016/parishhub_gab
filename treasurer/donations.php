<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Secretary', 'Admin');

$userId = currentUser()['user_id'];
$purposes = ['General Donation', 'Church Maintenance', 'Charity', 'Mass / Parish Activities'];
$methods = [1 => 'Cash', 2 => 'GCash', 3 => 'Maya', 4 => 'Bank Transfer', 5 => 'Credit/Debit Card', 6 => 'PayPal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    verifyCsrf();

    $amount = (float) ($_POST['amount'] ?? 0);
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $purpose = in_array($_POST['purpose'] ?? '', $purposes, true) ? $_POST['purpose'] : $purposes[0];
    $donorName = trim($_POST['donor_name'] ?? '') ?: null;
    $donorEmail = trim($_POST['donor_email'] ?? '') ?: null;
    $reference = trim($_POST['reference_number'] ?? '') ?: null;
    $selectedParishionerId = (int) ($_POST['parishioner_id'] ?? 0);

    if ($amount < 0) {
        flash('error', 'Please enter a valid donation amount.');
        redirect(url('treasurer/donations.php'));
    }
    if (!isset($methods[$methodId])) {
        flash('error', 'Please choose a payment method.');
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
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= ' ORDER BY a.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$donations = $stmt->fetchAll();

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

<div class="stat-grid">
  <div class="stat-card light"><div class="stat-label">Total Verified Donations</div><div class="stat-value"><?= money((float) $totalVerified) ?></div></div>
  <div class="stat-card light"><div class="stat-label">Total Donations Received</div><div class="stat-value"><?= count($donations) ?></div></div>
</div>

<div class="card">
  <div class="card-header"><h3>Record a Donation</h3></div>
  <p class="helper-text" style="margin-top:-6px; margin-bottom:16px;">For cash or in-person donations, or any offering received outside the website. This is recorded as already verified — no separate confirmation step needed.</p>
  <form method="POST" action="<?= url('treasurer/donations.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="manual">
    <div class="form-group">
      <label>Registered Parishioner (optional)</label>
      <select name="parishioner_id">
        <option value="">-- Walk-in / Not a member --</option>
        <?php foreach ($parishioners as $p): ?>
          <option value="<?= $p['parishioner_id'] ?>"><?= e($p['lastname']) ?>, <?= e($p['firstname']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Donor Name (optional)</label><input type="text" name="donor_name" placeholder="Leave blank for Anonymous"></div>
    <div class="form-group"><label>Email (optional)</label><input type="email" name="donor_email"></div>
    <div class="form-group"><label>Amount</label><input type="number" name="amount" min="0" step="0.01" required></div>
    <div class="form-group">
      <label>Purpose</label>
      <select name="purpose">
        <?php foreach ($purposes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Payment Method</label>
      <select name="method_id" required>
        <option value="">-- Select --</option>
        <?php foreach ($methods as $id => $label): ?><option value="<?= $id ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Reference # (optional)</label><input type="text" name="reference_number"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Record Donation</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>Donations</h3></div>

  <form method="GET" class="form-row mb-3">
    <div class="form-group">
      <label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
        <option value="verified" <?= $statusFilter==='verified'?'selected':'' ?>>Verified</option>
        <option value="failed" <?= $statusFilter==='failed'?'selected':'' ?>>Failed</option>
        <option value="refunded" <?= $statusFilter==='refunded'?'selected':'' ?>>Refunded</option>
      </select>
    </div>
    <div class="form-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Donor name, email, or purpose"></div>
    <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Search</button></div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Donor</th><th>Purpose</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
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
                <a href="<?= url('treasurer/payment-detail.php?id=' . $d['payment_id']) ?>" class="btn btn-outline btn-sm">View</a>
              <?php else: ?>
                <a href="<?= url('secretary/appointment-detail.php?id=' . $d['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($donations)): ?><p class="text-muted text-center mt-3">No donations found.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
