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
foreach (['Mass Intention', 'Wedding', 'Baptism', 'Funeral', 'Blessing', 'Confirmation', 'First Communion'] as $cat) {
    $policies[$cat] = schedulingPolicyText($cat);
}

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
        <a href="<?= url('parishioner/donate.php') ?>" class="btn btn-primary btn-sm">Donate Now</a>
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

        <div class="form-row">
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
          <label>Upload Requirements (optional — PDF/JPG/PNG, up to 5 files)</label>
          <input type="file" name="documents[]" id="documentsInput" multiple accept=".pdf,.jpg,.jpeg,.png">
          <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total for the whole form. If your photos are larger than that (common for phone camera photos), you can also upload requirements later from the appointment detail page instead.</p>
        </div>

        <div id="bookFormError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <button type="submit" class="btn btn-primary btn-block" id="bookSubmitBtn">Submit Appointment Request</button>
      </form>
    </div>

    <div id="bookConfirmView" style="display:none; text-align:center; padding: 20px 10px;">
      <div style="font-size:48px; margin-bottom:12px;">✔</div>
      <h3 style="margin:0 0 10px;">Request Submitted!</h3>
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

  <?php if ($preselectedDate): ?>
  document.getElementById('appointmentDateInput').value = <?= json_encode($preselectedDate) ?>;
  <?php endif; ?>

  toggleServiceUI();
  modal.showModal();
}

function closeBookModal() {
  document.getElementById('bookModal').close();
}

function toggleServiceUI() {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';

  var isMassIntention = category === 'Mass Intention';

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
  document.getElementById('documentsInput').disabled = isMassIntention;

  var policyBox = document.getElementById('policyBox');
  if (POLICIES[category]) {
    policyBox.style.display = 'block';
    policyBox.textContent = 'ℹ ' + POLICIES[category];
  } else {
    policyBox.style.display = 'none';
  }

  var freeGroup = document.getElementById('freeTimeGroup');
  var fixedGroup = document.getElementById('fixedTimeGroup');
  var massGroup = document.getElementById('massTimeGroup');
  var freeInput = document.getElementById('freeTimeInput');
  var fixedHidden = document.getElementById('fixedTimeInput');
  var massHidden = document.getElementById('massTimeInput');

  freeGroup.style.display = 'none'; freeInput.disabled = true;
  fixedGroup.style.display = 'none'; fixedHidden.disabled = true;
  massGroup.style.display = 'none'; massHidden.disabled = true;

  if (category === 'Baptism' || category === 'Wedding' || category === 'Funeral') {
    fixedGroup.style.display = 'block';
    fixedHidden.disabled = false;
    var fixedTime = category === 'Baptism' ? '09:00' : (category === 'Wedding' ? '08:00' : '13:00');
    document.getElementById('fixedTimeDisplay').value = formatTimeLabel(fixedTime) + ' (fixed)';
    fixedHidden.value = fixedTime;
  } else if (isMassIntention) {
    massGroup.style.display = 'block';
    massHidden.disabled = false;
    autoAssignMassTime();
  } else {
    freeGroup.style.display = 'block';
    freeInput.disabled = false;
    updateOccupiedTimesHint();
  }

  updateEarliestFuneralHint();
  rebuildMiniCalendar();
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
}

/** For free-choice categories (Blessing/Confirmation/First Communion) — show which times that date is already occupied by a scheduled Mass. */
function updateOccupiedTimesHint() {
  var dateInput = document.getElementById('appointmentDateInput');
  var hint = document.getElementById('occupiedTimesHint');
  if (!dateInput.value) {
    hint.textContent = '';
    return;
  }
  var times = massTimesForJS(dateInput.value).map(formatTimeLabel);
  hint.textContent = 'Occupied by Mass on this date: ' + times.join(', ') + '. Please choose a different time.';
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
      if (isTuesdayJS(dateStr)) return true;
      if (category === 'Baptism') return !isFirstOrThirdSaturdayJS(dateStr);
      if (category === 'Wedding') return !isFourthSaturdayJS(dateStr);
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
  document.getElementById('dateOfDeathInput').addEventListener('change', function () {
    updateEarliestFuneralHint();
    rebuildMiniCalendar();
  });
  document.getElementById('appointmentDateInput').addEventListener('change', function () {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    if (category === 'Mass Intention') autoAssignMassTime();
    else updateOccupiedTimesHint();
  });

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
          document.getElementById('bookConfirmMessage').textContent = data.message;
          document.getElementById('bookConfirmDetailLink').href = data.detail_url;
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
