<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        db()->prepare(
            "INSERT INTO events (title, description, event_date, event_time, location, created_by) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $_POST['title'], $_POST['description'] ?: null, $_POST['event_date'],
            $_POST['event_time'] ?: null, $_POST['location'] ?: null, $userId,
        ]);
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
        db()->prepare('DELETE FROM events WHERE event_id = ?')->execute([$_POST['event_id']]);
        flash('success', 'Event removed from calendar.');
    }
    redirect(url('secretary/calendar.php'));
}

$events = db()->query('SELECT * FROM events ORDER BY event_date ASC')->fetchAll();
$blocks = db()->query('SELECT * FROM calendar ORDER BY calendar_date ASC')->fetchAll();

$calendarEvents = array_map(fn($e) => ['date' => $e['event_date'], 'title' => $e['title']], $events);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocks);

$active = 'calendar';
$pageTitle = 'Manage Calendar';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: auto 1fr; gap: 22px; align-items:start;" class="calendar-layout">
  <div>
    <div id="parishCalendar"></div>
    <div class="pcal-legend">
      <span><i class="pcal-dot pcal-dot-today"></i> Today</span>
      <span><i class="pcal-dot pcal-dot-event"></i> Event</span>
      <span><i class="pcal-dot pcal-dot-blocked"></i> Unavailable</span>
      <span><i class="pcal-dot pcal-dot-dayoff"></i> Staff day off</span>
    </div>
    <p class="helper-text mt-2" style="max-width:480px;">Click any date to load it into the forms on the right. Tuesdays (shaded) are a full staff day off; Monday afternoons (12:00 PM onward) are also off, though not shaded here. Today's date is shown using your browser's own clock.</p>
  </div>

  <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 22px;">
    <div class="card">
      <div class="card-header"><h3>Add Event</h3></div>
      <form method="POST" action="<?= url('secretary/calendar.php') ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_event">
        <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
        <div class="form-row">
          <div class="form-group"><label>Date</label><input type="date" name="event_date" id="eventDateInput" required></div>
          <div class="form-group"><label>Time</label><input type="time" name="event_time"></div>
        </div>
        <div class="form-group"><label>Location</label><input type="text" name="location"></div>
        <button type="submit" class="btn btn-primary btn-block">Add Event</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h3>Block a Date</h3></div>
      <form method="POST" action="<?= url('secretary/calendar.php') ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="block_date">
        <div class="form-group"><label>Date to Block</label><input type="date" name="calendar_date" id="blockDateInput" required></div>
        <div class="form-group"><label>Reason</label><input type="text" name="notes" placeholder="e.g. Diocesan holiday"></div>
        <button type="submit" class="btn btn-dark btn-block">Block Date</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Upcoming Events</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Date</th><th>Time</th><th>Location</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td><?= e($ev['title']) ?></td>
            <td><?= formatDate($ev['event_date']) ?></td>
            <td><?= e($ev['event_time'] ?? '—') ?></td>
            <td><?= e($ev['location'] ?? '—') ?></td>
            <td>
              <form method="POST" action="<?= url('secretary/calendar.php') ?>" onsubmit="return confirm('Remove this event?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_event">
                <input type="hidden" name="event_id" value="<?= $ev['event_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($events)): ?><p class="text-muted text-center mt-3">No events scheduled.</p><?php endif; ?>
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
              <form method="POST" action="<?= url('secretary/calendar.php') ?>" onsubmit="return confirm('Unblock this date?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="unblock_date">
                <input type="hidden" name="calendar_id" value="<?= $b['calendar_id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm">Unblock</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($blocks)): ?><p class="text-muted text-center mt-3">No blocked dates currently.</p><?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
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

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
