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
        db()->prepare(
            "INSERT INTO events (title, description, event_date, event_time, location_id, priest_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $_POST['title'], $_POST['description'] ?: null, $_POST['event_date'],
            $_POST['event_time'] ?: null, $locationId ?: null, $_POST['priest_id'] ?: null, $userId,
        ]);
        logActivity($userId, "Created event: {$_POST['title']}", 'Calendar');
        flash('success', 'Event added to calendar.');
    } elseif ($action === 'block_date') {
        db()->prepare(
            "INSERT INTO calendar (title, calendar_date, is_blocked, notes, created_by) VALUES ('Blocked', ?, 1, ?, ?)"
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

$calendarEvents = array_map(fn($e) => ['date' => $e['event_date'], 'title' => $e['title']], $allEvents);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocks);

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

  <div style="display:flex; flex-direction:column; gap: 22px;">
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
    <span><i class="pcal-dot pcal-dot-event"></i> Event</span>
    <span><i class="pcal-dot pcal-dot-blocked"></i> Unavailable</span>
    <span><i class="pcal-dot pcal-dot-dayoff"></i> Staff day off</span>
  </div>
  <p class="helper-text" style="margin: 0; font-size: 13.5px;">
    <strong>💡 Tip:</strong> Click any date on the calendar to pre-select it before opening the Add Event or Block Date modals.<br>
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
      <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>Date</label><input type="date" name="event_date" id="eventDateInput" required></div>
        <div class="form-group"><label>Time</label><input type="time" name="event_time"></div>
      </div>
      <div class="form-group">
        <label for="selectedLocationName">Location</label>
        <button type="button" class="location-picker-trigger" id="selectedLocationName" aria-haspopup="dialog" aria-controls="locationPickerModal" <?= empty($locations) ? 'disabled' : '' ?>>
          <span id="selectedLocationLabel">Select a location</span><span aria-hidden="true">›</span>
        </button>
        <input type="hidden" name="location_id" id="selectedLocationId" value="">
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
      <div class="form-group"><label>Date to Block</label><input type="date" name="calendar_date" id="blockDateInput" required></div>
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
      <thead><tr><th>Title</th><th>Date</th><th>Time</th><th>Location</th><th>Priest</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td><?= e($ev['title']) ?></td>
            <td><?= formatDate($ev['event_date']) ?></td>
            <td><?= e($ev['event_time'] ?? '—') ?></td>
            <td><?= e($ev['location_name'] ?? $ev['location'] ?? '—') ?></td>
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

document.addEventListener('DOMContentLoaded', function () {
  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
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
        var msg = 'This date is already blocked' + (info.blockedInfo.notes ? ':\n' + info.blockedInfo.notes : '.') + '\n\nUse the "Unblock" button below to make it available again.';
        alert(msg);
      }
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

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
