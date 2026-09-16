<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    verifyCsrf();
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $result = verifyPaymentAndIssueReceipt($id, $userId, $referenceNumber);
    if ($result['ok']) {
        logActivity($userId, "Verified payment #$id, issued receipt {$result['receipt_number']}", 'Payments');
        flash('success', $result['message']);
    } else {
        flash('error', $result['message']);
    }
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    // Final confirmation step for Mass Intention / Donation payments — this
    // used to be the Secretary's job (see secretary/appointment-detail.php),
    // now handled here by the Cashier since it's a payment responsibility.
    // A confirmed Mass Intention becomes eligible for the public "Today's
    // Mass Intentions" display (see includes/functions.php).
    verifyCsrf();
    $stmt = db()->prepare(
        "SELECT a.appointment_id, s.category FROM payments p
         JOIN appointments a ON a.appointment_id = p.appointment_id
         JOIN services s ON a.service_id = s.service_id
         WHERE p.payment_id = ? AND a.status_id = 4"
    );
    $stmt->execute([$id]);
    $toConfirm = $stmt->fetch();

    if ($toConfirm && in_array($toConfirm['category'], ['Mass Intention', 'Donation'], true)) {
        $apptId = $toConfirm['appointment_id'];
        db()->prepare("UPDATE appointments SET status_id = 5 WHERE appointment_id = ?")->execute([$apptId]);
        $stmt = db()->prepare(
            "SELECT u.user_id FROM appointments a JOIN parishioners par ON a.parishioner_id = par.parishioner_id
             JOIN users u ON par.user_id = u.user_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$apptId]);
        $puid = $stmt->fetchColumn();
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Payment Confirmed', ?)")
            ->execute([$puid, "Your payment for appointment #$apptId has been confirmed. Thank you!"]);
        logActivity($userId, "Confirmed payment for appointment #$apptId", 'Payments');
        flash('success', 'Payment confirmed.');
    } else {
        flash('error', 'This payment cannot be confirmed right now.');
    }
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

$stmt = db()->prepare(
    "SELECT p.*, pm.method_name, u.firstname, u.lastname, u.email, s.service_name, s.category,
            a.appointment_date, a.appointment_id, a.status_id AS appointment_status_id
     FROM payments p
     JOIN payment_methods pm ON p.method_id = pm.method_id
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN services s ON a.service_id = s.service_id
     WHERE p.payment_id = ?"
);
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    flash('error', 'Payment not found.');
    redirect(url('treasurer/payments.php'));
}

$stmt = db()->prepare('SELECT * FROM official_receipts WHERE payment_id = ?');
$stmt->execute([$id]);
$receipt = $stmt->fetch() ?: null;

// Extra context so the Cashier can tell what they're verifying/confirming
// without needing to visit a separate page.
$intention = null;
$donation = null;
if ($payment['category'] === 'Mass Intention') {
    $stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
    $stmt->execute([$payment['appointment_id']]);
    $intention = $stmt->fetch() ?: null;
} elseif ($payment['category'] === 'Donation') {
    $stmt = db()->prepare('SELECT * FROM donations WHERE appointment_id = ?');
    $stmt->execute([$payment['appointment_id']]);
    $donation = $stmt->fetch() ?: null;
}

$active = 'payments';
$pageTitle = 'Payment #' . $payment['payment_id'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header">
      <h3>Payment Details</h3>
      <span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e($payment['payment_status']) ?></span>
    </div>
    <p><strong>Parishioner:</strong> <?= e($payment['firstname']) ?> <?= e($payment['lastname']) ?> (<?= e($payment['email']) ?>)</p>
    <p><strong>Service:</strong> <?= e($payment['service_name']) ?> (Appointment #<?= $payment['appointment_id'] ?>)</p>
    <p><strong>Amount:</strong> <?= money($payment['amount']) ?></p>
    <p><strong>Method:</strong> <?= e($payment['method_name']) ?></p>
    <p><strong>Reference #:</strong> <?= e($payment['reference_number']) ?></p>
    <p><strong>Submitted:</strong> <?= $payment['payment_date'] ? formatDateTime($payment['payment_date']) : '—' ?></p>

    <?php if ($intention): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Mass Intention Details</h4>
      <p><strong>Type:</strong> <?= e($intention['intention_type']) ?></p>
      <p><strong>Offerer:</strong> <?= e($intention['offerer_name']) ?></p>
      <p><strong>Intention For:</strong> <?= e($intention['intention_for']) ?></p>
      <?php if ($intention['message']): ?><p><strong>Message:</strong> <?= e($intention['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if ($donation): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Donation Details</h4>
      <p><strong>Donor:</strong> <?= e($donation['donor_name'] ?: 'Anonymous') ?></p>
      <p><strong>Purpose:</strong> <?= e($donation['purpose']) ?></p>
      <?php if ($donation['message']): ?><p><strong>Message:</strong> <?= e($donation['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if ($payment['payment_status'] === 'pending'): ?>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3" onsubmit="return confirm('Verify this payment and issue an official receipt?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-group"><label>Official Reference Number</label><input type="text" name="reference_number" placeholder="Enter Reference/OR Number" required style="padding:8px; width:100%; max-width:300px; border:1px solid #ccc; border-radius:6px;"></div>
        <button type="submit" class="btn btn-success">✔ Verify Payment & Issue Receipt</button>
      </form>
    <?php elseif ($payment['payment_status'] === 'verified' && (int) $payment['appointment_status_id'] === 4 && in_array($payment['category'], ['Mass Intention', 'Donation'], true)): ?>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3" onsubmit="return confirm('Confirm this payment?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="confirm">
        <p class="helper-text" style="margin-top:0;">
          <?= $payment['category'] === 'Mass Intention' ? 'Confirming makes this Mass Intention eligible for the public "Today\'s Mass Intentions" display on its scheduled date.' : 'Confirming finalizes this donation.' ?>
        </p>
        <button type="submit" class="btn btn-success">✔ Confirm Payment</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><h3>Official Receipt</h3></div>
    <?php if ($receipt): ?>
      <p><strong>Receipt #:</strong> <?= e($receipt['receipt_number']) ?></p>
      <p><strong>Issued:</strong> <?= formatDateTime($receipt['issue_date']) ?></p>
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨 Print Receipt</button>
    <?php else: ?>
      <p class="text-muted">No receipt issued yet. Verify the payment to generate one.</p>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
