<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$donationSetting = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn();
if ($donationSetting === '0') {
    flash('error', 'Online donations are currently unavailable. Please check back later.');
    redirect(url('parishioner/dashboard.php'));
}

$stmt = db()->prepare("SELECT service_id FROM services WHERE category = 'Donation' AND is_active = TRUE LIMIT 1");
$stmt->execute();
$donationServiceId = $stmt->fetchColumn();
if (!$donationServiceId) {
    flash('error', 'Online donations are currently unavailable. Please check back later.');
    redirect(url('parishioner/dashboard.php'));
}

$purposes = ['General Donation', 'Church Maintenance', 'Charity', 'Mass / Parish Activities'];
$methods = [2 => 'GCash', 3 => 'Maya', 6 => 'PayPal', 5 => 'Credit / Debit Card'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $donorName = trim($_POST['donor_name'] ?? '') ?: null;
    $donorEmail = trim($_POST['donor_email'] ?? '') ?: null;
    $amount = (float) ($_POST['amount'] ?? 0);
    $purpose = in_array($_POST['purpose'] ?? '', $purposes, true) ? $_POST['purpose'] : $purposes[0];
    $methodId = (int) ($_POST['method_id'] ?? 0);
    $message = trim($_POST['message'] ?? '') ?: null;

    if ($amount <= 0) {
        flash('error', 'Please enter a valid donation amount.');
        redirect(url('parishioner/donate.php'));
    }
    if (!isset($methods[$methodId])) {
        flash('error', 'Please choose a payment method.');
        redirect(url('parishioner/donate.php'));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Donations are approved and payable immediately — no secretary
        // review, no priest, no scheduled time. appointment_date/time just
        // record when the donation was made.
        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, approved_at)
             VALUES (?, ?, NULL, CURRENT_DATE, CURRENT_TIME, 2, NOW())"
        );
        $stmt->execute([$parishionerId, $donationServiceId]);
        $appointmentId = $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO donations (appointment_id, donor_name, donor_email, purpose, message) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$appointmentId, $donorName, $donorEmail, $purpose, $message]);

        $stmt = $pdo->prepare(
            "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
             VALUES (?, NULL, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([$appointmentId, $amount, $methodId]);

        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Thank You for Your Donation', ?)"
        );
        $stmt->execute([$userId, "Thank you for your generous donation (#$appointmentId). It will be verified by our treasurer shortly."]);

        $pdo->commit();
        logActivity($userId, "Submitted a donation (#$appointmentId)", 'Donations');

        flash('success', 'Thank you for your donation! It will be verified by our treasurer shortly.');
        redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        flash('error', 'Failed to submit your donation. Please try again.');
        redirect(url('parishioner/donate.php'));
    }
}

$active = 'services';
$pageTitle = 'Donate to Our Parish';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 620px;">
  <div class="card-header"><h3>Donate to Our Parish</h3></div>
  <p class="helper-text" style="margin-top:-6px; margin-bottom:18px;">Your generosity helps sustain our parish's ministries and services. Every offering, big or small, is deeply appreciated.</p>

  <form method="POST" action="<?= url('parishioner/donate.php') ?>">
    <?= csrfField() ?>

    <div class="form-row">
      <div class="form-group">
        <label>Donor Name (optional)</label>
        <input type="text" name="donor_name" placeholder="Leave blank to donate anonymously">
      </div>
      <div class="form-group">
        <label>Email Address (optional)</label>
        <input type="email" name="donor_email" placeholder="you@example.com">
      </div>
    </div>

    <div class="form-group">
      <label>Donation Amount</label>
      <input type="number" name="amount" min="1" step="0.01" placeholder="e.g. 500" required>
    </div>

    <div class="form-group">
      <label>Purpose of Donation</label>
      <div class="radio-group">
        <?php foreach ($purposes as $i => $p): ?>
          <label class="radio-option">
            <input type="radio" name="purpose" value="<?= e($p) ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <?= e($p) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-group">
      <label>Payment Method</label>
      <div class="radio-group">
        <?php foreach ($methods as $id => $label): ?>
          <label class="radio-option">
            <input type="radio" name="method_id" value="<?= $id ?>" required>
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-group">
      <label>Message (optional)</label>
      <textarea name="message" rows="3" placeholder="Anything you'd like to share with the parish..."></textarea>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Donate Now</button>
    <p class="helper-text mt-2">After submitting, please wait for our treasurer to verify your payment.</p>
  </form>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
