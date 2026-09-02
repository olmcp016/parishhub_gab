<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$events = db()->query('SELECT * FROM events ORDER BY event_date ASC')->fetchAll();
$blocked = db()->query('SELECT * FROM calendar WHERE is_blocked = 1 ORDER BY calendar_date ASC')->fetchAll();

// Data for the JS calendar widget — dates come back from MySQL as 'YYYY-MM-DD' strings already
$calendarEvents = array_map(fn($e) => ['date' => $e['event_date'], 'title' => $e['title']], $events);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocked);

$active = 'calendar';
$pageTitle = 'Parish Calendar';
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
    </div>
    <p class="helper-text mt-2" style="max-width:480px;">Click any available date to start booking an appointment for that day.</p>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><h3>Upcoming Parish Events</h3></div>
      <?php if (empty($events)): ?>
        <p class="text-muted">No events scheduled.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Event</th><th>Date</th><th>Time</th><th>Location</th></tr></thead>
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td><?= e($ev['title']) ?></td>
                  <td><?= formatDate($ev['event_date']) ?></td>
                  <td><?= e($ev['event_time'] ?? '—') ?></td>
                  <td><?= e($ev['location'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
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
<script src="<?= url('public/js/calendar.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // "today" is derived from the browser's own local clock, not the server's.
  var now = new Date();
  var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
    minDate: todayStr,
    onDateClick: function (dateStr, info) {
      if (info.isBlocked) {
        alert('This date is not available for booking' + (info.blockedInfo.notes ? ':\n' + info.blockedInfo.notes : '.'));
        return;
      }
      window.location.href = '<?= url('parishioner/book.php') ?>?date=' + dateStr;
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
