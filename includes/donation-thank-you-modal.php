<?php
/**
 * "Thank You for Your Generosity!" dialog, shown once after a verified online
 * (PayMongo) donation — on My Donations / Donate to Our Parish after a normal
 * return, or on Services when the donor pressed Back after paying.
 *
 * Expects $donationThankYou from pullDonationThankYou() (verifiedOnlineDonation():
 * amount, allocation, guest_reference), read from the database — never from the
 * URL. Nothing is rendered when it is empty. Optional $donationThankYouDoneUrl:
 * where Done goes (otherwise Done just closes the dialog). Nothing here touches
 * a payment record.
 */
if (empty($donationThankYou)) return;
?>
<style>
  #donationThankYouModal { width: 460px; background: var(--ivory); border: 1px solid var(--gold-light); }
  #donationThankYouModal[open] { animation: donationThanksIn .28s ease-out; }
  #donationThankYouModal::backdrop { animation: donationThanksFade .28s ease-out; }
  @keyframes donationThanksIn { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }
  @keyframes donationThanksFade { from { opacity: 0; } to { opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { #donationThankYouModal[open], #donationThankYouModal::backdrop { animation: none; } }
  .donation-thanks { position: relative; text-align: center; padding: 30px 26px 24px; overflow-y: auto; border-top: 4px solid var(--gold); }
  .donation-thanks-close { position: absolute; top: 10px; right: 12px; color: var(--brown-mid); }
  .donation-thanks-check { width: 72px; height: 72px; margin: 0 auto 14px; border-radius: 50%; background: var(--success-bg); border: 2px solid #b8e6c8; display: flex; align-items: center; justify-content: center; }
  .donation-thanks-check svg { width: 38px; height: 38px; }
  .donation-thanks h2 { font-size: 24px; margin: 0 0 10px; color: var(--brown-dark); }
  .donation-thanks p { color: var(--brown-mid); margin: 0 0 10px; line-height: 1.55; }
  .donation-thanks-blessing { font-style: italic; color: var(--gold-dark) !important; font-weight: 600; }
  .donation-thanks-details { margin: 18px 0; padding: 4px 16px; background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 12px; text-align: left; }
  .donation-thanks-details div { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--cream-dark); font-size: 14px; }
  .donation-thanks-details div:last-child { border-bottom: none; }
  .donation-thanks-details dt { color: var(--brown-mid); flex: 0 0 auto; }
  .donation-thanks-details dd { margin: 0; font-weight: 700; color: var(--brown-dark); text-align: right; min-width: 0; overflow-wrap: anywhere; }
  .donation-thanks-paid { color: var(--success) !important; }
  .donation-thanks-reference { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  @media (max-width: 480px) {
    .donation-thanks { padding: 26px 16px 18px; }
    .donation-thanks h2 { font-size: 21px; }
    .donation-thanks-details div { flex-direction: column; gap: 2px; }
    .donation-thanks-details dd { text-align: left; }
    .donation-thanks-reference { justify-content: flex-start; }
  }
</style>
<dialog class="modal" id="donationThankYouModal" aria-labelledby="donationThankYouTitle" aria-describedby="donationThankYouMessage">
  <div class="donation-thanks">
    <button type="button" class="modal-close donation-thanks-close" data-donation-thanks-close aria-label="Close">✕</button>
    <div class="donation-thanks-check" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="#23663a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
    </div>
    <h2 id="donationThankYouTitle">Thank You for Your Generosity!</h2>
    <div id="donationThankYouMessage">
      <p>Your donation has been successfully received.</p>
      <p>Your kindness and generosity help support the mission, services, and ongoing projects of Our Lady of Mt. Carmel Parish.</p>
      <p class="donation-thanks-blessing">May God bless you for your generous heart!</p>
    </div>

    <dl class="donation-thanks-details">
      <div><dt>Donation Amount</dt><dd><?= e(money((float) $donationThankYou['amount'])) ?></dd></div>
      <div><dt>Donation Allocation</dt><dd><?= e($donationThankYou['allocation']) ?></dd></div>
      <div><dt>Payment Method</dt><dd>PayMongo</dd></div>
      <div><dt>Payment Status</dt><dd class="donation-thanks-paid">Paid</dd></div>
      <?php if (!empty($donationThankYou['guest_reference'])): ?>
      <div>
        <dt>Reference Code</dt>
        <dd>
          <span class="donation-thanks-reference">
            <span id="donationThankYouCode"><?= e($donationThankYou['guest_reference']) ?></span>
            <button type="button" class="btn btn-outline btn-sm" id="donationThankYouCopy" aria-label="Copy reference code">Copy</button>
          </span>
          <span id="donationThankYouCopied" role="status" aria-live="polite" class="helper-text" style="display:block; margin:4px 0 0; min-height:1em; font-weight:400;"></span>
        </dd>
      </div>
      <?php endif; ?>
    </dl>
    <?php if (!empty($donationThankYou['guest_reference'])): ?>
      <p class="helper-text" style="margin-top:-6px;">Save this reference code to check your donation on the Check Status page.</p>
    <?php endif; ?>

    <button type="button" class="btn btn-primary btn-block" id="donationThankYouDone"<?= !empty($donationThankYouDoneUrl) ? ' data-done-url="' . e($donationThankYouDoneUrl) . '"' : ' data-donation-thanks-close' ?>>Done</button>
  </div>
</dialog>
<script>
(function () {
  var dialog = document.getElementById('donationThankYouModal');
  if (!dialog) return;
  dialog.showModal(); // modal: above the page and nav, focus kept inside, Esc closes
  var done = document.getElementById('donationThankYouDone');
  // Focus Done without scrolling it into view: on short screens the dialog opens at the top (check + heading).
  done.focus({ preventScroll: true });
  dialog.querySelector('.donation-thanks').scrollTop = 0;
  // ✕ and Esc only close the dialog. Done does too — or, when the page set a
  // destination (My Donations -> Services), goes there with a plain GET.
  // None of them touches a payment.
  dialog.querySelectorAll('[data-donation-thanks-close]').forEach(function (btn) {
    btn.addEventListener('click', function () { dialog.close(); });
  });
  if (done.dataset.doneUrl) {
    done.addEventListener('click', function () { window.location.assign(done.dataset.doneUrl); });
  }

  var copyBtn = document.getElementById('donationThankYouCopy');
  if (!copyBtn) return;
  var note = document.getElementById('donationThankYouCopied');
  var code = document.getElementById('donationThankYouCode').textContent.trim();
  var timer = null;
  function show(message) { note.textContent = message; clearTimeout(timer); timer = setTimeout(function () { note.textContent = ''; }, 2000); }
  function copyWithSelection() {
    var field = document.createElement('textarea');
    field.value = code; field.setAttribute('readonly', ''); field.style.position = 'fixed'; field.style.opacity = '0';
    document.body.appendChild(field); field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    field.remove();
    show(ok ? 'Copied!' : 'Could not copy. Please select the code and copy it.');
  }
  copyBtn.addEventListener('click', function () {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(code).then(function () { show('Copied!'); }, copyWithSelection);
    } else {
      copyWithSelection();
    }
  });
})();
</script>
