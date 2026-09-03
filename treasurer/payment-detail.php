<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    verifyCsrf();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $updateStmt = $pdo->prepare(
            "UPDATE payments SET payment_status='verified', reference_number=?, verified_by=?, verified_at=NOW()
             WHERE payment_id=? AND payment_status='pending'"
        );
        $updateStmt->execute([$referenceNumber, $userId, $id]);
        if ($updateStmt->rowCount() === 0) {
            throw new RuntimeException('Payment already processed or not found.');
        }

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE payment_id = ?');
        $stmt->execute([$id]);
        $payment = $stmt->fetch();

        $pdo->prepare("UPDATE appointments SET status_id = 4 WHERE appointment_id = ?")->execute([$payment['appointment_id']]);

        $receiptNumber = 'OR-' . date('Y') . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO official_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)")
            ->execute([$id, $receiptNumber, $userId]);

        $stmt = $pdo->prepare('SELECT parishioner_id FROM appointments WHERE appointment_id = ?');
        $stmt->execute([$payment['appointment_id']]);
        $parId = $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT user_id FROM parishioners WHERE parishioner_id = ?');
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();

        $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'payment', 'Payment Verified', ?)")
            ->execute([$puid, "Your payment (Ref: {$payment['reference_number']}) has been verified. Official Receipt $receiptNumber issued."]);

        $pdo->commit();
        logActivity($userId, "Verified payment #$id, issued receipt $receiptNumber", 'Payments');
        flash('success', "Payment verified. Receipt $receiptNumber generated.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        flash('error', 'Failed to verify payment.');
    }
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

$stmt = db()->prepare(
    "SELECT p.*, pm.method_name, u.firstname, u.lastname, u.email, s.service_name, a.appointment_date, a.appointment_id
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

    <?php if ($payment['payment_status'] === 'pending'): ?>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3" onsubmit="return confirm('Verify this payment and issue an official receipt?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-group"><label>Official Reference Number</label><input type="text" name="reference_number" placeholder="Enter Reference/OR Number" required style="padding:8px; width:100%; max-width:300px; border:1px solid #ccc; border-radius:6px;"></div>
        <button type="submit" class="btn btn-success">✔ Verify Payment & Issue Receipt</button>
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
