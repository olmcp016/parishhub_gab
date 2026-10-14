<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Priest');

/**
 * View-only for the Priest role (item 22) — no add-event, block-date, or
 * availability controls at all, unlike secretary/calendar.php. Shows the
 * parish Mass schedule (with each Mass's assigned priest), other parish
 * events, and this priest's own approved appointments, read-only throughout.
 */
$priestId = currentPriestId();
$today = date('Y-m-d');

$allEvents = db()->query(
    "SELECT e.*, l.name AS location_name, p.title AS priest_title, p.full_name AS priest_name
     FROM events e
     LEFT JOIN locations l ON e.location_id = l.location_id
     LEFT JOIN priests p ON e.priest_id = p.priest_id
     ORDER BY e.event_date ASC, e.event_time ASC"
)->fetchAll();
$blocked = db()->query('SELECT * FROM calendar WHERE is_blocked = 1 ORDER BY calendar_date ASC')->fetchAll();
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocked);

// Where church-held services and the regular Masses take place.
$parishChurch = 'Our Lady of Mt. Carmel Parish';

$priestLabel = fn(?string $title, ?string $name) => $name ? trim(($title ?? '') . ' ' . $name) : null;
$timeLabel = fn(?string $time) => $time ? date('g:i A', strtotime($time)) : '';

/**
 * Masses. The Secretary assigns a priest to a Mass by adding a real event on
 * that date (secretary/calendar.php); those events replace the regular
 * schedule's placeholder for that date + title. Every other event (fiesta,
 * activities) stays a gold parish event, as before.
 */
$massSchedule = [];
$calendarEvents = [];
$existingRealMasses = [];
foreach ($allEvents as $ev) {
    $ev['title'] = (string) ($ev['title'] ?? '');
    $existingRealMasses[$ev['event_date'] . '_' . strtolower(trim($ev['title']))] = true;
    if (!preg_match('/\bmass\b/i', $ev['title'])) { // whole word, so "Christmas Party" stays an event
        $calendarEvents[] = ['date' => $ev['event_date'], 'title' => $ev['title']];
        continue;
    }
    $priest = $priestLabel($ev['priest_title'], $ev['priest_name']);
    $time = $ev['event_time'] ? substr($ev['event_time'], 0, 5) : '';
    // Only a Mass whose event row names this priest is "theirs" — never assumed.
    $isMine = $priestId !== null && $ev['priest_id'] !== null && (int) $ev['priest_id'] === $priestId;
    $massSchedule[] = [
        'date' => $ev['event_date'],
        // Mass name and time only; priest and location are shown when the event is clicked.
        'title' => $ev['title'] . ($time ? ' (' . $timeLabel($time) . ')' : ''),
        'clean_title' => $ev['title'],
        'time' => $time,
        // This priest's own Masses use the appointment blue; everyone else's stay green.
        'backgroundColor' => $isMine ? '#2457a6' : null,
        'borderColor' => $isMine ? '#1b4280' : null,
        'details' => [
            'title' => $ev['title'],
            'date' => formatDate($ev['event_date']),
            'time' => $timeLabel($time) ?: '—',
            'priest' => $priest ?? 'Not Yet Assigned',
            'isMine' => $isMine,
            'location' => $parishChurch, // every parish Mass is at the parish church
        ],
    ];
}

// The parish's regular Mass schedule for the next 90 days — same rules as
// secretary/calendar.php. A slot with no real event has no priest assigned yet.
$massStart = new DateTime('today');
$massEnd = (clone $massStart)->modify('+90 days');
for ($massDay = clone $massStart; $massDay <= $massEnd; $massDay->modify('+1 day')) {
    $massDateStr = $massDay->format('Y-m-d');
    $massDow = (int) $massDay->format('w');
    if ($massDow === 0) { // Sunday: three Masses
        $slots = [['1st Mass', '06:30'], ['2nd Mass', '09:00'], ['3rd Mass', '16:30']];
    } elseif ($massDow === 3) { // Wednesday: Daily Mass (evening)
        $slots = [['Daily Mass', '17:15']];
    } else { // Mon/Tue/Thu/Fri/Sat: Daily Mass
        $slots = [['Daily Mass', '06:00']];
    }
    foreach ($slots as [$massTitle, $massTime]) {
        if (isset($existingRealMasses[$massDateStr . '_' . strtolower($massTitle)])) continue;
        $massSchedule[] = [
            'date' => $massDateStr,
            'title' => $massTitle . ' (' . $timeLabel($massTime) . ')',
            'clean_title' => $massTitle,
            'time' => $massTime,
            'details' => [
                'title' => $massTitle,
                'date' => formatDate($massDateStr),
                'time' => $timeLabel($massTime),
                'priest' => 'Not Yet Assigned',
                'isMine' => false,
                'location' => $parishChurch,
            ],
        ];
    }
}

// This priest's approved appointments: approved by the Secretary (2) and still
// active as they move on through payment (4 Payment Verified, 5 Confirmed).
// Pending (1), rejected (3), completed (6), cancelled (7), and other priests'
// appointments are excluded.
$myAppointments = [];
$calendarAppointments = [];
if ($priestId) {
    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.guest_name, a.location_address,
                s.service_name, s.category, st.status_name, u.firstname, u.lastname,
                p.title AS priest_title, p.full_name AS priest_name
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN appointment_status st ON a.status_id = st.status_id
         JOIN priests p ON a.priest_id = p.priest_id
         LEFT JOIN parishioners par ON a.parishioner_id = par.parishioner_id
         LEFT JOIN users u ON par.user_id = u.user_id
         WHERE a.priest_id = ? AND a.status_id IN (2, 4, 5)
         ORDER BY a.appointment_date, a.appointment_time, a.appointment_id"
    );
    $stmt->execute([$priestId]);

    // Church-held sacraments happen at the parish; everything else (House
    // Blessing, Special Mass Request, ...) uses the address saved with the
    // booking (appointments.location_address), if any.
    $appointmentLocation = function (array $a) use ($parishChurch): string {
        if (in_array($a['category'], ['Wedding', 'Baptism', 'Funeral', 'Confirmation'], true)) return $parishChurch;
        $address = trim((string) ($a['location_address'] ?? ''));
        return $address !== '' ? $address : 'Location not provided';
    };

    foreach ($stmt->fetchAll() as $a) {
        // Some appointments have no time set yet (NULL), so don't hand null to substr().
        $time = $a['appointment_time'] ? substr($a['appointment_time'], 0, 5) : '';
        $name = $a['guest_name'] ?: trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $calendarAppointments[] = [
            'date' => $a['appointment_date'],
            // Service name and time only; the address and other details are in the click-through modal.
            'title' => $a['service_name'] . ($time ? ' (' . $timeLabel($time) . ')' : ''),
            'time' => $time,
            'details' => [
                'service' => $a['service_name'],
                'parishioner' => $name ?: '—',
                'date' => formatDate($a['appointment_date']),
                'time' => $timeLabel($time) ?: '—',
                'priest' => $priestLabel($a['priest_title'], $a['priest_name']),
                'status' => $a['status_name'],
                'location' => $appointmentLocation($a),
            ],
        ];
        if ($a['appointment_date'] >= $today && count($myAppointments) < 20) $myAppointments[] = $a + ['parishioner_name' => $name ?: '—'];
    }
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

$active = 'calendar';
$pageTitle = 'Parish Calendar';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 22px; align-items:start;" class="calendar-layout">
  <?php if (!$priestId): ?>
    <div class="alert" style="grid-column:1/-1;background:var(--danger-bg);color:var(--danger);border:1px solid #f5c2c2;">Your Priest Portal account is not linked to a priest profile. Please contact the parish office.</div>
  <?php endif; ?>
  <div>
    <div class="pcal-scroll"><div id="parishCalendar"></div></div>
    <div class="pcal-legend">
      <span><i class="pcal-dot pcal-dot-today"></i> Today</span>
      <span><i class="pcal-dot" style="background:#2457a6;"></i> Approved Appointment / Your Mass</span>
      <span><i class="pcal-dot" style="background:#2d7a46;"></i> Other Mass</span>
      <span><i class="pcal-dot pcal-dot-event"></i> Event</span>
      <span><i class="pcal-dot pcal-dot-blocked"></i> Unavailable</span>
    </div>
    <p class="helper-text mt-2">View-only — scheduling changes are handled by the parish office.</p>
  </div>

  <div class="card">
    <div class="card-header"><h3>Your Upcoming Appointments</h3></div>
    <?php if (empty($myAppointments)): ?>
      <p class="text-muted">No upcoming approved appointments assigned to you.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Parishioner</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($myAppointments as $a): ?>
              <tr>
                <td><?= formatDate($a['appointment_date']) ?></td>
                <td><?= $a['appointment_time'] ? date('g:i A', strtotime($a['appointment_time'])) : '—' ?></td>
                <td><?= e($a['service_name']) ?></td>
                <td><?= e($a['parishioner_name']) ?></td>
                <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<dialog class="modal" id="pcalDetailModal" aria-labelledby="pcalDetailTitle">
  <div class="modal-head">
    <h3 id="pcalDetailTitle">Details</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('pcalDetailModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <dl class="epanel-dl" id="pcalDetailList"></dl>
  </div>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('pcalDetailModal');

  // rows: [[label, value], ...]; values are set as text, never HTML.
  function showDetails(heading, rows) {
    document.getElementById('pcalDetailTitle').textContent = heading;
    var list = document.getElementById('pcalDetailList');
    list.innerHTML = '';
    rows.forEach(function (row) {
      if (row[1] == null || row[1] === '') return;
      var dt = document.createElement('dt'); dt.textContent = row[0];
      var dd = document.createElement('dd'); dd.textContent = row[1];
      list.appendChild(dt); list.appendChild(dd);
    });
    modal.showModal();
  }

  renderParishCalendar('parishCalendar', {
    events: <?= json_encode($calendarEvents, $jsonFlags) ?>,
    blocked: <?= json_encode($calendarBlocked, $jsonFlags) ?>,
    massSchedule: <?= json_encode($massSchedule, $jsonFlags) ?>,
    appointments: <?= json_encode($calendarAppointments, $jsonFlags) ?>,
    dayMaxEvents: 4,
    eventOrder: 'time,title',
    onDateClick: function () { /* view-only — no booking/blocking from here */ },
    onMassClick: function (fcEvent) {
      var d = fcEvent.extendedProps.details;
      if (!d) return;
      showDetails('Mass Details', [
        ['Mass', d.title],
        ['Date', d.date],
        ['Time', d.time],
        ['Priest', d.priest],
        ['Location', d.location],
        ['Assignment', d.isMine ? 'Assigned to You' : (d.priest === 'Not Yet Assigned' ? 'Not Yet Assigned' : 'Assigned to Another Priest')]
      ]);
    },
    onAppointmentClick: function (fcEvent) {
      var d = fcEvent.extendedProps.details;
      if (!d) return;
      showDetails('Appointment Details', [
        ['Service', d.service],
        ['Parishioner', d.parishioner],
        ['Date', d.date],
        ['Time', d.time],
        ['Priest', d.priest],
        ['Status', d.status],
        ['Location', d.location]
      ]);
    }
  });

  modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });
});
</script>

<style>
@media (max-width: 900px) {
  .calendar-layout { grid-template-columns: minmax(0, 1fr) !important; }
}
/* The calendar takes the wide column; the appointments table scrolls inside its
   own card. If there still isn't room for all seven days, the calendar scrolls
   sideways instead of squeezing or hiding the Saturday column. */
.pcal-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
#parishCalendar { min-width: 540px; }
/* Event labels wrap inside their day cell instead of spilling out of it. */
#parishCalendar .fc-daygrid-event { white-space: normal; overflow: hidden; }
#parishCalendar .fc-daygrid-event .fc-event-title { display: block; overflow-wrap: anywhere; }
/* Same detail list as the Secretary's event panel (secretary/calendar.php). */
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
  white-space: pre-line; overflow-wrap: anywhere; /* full multi-line addresses */
}
.epanel-dl dd:last-child { border-bottom: none; }
</style>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
