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
$calendarEvents = array_map(fn($e) => ['id' => (int) $e['event_id'], 'date' => $e['event_date'], 'title' => $e['title']], $allEvents);
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blocked);

// Regular mass schedule for the next 90 days (display-only, same as secretary view)
$massScheduleEvents = [];
$massStart = new DateTime('today');
$massEnd   = (clone $massStart)->modify('+90 days');
for ($massDay = clone $massStart; $massDay <= $massEnd; $massDay->modify('+1 day')) {
    $massDateStr = $massDay->format('Y-m-d');
    $massDow = (int) $massDay->format('w');
    if ($massDow === 0) {
        $massScheduleEvents[] = ['date' => $massDateStr, 'title' => '1st Mass (6:30 AM)',     'clean_title' => '1st Mass',     'time' => '06:30'];
        $massScheduleEvents[] = ['date' => $massDateStr, 'title' => '2nd Mass (9:00 AM)',     'clean_title' => '2nd Mass',     'time' => '09:00'];
        $massScheduleEvents[] = ['date' => $massDateStr, 'title' => '3rd Mass (4:30 PM)',     'clean_title' => '3rd Mass',     'time' => '16:30'];
    } elseif ($massDow === 3) {
        $massScheduleEvents[] = ['date' => $massDateStr, 'title' => 'Evening Mass (5:15 PM)', 'clean_title' => 'Evening Mass', 'time' => '17:15'];
    } else {
        $massScheduleEvents[] = ['date' => $massDateStr, 'title' => 'Daily Mass (6:00 AM)',   'clean_title' => 'Daily Mass',   'time' => '06:00'];
    }
}

$active = 'calendar';
$pageTitle = 'Parish Calendar';
$shellMaxWidth = '1400px';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-start.php' : 'public-shell-start.php');
?>

<div style="display:grid; grid-template-columns: 1fr 350px; gap: 30px; align-items:start;" class="calendar-layout">
  <div id="calendarColumn">
    <div id="parishCalendar"></div>
    <div class="pcal-legend">
      <span><i class="pcal-dot pcal-dot-today"></i> Today</span>
      <span><i class="pcal-dot" style="background:#2d7a46;border-radius:50%;display:inline-block;width:10px;height:10px;vertical-align:middle;"></i> Mass Schedule</span>
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
                  <td><?= $ev['event_time'] ? date('g:i A', strtotime($ev['event_time'])) : '—' ?></td>
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
    massSchedule: <?= json_encode($massScheduleEvents, JSON_UNESCAPED_UNICODE) ?>,
    minDate: todayStr,
    onDateClick: function (dateStr, info) {
      if (info.isBlocked) {
        alert('This date is not available for booking' + (info.blockedInfo.notes ? ':\n' + info.blockedInfo.notes : '.'));
        return;
      }
      window.location.href = '<?= url('parishioner/services.php') ?>?date=' + dateStr;
    },
    onEventClick: function (fcEvent) {
      var eventId = fcEvent.extendedProps.eventId;
      if (eventId) { openEventPanel(eventId); return; }
      // Fallback: redirect by date if no ID
      var d = fcEvent.start;
      var dateStr = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      window.location.href = '<?= url('parishioner/services.php') ?>?date=' + dateStr;
    },
    onMassClick: function (fcEvent) {
      var props = fcEvent.extendedProps;
      openMassPanel(props.cleanTitle || fcEvent.title, fcEvent.startStr, props.time || '');
    },
    datesSet: function () {
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

/* Event blocks: clear hover affordance */
.fc-event { transition: all 0.2s ease; }
.fc-event:hover {
  filter: brightness(1.15);
  transform: scale(1.02);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
  cursor: pointer;
  z-index: 5;
}

/* Upcoming events table: compact font, all text wraps naturally */
#upcomingEventsCard .table-wrap { overflow-x: visible; }
#upcomingEventsCard .table-wrap table { font-size: 13px; width: 100%; }
#upcomingEventsCard .table-wrap td,
#upcomingEventsCard .table-wrap th { padding: 9px 10px; white-space: normal; word-break: normal; hyphens: auto; overflow-wrap: break-word; }
</style>

<!-- =================== READ-ONLY EVENT SLIDE-OVER PANEL =================== -->
<div id="eventPanelOverlay" class="epanel-overlay" aria-hidden="true"></div>
<aside id="eventPanel" class="epanel" role="dialog" aria-modal="true" aria-labelledby="epanelTitle" aria-hidden="true">
  <div class="epanel-header">
    <h3 id="epanelTitle" class="epanel-title">Event Details</h3>
    <button type="button" id="epanelCloseBtn" class="epanel-close" aria-label="Close panel">✕</button>
  </div>
  <div class="epanel-body">
    <div id="epanelLoading" class="epanel-loading" hidden>
      <span class="epanel-spinner"></span> Loading…
    </div>
    <div id="epanelViewMode">
      <dl class="epanel-dl">
        <dt>Title</dt>    <dd id="epView_title">—</dd>
        <dt>Date</dt>     <dd id="epView_date">—</dd>
        <dt>Time</dt>     <dd id="epView_time">—</dd>
        <dt>Location</dt> <dd id="epView_location">—</dd>
        <dt>Priest</dt>   <dd id="epView_priest">—</dd>
        <dt id="epView_desc_label" style="display:none;">About</dt>
        <dd  id="epView_desc"       style="display:none;">—</dd>
      </dl>
    </div>
  </div>
</aside>

<style>
.epanel-overlay {
  position: fixed; inset: 0;
  background: rgba(30,26,10,.35);
  z-index: 1040; opacity: 0; pointer-events: none;
  transition: opacity .25s ease;
}
.epanel-overlay.is-open { opacity: 1; pointer-events: auto; }
.epanel {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: 380px; max-width: 94vw;
  background: #fff;
  box-shadow: -4px 0 24px rgba(30,26,10,.14);
  z-index: 1050;
  display: flex; flex-direction: column;
  transform: translateX(100%);
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}
.epanel.is-open { transform: translateX(0); }
.epanel-header {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--border);
  background: var(--cream);
}
.epanel-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--brown-dark); }
.epanel-close {
  border: none; background: none; cursor: pointer; font-size: 17px;
  color: var(--brown-muted); line-height: 1; padding: 4px 6px; border-radius: 6px;
  transition: background .15s, color .15s;
}
.epanel-close:hover { background: var(--cream-dark); color: var(--brown-dark); }
.epanel-body { flex: 1; overflow-y: auto; padding: 20px; }
.epanel-loading {
  display: flex; align-items: center; gap: 10px;
  color: var(--brown-muted); font-size: 14px; padding: 20px 0;
}
#eventPanel [hidden] { display: none !important; }
.epanel-spinner {
  display: inline-block; width: 18px; height: 18px; border-radius: 50%;
  border: 2.5px solid var(--gold-light); border-top-color: var(--gold);
  animation: epanelSpin .7s linear infinite;
}
@keyframes epanelSpin { to { transform: rotate(360deg); } }
.epanel-dl {
  display: grid; grid-template-columns: 90px 1fr;
  gap: 6px 12px; margin: 0; align-items: baseline;
}
.epanel-dl dt {
  font-size: 12px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .04em; color: var(--brown-muted); padding-top: 2px;
}
.epanel-dl dd {
  margin: 0; font-size: 14.5px; color: var(--brown-dark);
  padding: 2px 0 6px; border-bottom: 1px solid var(--cream-dark);
  word-break: break-word;
}
.epanel-dl dd:last-child { border-bottom: none; }
</style>

<script>
(function () {
  var API_BASE = <?= json_encode(url('api/')) ?>;
  var overlay  = document.getElementById('eventPanelOverlay');
  var panel    = document.getElementById('eventPanel');
  var loading  = document.getElementById('epanelLoading');
  var viewMode = document.getElementById('epanelViewMode');

  function openPanel() {
    panel.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');
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
  }

  document.getElementById('epanelCloseBtn').addEventListener('click', closePanel);
  overlay.addEventListener('click', closePanel);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) closePanel();
  });

  function formatTime12h(t) {
    if (!t) return '—';
    var parts = t.split(':');
    var h = parseInt(parts[0], 10), m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
  }

  function populateView(data) {
    document.getElementById('epanelTitle').textContent = data.title || 'Event Details';
    document.getElementById('epView_title').textContent = data.title || '—';
    var dateStr = data.event_date || '';
    document.getElementById('epView_date').textContent = dateStr
      ? new Date(dateStr + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
      : '—';
    document.getElementById('epView_time').textContent = formatTime12h(data.event_time || '');
    document.getElementById('epView_location').textContent = data.location_name || '—';
    var priest = data.priest_name ? ((data.priest_title ? data.priest_title + ' ' : '') + data.priest_name) : '—';
    document.getElementById('epView_priest').textContent = priest;
    var dl = document.getElementById('epView_desc_label');
    var dd = document.getElementById('epView_desc');
    if (data.description) {
      dl.style.display = ''; dd.style.display = '';
      dd.textContent = data.description;
    } else {
      dl.style.display = 'none'; dd.style.display = 'none';
    }
  }

  // Called when a real (gold) event is clicked — fetches full details via API
  window.openEventPanel = function (eventId) {
    openPanel();
    loading.hidden = false;
    viewMode.hidden = true;
    fetch(API_BASE + 'get_event.php?event_id=' + encodeURIComponent(eventId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loading.hidden = true;
        if (data.error) {
          document.getElementById('epView_title').textContent = 'Could not load event details.';
          viewMode.hidden = false;
          return;
        }
        populateView(data);
        viewMode.hidden = false;
      })
      .catch(function () {
        loading.hidden = true;
        document.getElementById('epView_title').textContent = 'Network error. Please try again.';
        viewMode.hidden = false;
      });
  };

  // Called when a virtual mass (green) block is clicked — populates from JS props directly
  window.openMassPanel = function (cleanTitle, dateStr, time) {
    openPanel();
    loading.hidden = true;
    populateView({
      title:         cleanTitle,
      event_date:    dateStr,
      event_time:    time,
      location_name: 'Parish Church',
      priest_name:   null,
      description:   null
    });
    viewMode.hidden = false;
  };
}());
</script>

<?php include __DIR__ . '/../includes/' . (usesParishionerShell() ? 'dash-end.php' : 'public-shell-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
