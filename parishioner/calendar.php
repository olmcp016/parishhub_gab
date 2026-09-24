<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
// Fully public — anyone can view the parish calendar without an account.

// The mini calendar widget needs every event (so month navigation always
// shows the right dots), but the "Upcoming Parish Events" table below it is
// paginated separately — a long unbounded list isn't useful there.
$allEvents = db()->query('SELECT * FROM events ORDER BY event_date ASC')->fetchAll();
$blocked = db()->query('SELECT * FROM calendar WHERE is_blocked = 1 ORDER BY calendar_date ASC')->fetchAll();

$today = date('Y-m-d');
$stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE event_date >= ?');
$stmt->execute([$today]);
$totalUpcoming = (int) $stmt->fetchColumn();
$pagination = paginate($totalUpcoming, 10);
$stmt = db()->prepare(
    "SELECT e.*, l.name AS location_name, p.title AS priest_title, p.full_name AS priest_name
     FROM events e
     LEFT JOIN locations l ON e.location_id = l.location_id
     LEFT JOIN priests p ON e.priest_id = p.priest_id
     WHERE e.event_date >= ? ORDER BY e.event_date ASC, e.event_time ASC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $today);
$stmt->bindValue(2, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

// Data for the JS calendar widget — dates come back from MySQL as 'YYYY-MM-DD' strings already
$calendarEvents = array_map(fn($e) => ['date' => $e['event_date'], 'title' => $e['title']], $allEvents);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocked);

$active = 'calendar';
$pageTitle = 'Parish Calendar';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-start.php' : 'public-shell-start.php');
?>

<div style="display:grid; grid-template-columns: auto 1fr; gap: 22px; align-items:start;" class="calendar-layout">
  <div id="calendarColumn">
    <div id="parishCalendar"></div>
    <div class="pcal-legend">
      <span><i class="pcal-dot pcal-dot-today"></i> Today</span>
      <span><i class="pcal-dot pcal-dot-event"></i> Event</span>
      <span><i class="pcal-dot pcal-dot-blocked"></i> Unavailable</span>
    </div>
    <p class="helper-text mt-2" style="max-width:480px;">Click any available date to start booking an appointment for that day.</p>
  </div>

  <div>
    <div class="card" id="upcomingEventsCard">
      <div class="card-header"><h3>Upcoming Parish Events</h3></div>
      <?php if (empty($events)): ?>
        <p class="text-muted">No upcoming events scheduled.</p>
      <?php else: ?>
        <div class="table-wrap" id="upcomingEventsTableWrap">
          <table>
            <thead><tr><th>Event</th><th>Date</th><th>Time</th><th>Location</th><th>Priest</th></tr></thead>
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td><?= e($ev['title']) ?></td>
                  <td><?= formatDate($ev['event_date']) ?></td>
                  <td><?= e($ev['event_time'] ?? '—') ?></td>
                  <td><?= e($ev['location_name'] ?? $ev['location'] ?? '—') ?></td>
                  <td><?= $ev['priest_name'] ? e($ev['priest_title'] . ' ' . $ev['priest_name']) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= renderPagination($pagination, url('parishioner/calendar.php')) ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>Blocked / Unavailable Dates</h3></div>
      <?php if (empty($blocked)): ?>
        <p class="text-muted">No blocked dates currently.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($blocked as $b): ?>
            <li><?= formatDate($b['calendar_date']) ?><?php if ($b['notes']): ?> — <?= e($b['notes']) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // "today" is derived from the browser's own local clock, not the server's.
  var now = new Date();
  var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

  // Keeps "Upcoming Parish Events" the same height as the calendar (which
  // varies between 4 and 6 week-rows depending on the month) so its
  // pagination controls are always visible without scrolling the page —
  // the events table scrolls internally instead of pushing the page taller.
  function syncUpcomingEventsHeight() {
    var column = document.getElementById('calendarColumn');
    var card = document.getElementById('upcomingEventsCard');
    var tableWrap = document.getElementById('upcomingEventsTableWrap');
    if (!column || !card || !tableWrap) return;

    if (window.innerWidth <= 900) {
      // Stacked layout on small screens — no benefit to capping the height.
      tableWrap.style.maxHeight = '';
      tableWrap.style.overflowY = '';
      return;
    }

    var otherContentHeight = card.offsetHeight - tableWrap.offsetHeight;
    var target = column.offsetHeight - otherContentHeight;
    tableWrap.style.maxHeight = Math.max(target, 180) + 'px';
    tableWrap.style.overflowY = 'auto';
  }

  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
    minDate: todayStr,
    onDateClick: function (dateStr, info) {
      if (info.isBlocked) {
        alert('This date is not available for booking' + (info.blockedInfo.notes ? ':\n' + info.blockedInfo.notes : '.'));
        return;
      }
      window.location.href = '<?= url('parishioner/services.php') ?>?date=' + dateStr;
    },
    datesSet: function () {
      // The calendar has already re-rendered synchronously by this point,
      // but give layout a tick to settle before measuring.
      setTimeout(syncUpcomingEventsHeight, 0);
    }
  });

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(syncUpcomingEventsHeight, 150);
  });
});
</script>

<style>
@media (max-width: 900px) {
  .calendar-layout { grid-template-columns: 1fr !important; }
}
</style>

<?php include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-end.php' : 'public-shell-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
