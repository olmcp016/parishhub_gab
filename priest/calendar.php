<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Priest');

/**
 * View-only for the Priest role (item 22) — no add-event, block-date, or
 * availability controls at all, unlike secretary/calendar.php. Shows the
 * general parish calendar (Mass schedule / parish events) plus this
 * priest's own upcoming appointments, read-only throughout.
 */
$priestId = currentPriestId();

$allEvents = db()->query('SELECT * FROM events ORDER BY event_date ASC')->fetchAll();
$blocked = db()->query('SELECT * FROM calendar WHERE is_blocked = 1 ORDER BY calendar_date ASC')->fetchAll();
$calendarEvents = array_map(fn($e) => ['date' => $e['event_date'], 'title' => $e['title']], $allEvents);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocked);

$today = date('Y-m-d');
$myAppointments = [];
if ($priestId) {
    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name, st.status_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN appointment_status st ON a.status_id = st.status_id
         WHERE a.priest_id = ? AND a.appointment_date >= ? AND a.status_id NOT IN (3, 7)
         ORDER BY a.appointment_date, a.appointment_time LIMIT 20"
    );
    $stmt->execute([$priestId, $today]);
    $myAppointments = $stmt->fetchAll();
}

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
    <p class="helper-text mt-2">View-only — scheduling changes are handled by the parish office.</p>
  </div>

  <div class="card">
    <div class="card-header"><h3>Your Upcoming Appointments</h3></div>
    <?php if (empty($myAppointments)): ?>
      <p class="text-muted">No upcoming appointments assigned to you.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($myAppointments as $a): ?>
              <tr>
                <td><?= formatDate($a['appointment_date']) ?></td>
                <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                <td><?= e($a['service_name']) ?></td>
                <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
    blocked: <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>,
    onDateClick: function () { /* view-only — no booking/blocking from here */ }
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
