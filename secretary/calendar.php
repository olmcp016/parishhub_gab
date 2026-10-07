<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin', 'Treasurer');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        if ($locationId > 0) {
            $locationCheck = db()->prepare('SELECT 1 FROM locations WHERE location_id = ? AND is_active = TRUE');
            $locationCheck->execute([$locationId]);
            if (!$locationCheck->fetchColumn()) {
                flash('error', 'Please choose a valid active location.');
                redirect(url('secretary/calendar.php'));
            }
        }
        $dupCheck = db()->prepare("SELECT COUNT(*) FROM events WHERE event_date = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))");
        $dupCheck->execute([$_POST['event_date'], $_POST['title']]);
        if ($dupCheck->fetchColumn() > 0) {
            flash('error', "An event titled \"{$_POST['title']}\" already exists on this date. Edit the existing event instead of adding a new one.");
            redirect(url('secretary/calendar.php'));
        }
        db()->prepare(
            "INSERT INTO events (title, description, event_date, event_time, location_id, priest_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $_POST['title'], $_POST['description'] ?: null, $_POST['event_date'],
            $_POST['event_time'] ?: null, $locationId ?: null, $_POST['priest_id'] ?: null, $userId,
        ]);
        logActivity($userId, "Created event: {$_POST['title']}", 'Calendar');
        flash('success', 'Event added to calendar.');
    } elseif ($action === 'block_date') {
        if ($_POST['calendar_date'] < date('Y-m-d')) {
            flash('error', 'You cannot block a past date.');
            redirect(url('secretary/calendar.php'));
        }
        db()->prepare(
            "INSERT INTO calendar (title, calendar_date, is_blocked, notes, created_by) VALUES ('Blocked', ?, TRUE, ?, ?)"
        )->execute([$_POST['calendar_date'], $_POST['notes'] ?: null, $userId]);
        flash('success', 'Date blocked for booking.');
    } elseif ($action === 'unblock_date') {
        db()->prepare('DELETE FROM calendar WHERE calendar_id = ?')->execute([$_POST['calendar_id']]);
        flash('success', 'Date unblocked — it is now available for booking again.');
    } elseif ($action === 'delete_event') {
        $stmt = db()->prepare('SELECT title FROM events WHERE event_id = ?');
        $stmt->execute([$_POST['event_id']]);
        $deletedTitle = $stmt->fetchColumn();
        db()->prepare('DELETE FROM events WHERE event_id = ?')->execute([$_POST['event_id']]);
        logActivity($userId, "Deleted event: " . ($deletedTitle ?: '#' . $_POST['event_id']), 'Calendar');
        flash('success', 'Event removed from calendar.');
    }
    redirect(url('secretary/calendar.php'));
}

// The mini calendar widget needs every event for month-navigation dots;
// the "Upcoming Events" table below is paginated separately.
$allEvents = db()->query('SELECT * FROM events ORDER BY event_date ASC')->fetchAll();
$blocks = db()->query('SELECT * FROM calendar ORDER BY calendar_date ASC')->fetchAll();

$today = date('Y-m-d');
$stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE event_date >= ?');
$stmt->execute([$today]);
$eventPagination = paginate((int) $stmt->fetchColumn(), 10);

$calendarEvents = array_map(fn($e) => ['id' => (int) $e['event_id'], 'date' => $e['event_date'], 'title' => $e['title']], $allEvents);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocks);

// Lookup of real (assigned) events by date+title to suppress duplicate virtual green blocks.
$existingRealMasses = [];
foreach ($allEvents as $ev) {
    $existingRealMasses[$ev['event_date'] . '_' . strtolower(trim($ev['title']))] = true;
}

// Generate regular mass schedule for the next 90 days as virtual calendar events.
// These are display-only markers derived from the parish's fixed mass schedule;
// the secretary assigns priests by creating a real event (Add Event) on that date.
$massScheduleEvents = [];
$massStart = new DateTime('today');
$massEnd = (clone $massStart)->modify('+90 days');
for ($massDay = clone $massStart; $massDay <= $massEnd; $massDay->modify('+1 day')) {
    $massDateStr = $massDay->format('Y-m-d');
    $massDow = (int) $massDay->format('w');
    if ($massDow === 0) { // Sunday: three Masses
        foreach ([
            ['title' => '1st Mass (6:30 AM)', 'clean_title' => '1st Mass', 'time' => '06:30'],
            ['title' => '2nd Mass (9:00 AM)', 'clean_title' => '2nd Mass', 'time' => '09:00'],
            ['title' => '3rd Mass (4:30 PM)', 'clean_title' => '3rd Mass', 'time' => '16:30'],
        ] as $mass) {
            if (!isset($existingRealMasses[$massDateStr . '_' . strtolower(trim($mass['clean_title']))])) {
                $massScheduleEvents[] = array_merge(['date' => $massDateStr], $mass);
            }
        }
    } elseif ($massDow === 3) { // Wednesday: Daily Mass (evening)
        if (!isset($existingRealMasses[$massDateStr . '_daily mass'])) {
            $massScheduleEvents[] = ['date' => $massDateStr, 'title' => 'Daily Mass (5:15 PM)', 'clean_title' => 'Daily Mass', 'time' => '17:15'];
        }
    } else { // Mon/Tue/Thu/Fri/Sat: Daily Mass
        if (!isset($existingRealMasses[$massDateStr . '_daily mass'])) {
            $massScheduleEvents[] = ['date' => $massDateStr, 'title' => 'Daily Mass (6:00 AM)', 'clean_title' => 'Daily Mass', 'time' => '06:00'];
        }
    }
}

$locations = db()->query('SELECT * FROM locations WHERE is_active = TRUE ORDER BY name')->fetchAll();
$priests = db()->query("SELECT * FROM priests WHERE status = 'active' ORDER BY full_name")->fetchAll();

$stmt = db()->prepare(
    "SELECT e.*, l.name AS location_name, p.title AS priest_title, p.full_name AS priest_name
     FROM events e
     LEFT JOIN locations l ON e.location_id = l.location_id
     LEFT JOIN priests p ON e.priest_id = p.priest_id
     WHERE e.event_date >= ? ORDER BY e.event_date ASC, e.event_time ASC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $today);
$stmt->bindValue(2, $eventPagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(3, $eventPagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

$active = 'calendar';
$pageTitle = 'Manage Calendar';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1fr 300px; gap: 22px; align-items:stretch;" class="calendar-layout">
  <div id="parishCalendar"></div>

  <div style="display:flex; flex-direction:column; gap: 22px; height: 100%;">
    <div class="card" style="flex: 1; display: flex; flex-direction: column;">
      <div class="card-header"><h3>Add Event</h3></div>
      <div style="padding: 20px; flex: 1; display: flex; flex-direction: column; justify-content: center;">
        <p class="text-muted" style="margin-bottom:15px; font-size:14px; line-height:1.4;">Create a new parish event, mass, or activity and assign it to a location and priest.</p>
        <button type="button" class="btn btn-primary btn-block" onclick="document.getElementById('addEventModal').showModal()">+ Add Event</button>
      </div>
    </div>

    <div class="card" style="flex: 1; display: flex; flex-direction: column;">
      <div class="card-header"><h3>Block a Date</h3></div>
      <div style="padding: 20px; flex: 1; display: flex; flex-direction: column; justify-content: center;">
        <p class="text-muted" style="margin-bottom:15px; font-size:14px; line-height:1.4;">Prevent parishioners from booking services on a specific date (e.g., diocesan holidays, parish closures).</p>
        <button type="button" class="btn btn-dark btn-block" onclick="document.getElementById('blockDateModal').showModal()">Block Date</button>
      </div>
    </div>
  </div>
</div>

<div style="margin-top: 22px; margin-bottom: 32px; padding: 16px; background-color: var(--cream); border-radius: var(--radius); border: 1px solid var(--border);">
  <div class="pcal-legend" style="margin-bottom: 8px;">
    <span><i class="pcal-dot pcal-dot-today"></i> Today</span>
    <span><i class="pcal-dot" style="background:#2d7a46;border-radius:50%;display:inline-block;width:10px;height:10px;vertical-align:middle;"></i> Mass Schedule</span>
    <span><i class="pcal-dot pcal-dot-event"></i> Event</span>
    <span><i class="pcal-dot pcal-dot-blocked"></i> Unavailable</span>
    <span><i class="pcal-dot pcal-dot-dayoff"></i> Staff day off</span>
  </div>
  <p class="helper-text" style="margin: 0; font-size: 13.5px;">
    <strong>💡 Tip:</strong> Click any date on the calendar to pre-select it before opening the Add Event or Block Date modals. Green entries show the regular mass schedule — to assign a priest to a specific mass, add an event on that date.<br>
    <em style="display: inline-block; margin-top: 6px;">Note: Tuesdays (shaded) are a full staff day off; Monday afternoons (12:00 PM onward) are also off.</em>
  </p>
</div>

<dialog class="modal" id="addEventModal" style="width: 550px; max-width: 95vw;">
  <div class="modal-head">
    <h3>Add Event</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('addEventModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('secretary/calendar.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_event">
      <div class="form-group"><label>Title</label><input type="text" name="title" id="eventTitleInput" required></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>Date</label><input type="date" name="event_date" id="eventDateInput" required></div>
        <div class="form-group"><label>Time</label><input type="time" name="event_time" id="eventTimeInput"></div>
      </div>
      <div class="form-group">
        <label for="selectedLocationName">Location</label>
        <button type="button" class="location-picker-trigger" id="selectedLocationName" aria-haspopup="dialog" aria-controls="locationPickerModal" <?= empty($locations) ? 'disabled' : '' ?>>
          <span id="selectedLocationLabel">Main Parish Church (Default)</span><span aria-hidden="true">›</span>
        </button>
        <input type="hidden" name="location_id" id="selectedLocationId" value="">
        <button type="button" id="clearLocationBtn" class="helper-text" style="display:none; background:none; border:none; padding:0; cursor:pointer; color:var(--gold-dark); text-decoration:underline; font-size:13px; margin-top:4px;">✕ Reset to Main Parish Church</button>
        <?php if (empty($locations)): ?><p class="helper-text">No locations yet — <a href="<?= url('secretary/locations.php') ?>">add one first</a>.</p><?php endif; ?>
      </div>
      <div class="form-group">
        <label>Priest (optional)</label>
        <select name="priest_id">
          <option value="">No preference</option>
          <?php foreach ($priests as $p): ?>
            <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add Event</button>
    </form>
  </div>
</dialog>

<dialog class="modal" id="blockDateModal" style="width: 450px; max-width: 95vw;">
  <div class="modal-head">
    <h3>Block a Date</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('blockDateModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('secretary/calendar.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="block_date">
      <div class="form-group"><label>Date to Block</label><input type="date" name="calendar_date" id="blockDateInput" required min="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Reason</label><input type="text" name="notes" placeholder="e.g. Diocesan holiday"></div>
      <button type="submit" class="btn btn-dark btn-block">Block Date</button>
    </form>
  </div>
</dialog>

<dialog class="modal location-picker-modal" id="locationPickerModal" aria-labelledby="locationPickerTitle">
  <div class="modal-head"><h3 id="locationPickerTitle">Select Location</h3><button type="button" class="modal-close" id="locationPickerClose" aria-label="Close">✕</button></div>
  <div class="modal-body">
    <div class="location-picker-filters" role="group" aria-label="Location category">
      <button type="button" class="btn btn-outline location-filter is-active" data-category="all">All</button>
      <button type="button" class="btn btn-outline location-filter" data-category="barangay">Barangay</button>
      <button type="button" class="btn btn-outline location-filter" data-category="school">School</button>
      <button type="button" class="btn btn-outline location-filter" data-category="chapel">Chapel</button>
    </div>
    <label class="sr-only" for="locationSearch">Search location</label>
    <input type="search" id="locationSearch" placeholder="Search chapel or barangay..." autocomplete="off">
    <div class="location-picker-results" id="locationPickerResults" role="listbox" aria-label="Available locations"></div>
  </div>
</dialog>

<div class="card">
  <div class="card-header"><h3>Upcoming Events</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Time</th><th>Location</th><th>Priest</th><th></th></tr></thead>
      <tbody>
        <?php $currentDate = null; foreach ($events as $ev): ?>
          <?php if ($ev['event_date'] !== $currentDate): $currentDate = $ev['event_date']; ?>
            <tr><td colspan="5" style="background:var(--cream-dark);font-weight:700;border-bottom:2px solid var(--gold);padding:8px 14px;font-size:13px;"><?= date('l, F j, Y', strtotime($ev['event_date'])) ?></td></tr>
          <?php endif; ?>
          <tr>
            <td style="padding-left:20px;"><?= e($ev['title']) ?></td>
            <td><?= $ev['event_time'] ? date('g:i A', strtotime($ev['event_time'])) : '—' ?></td>
            <td><?= e($ev['location_name'] ?? $ev['location'] ?? 'Main Parish Church') ?></td>
            <td><?= $ev['priest_name'] ? e($ev['priest_title'] . ' ' . $ev['priest_name']) : '—' ?></td>
            <td>
              <button type="button" class="btn btn-danger btn-sm js-delete-event" data-id="<?= $ev['event_id'] ?>">Remove</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($events)): ?>
    <p class="text-muted text-center mt-3">No upcoming events scheduled.</p>
  <?php else: ?>
    <?= renderPagination($eventPagination, url('secretary/calendar.php')) ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Blocked Dates</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Reason</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($blocks as $b): ?>
          <tr>
            <td><?= formatDate($b['calendar_date']) ?></td>
            <td><?= e($b['notes'] ?? '—') ?></td>
            <td>
              <button type="button" class="btn btn-outline btn-sm js-unblock-date" data-id="<?= $b['calendar_id'] ?>">Unblock</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($blocks)): ?><p class="text-muted text-center mt-3">No blocked dates currently.</p><?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
var parishLocations = <?= json_encode(array_map(static function ($loc) {
  return ['id' => (int) $loc['location_id'], 'name' => $loc['name'], 'notes' => $loc['notes'] ?? '', 'category' => $loc['location_category'] ?? 'other'];
}, $locations), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var locationPicker = document.getElementById('locationPickerModal');
var locationResults = document.getElementById('locationPickerResults');
var locationSearch = document.getElementById('locationSearch');
var selectedLocationId = document.getElementById('selectedLocationId');
var selectedLocationLabel = document.getElementById('selectedLocationLabel');
var selectedLocationCategory = 'all';

function renderLocationResults() {
  var query = (locationSearch.value || '').trim().toLocaleLowerCase();
  var selected = selectedLocationId.value;
  var matches = parishLocations.filter(function (location) {
    if (selectedLocationCategory !== 'all' && location.category !== selectedLocationCategory) return false;
    return !query || (location.name + ' ' + location.notes).toLocaleLowerCase().indexOf(query) !== -1;
  });
  locationResults.innerHTML = '';
  if (!matches.length) {
    var empty = document.createElement('p');
    empty.className = 'location-picker-empty';
    empty.textContent = query ? 'No locations found for your search.' : 'No locations available in this category.';
    locationResults.appendChild(empty);
    return;
  }
  matches.sort(function (a, b) { return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }); });
  matches.forEach(function (location) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'location-picker-option' + (String(location.id) === String(selected) ? ' is-selected' : '');
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', String(location.id) === String(selected) ? 'true' : 'false');
    var name = document.createElement('strong');
    name.textContent = location.name;
    button.appendChild(name);
    if (location.notes) {
      var notes = document.createElement('span');
      notes.textContent = location.notes;
      button.appendChild(notes);
    }
    button.addEventListener('click', function () {
      selectedLocationId.value = location.id;
      selectedLocationLabel.textContent = location.name;
      document.getElementById('clearLocationBtn').style.display = 'block';
      locationPicker.close();
    });
    locationResults.appendChild(button);
  });
}

document.getElementById('selectedLocationName').addEventListener('click', function () {
  renderLocationResults();
  locationPicker.showModal();
  locationSearch.focus();
});
locationSearch.addEventListener('input', renderLocationResults);
document.querySelectorAll('.location-filter').forEach(function (button) {
  button.addEventListener('click', function () {
    selectedLocationCategory = this.dataset.category;
    document.querySelectorAll('.location-filter').forEach(function (item) { item.classList.toggle('is-active', item === button); });
    renderLocationResults();
  });
});
document.getElementById('locationPickerClose').addEventListener('click', function () { locationPicker.close(); });
locationPicker.addEventListener('close', function () { locationSearch.value = ''; });

document.getElementById('clearLocationBtn').addEventListener('click', function () {
  selectedLocationId.value = '';
  selectedLocationLabel.textContent = 'Main Parish Church (Default)';
  this.style.display = 'none';
});

document.addEventListener('DOMContentLoaded', function () {
  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
    massSchedule: <?= json_encode($massScheduleEvents, JSON_UNESCAPED_UNICODE) ?>,
    dayMaxEvents: 4,
    extraDayClassNames: function (dateStr) {
      // Tuesday is a full day off. Monday afternoon is also off, but that
      // can't be represented at day-level shading without implying the
      // whole Monday is closed — see the helper text below instead.
      var d = new Date(dateStr + 'T00:00:00');
      if (d.getDay() === 2) return ['pcal-fc-dayoff'];
      return [];
    },
    onDateClick: function (dateStr, info) {
      document.getElementById('eventDateInput').value = dateStr;
      document.getElementById('blockDateInput').value = dateStr;
      if (info.isBlocked) {
        var notes = info.blockedInfo && info.blockedInfo.notes ? info.blockedInfo.notes : '';
        document.getElementById('blockedDateNotesText').textContent = notes ? 'Note: ' + notes : '';
        document.getElementById('blockedDateModal').showModal();
      }
    },
    onEventClick: function (fcEvent) {
      var eventId = fcEvent.extendedProps.eventId;
      if (!eventId) return;
      openEventPanel(eventId);
    },
    onMassClick: function (fcEvent) {
      document.getElementById('eventDateInput').value = fcEvent.startStr;
      document.getElementById('eventTimeInput').value = fcEvent.extendedProps.time || '';
      document.getElementById('eventTitleInput').value = fcEvent.extendedProps.cleanTitle || '';
      document.getElementById('addEventModal').showModal();
    }
  });
});
</script>

<style>
@media (max-width: 900px) {
  .calendar-layout { grid-template-columns: 1fr !important; }
}
</style>

<dialog id="deleteEventModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Remove Event?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to remove this event?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteEventModal').close()">Cancel</button>
    <form method="POST" action="<?= url('secretary/calendar.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete_event">
      <input type="hidden" name="event_id" id="deleteEventId">
      <button type="submit" class="btn btn-danger">Yes, Remove</button>
    </form>
  </div>
</dialog>

<dialog id="unblockDateModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Unblock Date?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to unblock this date?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('unblockDateModal').close()">Cancel</button>
    <form method="POST" action="<?= url('secretary/calendar.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="unblock_date">
      <input type="hidden" name="calendar_id" id="unblockDateId">
      <button type="submit" class="btn btn-primary">Yes, Unblock</button>
    </form>
  </div>
</dialog>

<dialog id="blockedDateModal" style="max-width:420px;padding:24px;border-radius:10px;border:none;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
    <span style="font-size:24px;">🚫</span>
    <h3 style="margin:0;color:var(--brown-dark);">Date Already Blocked</h3>
  </div>
  <p style="color:var(--brown-mid);margin:0 0 8px;">This date is already marked as unavailable for bookings.</p>
  <p id="blockedDateNotesText" style="color:var(--brown-mid);font-size:13.5px;background:var(--cream);border-radius:6px;padding:8px 10px;margin:0 0 16px;display:block;"></p>
  <p style="font-size:13px;color:var(--text-muted,#888);margin:0 0 20px;">Use the "Unblock" button in the Blocked Dates list below to make it available again.</p>
  <div style="display:flex;justify-content:flex-end;">
    <button type="button" class="btn btn-primary" onclick="document.getElementById('blockedDateModal').close()">Got it</button>
  </div>
</dialog>

<script>
document.querySelectorAll('.js-delete-event').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('deleteEventId').value = this.dataset.id;
    document.getElementById('deleteEventModal').showModal();
  });
});

document.querySelectorAll('.js-unblock-date').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('unblockDateId').value = this.dataset.id;
    document.getElementById('unblockDateModal').showModal();
  });
});
</script>

<!-- =================== EVENT SLIDE-OVER PANEL =================== -->
<div id="eventPanelOverlay" class="epanel-overlay" aria-hidden="true"></div>
<aside id="eventPanel" class="epanel" role="dialog" aria-modal="true" aria-labelledby="epanelTitle" aria-hidden="true">
  <div class="epanel-header">
    <h3 id="epanelTitle" class="epanel-title">Event Details</h3>
    <div class="epanel-header-actions">
      <button type="button" id="epanelEditBtn" class="btn btn-outline btn-sm epanel-edit-btn" title="Edit event" aria-label="Edit event">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
      </button>
      <button type="button" id="epanelCloseBtn" class="epanel-close" aria-label="Close panel">✕</button>
    </div>
  </div>

  <div class="epanel-body">
    <div id="epanelLoading" class="epanel-loading" hidden>
      <span class="epanel-spinner"></span> Loading…
    </div>

    <div id="epanelViewMode">
      <dl class="epanel-dl">
        <dt>Title</dt>
        <dd id="epView_title">—</dd>
        <dt>Date</dt>
        <dd id="epView_date">—</dd>
        <dt>Time</dt>
        <dd id="epView_time">—</dd>
        <dt>Location</dt>
        <dd id="epView_location">—</dd>
        <dt>Priest</dt>
        <dd id="epView_priest">—</dd>
        <dt id="epView_desc_label" style="display:none;">Description</dt>
        <dd id="epView_desc" style="display:none;">—</dd>
      </dl>
    </div>

    <form id="epanelEditForm" hidden>
      <input type="hidden" id="epEdit_eventId">
      <div class="form-group">
        <label for="epEdit_time">Time</label>
        <input type="time" id="epEdit_time" name="event_time">
      </div>
      <div class="form-group">
        <label for="epEdit_priest">Assigned Priest</label>
        <select id="epEdit_priest" name="priest_id">
          <option value="">— No priest assigned —</option>
          <?php foreach ($priests as $p): ?>
            <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="epanel-edit-actions">
        <button type="button" id="epanelCancelEdit" class="btn btn-outline">Cancel</button>
        <button type="submit" id="epanelSaveBtn" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</aside>

<style>
.epanel-overlay {
  position: fixed; inset: 0;
  background: rgba(30, 26, 10, 0.35);
  z-index: 1040;
  opacity: 0; pointer-events: none;
  transition: opacity 0.25s ease;
}
.epanel-overlay.is-open { opacity: 1; pointer-events: auto; }

.epanel {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: 380px; max-width: 94vw;
  background: #fff;
  box-shadow: -4px 0 24px rgba(30,26,10,0.14);
  z-index: 1050;
  display: flex; flex-direction: column;
  transform: translateX(100%);
  transition: transform 0.28s cubic-bezier(.4,0,.2,1);
}
.epanel.is-open { transform: translateX(0); }

.epanel-header {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--border);
  background: var(--cream);
}
.epanel-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--brown-dark); }
.epanel-header-actions { display: flex; align-items: center; gap: 8px; }

.epanel-edit-btn { display: flex; align-items: center; gap: 5px; font-size: 13px; }
.epanel-close {
  border: none; background: none; cursor: pointer; font-size: 17px;
  color: var(--brown-muted); line-height: 1; padding: 4px 6px; border-radius: 6px;
  transition: background 0.15s, color 0.15s;
}
.epanel-close:hover { background: var(--cream-dark); color: var(--brown-dark); }

.epanel-body {
  flex: 1; overflow-y: auto;
  padding: 20px;
}

.epanel-loading {
  display: flex; align-items: center; gap: 10px;
  color: var(--brown-muted); font-size: 14px; padding: 20px 0;
}
/* Prevent display:flex from overriding the native `hidden` attribute */
#eventPanel [hidden] { display: none !important; }
.epanel-spinner {
  display: inline-block; width: 18px; height: 18px; border-radius: 50%;
  border: 2.5px solid var(--gold-light); border-top-color: var(--gold);
  animation: epanelSpin 0.7s linear infinite;
}
@keyframes epanelSpin { to { transform: rotate(360deg); } }

.epanel-dl {
  display: grid; grid-template-columns: 100px 1fr;
  gap: 6px 12px; margin: 0; align-items: baseline;
}
.epanel-dl dt {
  font-size: 12px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .04em; color: var(--brown-muted); padding-top: 2px;
}
.epanel-dl dd {
  margin: 0; font-size: 14.5px; color: var(--brown-dark);
  padding: 2px 0; border-bottom: 1px solid var(--cream-dark);
}
.epanel-dl dd:last-child { border-bottom: none; }

.epanel-edit-actions {
  display: flex; gap: 10px; margin-top: 20px;
}
.epanel-edit-actions .btn { flex: 1; }

/* Success toast */
.epanel-toast {
  position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(20px);
  background: var(--brown-dark); color: var(--cream);
  padding: 11px 22px; border-radius: var(--radius-pill);
  font-size: 14px; font-weight: 600; z-index: 1100;
  opacity: 0; transition: opacity 0.25s, transform 0.25s;
  pointer-events: none; white-space: nowrap;
}
.epanel-toast.is-visible { opacity: 1; transform: translateX(-50%) translateY(0); }
</style>

<div id="epanelToast" class="epanel-toast" role="status" aria-live="polite"></div>

<script>
(function () {
  var CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
  var API_BASE   = <?= json_encode(url('api/')) ?>;

  var overlay  = document.getElementById('eventPanelOverlay');
  var panel    = document.getElementById('eventPanel');
  var loading  = document.getElementById('epanelLoading');
  var viewMode = document.getElementById('epanelViewMode');
  var editForm = document.getElementById('epanelEditForm');
  var toast    = document.getElementById('epanelToast');

  var fcCalendarInstance = null; // set after renderParishCalendar returns

  // ── open / close ──────────────────────────────────────────
  function openPanel() {
    panel.hidden = false;
    panel.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');
    // Trigger transition on next frame
    requestAnimationFrame(function () {
      overlay.classList.add('is-open');
      panel.classList.add('is-open');
    });
    document.body.style.overflow = 'hidden';
  }

  function closePanel() {
    overlay.classList.remove('is-open');
    panel.classList.remove('is-open');
    document.body.style.overflow = '';
    panel.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('aria-hidden', 'true');
    setViewMode();
  }

  document.getElementById('epanelCloseBtn').addEventListener('click', closePanel);
  overlay.addEventListener('click', closePanel);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) closePanel();
  });

  // ── view / edit mode toggle ───────────────────────────────
  function setViewMode() {
    viewMode.hidden = false;
    editForm.hidden = true;
    document.getElementById('epanelEditBtn').textContent = '';
    // Rebuild edit icon + text
    document.getElementById('epanelEditBtn').innerHTML =
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit';
    document.getElementById('epanelEditBtn').disabled = false;
  }

  function setEditMode(event) {
    viewMode.hidden = true;
    editForm.hidden = false;
    document.getElementById('epEdit_eventId').value = event.event_id;
    document.getElementById('epEdit_time').value = event.event_time || '';
    document.getElementById('epEdit_priest').value = event.priest_id || '';
    document.getElementById('epanelEditBtn').innerHTML = '← View';
    document.getElementById('epanelEditBtn').disabled = false;
  }

  var currentEvent = null;

  document.getElementById('epanelEditBtn').addEventListener('click', function () {
    if (!editForm.hidden) {
      setViewMode();
    } else if (currentEvent) {
      setEditMode(currentEvent);
    }
  });

  document.getElementById('epanelCancelEdit').addEventListener('click', setViewMode);

  // ── populate view mode fields ─────────────────────────────
  function populateView(event) {
    document.getElementById('epanelTitle').textContent = event.title || 'Event Details';
    document.getElementById('epView_title').textContent = event.title || '—';

    var dateStr = event.event_date || '';
    document.getElementById('epView_date').textContent = dateStr
      ? new Date(dateStr + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
      : '—';

    document.getElementById('epView_time').textContent = event.event_time
      ? formatTime12h(event.event_time)
      : '—';

    document.getElementById('epView_location').textContent = event.location_name || 'Main Parish Church';

    var priestLabel = event.priest_name
      ? ((event.priest_title ? event.priest_title + ' ' : '') + event.priest_name)
      : '—';
    document.getElementById('epView_priest').textContent = priestLabel;

    var descLabel = document.getElementById('epView_desc_label');
    var descVal   = document.getElementById('epView_desc');
    if (event.description) {
      descLabel.style.display = '';
      descVal.style.display   = '';
      descVal.textContent = event.description;
    } else {
      descLabel.style.display = 'none';
      descVal.style.display   = 'none';
    }
  }

  function formatTime12h(t) {
    if (!t) return '—';
    var parts = t.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
  }

  // ── fetch event & open panel ──────────────────────────────
  window.openEventPanel = function (eventId) {
    openPanel();
    setViewMode();
    loading.hidden = false;
    viewMode.hidden = true;

    fetch(API_BASE + 'get_event.php?event_id=' + encodeURIComponent(eventId), {
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loading.hidden = true;
        if (data.error) {
          viewMode.hidden = false;
          document.getElementById('epView_title').textContent = 'Could not load event.';
          return;
        }
        currentEvent = data;
        populateView(data);
        viewMode.hidden = false;
      })
      .catch(function () {
        loading.hidden = true;
        viewMode.hidden = false;
        document.getElementById('epView_title').textContent = 'Network error. Please try again.';
      });
  };

  // ── save changes ──────────────────────────────────────────
  editForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var saveBtn = document.getElementById('epanelSaveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    var body = new FormData();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('event_id',   document.getElementById('epEdit_eventId').value);
    body.append('event_time', document.getElementById('epEdit_time').value);
    body.append('priest_id',  document.getElementById('epEdit_priest').value);

    fetch(API_BASE + 'update_event.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Changes';
        if (data.error) {
          showToast('Error: ' + data.error, true);
          return;
        }
        currentEvent = data.event;
        populateView(data.event);
        setViewMode();
        showToast('Event updated successfully.');

        // Refresh the FullCalendar event title/time in place
        if (fcCalendarInstance) {
          var fcEv = fcCalendarInstance.getEventById ? null : null;
          // FullCalendar 6 requires id on source events; rebuild by removing & re-adding
          fcCalendarInstance.getEvents().forEach(function (ev) {
            if (ev.extendedProps.eventId === data.event.event_id) {
              ev.remove();
            }
          });
          fcCalendarInstance.addEvent({
            title: data.event.title,
            start: data.event.event_date,
            allDay: true,
            backgroundColor: '#c99b2f',
            borderColor: '#a5791f',
            textColor: '#241611',
            extendedProps: { kind: 'event', eventId: data.event.event_id }
          });
        }
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Changes';
        showToast('Network error. Please try again.', true);
      });
  });

  // ── toast helper ─────────────────────────────────────────
  var toastTimer = null;
  function showToast(msg, isError) {
    toast.textContent = msg;
    toast.style.background = isError ? 'var(--danger)' : 'var(--brown-dark)';
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('is-visible'); }, 3200);
  }

  // ── store calendar instance for live refresh ──────────────
  // renderParishCalendar is called inside DOMContentLoaded below;
  // we wrap it to capture the return value.
  var _origRender = window.renderParishCalendar;
  window.renderParishCalendar = function (elId, opts) {
    var inst = _origRender(elId, opts);
    if (elId === 'parishCalendar') fcCalendarInstance = inst;
    return inst;
  };
}());
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
