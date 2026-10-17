<?php
/**
 * "Thank You for Your Booking!" dialog, opened on arrival after a submission.
 * Include it on the page a booking lands on (Check Status for guests,
 * parishioner/services.php for parishioners). Expects:
 *   $thankYouReference  the reference code (nothing is rendered when empty)
 *   $thankYouIsGuest    true for guests (Check Status page), false for parishioners
 *   $thankYouBooking    parishioners only: that booking (category, status_name)
 *                       when the reference is one of their own bookings, else
 *                       null. Only used to word the message for a Mass Intention.
 *
 * Both get the same simple dialog: message, reference code with Copy, Done.
 * Guests also get a note about the Check Status page.
 */
if (empty($thankYouReference)) return;
$thankYouMessage = 'Your request has been submitted and is pending review by our parish office.';
$thankYouNote = null;
if (!empty($thankYouIsGuest)) {
    $thankYouNote = 'To check the status of your appointment, please enter the reference code on this status page.';
} elseif (($thankYouBooking['category'] ?? '') === 'Mass Intention') {
    // Never "approved" or "paid" unless the database says so: Confirmed only
    // after the payment was verified (PayMongo); otherwise the Cashier verifies it.
    $thankYouMessage = ($thankYouBooking['status_name'] ?? '') === 'Confirmed'
        ? 'Your offering was received and your Mass Intention has been submitted.'
        : 'Your Mass Intention has been submitted. Payment verification by our Cashier is pending.';
}
?>
<!-- ===================== Thank You (after a booking) ===================== -->
<dialog class="modal" id="thankYouModal" aria-labelledby="thankYouTitle">
  <div class="modal-head">
    <h3 id="thankYouTitle">Thank You for Your Booking!</h3>
    <button type="button" class="modal-close" data-thank-you-close aria-label="Close">✕</button>
  </div>
  <div class="modal-body" style="text-align:center;">
    <p style="color: var(--brown-mid); margin: 0 0 16px;"><?= e($thankYouMessage) ?></p>

    <div style="background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 16px; margin-bottom: <?= $thankYouNote ? '16px' : '20px' ?>;">
      <p class="helper-text" style="margin: 0 0 8px;">Your booking reference code</p>
      <div style="display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap;">
        <span id="thankYouCode" style="font-size:24px; font-weight:700; letter-spacing:2px; color: var(--brown-dark);"><?= e($thankYouReference) ?></span>
        <button type="button" class="btn btn-outline btn-sm" id="thankYouCopy" aria-label="Copy reference code">📋 Copy</button>
      </div>
      <p id="thankYouCopied" role="status" aria-live="polite" class="helper-text" style="margin: 8px 0 0; min-height: 1em;"></p>
    </div>

<?php if ($thankYouNote): ?>
    <p class="helper-text" style="margin: 0 0 20px;"><?= e($thankYouNote) ?></p>

<?php endif; ?>
    <button type="button" class="btn btn-primary btn-block" data-thank-you-close>Done</button>
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
