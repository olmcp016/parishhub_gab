<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
requireRole('Treasurer', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];
$isModalRequest = isDetailModalRequest();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    verifyCsrf();
    // A PayMongo charge or checkout flagged for reconciliation is never verified
    // by hand (audit B-07): only a re-check with PayMongo can confirm what it
    // actually charged. Enforced here, not just by hiding the form.
    if (paymongoNeedsReconciliation($id)) {
        $message = 'This payment is flagged for reconciliation with PayMongo, so it cannot be verified manually. Use "Re-check with PayMongo" to confirm what PayMongo actually charged.';
        if ($isModalRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        flash('error', $message);
        redirect(url('treasurer/payment-detail.php?id=' . $id));
    }
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $result = verifyPaymentAndIssueReceipt($id, $userId, $referenceNumber);
    if ($result['ok']) {
        logActivity($userId, "Verified payment #$id, issued receipt {$result['receipt_number']}", 'Payments');
        if ($isModalRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $result['message']]);
            exit;
        }
        flash('success', $result['message']);
    } else {
        if ($isModalRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $result['message']]);
            exit;
        }
        flash('error', $result['message']);
    }
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recheck_paymongo') {
    // Manual recovery for a PayMongo payment stuck 'pending': the payer's
    // browser may never have made it back to the return page, and the
    // webhook (if configured at all for this deployment) may never have
    // fired either. This re-checks directly with PayMongo instead of
    // waiting on either of those.
    verifyCsrf();
    $result = paymongoRecheckPayment($id);
    if ($result['outcome'] === 'verified') {
        logActivity($userId, "Re-checked payment #$id with PayMongo — verified", 'Payments');
    } elseif (in_array($result['outcome'], ['failed', 'cancelled', 'reconcile'], true)) {
        logActivity($userId, "Re-checked payment #$id with PayMongo — " . ($result['outcome'] === 'reconcile' ? 'paid, needs reconciliation' : $result['outcome']), 'Payments');
    }
    if ($isModalRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $result['ok'], 'message' => $result['message']]);
        exit;
    }
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_refunded') {
    // A PayMongo charge flagged for reconciliation (paid, but its booking could
    // not take it) is refunded by the Treasurer in PayMongo's own dashboard —
    // never automatically. This only records that it was done (audit B-07).
    verifyCsrf();
    $update = db()->prepare(
        "UPDATE payments p SET payment_status = 'refunded'
         FROM transactions t
         WHERE p.payment_id = ? AND p.payment_status = 'pending'
           AND t.payment_id = p.payment_id AND t.gateway = 'paymongo' AND t.status = 'needs_reconciliation'"
    );
    $update->execute([$id]);
    $marked = $update->rowCount() === 1;
    if ($marked) {
        db()->prepare("UPDATE transactions SET status = 'refunded_manually' WHERE payment_id = ? AND gateway = 'paymongo'")->execute([$id]);
        logActivity($userId, "Marked PayMongo payment #$id as refunded (refunded in the PayMongo dashboard)", 'Payments');
    }
    $message = $marked ? 'Payment marked as refunded.' : 'Only a PayMongo payment flagged for reconciliation can be marked refunded.';
    if ($isModalRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $marked, 'message' => $message]);
        exit;
    }
    flash($marked ? 'success' : 'error', $message);
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
         WHERE p.payment_id = ? AND p.payment_status = 'verified' AND a.status_id = 4"
    );
    $stmt->execute([$id]);
    $toConfirm = $stmt->fetch();

    if ($toConfirm && in_array($toConfirm['category'], ['Mass Intention', 'Donation'], true)) {
        $apptId = $toConfirm['appointment_id'];
        $update = db()->prepare("UPDATE appointments SET status_id = 5 WHERE appointment_id = ? AND status_id = 4");
        $update->execute([$apptId]);
        if ($update->rowCount() !== 1) {
            if ($isModalRequest) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'This payment cannot be confirmed because its appointment status has changed.']);
                exit;
            }
            flash('error', 'This payment cannot be confirmed because its appointment status has changed.');
            redirect(url('treasurer/payment-detail.php?id=' . $id));
        }
        $stmt = db()->prepare(
            "SELECT u.user_id FROM appointments a JOIN parishioners par ON a.parishioner_id = par.parishioner_id
             JOIN users u ON par.user_id = u.user_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$apptId]);
        $puid = $stmt->fetchColumn();
        // A Mass Intention already has its Appointment Confirmed notification from the
        // status trigger ("Your appointment #12 is confirmed…"): no second one. Donations unchanged.
        $alreadyConfirmed = false;
        if ($toConfirm['category'] === 'Mass Intention') {
            $confirmedStmt = db()->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND title = 'Appointment Confirmed' AND message LIKE ?");
            $confirmedStmt->execute([$puid, "Your appointment #$apptId %"]);
            $alreadyConfirmed = (bool) $confirmedStmt->fetchColumn();
        }
        if (!$alreadyConfirmed) {
            db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Payment Confirmed', ?)")
                ->execute([$puid, "Your payment for appointment #$apptId has been confirmed. Thank you!"]);
        }
        logActivity($userId, "Confirmed payment for appointment #$apptId", 'Payments');
        if ($isModalRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Payment confirmed.']);
            exit;
        }
        flash('success', 'Payment confirmed.');
    } else {
        if ($isModalRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This payment cannot be confirmed right now.']);
            exit;
        }
        flash('error', 'This payment cannot be confirmed right now.');
    }
    redirect(url('treasurer/payment-detail.php?id=' . $id));
}

$stmt = db()->prepare(
    "SELECT p.*, pm.method_name, u.firstname, u.lastname, u.email, s.service_name, s.category,
            a.appointment_date, a.appointment_id, a.status_id AS appointment_status_id,
            a.guest_name, a.guest_phone, a.guest_reference, st.status_name AS appointment_status_name
     FROM payments p
     JOIN payment_methods pm ON p.method_id = pm.method_id
     JOIN appointments a ON p.appointment_id = a.appointment_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
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

// PayMongo checkout of this payment, if any (audit B-07).
$gateway = paymongoPaymentRow($id);
$needsReconciliation = $gateway && paymongoStatusNeedsReconciliation($payment['payment_status'], $gateway['gateway_status']);
$unrecordedCheckout = $needsReconciliation && $gateway['gateway_status'] === 'unrecorded_checkout';
$canRecheck = $gateway && $gateway['gateway_transaction_id'] && in_array($payment['payment_status'], ['pending', 'cancelled', 'failed'], true);

// Extra context so the Cashier can tell what they're verifying/confirming
// without needing to visit a separate page.
$intention = null;
$donation = null;
if ($payment['category'] === 'Mass Intention') {
    $stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
    $stmt->execute([$payment['appointment_id']]);
    $intention = $stmt->fetch() ?: null;
} elseif ($payment['category'] === 'Donation') {
    $stmt = db()->prepare('SELECT d.*, pr.project_name FROM donations d LEFT JOIN projects pr ON pr.project_id = d.project_id WHERE d.appointment_id = ?');
    $stmt->execute([$payment['appointment_id']]);
    $donation = $stmt->fetch() ?: null;
}

if (!$isModalRequest) {
    $active = 'payments';
    $pageTitle = 'Payment #' . $payment['payment_id'];
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/dash-start.php';
}
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header">
      <h3>Payment Details</h3>
      <span>
        <span class="badge badge-<?= e($payment['payment_status']) ?>"><?= e($payment['payment_status']) ?></span>
        <?php if ($needsReconciliation): ?><span class="badge badge-rejected">Needs Reconciliation</span><?php endif; ?>
      </span>
    </div>
    <p><strong>Parishioner:</strong> <?= e($payment['firstname']) ?> <?= e($payment['lastname']) ?> (<?= e($payment['email']) ?>)</p>
    <?php if ($payment['guest_name']): ?>
      <p><strong>Guest:</strong> <?= e($payment['guest_name']) ?> (<?= e($payment['guest_phone']) ?>) — Ref <?= e($payment['guest_reference']) ?></p>
    <?php endif; ?>
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
      <p><strong>Allocation:</strong> <?= e(donationAllocationLabel($donation)) ?></p>
      <?php if ($donation['message']): ?><p><strong>Message:</strong> <?= e($donation['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php
      $isIntention = $payment['category'] === 'Mass Intention';
      if ($isIntention):
        [$miLabel, $miClass] = massIntentionStatusDisplay($payment['appointment_status_name']);
    ?>
      <p><strong>Mass Intention Status:</strong> <span class="badge badge-<?= $miClass ?>"><?= e($miLabel) ?></span></p>
    <?php endif; ?>

    <?php if ($unrecordedCheckout): ?>
      <div class="alert alert-error mt-3">
        <strong>PayMongo checkout not recorded — needs reconciliation.</strong>
        PayMongo created a checkout (<?= e((string) (paymongoUnrecordedSessionId($gateway) ?? '')) ?>) for this payment, but ParishHub could not record it, and PayMongo did not confirm it expired. Its link was never given to the payer.
        Re-check it with PayMongo: the checkout is recorded and whatever PayMongo reports is applied (paid: verified or flagged; still open: left for the payer). Manual verification is disabled for this payment.
      </div>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="recheck_paymongo">
        <button type="submit" class="btn btn-outline">🔄 Re-check with PayMongo</button>
      </form>
    <?php elseif ($needsReconciliation): ?>
      <div class="alert alert-error mt-3">
        <strong>Paid at PayMongo — needs reconciliation.</strong>
        PayMongo confirms this checkout (<?= e((string) $gateway['gateway_transaction_id']) ?>) was paid, but the payment could not be applied to its booking automatically: the booking may already be paid or cancelled, or the amount differs. No receipt has been issued for it, and manual verification is disabled.
        Review the booking. If this charge should not stand, refund it in the PayMongo dashboard (nothing is refunded automatically), then mark it refunded here.
      </div>
      <div class="flex gap-3 mt-3" style="flex-wrap:wrap;">
        <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="recheck_paymongo">
          <button type="submit" class="btn btn-outline">🔄 Re-check with PayMongo</button>
        </form>
        <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" onsubmit="return confirm('Mark this payment as refunded? Only do this after refunding it in the PayMongo dashboard.');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_refunded">
          <button type="submit" class="btn btn-danger">Mark as Refunded</button>
        </form>
      </div>
    <?php elseif ($canRecheck): ?>
      <?php if ($payment['payment_status'] === 'pending'): ?>
        <p class="helper-text">This online payment normally verifies automatically once the parishioner pays and their browser returns to our site (or via PayMongo's webhook). If they already paid but it's still showing as pending — e.g. they closed the GCash/Maya app without being redirected back — use this to check directly with PayMongo instead of waiting.</p>
      <?php else: ?>
        <p class="helper-text">This online checkout was closed without a payment on record. If the payer says they were charged anyway, re-check it with PayMongo: a payment PayMongo confirms is recovered automatically.</p>
      <?php endif; ?>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="recheck_paymongo">
        <button type="submit" class="btn btn-outline">🔄 Re-check with PayMongo</button>
      </form>
    <?php elseif ($payment['payment_status'] === 'pending'): ?>
      <form method="POST" action="<?= url('treasurer/payment-detail.php?id=' . $id) ?>" class="mt-3" id="verifyForm" style="display: flex; align-items: flex-end; gap: 12px; width: 100%;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-group" style="flex: 1; margin-bottom: 0;">
          <label style="display: block; margin-bottom: 5px; font-weight: bold; color: var(--text-color);">Official Reference Number</label>
          <input type="text" name="reference_number" value="<?= e($payment['reference_number'] ?? '') ?>" placeholder="Enter Reference/OR Number" required style="padding: 10px; width: 100%; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box;">
        </div>
        <button type="button" class="btn btn-success" id="verifyBtn" onclick="document.getElementById('confirmPayModal').showModal()" style="padding: 11px 20px; white-space: nowrap; height: 42px;">&#10004; Verify Payment &amp; Issue Receipt</button>
      </form>
    <?php endif; ?>

  </div>

<!-- Verify/Confirm styled modal -->
<dialog id="confirmPayModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Are you sure?</h3>
  <p style="color:var(--text-muted,#555);">This action will be recorded and cannot easily be undone. Proceed?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('confirmPayModal').close()">Cancel</button>
    <button type="button" class="btn btn-success" id="confirmPayProceed"
      onclick="document.getElementById('confirmPayModal').close(); var paymentForm = document.getElementById('verifyForm') || document.getElementById('confirmForm'); if (paymentForm) paymentForm.requestSubmit();">Yes, Proceed</button>
  </div>
</dialog>

  <div class="card" style="padding:0; overflow:hidden;">
    <?php if ($receipt): ?>
      <iframe src="<?= url('treasurer/print-receipt.php?id=' . $payment['payment_id']) ?>" style="width:100%; height:100%; min-height:550px; border:none;"></iframe>
    <?php else: ?>
      <div style="padding: 24px;">
        <h3 style="margin-top:0;">Official Receipt</h3>
        <p class="text-muted">No receipt issued yet. Verify the payment to generate one.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isModalRequest): ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
