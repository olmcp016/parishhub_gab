<?php
/**
 * "Thank You for Your Booking!" dialog, opened on arrival after a submission.
 * Include it on the page a booking lands on (Check Status for guests,
 * parishioner/services.php for parishioners). Expects:
 *   $thankYouReference  the reference code (nothing is rendered when empty)
 *   $thankYouIsGuest    true for guests (Check Status page), false for parishioners
 *   $thankYouBooking    parishioners only: that booking (appointment_id,
 *                       service_name, category, status_name) when the reference
 *                       is one of their own bookings, else null — then only the
 *                       code is shown, with no details and no View My Booking.
 */
if (empty($thankYouReference)) return;
$thankYouNote = !empty($thankYouIsGuest)
    ? 'To check the status of your appointment, please enter the reference code on this status page.'
    : 'To check the status of your appointment, open My Appointments. Your request is listed there with this reference code.';
$thankYouBooking = empty($thankYouIsGuest) ? ($thankYouBooking ?? null) : null;
if ($thankYouBooking) {
    // The same status labels and badge colours as the My Appointments list.
    $thankYouIsMass = $thankYouBooking['category'] === 'Mass Intention';
    [$thankYouStatus, $thankYouBadge] = $thankYouIsMass
        ? massIntentionStatusDisplay($thankYouBooking['status_name'])
        : [$thankYouBooking['status_name'], badgeClass($thankYouBooking['status_name'])];
    $thankYouNext = !$thankYouIsMass
        ? 'Our parish office will review your request. You can track its progress in My Appointments.'
        : ($thankYouBooking['status_name'] === 'Confirmed'
            ? 'Your offering was received. You can track your Mass Intention in My Appointments.'
            : 'Our Cashier will verify your payment. Once it is verified, your Mass Intention is confirmed. You can track its progress in My Appointments.');
    $thankYouDetailUrl = url('parishioner/appointment-detail.php?id=' . (int) $thankYouBooking['appointment_id']);
    $thankYouOpenUrl = url('parishioner/appointments.php?open=' . (int) $thankYouBooking['appointment_id']);
}
?>
<!-- ===================== Thank You (after a booking) ===================== -->
<dialog class="modal" id="thankYouModal" aria-labelledby="thankYouTitle">
  <div class="modal-head">
    <h3 id="thankYouTitle">Thank You for Your Booking!</h3>
    <button type="button" class="modal-close" data-thank-you-close aria-label="Close">✕</button>
  </div>
  <div class="modal-body" style="text-align:center;">
<?php if (empty($thankYouIsGuest)): ?>
    <p style="color: var(--brown-dark); font-weight:600; margin: 0 0 6px;">Your service booking request has been successfully submitted.</p>
    <p style="color: var(--brown-mid); margin: 0 0 16px;"><?= e($thankYouBooking ? $thankYouNext : 'Our parish office will review your request. You can track its progress in My Appointments.') ?></p>

    <div style="background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 16px; margin-bottom: 20px;">
      <?php if ($thankYouBooking): ?>
        <p class="helper-text" style="margin: 0 0 2px;">Service</p>
        <p id="thankYouService" style="font-weight:600; color: var(--brown-dark); margin: 0 0 12px;"><?= e($thankYouBooking['service_name']) ?></p>
      <?php endif; ?>
      <p class="helper-text" style="margin: 0 0 8px;">Your booking reference code</p>
      <div style="display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap;">
        <span id="thankYouCode" style="font-size:24px; font-weight:700; letter-spacing:2px; color: var(--brown-dark); overflow-wrap:anywhere;"><?= e($thankYouReference) ?></span>
        <button type="button" class="btn btn-outline btn-sm" id="thankYouCopy" aria-label="Copy reference code">📋 Copy</button>
      </div>
      <p id="thankYouCopied" role="status" aria-live="polite" class="helper-text" style="margin: 8px 0 0; min-height: 1em;"></p>
      <?php if ($thankYouBooking): ?>
        <p class="helper-text" style="margin: 8px 0 4px;">Current Status</p>
        <span id="thankYouStatus" class="badge badge-<?= e($thankYouBadge) ?>"><?= e($thankYouStatus) ?></span>
      <?php endif; ?>
    </div>

    <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap;">
      <?php if ($thankYouBooking): ?>
        <a href="<?= $thankYouDetailUrl ?>" class="btn btn-outline" id="thankYouView" data-open-url="<?= $thankYouOpenUrl ?>">View My Booking</a>
      <?php endif; ?>
      <button type="button" class="btn btn-primary" data-thank-you-close>Done</button>
    </div>
<?php else: ?>
    <p style="color: var(--brown-mid); margin: 0 0 16px;">Your request has been submitted and is pending review by our parish office.</p>

    <div style="background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 16px; margin-bottom: 16px;">
      <p class="helper-text" style="margin: 0 0 8px;">Your booking reference code</p>
      <div style="display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap;">
        <span id="thankYouCode" style="font-size:24px; font-weight:700; letter-spacing:2px; color: var(--brown-dark);"><?= e($thankYouReference) ?></span>
        <button type="button" class="btn btn-outline btn-sm" id="thankYouCopy" aria-label="Copy reference code">📋 Copy</button>
      </div>
      <p id="thankYouCopied" role="status" aria-live="polite" class="helper-text" style="margin: 8px 0 0; min-height: 1em;"></p>
    </div>

    <p class="helper-text" style="margin: 0 0 20px;"><?= e($thankYouNote) ?></p>

    <button type="button" class="btn btn-primary btn-block" data-thank-you-close>Done</button>
<?php endif; ?>
  </div>
</dialog>
<script>
(function () {
  var dialog = document.getElementById('thankYouModal');
  if (!dialog) return;
  dialog.showModal();

  // Done and the ✕ close the dialog and leave the page as it is.
  dialog.querySelectorAll('[data-thank-you-close]').forEach(function (btn) {
    btn.addEventListener('click', function () { dialog.close(); });
  });

  // View My Booking: close this dialog and go to My Appointments, which opens
  // this appointment's View Details (parishioner/appointments.php?open=N).
  // Without JavaScript it is a plain link to the appointment page.
  var viewLink = document.getElementById('thankYouView');
  if (viewLink) viewLink.addEventListener('click', function (e) {
    e.preventDefault();
    dialog.close();
    window.location.href = viewLink.dataset.openUrl;
  });

  var copyBtn = document.getElementById('thankYouCopy');
  var copiedNote = document.getElementById('thankYouCopied');
  var code = document.getElementById('thankYouCode').textContent.trim();
  var resetTimer = null;

  function showCopied(message) {
    copiedNote.textContent = message;
    clearTimeout(resetTimer);
    resetTimer = setTimeout(function () { copiedNote.textContent = ''; }, 2000);
  }

  // Fallback for browsers or non-HTTPS pages without the async clipboard API.
  function copyWithSelection() {
    var field = document.createElement('textarea');
    field.value = code;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    field.remove();
    if (ok) showCopied('Copied!');
    else showCopied('Could not copy. Please select the code and copy it.');
  }

  copyBtn.addEventListener('click', function () {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(code).then(function () { showCopied('Copied!'); }, copyWithSelection);
    } else {
      copyWithSelection();
    }
  });
})();
</script>
