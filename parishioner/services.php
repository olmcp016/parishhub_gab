<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Parishioner');

$services = db()->query("SELECT * FROM services WHERE is_active = 1 AND category != 'Donation' ORDER BY category, service_name")->fetchAll();
$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$blockedRows = db()->query('SELECT calendar_date, notes FROM calendar WHERE is_blocked = 1')->fetchAll();
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blockedRows);
$preselectedDate = $_GET['date'] ?? '';

$policies = [];
$requirementsByService = [];
foreach ($services as $s) {
    $policies[$s['category']] = schedulingPolicyText($s['category'], (int) $s['service_id']);
    $requirementsByService[$s['service_id']] = parseRequirementsList($s['requirements']);
}
foreach (['Mass Intention', 'Funeral', 'First Communion'] as $cat) {
    if (!isset($policies[$cat])) {
        $policies[$cat] = schedulingPolicyText($cat);
    }
}

// These 4 categories offer a Regular (parish-fixed slot) vs Special
// (custom date/time, availability-checked) choice — see includes/scheduling.php.
$scheduleToggleCategories = ['Baptism', 'Wedding', 'Blessing', 'Confirmation'];

$active = 'services';
$pageTitle = 'Available Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="grid-3">
  <?php foreach ($services as $s): ?>
    <?php $isMassIntention = $s['category'] === 'Mass Intention'; ?>
    <div class="card">
      <h3><?= e($s['service_name']) ?></h3>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;"><?= e($s['category']) ?></p>
      <p style="font-size:14px;"><?= e($s['description']) ?></p>
      <?php if ($s['requirements']): ?>
        <details class="requirements-toggle">
          <summary>Requirements</summary>
          <p><?= e($s['requirements']) ?></p>
        </details>
      <?php endif; ?>
      <div class="flex-between mt-3">
        <span class="text-gold" style="font-weight:700; font-size:18px;"><?= feeLabel((float) $s['fee']) ?></span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openBookModal(<?= $s['service_id'] ?>)"><?= $isMassIntention ? 'Enter Intentions' : 'Book Now' ?></button>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($donationEnabled): ?>
    <div class="card" style="background: linear-gradient(135deg, var(--cream-dark), #faf0d0); display:flex; flex-direction:column;">
      <h3>🤲 Donate to Our Parish</h3>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;">Donation</p>
      <p style="font-size:14px;">Support our ministries and services with a voluntary offering — any amount is welcome.</p>
      <div class="flex-between mt-3" style="margin-top:auto;">
        <span class="text-gold" style="font-weight:700; font-size:18px;">Voluntary</span>
        <a href="<?= url('parishioner/donations.php?donate=1') ?>" class="btn btn-primary btn-sm">Donate Now</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ===================== Booking Modal ===================== -->
<dialog class="modal modal-lg" id="bookModal">
  <div class="modal-head">
    <h3 id="bookModalTitle">Book a Service</h3>
    <button type="button" class="modal-close" onclick="closeBookModal()">✕</button>
  </div>
  <div class="modal-body">

    <div id="bookFormView">
      <div id="policyBox" class="alert" style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);"></div>

      <form method="POST" action="<?= url('parishioner/book.php') ?>" enctype="multipart/form-data" id="bookForm">
        <?= csrfField() ?>
        <input type="hidden" name="ajax" value="1">
        <div class="form-group">
          <label>Select Service</label>
          <select name="service_id" id="serviceSelect" required>
            <option value="">-- Choose a service --</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= $s['service_id'] ?>" data-category="<?= e($s['category']) ?>">
                <?= e($s['service_name']) ?> (<?= feeLabel((float) $s['fee']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="dateOfDeathGroup" class="form-group" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Date of Death</label>
          <input type="date" name="date_of_death" id="dateOfDeathInput" max="<?= date('Y-m-d') ?>">
          <p class="helper-text" id="earliestFuneralHint"></p>
        </div>

        <div class="form-group" id="scheduleTypeGroup" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Scheduling Option <span class="badge" id="scheduleTypeBadge" style="margin-left:6px;"></span></label>
          <label style="font-weight:400; display:block; margin-bottom:6px;">
            <input type="radio" name="schedule_type" value="Regular" id="scheduleTypeRegular" checked>
            Regular — choose from the parish's fixed available slots
          </label>
          <label style="font-weight:400; display:block;">
            <input type="radio" name="schedule_type" value="Special" id="scheduleTypeSpecial">
            Special — request a custom date &amp; time (availability is checked automatically)
          </label>
        </div>

        <div id="regularSlotGroup" class="form-group" style="display:none;">
          <label>Available Slot</label>
          <select id="regularSlotSelect">
            <option value="">Loading available slots…</option>
          </select>
          <input type="hidden" name="appointment_date" id="regularDateInput" disabled>
          <input type="hidden" name="appointment_time" id="regularTimeInput" disabled>
          <p class="helper-text" id="regularSlotHint"></p>
        </div>

        <div class="form-row" id="dateTimeRow">
          <div class="form-group">
            <label>Preferred Date</label>
            <input type="date" name="appointment_date" id="appointmentDateInput" required min="<?= date('Y-m-d') ?>" value="<?= e($preselectedDate) ?>">
            <button type="button" class="btn btn-outline btn-sm mt-2" id="togglePickerBtn">📅 Pick from calendar</button>
            <div id="miniCalendarWrap" style="display:none; margin-top:10px;">
              <div id="miniCalendar"></div>
            </div>
          </div>
          <div class="form-group">
            <label>Preferred Time</label>

            <div id="freeTimeGroup">
              <input type="time" name="appointment_time" id="freeTimeInput">
              <p class="helper-text" id="occupiedTimesHint"></p>
            </div>

            <div id="fixedTimeGroup" style="display:none;">
              <input type="text" id="fixedTimeDisplay" disabled>
              <input type="hidden" name="appointment_time" id="fixedTimeInput">
            </div>

            <div id="massTimeGroup" style="display:none;">
              <input type="text" id="massTimeDisplay" disabled>
              <input type="hidden" name="appointment_time" id="massTimeInput">
              <p class="helper-text" id="massTimeHint">Select a date to see the assigned Mass time.</p>
            </div>
          </div>
        </div>

        <div id="availabilityMsg" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <div class="form-group" id="priestFieldGroup">
          <label>Preferred Priest (optional)</label>
          <select name="priest_id" id="priestSelect">
            <option value="">No preference</option>
            <?php foreach ($priests as $p): ?>
              <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="helper-text">Choosing a specific priest is subject to his availability — the secretary will confirm.</p>
        </div>

        <div id="intentionFields" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">Mass Intention Details</h4>
          <p class="helper-text" style="margin-top:-4px;">Mass Intention requests are approved instantly — no documents needed, and no fixed fee. You'll choose your own offering amount at payment.</p>
          <div class="form-group">
            <label>Intention Type</label>
            <select name="intention_type">
              <option>Living</option>
              <option>Dead</option>
              <option>Thanksgiving</option>
              <option>Healing</option>
              <option>Birthday</option>
            </select>
          </div>
          <div class="form-group">
            <label>Offerer Name</label>
            <input type="text" name="offerer_name" id="offererNameInput" placeholder="Your full name">
          </div>
          <div class="form-group">
            <label>Intention For</label>
            <input type="text" name="intention_for" id="intentionForInput" placeholder="Name(s) the mass is offered for">
          </div>
          <div class="form-group">
            <label>Prayer Message (optional)</label>
            <textarea name="message" rows="2"></textarea>
          </div>
        </div>

        <div class="form-group">
          <label>Additional Remarks</label>
          <textarea name="remarks" rows="2" placeholder="Any special requests..."></textarea>
        </div>

        <div class="form-group" id="uploadGroup">
          <label>Required Documents</label>
          <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); font-size:13px; margin-bottom:12px;">
            <strong>Document Requirements:</strong>
            <ul style="margin:6px 0 0; padding-left:18px;">
              <li>Please upload a clear and readable document.</li>
              <li>Document must be in portrait orientation.</li>
              <li>Make sure all information is visible.</li>
              <li>Do not upload blurry, corrupted, or incorrect files.</li>
              <li>Upload the required document as a PDF, JPG, or PNG file.</li>
            </ul>
            <p style="margin:8px 0 0;">These automated checks confirm a file is readable and correctly formatted — they do not verify authenticity. Our parish staff will do a final manual review before approval.</p>
          </div>

          <div id="requirementRows"></div>

          <div class="form-group" style="margin-top:10px;">
            <label>Additional Documents (optional)</label>
            <input type="file" name="documents[]" id="extraDocumentsInput" multiple accept=".pdf,.jpg,.jpeg,.png">
            <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total for the whole form. If your photos are larger than that (common for phone camera photos), you can also upload documents later from the appointment detail page instead.</p>
          </div>
        </div>

        <div id="bookFormError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <button type="submit" class="btn btn-primary btn-block" id="bookSubmitBtn">Submit Appointment Request</button>
      </form>
    </div>

    <div id="bookConfirmView" style="display:none; text-align:center; padding: 20px 10px;">
      <div style="font-size:48px; margin-bottom:12px;">✔</div>
      <h3 style="margin:0 0 10px;">Request Submitted!</h3>
      <p id="bookConfirmScheduleType" style="display:none; margin: -4px 0 10px;"></p>
      <p id="bookConfirmMessage" style="color: var(--brown-mid); margin-bottom:20px;"></p>
      <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap;">
        <a href="#" id="bookConfirmDetailLink" class="btn btn-outline">View Appointment</a>
        <button type="button" class="btn btn-primary" onclick="closeBookModal()">Done</button>
      </div>
    </div>

  </div>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
var POLICIES = <?= json_encode($policies, JSON_UNESCAPED_UNICODE) ?>;
var CALENDAR_BLOCKED = <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>;
var REQUIREMENTS_BY_SERVICE = <?= json_encode($requirementsByService, JSON_UNESCAPED_UNICODE) ?>;
var SCHEDULE_TOGGLE_CATEGORIES = <?= json_encode($scheduleToggleCategories) ?>;
var CHECK_AVAILABILITY_URL = <?= json_encode(url('parishioner/check-availability.php')) ?>;

function openBookModal(serviceId) {
  var modal = document.getElementById('bookModal');
  document.getElementById('bookFormView').style.display = 'block';
  document.getElementById('bookConfirmView').style.display = 'none';
  document.getElementById('bookFormError').style.display = 'none';
  document.getElementById('bookForm').reset();

  var select = document.getElementById('serviceSelect');
  select.value = serviceId;
  var category = select.options[select.selectedIndex]?.dataset.category || '';
  document.getElementById('bookModalTitle').textContent = category === 'Mass Intention' ? 'Enter Mass Intentions' : 'Book a Service';
  document.getElementById('scheduleTypeRegular').checked = true;

  <?php if ($preselectedDate): ?>
  document.getElementById('appointmentDateInput').value = <?= json_encode($preselectedDate) ?>;
  <?php endif; ?>

  toggleServiceUI();
  modal.showModal();
}

function closeBookModal() {
  document.getElementById('bookModal').close();
}

function getScheduleType() {
  return document.getElementById('scheduleTypeSpecial').checked ? 'Special' : 'Regular';
}

function toggleServiceUI() {
  var select = document.getElementById('serviceSelect');
  var selectedOption = select.options[select.selectedIndex];
  var category = selectedOption ? selectedOption.dataset.category || '' : '';
  var serviceId = selectedOption ? selectedOption.value : '';

  var isMassIntention = category === 'Mass Intention';
  var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;
  var scheduleType = usesToggle ? getScheduleType() : null;

  document.getElementById('bookModalTitle').textContent = isMassIntention ? 'Enter Mass Intentions' : 'Book a Service';
  document.getElementById('intentionFields').style.display = isMassIntention ? 'block' : 'none';
  document.getElementById('offererNameInput').required = isMassIntention;
  document.getElementById('intentionForInput').required = isMassIntention;
  document.getElementById('dateOfDeathGroup').style.display = category === 'Funeral' ? 'block' : 'none';
  document.getElementById('dateOfDeathInput').required = (category === 'Funeral');

  // Priests do not personally read Mass Intentions, so there's nothing to prefer.
  var priestGroup = document.getElementById('priestFieldGroup');
  var priestSelect = document.getElementById('priestSelect');
  priestGroup.style.display = isMassIntention ? 'none' : 'block';
  priestSelect.disabled = isMassIntention;
  if (isMassIntention) priestSelect.value = '';

  // No documents are required for Mass Intentions — they're approved instantly.
  var uploadGroup = document.getElementById('uploadGroup');
  uploadGroup.style.display = isMassIntention ? 'none' : 'block';
  if (!isMassIntention) rebuildRequirementRows(serviceId);

  var policyBox = document.getElementById('policyBox');
  if (POLICIES[category]) {
    policyBox.style.display = 'block';
    policyBox.textContent = 'ℹ ' + POLICIES[category];
  } else {
    policyBox.style.display = 'none';
  }

  // Regular/Special toggle, only for the 4 admin-configurable categories.
  var scheduleTypeGroup = document.getElementById('scheduleTypeGroup');
  scheduleTypeGroup.style.display = usesToggle ? 'block' : 'none';
  var badge = document.getElementById('scheduleTypeBadge');
  if (usesToggle) {
    badge.textContent = scheduleType;
    badge.className = 'badge ' + (scheduleType === 'Regular' ? 'badge-regular' : 'badge-special');
  } else {
    badge.textContent = '';
  }

  var freeGroup = document.getElementById('freeTimeGroup');
  var fixedGroup = document.getElementById('fixedTimeGroup');
  var massGroup = document.getElementById('massTimeGroup');
  var regularGroup = document.getElementById('regularSlotGroup');
  var dateTimeRow = document.getElementById('dateTimeRow');
  var freeInput = document.getElementById('freeTimeInput');
  var fixedHidden = document.getElementById('fixedTimeInput');
  var massHidden = document.getElementById('massTimeInput');
  var regularDateHidden = document.getElementById('regularDateInput');
  var regularTimeHidden = document.getElementById('regularTimeInput');
  var dateInput = document.getElementById('appointmentDateInput');

  freeGroup.style.display = 'none'; freeInput.disabled = true;
  fixedGroup.style.display = 'none'; fixedHidden.disabled = true;
  massGroup.style.display = 'none'; massHidden.disabled = true;
  regularGroup.style.display = 'none'; regularDateHidden.disabled = true; regularTimeHidden.disabled = true;
  dateTimeRow.style.display = 'flex'; dateInput.disabled = false;

  if (category === 'Funeral') {
    fixedGroup.style.display = 'block';
    fixedHidden.disabled = false;
    document.getElementById('fixedTimeDisplay').value = formatTimeLabel('13:00') + ' (fixed)';
    fixedHidden.value = '13:00';
  } else if (isMassIntention) {
    massGroup.style.display = 'block';
    massHidden.disabled = false;
    autoAssignMassTime();
  } else if (usesToggle && scheduleType === 'Regular') {
    dateTimeRow.style.display = 'none';
    dateInput.disabled = true;
    regularGroup.style.display = 'block';
    regularDateHidden.disabled = false;
    regularTimeHidden.disabled = false;
    loadRegularSlots(serviceId);
  } else {
    // Special mode for the 4 toggle categories, or First Communion (always free-form).
    freeGroup.style.display = 'block';
    freeInput.disabled = false;
    updateOccupiedTimesHint();
  }

  updateEarliestFuneralHint();
  rebuildMiniCalendar();
  refreshAvailability();
}

/** Fetches this service's upcoming Regular slots and populates the dropdown. */
function loadRegularSlots(serviceId) {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';
  var slotSelect = document.getElementById('regularSlotSelect');
  var hint = document.getElementById('regularSlotHint');
  slotSelect.innerHTML = '<option value="">Loading available slots…</option>';

  fetch(CHECK_AVAILABILITY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ service_id: serviceId, category: category, schedule_type: 'Regular' })
  })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      var slots = data.regular_slots || [];
      if (!slots.length) {
        slotSelect.innerHTML = '<option value="">No upcoming slots available</option>';
        hint.textContent = 'No Regular slots are currently available for this service — please choose Special instead, or check back later.';
        applyRegularSlot();
        return;
      }
      slotSelect.innerHTML = slots.map(function (s) {
        return '<option value="' + s.date + '|' + s.time + '" data-date="' + s.date + '" data-time="' + s.time + '">' + s.label + '</option>';
      }).join('');
      hint.textContent = '';
      applyRegularSlot();
    })
    .catch(function () {
      slotSelect.innerHTML = '<option value="">Could not load slots — please try again</option>';
    });
}

function applyRegularSlot() {
  var slotSelect = document.getElementById('regularSlotSelect');
  var opt = slotSelect.options[slotSelect.selectedIndex];
  var dateHidden = document.getElementById('regularDateInput');
  var timeHidden = document.getElementById('regularTimeInput');
  dateHidden.value = opt && opt.dataset.date ? opt.dataset.date : '';
  timeHidden.value = opt && opt.dataset.time ? opt.dataset.time : '';
  refreshAvailability();
}

/**
 * Live pre-submit check: re-validates the currently selected date/time via
 * the server's scheduling rules and refreshes which priests are available.
 * Advisory only — book.php re-runs the same checks before actually saving.
 */
function refreshAvailability() {
  var select = document.getElementById('serviceSelect');
  var selectedOption = select.options[select.selectedIndex];
  var category = selectedOption ? selectedOption.dataset.category || '' : '';
  var serviceId = selectedOption ? selectedOption.value : '';
  if (!category) return;

  var effective = getEffectiveDateTime(category);
  var msgBox = document.getElementById('availabilityMsg');
  var submitBtn = document.getElementById('bookSubmitBtn');

  if (!effective.date || !effective.time) {
    msgBox.style.display = 'none';
    submitBtn.disabled = false;
    return;
  }

  var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;
  var payload = {
    service_id: serviceId,
    category: category,
    schedule_type: usesToggle ? getScheduleType() : null,
    appointment_date: effective.date,
    appointment_time: effective.time,
    priest_id: document.getElementById('priestSelect').value || null,
    date_of_death: document.getElementById('dateOfDeathInput').value || null
  };

  fetch(CHECK_AVAILABILITY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data.valid === false) {
        msgBox.textContent = '⚠ ' + data.message;
        msgBox.style.display = 'block';
        submitBtn.disabled = true;
      } else {
        msgBox.style.display = 'none';
        submitBtn.disabled = false;
      }
      rebuildPriestOptions(data.available_priests || []);
    })
    .catch(function () { /* Advisory check only — submit still re-validates server-side. */ });
}

/** Reads the date+time currently in effect for the selected category/mode. */
function getEffectiveDateTime(category) {
  var isMassIntention = category === 'Mass Intention';
  var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;

  if (category === 'Funeral') {
    return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('fixedTimeInput').value };
  }
  if (isMassIntention) {
    return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('massTimeInput').value };
  }
  if (usesToggle && getScheduleType() === 'Regular') {
    return { date: document.getElementById('regularDateInput').value, time: document.getElementById('regularTimeInput').value };
  }
  return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('freeTimeInput').value };
}

/** Rebuilds the priest <select> as Available/Unavailable optgroups from a check-availability.php response. */
function rebuildPriestOptions(priests) {
  var select = document.getElementById('priestSelect');
  if (select.disabled || !priests.length) return;
  var current = select.value;

  var html = '<option value="">No preference</option>';
  var available = priests.filter(function (p) { return p.available; });
  var unavailable = priests.filter(function (p) { return !p.available; });

  if (available.length) {
    html += '<optgroup label="Available">' + available.map(function (p) {
      return '<option value="' + p.priest_id + '">' + p.label + '</option>';
    }).join('') + '</optgroup>';
  }
  if (unavailable.length) {
    html += '<optgroup label="Unavailable">' + unavailable.map(function (p) {
      return '<option value="' + p.priest_id + '" disabled title="' + (p.reason || '') + '">' + p.label + ' — Unavailable</option>';
    }).join('') + '</optgroup>';
  }
  select.innerHTML = html;

  // Keep the parishioner's selection unless it just became unavailable.
  var stillAvailable = available.some(function (p) { return String(p.priest_id) === current; });
  select.value = stillAvailable ? current : '';
}

/**
 * Builds one file-upload row (with a live status pill) per parsed service
 * requirement. Each gets its own uniquely-named field (req_doc_0, req_doc_1,
 * ...) rather than a shared documents[] array, so the server can match each
 * upload to its requirement by field name alone — no fragile positional
 * pairing with a parallel labels array.
 */
function rebuildRequirementRows(serviceId) {
  var container = document.getElementById('requirementRows');
  var items = REQUIREMENTS_BY_SERVICE[serviceId] || [];
  container.innerHTML = items.map(function (label, i) {
    return (
      '<div class="form-group doc-req-row">' +
        '<label>' + label + ' <span class="badge badge-cancelled doc-status-pill">Missing</span></label>' +
        '<input type="file" name="req_doc_' + i + '" accept=".pdf,.jpg,.jpeg,.png">' +
      '</div>'
    );
  }).join('');

  container.querySelectorAll('input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () { checkRequirementFile(input); });
  });
}

/** Client-side pre-check (extension/size/portrait) — advisory; book.php is authoritative. */
function checkRequirementFile(input) {
  var pill = input.closest('.doc-req-row').querySelector('.doc-status-pill');
  var file = input.files[0];

  if (!file) {
    pill.textContent = 'Missing';
    pill.className = 'badge badge-cancelled doc-status-pill';
    return;
  }

  pill.textContent = 'Checking…';
  pill.className = 'badge badge-pending doc-status-pill';

  var allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
  var ext = file.name.split('.').pop().toLowerCase();
  if (allowedExt.indexOf(ext) === -1) {
    pill.textContent = 'Invalid';
    pill.className = 'badge badge-rejected doc-status-pill';
    return;
  }

  if (ext === 'pdf') {
    pill.textContent = 'Valid';
    pill.className = 'badge badge-pending doc-status-pill';
    return;
  }

  checkImagePortrait(file, function (ok) {
    if (ok) {
      pill.textContent = 'Valid';
      pill.className = 'badge badge-pending doc-status-pill';
    } else {
      pill.textContent = 'Invalid';
      pill.className = 'badge badge-rejected doc-status-pill';
    }
  });
}

/**
 * Mass Intention times are never chosen by the parishioner — the time is
 * assigned automatically from the parish's Mass schedule for the selected
 * date (the earliest/only official Mass time that day).
 */
function autoAssignMassTime() {
  var dateInput = document.getElementById('appointmentDateInput');
  var display = document.getElementById('massTimeDisplay');
  var hidden = document.getElementById('massTimeInput');
  var hint = document.getElementById('massTimeHint');

  if (!dateInput.value) {
    display.value = '';
    hidden.value = '';
    hint.textContent = 'Select a date to see the assigned Mass time.';
    return;
  }
  var t = massTimesForJS(dateInput.value)[0];
  display.value = formatTimeLabel(t) + ' (assigned automatically)';
  hidden.value = t;
  hint.textContent = 'This Mass Intention will be offered during the ' + formatTimeLabel(t) + ' Mass on the selected date.';
  refreshAvailability();
}

/** For free-choice date/time entry (First Communion, or Special mode) — show which times that date is already occupied by a scheduled Mass. */
function updateOccupiedTimesHint() {
  var dateInput = document.getElementById('appointmentDateInput');
  var hint = document.getElementById('occupiedTimesHint');
  if (!dateInput.value) {
    hint.textContent = '';
  } else {
    var times = massTimesForJS(dateInput.value).map(formatTimeLabel);
    hint.textContent = 'Occupied by Mass on this date: ' + times.join(', ') + '. Please choose a different time.';
  }
  refreshAvailability();
}

function updateEarliestFuneralHint() {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';
  var hint = document.getElementById('earliestFuneralHint');
  var dodInput = document.getElementById('dateOfDeathInput');
  var dateInput = document.getElementById('appointmentDateInput');

  if (category !== 'Funeral' || !dodInput.value) {
    hint.textContent = '';
    return;
  }
  var earliest = addDaysJS(dodInput.value, 9);
  hint.textContent = 'Earliest available funeral Mass date: ' + earliest + ' (9-day mourning period), fixed at 1:00 PM.';
  dateInput.min = earliest;
}

function rebuildMiniCalendar() {
  var wrap = document.getElementById('miniCalendarWrap');
  if (wrap.style.display === 'none') return;
  buildMiniCalendarNow();
}

function buildMiniCalendarNow() {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';
  var dodInput = document.getElementById('dateOfDeathInput');
  var dateInput = document.getElementById('appointmentDateInput');

  var now = new Date();
  var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

  var earliestFuneral = (category === 'Funeral' && dodInput.value) ? addDaysJS(dodInput.value, 9) : null;

  document.getElementById('miniCalendar').innerHTML = '';
  renderParishCalendar('miniCalendar', {
    events: [],
    blocked: CALENDAR_BLOCKED,
    minDate: earliestFuneral && earliestFuneral > todayStr ? earliestFuneral : todayStr,
    isDateDisabled: function (dateStr) {
      // The mini-calendar is only shown for free-form date entry (Funeral,
      // First Communion, or Special mode for the 4 toggle categories) —
      // Regular mode picks from a slot dropdown instead, so no weekday
      // restriction is needed here anymore.
      if (isTuesdayJS(dateStr)) return true;
      if (category === 'Funeral' && earliestFuneral) return dateStr < earliestFuneral;
      return false;
    },
    onDateClick: function (dateStr, info) {
      if (info.isBlocked) {
        alert('This date is not available for booking' + (info.blockedInfo.notes ? ':\n' + info.blockedInfo.notes : '.'));
        return;
      }
      dateInput.value = dateStr;
      document.getElementById('miniCalendarWrap').style.display = 'none';
      document.getElementById('togglePickerBtn').textContent = '📅 Pick from calendar';
      if (category === 'Mass Intention') autoAssignMassTime();
      else updateOccupiedTimesHint();
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('serviceSelect').addEventListener('change', toggleServiceUI);
  document.getElementById('scheduleTypeRegular').addEventListener('change', toggleServiceUI);
  document.getElementById('scheduleTypeSpecial').addEventListener('change', toggleServiceUI);
  document.getElementById('regularSlotSelect').addEventListener('change', applyRegularSlot);
  document.getElementById('priestSelect').addEventListener('change', refreshAvailability);
  document.getElementById('dateOfDeathInput').addEventListener('change', function () {
    updateEarliestFuneralHint();
    rebuildMiniCalendar();
    refreshAvailability();
  });
  document.getElementById('appointmentDateInput').addEventListener('change', function () {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    if (category === 'Mass Intention') autoAssignMassTime();
    else updateOccupiedTimesHint();
  });
  document.getElementById('freeTimeInput').addEventListener('change', refreshAvailability);

  var toggleBtn = document.getElementById('togglePickerBtn');
  var wrap = document.getElementById('miniCalendarWrap');
  toggleBtn.addEventListener('click', function () {
    var showing = wrap.style.display !== 'none';
    wrap.style.display = showing ? 'none' : 'block';
    toggleBtn.textContent = showing ? '📅 Pick from calendar' : '✕ Close calendar';
    if (!showing) buildMiniCalendarNow();
  });

  document.getElementById('bookForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var submitBtn = document.getElementById('bookSubmitBtn');
    var errorBox = document.getElementById('bookFormError');
    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    var formData = new FormData(e.target);
    fetch('<?= url('parishioner/book.php') ?>', { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Appointment Request';
        if (data.success) {
          document.getElementById('bookFormView').style.display = 'none';
          document.getElementById('bookConfirmView').style.display = 'block';
          document.getElementById('bookConfirmMessage').textContent = data.message + (data.documents_reminder ? ' ' + data.documents_reminder : '');
          document.getElementById('bookConfirmDetailLink').href = data.detail_url;
          var typeLine = document.getElementById('bookConfirmScheduleType');
          if (data.schedule_type) {
            typeLine.textContent = data.schedule_type + ' Schedule';
            typeLine.className = 'badge ' + (data.schedule_type === 'Regular' ? 'badge-regular' : 'badge-special');
            typeLine.style.display = 'inline-block';
          } else {
            typeLine.style.display = 'none';
          }
        } else {
          errorBox.textContent = data.message;
          errorBox.style.display = 'block';
          errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Appointment Request';
        errorBox.textContent = 'Something went wrong submitting your request. Please try again.';
        errorBox.style.display = 'block';
      });
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
