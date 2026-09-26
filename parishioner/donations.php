<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
$identity = requireParishionerOrGuest();
$userId = $identity['user_id'];
$parishionerId = $identity['parishioner_id'];
$isGuest = $identity['is_guest'];

$accountName = '';
if (!$isGuest) {
    $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u) $accountName = trim($u['firstname'] . ' ' . $u['lastname']);
}

/**
 * Reconciles a return from PayMongo's hosted checkout page. The redirect
 * itself is NOT trusted as proof of payment (a donor could tamper with the
 * URL, or the browser could lose the redirect) — we always re-check the
 * checkout session's real status server-side via the secret key before
 * touching payment_status. Idempotent: verifyPaymentAndIssueReceipt() and
 * markPaymentUnsuccessful() both only act on a still-pending payment, so a
 * repeat visit to this URL (e.g. the donor refreshes) is harmless.
 */
$paymongoResultMessage = null;
$paymongoResultStatus = null; // 'verified' | 'pending' | 'failed' | 'cancelled'

if (isset($_GET['paymongo_return']) || isset($_GET['paymongo_cancelled'])) {
    $returnAppointmentId = (int) ($_GET['appointment_id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status, t.gateway_transaction_id, a.guest_reference
         FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         JOIN appointments a ON a.appointment_id = p.appointment_id
         WHERE p.appointment_id = ?
         ORDER BY p.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$returnAppointmentId]);
    $paymentRow = $stmt->fetch();

    if ($paymentRow) {
        if (isset($_GET['paymongo_cancelled']) && $paymentRow['payment_status'] !== 'verified' && !empty($_SESSION['donation_checkout'][$returnAppointmentId])) {
            // Only the browser session that started this checkout may cancel it
            // (anyone could otherwise add ?paymongo_cancelled=1 to someone
            // else's link and kill an unfinished payment).
            unset($_SESSION['donation_checkout'][$returnAppointmentId]);
            markPaymentUnsuccessful((int) $paymentRow['payment_id'], 'cancelled');
            db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$paymentRow['payment_id']]);
            $paymongoResultStatus = 'cancelled';
            $paymongoResultMessage = 'You cancelled the payment before it completed. No charge was made — you can try again anytime.';
        } elseif ($paymentRow['payment_status'] === 'verified') {
            // Already reconciled (e.g. webhook beat us to it, or a repeat visit).
            $paymongoResultStatus = 'verified';
            $paymongoResultMessage = 'Thank you! Your donation has been received and verified.';
        } else {
            $session = paymongoGetCheckoutSession($paymentRow['gateway_transaction_id']);
            $localStatus = $session['ok'] ? paymongoStatusToLocal($session['status']) : 'pending';

            db()->prepare("UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ?")
                ->execute([$session['ok'] ? $session['status'] : 'unknown', json_encode($session['raw'] ?? []), $paymentRow['payment_id']]);

            if ($localStatus === 'verified') {
                $result = verifyPaymentAndIssueReceipt((int) $paymentRow['payment_id'], null, $paymentRow['gateway_transaction_id']);
                if ($result['ok']) {
                    $paymongoResultStatus = 'verified';
                    $paymongoResultMessage = 'Thank you! Your donation has been received and verified — a receipt has been issued.';
                    if ($paymentRow['guest_reference']) {
                        $paymongoResultMessage .= ' Your reference code is ' . $paymentRow['guest_reference'] . ' — save it to check your donation\'s status anytime.';
                    }
                } else {
                    // PayMongo confirmed payment, but our own bookkeeping step
                    // failed (e.g. a race with the webhook) — do NOT tell the
                    // donor it failed, since they WERE charged. Flag it as
                    // pending so staff can reconcile manually if needed.
                    error_log("PayMongo payment {$paymentRow['payment_id']} confirmed but verifyPaymentAndIssueReceipt failed: {$result['message']}");
                    $paymongoResultStatus = 'pending';
                    $paymongoResultMessage = "Your payment was received by PayMongo. We're finishing up on our end — this will show as verified shortly; contact us if it doesn't within a day.";
                }
            } elseif ($localStatus === 'failed' || $localStatus === 'cancelled') {
                markPaymentUnsuccessful((int) $paymentRow['payment_id'], $localStatus);
                $paymongoResultStatus = $localStatus;
                $paymongoResultMessage = $localStatus === 'cancelled'
                    ? 'The payment was cancelled. No charge was made — you can try again anytime.'
                    : 'The payment did not go through. No charge was made — please try again or choose a different method.';
            } else {
                $paymongoResultStatus = 'pending';
                $paymongoResultMessage = "We're still waiting for PayMongo to confirm this payment. If you completed it, it will update automatically shortly — check back on this page.";
            }
        }
    }
}

// Guests share one placeholder parishioner_id, so a personal history list
// would leak every OTHER guest's donations too — skip it entirely for
// guests; they track their own donation via the reference code instead
// (status.php), same as a guest booking.
$donations = [];
$donationsPagination = null;
if (!$isGuest) {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM appointments a JOIN services s ON a.service_id = s.service_id
         WHERE a.parishioner_id = ? AND s.category = 'Donation'"
    );
    $stmt->execute([$parishionerId]);
    $donationsPagination = paginate((int) $stmt->fetchColumn(), 10);

    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.created_at, d.purpose, d.message, p.amount, p.payment_status, pm.method_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN donations d ON d.appointment_id = a.appointment_id
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
}

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
$purposes = ['Church Maintenance', 'Charity', 'Mass / Parish Activities', 'Other / Not Specified'];
$projects = db()->query('SELECT project_id, project_name FROM projects WHERE is_active = TRUE ORDER BY project_name')->fetchAll();
$preselectedProjectId = (int) ($_GET['project_id'] ?? 0) ?: null;
$autoOpenModal = isset($_GET['donate']) || $preselectedProjectId;

$active = 'donations';
$pageTitle = $isGuest ? 'Donate to Our Parish' : 'My Donations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . ($isGuest ? 'public-shell-start.php' : 'dash-start.php');
?>

<?php if ($paymongoResultMessage): ?>
  <div class="alert <?= $paymongoResultStatus === 'verified' ? 'alert-success' : '' ?>" style="<?= $paymongoResultStatus !== 'verified' ? 'background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);' : '' ?> margin-bottom:16px;">
    <?php if ($paymongoResultStatus === 'verified'): ?>✔<?php elseif (in_array($paymongoResultStatus, ['failed', 'cancelled'], true)): ?>⚠<?php else: ?>⏳<?php endif; ?>
    <?= e($paymongoResultMessage) ?>
  </div>
<?php endif; ?>

<div class="card">
  <?php if ($isGuest): ?>
    <div class="card-header"><h3>Donate to Our Parish</h3></div>
    <p style="font-size:14px; color: var(--brown-mid);">Your generosity helps sustain our parish's ministries and services. No account needed — you'll get a reference code to check your donation's status afterward.</p>
    <?php if ($donationEnabled): ?>
      <button type="button" class="btn btn-primary" onclick="openDonateModal()">🤲 Donate Now</button>
    <?php else: ?>
      <p class="text-muted">Online donations are currently unavailable. Please check back later.</p>
    <?php endif; ?>
  <?php else: ?>
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
          <thead><tr><th>Date</th><th>Purpose</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($donations as $d): ?>
              <tr>
                <td><?= formatDate($d['created_at']) ?></td>
                <td><?= e($d['purpose']) ?></td>
                <td><?= money((float) $d['amount']) ?></td>
                <td><?= e($d['method_name'] ?? '—') ?></td>
                <td><?php if ($d['payment_status']): ?><span class="badge badge-<?= e($d['payment_status']) ?>"><?= e($d['payment_status']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td><a href="<?= url('parishioner/appointment-detail.php?id=' . $d['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= renderPagination($donationsPagination, url('parishioner/donations.php')) ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ===================== Donation Modal ===================== -->
<dialog class="modal modal-lg" id="donateModal">
  <div class="modal-head">
    <h3>Donate to Our Parish</h3>
    <button type="button" class="modal-close" onclick="closeDonateModal()">✕</button>
  </div>
  <div class="modal-body">

    <div id="donateFormView">
      <p class="helper-text" style="margin-top:-4px; margin-bottom:16px;">Your generosity helps sustain our parish's ministries and services. Every offering, big or small, is deeply appreciated.</p>

      <form id="donateForm">
        <?= csrfField() ?>
        <input type="hidden" name="ajax" value="1">

        <?php if ($isGuest): ?>
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
        <?php else: ?>
        <input type="hidden" name="donor_name" id="donateDonorNameField" value="<?= e($accountName) ?>">
        <div class="form-group">
          <label>Donor</label>
          <p style="margin:4px 0 8px;"><strong id="donateDonorDisplay"><?= e($accountName) ?></strong></p>
          <label class="radio-option" style="display:inline-flex; align-items:center; gap:6px;">
            <input type="checkbox" id="donateAnonymousCheck" style="width:auto;">
            Donate Anonymously
          </label>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label>Donation Amount</label>
          <input type="number" name="amount" min="1" step="0.01" placeholder="e.g. 500" required>
        </div>

        <div class="form-group">
          <label>Purpose of Donation</label>
          <select name="purpose">
            <?php foreach ($purposes as $p): ?>
              <option value="<?= e($p) ?>"><?= e($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!empty($projects)): ?>
        <div class="form-group">
          <label>Support an Ongoing Project</label>
          <select name="project_id" id="donateProjectSelect">
            <option value="">Not tied to a specific project</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['project_id'] ?>" <?= $preselectedProjectId == $proj['project_id'] ? 'selected' : '' ?>><?= e($proj['project_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label>How would you like to pay?</label>
          <label class="radio-option" style="display:block; margin-bottom:8px;">
            <input type="radio" name="pay_online" value="1" id="payOnlineRadio" checked>
            <strong>Pay Online Now</strong> — Card, GCash, or Maya via PayMongo (instant, secure)
          </label>
          <label class="radio-option" style="display:block;">
            <input type="radio" name="pay_online" value="0" id="payLaterRadio">
            Pay Later / In Person — mark as pending, our cashier verifies it
          </label>
        </div>

        <div class="form-group" id="manualMethodGroup" style="display:none;">
          <label>Payment Method</label>
          <div class="radio-group">
            <?php foreach (['1' => 'Cash', '2' => 'GCash', '3' => 'Maya', '4' => 'Bank Transfer', '6' => 'PayPal'] as $id => $label): ?>
              <label class="radio-option">
                <input type="radio" name="method_id" value="<?= $id ?>">
                <?= e($label) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label>Message (optional)</label>
          <textarea name="message" rows="2" placeholder="Anything you'd like to share with the parish..."></textarea>
        </div>

        <div id="donateFormError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <button type="submit" class="btn btn-primary btn-block" id="donateSubmitBtn">Donate Now</button>
        <p class="helper-text mt-2" id="donateSubmitHint">You'll be taken to PayMongo's secure payment page to complete your donation.</p>
      </form>
    </div>

    <div id="donateConfirmView" style="display:none; text-align:center; padding: 20px 10px;">
      <div style="font-size:48px; margin-bottom:12px;">✔</div>
      <h3 style="margin:0 0 10px;">Thank You!</h3>
      <p id="donateConfirmMessage" style="color: var(--brown-mid); margin-bottom:20px;"></p>
      <div id="donateConfirmReferenceBox" style="display:none; background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 14px; margin-bottom:20px;">
        <p class="helper-text" style="margin:0 0 6px;">Your reference code — save this to check your donation's status anytime:</p>
        <p style="font-size:22px; font-weight:700; letter-spacing:1px; color: var(--brown-dark); margin:0;" id="donateConfirmReferenceCode"></p>
      </div>
      <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap;">
        <a href="#" id="donateConfirmDetailLink" class="btn btn-outline">View Details</a>
        <button type="button" class="btn btn-primary" onclick="closeDonateModal()">Done</button>
      </div>
    </div>

  </div>
</dialog>

<script>
function openDonateModal() {
  document.getElementById('donateFormView').style.display = 'block';
  document.getElementById('donateConfirmView').style.display = 'none';
  document.getElementById('donateFormError').style.display = 'none';
  document.getElementById('donateModal').showModal();
}
function closeDonateModal() {
  document.getElementById('donateModal').close();
}

document.addEventListener('DOMContentLoaded', function () {
  <?php if ($autoOpenModal): ?>
  openDonateModal();
  <?php endif; ?>

  var payOnlineRadio = document.getElementById('payOnlineRadio');
  var payLaterRadio = document.getElementById('payLaterRadio');
  var manualGroup = document.getElementById('manualMethodGroup');
  var submitBtn = document.getElementById('donateSubmitBtn');
  var submitHint = document.getElementById('donateSubmitHint');

  var anonCheck = document.getElementById('donateAnonymousCheck');
  if (anonCheck) {
    var accountName = <?= json_encode($accountName) ?>;
    var nameField = document.getElementById('donateDonorNameField');
    var nameDisplay = document.getElementById('donateDonorDisplay');
    anonCheck.addEventListener('change', function () {
      nameField.value = anonCheck.checked ? '' : accountName;
      nameDisplay.textContent = anonCheck.checked ? 'Anonymous' : accountName;
    });
  }

  function toggleMethodUI() {
    var online = payOnlineRadio.checked;
    manualGroup.style.display = online ? 'none' : 'block';
    document.querySelectorAll('#manualMethodGroup input[name="method_id"]').forEach(function (el) { el.required = !online; });
    submitBtn.textContent = online ? 'Continue to Payment' : 'Donate Now';
    submitHint.textContent = online
      ? "You'll be taken to PayMongo's secure payment page to complete your donation."
      : 'After submitting, please wait for our cashier to verify your payment.';
  }
  payOnlineRadio.addEventListener('change', toggleMethodUI);
  payLaterRadio.addEventListener('change', toggleMethodUI);
  toggleMethodUI();

  document.getElementById('donateForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var errorBox = document.getElementById('donateFormError');
    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    var originalText = submitBtn.textContent;
    submitBtn.textContent = 'Please wait...';

    var formData = new FormData(e.target);
    fetch('<?= url('parishioner/donate.php') ?>', { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success) {
          if (data.redirect) {
            // PayMongo's hosted checkout — a provider-required redirect.
            window.location.href = data.redirect;
            return;
          }
          document.getElementById('donateFormView').style.display = 'none';
          document.getElementById('donateConfirmView').style.display = 'block';
          document.getElementById('donateConfirmMessage').textContent = data.message;
          var detailLink = document.getElementById('donateConfirmDetailLink');
          var referenceBox = document.getElementById('donateConfirmReferenceBox');
          if (data.guest_reference) {
            detailLink.style.display = 'none';
            document.getElementById('donateConfirmReferenceCode').textContent = data.guest_reference;
            referenceBox.style.display = 'block';
          } else {
            detailLink.style.display = '';
            detailLink.href = data.detail_url;
            referenceBox.style.display = 'none';
          }
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
          errorBox.textContent = data.message;
          errorBox.style.display = 'block';
          errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        errorBox.textContent = 'Something went wrong submitting your donation. Please try again.';
        errorBox.style.display = 'block';
      });
  });
});
</script>

<?php include __DIR__ . '/../includes/' . ($isGuest ? 'public-shell-end.php' : 'dash-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
