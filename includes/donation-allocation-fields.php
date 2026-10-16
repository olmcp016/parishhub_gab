<?php
/**
 * Donation form: where the gift goes, the amount, and a read-only preview.
 * Shared by the donation modal on parishioner/services.php and
 * parishioner/donations.php (both post to parishioner/donate.php).
 *
 * ONE donation = ONE allocation: a General Parish Donation (one of
 * DONATION_PURPOSES) OR one active Ongoing Project — never both. Only the
 * chosen destination's field is enabled, so only it is submitted; donate.php
 * checks the same rules again.
 *
 * Expects: $projects (active projects: project_id, project_name) and
 * $preselectedProjectId (from "Donate to This Project", may be null).
 */
$__activeProjectIds = array_map(static fn(array $p): int => (int) $p['project_id'], $projects ?? []);
$__preselectedProject = in_array((int) ($preselectedProjectId ?? 0), $__activeProjectIds, true) ? (int) $preselectedProjectId : null;
$__hasProjects = !empty($__activeProjectIds);
?>
<div class="form-group" id="donationAllocationGroup">
  <label>Where would you like your donation to go?</label>
  <div class="precana-options" role="radiogroup" aria-label="Where would you like your donation to go?">
    <label class="precana-option" style="text-transform:none; letter-spacing:normal;">
      <input type="radio" name="allocation_type" value="general" required data-label="where your donation should go">
      <span class="precana-option-body"><strong>General Parish Donation</strong><span>Supports the parish for the purpose you choose.</span></span>
    </label>
    <label class="precana-option" style="text-transform:none; letter-spacing:normal;<?= $__hasProjects ? '' : ' opacity:.6; cursor:not-allowed;' ?>">
      <input type="radio" name="allocation_type" value="project" required data-label="where your donation should go" <?= $__preselectedProject ? 'checked' : '' ?><?= $__hasProjects ? '' : ' disabled' ?>>
      <span class="precana-option-body"><strong>Support an Ongoing Project</strong><span><?= $__hasProjects ? 'Your whole gift goes to one project you choose.' : 'There are no ongoing projects right now.' ?></span></span>
    </label>
  </div>
</div>

<div class="form-group" id="donationPurposeGroup" hidden>
  <label for="donationPurposeSelect">Purpose of Donation</label>
  <select name="purpose" id="donationPurposeSelect" data-label="a purpose" disabled>
    <option value="">Select a purpose</option>
    <?php foreach (DONATION_PURPOSES as $__purpose): ?>
      <option value="<?= e($__purpose) ?>"><?= e($__purpose) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="form-group" id="donationProjectGroup" hidden>
  <label for="donateProjectSelect">Select an Ongoing Project</label>
  <select name="project_id" id="donateProjectSelect" data-label="an ongoing project" disabled>
    <option value="">Select a project</option>
    <?php foreach ($projects ?? [] as $__project): ?>
      <option value="<?= (int) $__project['project_id'] ?>" <?= $__preselectedProject === (int) $__project['project_id'] ? 'selected' : '' ?>><?= e($__project['project_name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="form-group">
  <label for="donationAmountInput">Donation Amount</label>
  <input type="number" name="amount" id="donationAmountInput" min="1" step="0.01" value="0" class="amount-no-spinner" onfocus="if(this.value==='0') this.value='';" onblur="if(this.value==='') this.value='0';" required>
</div>

<div class="precana-summary" id="donationAllocationPreview" hidden aria-live="polite">
  <strong>Your Donation</strong>
  <p>Donation Type: <span id="donationPreviewType"></span></p>
  <p>Allocation: <span id="donationPreviewAllocation"></span></p>
  <p>Total Donation: <span id="donationPreviewTotal" style="font-weight:700;"></span></p>
</div>

<script>
(function () {
  var group = document.getElementById('donationAllocationGroup');
  var form = group.closest('form');
  var radios = form.querySelectorAll('input[name="allocation_type"]');
  var purposeGroup = document.getElementById('donationPurposeGroup');
  var purposeSelect = document.getElementById('donationPurposeSelect');
  var projectGroup = document.getElementById('donationProjectGroup');
  var projectSelect = document.getElementById('donateProjectSelect');
  var amountInput = document.getElementById('donationAmountInput');
  var preview = document.getElementById('donationAllocationPreview');

  function selectedType() {
    var checked = form.querySelector('input[name="allocation_type"]:checked');
    return checked ? checked.value : '';
  }
  function peso(value) {
    var n = parseFloat(value);
    return '₱' + (isFinite(n) && n > 0 ? n : 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function selectedText(select) {
    return select.value ? select.options[select.selectedIndex].text : 'Not selected yet';
  }
  // Only the chosen destination is enabled (and so submitted); the other is cleared.
  function show(field, wrapper, on) {
    wrapper.hidden = !on;
    field.disabled = !on;
    field.required = on;
    if (!on) field.value = '';
  }
  function updatePreview() {
    var type = selectedType();
    preview.hidden = !type;
    if (!type) return;
    document.getElementById('donationPreviewType').textContent = type === 'project' ? 'Support an Ongoing Project' : 'General Parish Donation';
    document.getElementById('donationPreviewAllocation').textContent = selectedText(type === 'project' ? projectSelect : purposeSelect);
    document.getElementById('donationPreviewTotal').textContent = peso(amountInput.value);
  }
  function updateAllocation() {
    var type = selectedType();
    show(purposeSelect, purposeGroup, type === 'general');
    show(projectSelect, projectGroup, type === 'project');
    updatePreview();
  }
  // Switching type keeps the amount, donor details and payment method; only the destination resets.
  radios.forEach(function (radio) { radio.addEventListener('change', updateAllocation); });
  purposeSelect.addEventListener('change', updatePreview);
  projectSelect.addEventListener('change', updatePreview);
  amountInput.addEventListener('input', updatePreview);
  amountInput.addEventListener('change', updatePreview);

  // A preselected project ("Donate to This Project") opens on that project.
  var initialProject = projectSelect.value;
  updateAllocation();
  if (initialProject && selectedType() === 'project') { projectSelect.value = initialProject; updatePreview(); }
})();
</script>
