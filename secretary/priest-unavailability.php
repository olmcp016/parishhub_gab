<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $priestId = (int) $_POST['priest_id'];
        $startTime = $_POST['start_time'] ?: null;
        $endTime = $_POST['end_time'] ?: null;
        $reason = trim($_POST['reason'] ?? '') ?: null;

        $singleDate = $_POST['unavailable_date'] ?? '';
        $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $singleDate) && strtotime($singleDate) !== false;
        if (!$dateOk || $singleDate < date('Y-m-d')) {
            flash('error', 'Please choose a valid date that is today or later.');
            redirect(url('secretary/priest-unavailability.php'));
        }
        // Times are all-or-nothing: both blank = the whole day; otherwise a
        // real window where the end is after the start.
        if (($startTime === null) !== ($endTime === null)) {
            flash('error', 'Enter both a start time and an end time, or leave both blank to block the whole day.');
            redirect(url('secretary/priest-unavailability.php'));
        }
        if ($startTime !== null && $endTime <= $startTime) {
            flash('error', 'The end time must be later than the start time.');
            redirect(url('secretary/priest-unavailability.php'));
        }
        $exists = db()->prepare("SELECT 1 FROM priests WHERE priest_id = ? AND status != 'inactive'");
        $exists->execute([$priestId]);
        if (!$exists->fetchColumn()) {
            flash('error', 'Please choose a valid priest.');
            redirect(url('secretary/priest-unavailability.php'));
        }

        $dates = [$singleDate];
        if (!empty($_POST['repeat_weekly']) && !empty($_POST['repeat_until'])) {
            if ($_POST['repeat_until'] < $singleDate) {
                flash('error', 'The "repeat until" date must be on or after the start date.');
                redirect(url('secretary/priest-unavailability.php'));
            }
            $cursor = new DateTime($_POST['unavailable_date']);
            $until = new DateTime($_POST['repeat_until']);
            $dates = [];
            while ($cursor <= $until) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor->modify('+7 days');
            }
        }

        // A priest already booked for a specific date/time can't simply be
        // marked unavailable for that same window — the existing appointment
        // would be left with no priest showing up. Staff must reschedule or
        // reassign that appointment first (through the normal appointment
        // workflow), not paper over it here.
        $conflictStmt = db()->prepare(
            "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name
             FROM appointments a JOIN services s ON a.service_id = s.service_id
             WHERE a.priest_id = ? AND a.appointment_date = ? AND a.status_id NOT IN (3, 7)
               AND (? IS NULL OR (a.appointment_time >= ? AND a.appointment_time < ?))
             LIMIT 1"
        );
        foreach ($dates as $d) {
            $conflictStmt->execute([$priestId, $d, $startTime, $startTime, $endTime]);
            $conflict = $conflictStmt->fetch();
            if ($conflict) {
                flash('error', 'Cannot mark this priest unavailable on ' . formatDate($d) . ' — already booked for "' . $conflict['service_name'] . '" (#' . $conflict['appointment_id'] . ') at ' . date('g:i A', strtotime($conflict['appointment_time'])) . '. Reschedule or reassign that appointment first.');
                redirect(url('secretary/priest-unavailability.php'));
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO priest_unavailability (priest_id, unavailable_date, start_time, end_time, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($dates as $d) {
            $stmt->execute([$priestId, $d, $startTime, $endTime, $reason, $userId]);
        }
        $pdo->commit();
        flash('success', count($dates) > 1 ? count($dates) . ' unavailable dates added.' : 'Unavailable date added.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM priest_unavailability WHERE unavailability_id = ?')->execute([$_POST['unavailability_id']]);
        flash('success', 'Removed.');
    }
    redirect(url('secretary/priest-unavailability.php'));
}

$priests = db()->query("SELECT * FROM priests WHERE status != 'inactive' ORDER BY full_name")->fetchAll();
$rows = db()->query(
    "SELECT pu.*, p.title, p.full_name FROM priest_unavailability pu
     JOIN priests p ON pu.priest_id = p.priest_id
     WHERE pu.unavailable_date >= CURRENT_DATE
     ORDER BY p.full_name, pu.unavailable_date"
)->fetchAll();

// Each priest's upcoming schedule (appointments + unavailability), for the
// "View" modal below — kept off the page itself so this doesn't turn into
// one long bullet list per priest.
$today = date('Y-m-d');
$scheduleByPriest = [];
foreach ($priests as $p) {
    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name
         FROM appointments a JOIN services s ON a.service_id = s.service_id
         WHERE a.priest_id = ? AND a.status_id NOT IN (3, 7) AND a.appointment_date >= ?
         ORDER BY a.appointment_date, a.appointment_time LIMIT 10"
    );
    $stmt->execute([$p['priest_id'], $today]);
    $appts = $stmt->fetchAll();

    $stmt = db()->prepare(
        "SELECT unavailable_date, start_time, end_time, reason FROM priest_unavailability
         WHERE priest_id = ? AND unavailable_date >= ?
         ORDER BY unavailable_date LIMIT 10"
    );
    $stmt->execute([$p['priest_id'], $today]);
    $unavail = $stmt->fetchAll();

    $scheduleByPriest[$p['priest_id']] = [
        'name' => trim($p['title'] . ' ' . $p['full_name']),
        'appointments' => array_map(fn($a) => [
            'label' => formatDate($a['appointment_date']) . ' at ' . date('g:i A', strtotime($a['appointment_time'])) . ' — ' . $a['service_name'],
        ], $appts),
        'unavailability' => array_map(fn($u) => [
            'label' => 'Unavailable ' . formatDate($u['unavailable_date'])
                . ($u['start_time'] ? ' (' . date('g:i A', strtotime($u['start_time'])) . '–' . date('g:i A', strtotime($u['end_time'])) . ')' : ' (whole day)')
                . ($u['reason'] ? ' — ' . $u['reason'] : ''),
        ], $unavail),
    ];
}

$active = 'priest-unavailability';
$pageTitle = 'Priest Unavailability';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Mark a Priest Unavailable</h3></div>
  <p class="helper-text" style="margin-top:-6px;">Leave the time fields blank to block the whole day. For a priest with a recurring fixed schedule (e.g. only available certain weekdays), check "repeat weekly" and set an end date — this adds one entry per week rather than a single day.</p>
  <form method="POST" action="<?= url('secretary/priest-unavailability.php') ?>" class="form-row" style="align-items:end; flex-wrap:wrap;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group">
      <label>Priest</label>
      <select name="priest_id" required>
        <?php foreach ($priests as $p): ?>
          <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Date</label>
      <input type="date" name="unavailable_date" required min="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group">
      <label>Start Time (optional)</label>
      <input type="time" name="start_time">
    </div>
    <div class="form-group">
      <label>End Time (optional)</label>
      <input type="time" name="end_time">
    </div>
    <div class="form-group">
      <label>Reason (optional)</label>
      <input type="text" name="reason" placeholder="e.g. Fixed Schedule, Retreat, Vacation">
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="repeat_weekly" value="1" style="width:auto; display:inline-block;" onchange="document.getElementById('repeatUntilGroup').style.display=this.checked?'block':'none'"> Repeat weekly</label>
    </div>
    <div class="form-group" id="repeatUntilGroup" style="display:none;">
      <label>Repeat Until</label>
      <input type="date" name="repeat_until" min="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>Priest Schedules</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Priest</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($priests as $p): ?>
          <tr>
            <td><?= e($p['title']) ?> <?= e($p['full_name']) ?></td>
            <td><span class="badge badge-<?= $p['status'] === 'active' ? 'regular' : 'pending' ?>"><?= e(ucfirst(str_replace('_', ' ', $p['status']))) ?></span></td>
            <td><button type="button" class="btn btn-outline btn-sm js-view-priest-schedule" data-priest-id="<?= $p['priest_id'] ?>">View Schedule</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog class="modal" id="priestScheduleModal">
  <div class="modal-head">
    <h3 id="priestScheduleModalTitle">Priest Schedule</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('priestScheduleModal').close()">✕</button>
  </div>
  <div class="modal-body" id="priestScheduleModalBody"></div>
</dialog>

<script type="application/json" id="priestSchedulesData"><?= json_encode($scheduleByPriest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script>
(function () {
  var data = {};
  try { data = JSON.parse(document.getElementById('priestSchedulesData').textContent); } catch (e) {}

  function section(title, items, emptyText, dangerColor) {
    var html = '<h4 style="margin:14px 0 6px;">' + title + '</h4>';
    if (!items.length) {
      html += '<p class="text-muted" style="font-size:13px;">' + emptyText + '</p>';
      return html;
    }
    html += '<ul style="margin:0; padding-left:18px;' + (dangerColor ? ' color: var(--danger);' : '') + '">';
    items.forEach(function (i) { html += '<li style="font-size:13.5px; margin-bottom:4px;">' + i.label + '</li>'; });
    html += '</ul>';
    return html;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-view-priest-schedule');
    if (!btn) return;
    var p = data[btn.dataset.priestId];
    if (!p) return;
    document.getElementById('priestScheduleModalTitle').textContent = p.name;
    document.getElementById('priestScheduleModalBody').innerHTML =
      section('Upcoming Appointments (next 10)', p.appointments, 'No upcoming appointments.', false) +
      section('Unavailability (next 10)', p.unavailability, 'No upcoming unavailability entries.', true);
    document.getElementById('priestScheduleModal').showModal();
  });
})();
</script>

<div class="card">
  <div class="card-header"><h3>Upcoming Unavailability</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Priest</th><th>Date</th><th>Time</th><th>Reason</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['title']) ?> <?= e($r['full_name']) ?></td>
            <td><?= formatDate($r['unavailable_date']) ?></td>
            <td><?= ($r['start_time'] || $r['end_time']) ? ($r['start_time'] ? date('g:i A', strtotime($r['start_time'])) : 'Start of day') . '–' . ($r['end_time'] ? date('g:i A', strtotime($r['end_time'])) : 'End of day') : 'Whole day' ?></td>
            <td><?= e($r['reason'] ?? '—') ?></td>
            <td>
              <form method="POST" action="<?= url('secretary/priest-unavailability.php') ?>" onsubmit="return confirm('Remove this entry?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="unavailability_id" value="<?= $r['unavailability_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="5" class="text-muted">No upcoming unavailability entries.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
