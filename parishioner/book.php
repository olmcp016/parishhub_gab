<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP silently empties $_POST and $_FILES entirely when the total upload
    // exceeds post_max_size — even though real data WAS sent. Detect this
    // specific case first, before verifyCsrf() runs, so the parishioner sees
    // an accurate "your files were too large" message instead of a
    // confusing, unrelated "session expired" error.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        $limit = ini_get('post_max_size');
        flash('error', "Your uploaded files were too large for the server to accept (total limit is $limit). Please upload smaller files or fewer at a time — you can also add documents later from your appointment page — then submit the rest of the form again.");
        redirect(url('parishioner/book.php'));
    }

    verifyCsrf();
    $serviceId = $_POST['service_id'] ?? '';
    $priestId = $_POST['priest_id'] ?: null;
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $dateOfDeath = $_POST['date_of_death'] ?: null;
    $remarks = trim($_POST['remarks'] ?? '') ?: null;

    $stmt = db()->prepare('SELECT category FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $category = $stmt->fetchColumn();

    if (!$category || !$date || !$time) {
        flash('error', 'Please fill in the service, date, and time.');
        redirect(url('parishioner/book.php'));
    }

    // ---- Enforce the parish's fixed scheduling rules ----
    $check = validateBooking($category, $date, $time, $dateOfDeath);
    if (!$check['valid']) {
        flash('error', $check['message']);
        redirect(url('parishioner/book.php'));
    }
    $finalTime = $check['forcedTime'] ?? $time;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Manually blocked dates (holidays, etc.) still apply on top of the fixed rules
        $stmt = $pdo->prepare('SELECT * FROM calendar WHERE calendar_date = ? AND is_blocked = 1');
        $stmt->execute([$date]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            flash('error', 'The selected date is not available for booking. Please choose another date.');
            redirect(url('parishioner/book.php'));
        }

        // Priest availability check — a priest cannot be double-booked at the same date/time
        if ($priestId) {
            $stmt = $pdo->prepare(
                "SELECT a.appointment_id, s.service_name FROM appointments a
                 JOIN services s ON a.service_id = s.service_id
                 WHERE a.priest_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
                   AND a.status_id NOT IN (3, 7)"
            );
            $stmt->execute([$priestId, $date, $finalTime]);
            $conflict = $stmt->fetch();
            if ($conflict) {
                $pdo->rollBack();
                flash('error', "That priest is already booked for another appointment (\"{$conflict['service_name']}\", #{$conflict['appointment_id']}) at that exact date and time. Please choose a different time, or leave the priest field as \"No preference\" and the secretary will assign one.");
                redirect(url('parishioner/book.php'));
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
        );
        $stmt->execute([$parishionerId, $serviceId, $priestId, $date, $finalTime, $remarks, $dateOfDeath]);
        $appointmentId = $pdo->lastInsertId();

        if ($category === 'Mass Intention' && !empty($_POST['intention_type'])) {
            $stmt = $pdo->prepare(
                "INSERT INTO mass_intentions (appointment_id, intention_type, offerer_name, intention_for, message)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $appointmentId,
                $_POST['intention_type'],
                $_POST['offerer_name'] ?? '',
                $_POST['intention_for'] ?? '',
                $_POST['message'] ?: null,
            ]);
        }

        $skippedFiles = [];
        if (!empty($_FILES['documents']['name'][0])) {
            $uploadDir = __DIR__ . '/../public/uploads/';
            foreach ($_FILES['documents']['name'] as $i => $name) {
                $err = $_FILES['documents']['error'][$i];
                if ($err !== UPLOAD_ERR_OK) {
                    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                        $skippedFiles[] = $name;
                    }
                    continue;
                }
                $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $dest = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $dest)) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$appointmentId, $name, 'public/uploads/' . $safeName, $_FILES['documents']['type'][$i]]);
                }
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Submitted', ?)"
        );
        $stmt->execute([$userId, "Your appointment request (#$appointmentId) has been submitted and is pending review. Our secretary will check your requirements next."]);

        $pdo->commit();
        logActivity($userId, "Booked appointment #$appointmentId", 'Appointments');

        flash('success', 'Appointment request submitted! Our secretary will review your requirements before approving.');
        if (!empty($skippedFiles)) {
            $limit = ini_get('upload_max_filesize');
            flash('error', 'Note: the following file(s) were too large (max ' . $limit . ' each) and were NOT uploaded: ' . implode(', ', $skippedFiles) . '. You can upload them separately from your appointment page.');
        }
        redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        flash('error', 'Failed to submit appointment. Please try again.');
        redirect(url('parishioner/book.php'));
    }
}

$services = db()->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, service_name")->fetchAll();
$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$blockedRows = db()->query('SELECT calendar_date, notes FROM calendar WHERE is_blocked = 1')->fetchAll();
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blockedRows);
$preselected = $_GET['service_id'] ?? '';
$preselectedDate = $_GET['date'] ?? '';

$policies = [];
foreach (['Mass Intention', 'Wedding', 'Baptism', 'Funeral', 'Blessing', 'Confirmation', 'First Communion'] as $cat) {
    $policies[$cat] = schedulingPolicyText($cat);
}

$active = 'book';
$pageTitle = 'Book an Appointment';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 720px;">
  <div class="card-header"><h3>Book a Service</h3></div>

  <div id="policyBox" class="alert" style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);"></div>

  <form method="POST" action="<?= url('parishioner/book.php') ?>" enctype="multipart/form-data" id="bookForm">
    <?= csrfField() ?>
    <div class="form-group">
      <label>Select Service</label>
      <select name="service_id" id="serviceSelect" required>
        <option value="">-- Choose a service --</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= $s['service_id'] ?>" data-category="<?= e($s['category']) ?>" <?= (string)$preselected === (string)$s['service_id'] ? 'selected' : '' ?>>
            <?= e($s['service_name']) ?> (<?= money($s['fee']) ?>)
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
        </div>

        <div id="fixedTimeGroup" style="display:none;">
          <input type="text" id="fixedTimeDisplay" disabled>
          <input type="hidden" name="appointment_time" id="fixedTimeInput">
        </div>

        <div id="massTimeGroup" style="display:none;">
          <select name="appointment_time" id="massTimeSelect">
            <option value="">-- Select date first --</option>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label>Preferred Priest (optional)</label>
      <select name="priest_id">
        <option value="">No preference</option>
        <?php foreach ($priests as $p): ?>
          <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="helper-text">Choosing a specific priest is subject to his availability — the secretary will confirm.</p>
    </div>

    <div id="intentionFields" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
      <h4 style="margin-top:0;">Mass Intention Details</h4>
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
        <input type="text" name="offerer_name" placeholder="Your full name">
      </div>
      <div class="form-group">
        <label>Intention For</label>
        <input type="text" name="intention_for" placeholder="Name(s) the mass is offered for">
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

    <div class="form-group">
      <label>Upload Requirements (optional — PDF/JPG/PNG, up to 5 files)</label>
      <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
      <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total for the whole form. If your photos are larger than that (common for phone camera photos), you can also upload requirements later from the appointment detail page instead. Our secretary will review these before your appointment is approved.</p>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Submit Appointment Request</button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
var POLICIES = <?= json_encode($policies, JSON_UNESCAPED_UNICODE) ?>;

function toggleServiceUI() {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';

  document.getElementById('intentionFields').style.display = category === 'Mass Intention' ? 'block' : 'none';
  document.getElementById('dateOfDeathGroup').style.display = category === 'Funeral' ? 'block' : 'none';
  document.getElementById('dateOfDeathInput').required = (category === 'Funeral');

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
  var massSelect = document.getElementById('massTimeSelect');

  freeGroup.style.display = 'none'; freeInput.disabled = true;
  fixedGroup.style.display = 'none'; fixedHidden.disabled = true;
  massGroup.style.display = 'none'; massSelect.disabled = true;

  if (category === 'Baptism' || category === 'Wedding' || category === 'Funeral') {
    fixedGroup.style.display = 'block';
    fixedHidden.disabled = false;
    var fixedTime = category === 'Baptism' ? '09:00' : (category === 'Wedding' ? '08:00' : '13:00');
    document.getElementById('fixedTimeDisplay').value = formatTimeLabel(fixedTime) + ' (fixed)';
    fixedHidden.value = fixedTime;
  } else if (category === 'Mass Intention') {
    massGroup.style.display = 'block';
    massSelect.disabled = false;
    populateMassTimes();
  } else {
    freeGroup.style.display = 'block';
    freeInput.disabled = false;
  }

  updateEarliestFuneralHint();
  rebuildMiniCalendar();
}

function populateMassTimes() {
  var dateInput = document.getElementById('appointmentDateInput');
  var select = document.getElementById('massTimeSelect');
  select.innerHTML = '';
  if (!dateInput.value) {
    select.innerHTML = '<option value="">-- Select date first --</option>';
    return;
  }
  var times = massTimesForJS(dateInput.value);
  times.forEach(function (t) {
    var opt = document.createElement('option');
    opt.value = t;
    opt.textContent = formatTimeLabel(t);
    select.appendChild(opt);
  });
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
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
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
      if (category === 'Mass Intention') populateMassTimes();
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  toggleServiceUI();

  document.getElementById('serviceSelect').addEventListener('change', toggleServiceUI);
  document.getElementById('dateOfDeathInput').addEventListener('change', function () {
    updateEarliestFuneralHint();
    rebuildMiniCalendar();
  });
  document.getElementById('appointmentDateInput').addEventListener('change', function () {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    if (category === 'Mass Intention') populateMassTimes();
  });

  var toggleBtn = document.getElementById('togglePickerBtn');
  var wrap = document.getElementById('miniCalendarWrap');
  toggleBtn.addEventListener('click', function () {
    var showing = wrap.style.display !== 'none';
    wrap.style.display = showing ? 'none' : 'block';
    toggleBtn.textContent = showing ? '📅 Pick from calendar' : '✕ Close calendar';
    if (!showing) buildMiniCalendarNow();
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
