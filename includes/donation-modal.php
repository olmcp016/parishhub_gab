<?php
/**
 * The "Donate to Our Parish" modal — the one donation form, shared by
 * parishioner/services.php (Donate Now, ?donate=1, ?project_id=N) and
 * parishioner/projects.php ("Donate to This Project", which opens it in place).
 * It posts to parishioner/donate.php; PayMongo returns to Services either way.
 *
 * Expects: $isGuest, $accountName, $projects (active projects),
 * $preselectedProjectId (may be null) and $autoOpenDonate (open on page load).
 *
 * JavaScript: openDonateModal(projectId?) opens it, on that project when given
 * (only if it is an active project listed here; donate.php re-checks it);
 * closeDonateModal() closes it.
 */
?>
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

        <?php include __DIR__ . '/donation-allocation-fields.php'; // where the gift goes, amount, preview ?>

        <div class="form-group">
          <label>How would you like to pay?</label>
          <label class="radio-option" style="display:block; margin-bottom:8px;">
            <input type="radio" name="pay_online" value="1" id="payOnlineRadio" checked>
            <strong>Pay Online Now</strong> — Card, GCash, or Maya via PayMongo (instant, secure)
          </label>
          <label class="radio-option" style="display:block;">
            <input type="radio" name="pay_online" value="0" id="payLaterRadio">
            <strong>Pay Later / In Person</strong> — Cash at Parish Office
          </label>
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
// A Cash / Pay Later donation that was just submitted: its confirmation (and
// reference code) stays up until the modal is opened again, which then starts
// a fresh, empty form instead of showing the previous donation's details.
var donateFormCompleted = false;

function openDonateModal(projectId) {
  if (donateFormCompleted) resetDonateForm();
  document.getElementById('donateFormView').style.display = 'block';
  document.getElementById('donateConfirmView').style.display = 'none';
  document.getElementById('donateFormError').style.display = 'none';
  // "Donate to This Project": always that project, whatever was chosen before.
  if (projectId) window.donationAllocation.selectProject(projectId);
  document.getElementById('donateModal').showModal();
}
function closeDonateModal() {
  document.getElementById('donateModal').close();
}
function resetDonateForm() {
  var form = document.getElementById('donateForm');
  form.reset();
  if (typeof clearFieldError === 'function') {
    Array.prototype.forEach.call(form.elements, function (el) { clearFieldError(el); });
  }
  // A hidden input's value IS its default, so reset() cannot restore it.
  var nameField = document.getElementById('donateDonorNameField');
  if (nameField) nameField.value = <?= json_encode($accountName) ?>;
  var nameDisplay = document.getElementById('donateDonorDisplay');
  if (nameDisplay) nameDisplay.textContent = <?= json_encode($accountName) ?>;
  // reset() fires no change events: re-apply the allocation and payment UI.
  window.donationAllocation.refresh();
  document.getElementById('donateSubmitBtn').disabled = false;
  document.getElementById('payOnlineRadio').dispatchEvent(new Event('change'));
  donateFormCompleted = false;
}

document.addEventListener('DOMContentLoaded', function () {
  var payOnlineRadio = document.getElementById('payOnlineRadio');
  var payLaterRadio = document.getElementById('payLaterRadio');
  var submitBtn = document.getElementById('donateSubmitBtn');
  var submitHint = document.getElementById('donateSubmitHint');
  var submitting = false;

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
    submitBtn.textContent = online ? 'Continue to Payment' : 'Donate Now';
    submitHint.textContent = online
      ? "You'll be taken to PayMongo's secure payment page to complete your donation."
      : 'After submitting, please wait for our cashier to verify your payment.';
  }
  payOnlineRadio.addEventListener('change', toggleMethodUI);
  payLaterRadio.addEventListener('change', toggleMethodUI);
  toggleMethodUI();
  attachInlineValidation(document.getElementById('donateForm'));
  <?php if (!empty($autoOpenDonate)): // ?donate=1 / ?project_id=N on Services (Dashboard, My Donations, old links) ?>
  openDonateModal();
  <?php endif; ?>

  // Back from PayMongo with the browser's Back button (page restored from
  // cache): the button must not stay stuck on "Please wait...".
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && submitting) { submitting = false; submitBtn.disabled = false; toggleMethodUI(); }
  });

  document.getElementById('donateForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return; // one submission at a time
    var errorBox = document.getElementById('donateFormError');
    errorBox.style.display = 'none';
    submitting = true;
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
          submitting = false;
          donateFormCompleted = true;
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
          submitting = false;
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
          errorBox.textContent = data.message;
          errorBox.style.display = 'block';
          errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      })
      .catch(function () {
        submitting = false;
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        errorBox.textContent = 'Something went wrong submitting your donation. Please try again.';
        errorBox.style.display = 'block';
      });
  });
});
</script>
