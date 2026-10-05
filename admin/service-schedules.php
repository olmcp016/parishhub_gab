<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Secretary');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        db()->prepare(
            "INSERT INTO service_schedules (service_id, weekday, occurrence, slot_time, created_by) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            (int) $_POST['service_id'],
            (int) $_POST['weekday'],
            $_POST['occurrence'] !== '' ? (int) $_POST['occurrence'] : null,
            $_POST['slot_time'],
            $userId,
        ]);
        flash('success', 'Regular schedule added.');
    } elseif ($action === 'edit') {
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        $occurrenceRaw = $_POST['occurrence'] ?? '';
        $occurrence = $occurrenceRaw === '' ? null : filter_var($occurrenceRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $slotTime = trim((string) ($_POST['slot_time'] ?? ''));
        $isActive = $_POST['is_active'] ?? null;

        if (!$scheduleId || !$serviceId || $weekday === false || ($occurrenceRaw !== '' && $occurrence === false)
            || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slotTime) || !in_array($isActive, ['0', '1'], true)) {
            flash('error', 'Please provide valid regular schedule details.');
            redirect(url('admin/service-schedules.php'));
        }

        $stmt = db()->prepare(
            "SELECT schedule_id FROM service_schedules
             WHERE schedule_id = ? AND service_id IS NOT NULL"
        );
        $stmt->execute([$scheduleId]);
        if (!$stmt->fetchColumn()) {
            flash('error', 'That regular schedule could not be found.');
            redirect(url('admin/service-schedules.php'));
        }

        $stmt = db()->prepare(
            "SELECT service_id FROM services
             WHERE service_id = ? AND category IN ('Baptism', 'Wedding', 'Blessing', 'Confirmation')"
        );
        $stmt->execute([$serviceId]);
        if (!$stmt->fetchColumn()) {
            flash('error', 'Please choose a valid service.');
            redirect(url('admin/service-schedules.php'));
        }

        $stmt = db()->prepare(
            "SELECT schedule_id FROM service_schedules
             WHERE service_id = ? AND weekday = ? AND occurrence IS NOT DISTINCT FROM ?
               AND slot_time = ? AND schedule_id <> ? LIMIT 1"
        );
        $stmt->execute([$serviceId, $weekday, $occurrence, $slotTime . ':00', $scheduleId]);
        if ($stmt->fetchColumn()) {
            flash('error', 'That service already has the same regular schedule configured.');
            redirect(url('admin/service-schedules.php'));
        }

        db()->prepare(
            'UPDATE service_schedules SET service_id = ?, weekday = ?, occurrence = ?, slot_time = ?, is_active = ? WHERE schedule_id = ?'
        )->execute([$serviceId, $weekday, $occurrence, $slotTime . ':00', (int) $isActive, $scheduleId]);
        flash('success', 'Regular schedule updated successfully.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE service_schedules SET is_active = ? WHERE schedule_id = ?')
            ->execute([!empty($_POST['is_active']) ? 1 : 0, $_POST['schedule_id']]);
        flash('success', 'Schedule updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM service_schedules WHERE schedule_id = ?')->execute([$_POST['schedule_id']]);
        flash('success', 'Schedule removed.');
    }
    redirect(url('admin/service-schedules.php'));
}

$services = db()->query(
    "SELECT service_id, service_name, category FROM services
     WHERE category IN ('Baptism', 'Wedding', 'Blessing', 'Confirmation')
       AND (is_active = TRUE OR service_id IN (SELECT service_id FROM service_schedules))
     ORDER BY category, service_name"
)->fetchAll();

$schedules = db()->query(
    "SELECT ss.*, s.service_name, s.category FROM service_schedules ss
     JOIN services s ON ss.service_id = s.service_id
     ORDER BY s.category, ss.weekday, ss.occurrence NULLS FIRST"
)->fetchAll();

$active = 'service-schedules';
$pageTitle = 'Regular Schedules';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Add Regular Schedule</h3></div>
  <p class="helper-text" style="margin-top:-6px;">These are the fixed schedules parishioners see when they choose "Regular" for Wedding, Baptism, House Blessing, or Confirmation. Add one row per recurring schedule — e.g. add "1st Saturday, 9:00 AM" and "3rd Saturday, 9:00 AM" separately for a "1st and 3rd Saturday" rule.</p>
  <form method="POST" action="<?= url('admin/service-schedules.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group" style="flex: 2; min-width: 220px;">
      <label>Service</label>
      <select name="service_id" class="form-control" required>
        <?php foreach ($services as $s): ?>
          <option value="<?= $s['service_id'] ?>"><?= e($s['service_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex: 1.5; min-width: 150px;">
      <label>Weekday</label>
      <select name="weekday" class="form-control" required>
        <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $name): ?>
          <option value="<?= $i ?>"><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex: 1.5; min-width: 150px;">
      <label>Occurrence</label>
      <select name="occurrence" class="form-control">
        <option value="">Every week</option>
        <option value="1">1st</option>
        <option value="2">2nd</option>
        <option value="3">3rd</option>
        <option value="4">4th</option>
        <option value="5">5th</option>
      </select>
    </div>
    <div class="form-group" style="flex: 1; min-width: 130px;">
      <label>Time</label>
      <input type="time" name="slot_time" required>
    </div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>Configured Schedules</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Weekday</th><th>Occurrence</th><th>Time</th><th>Active</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($schedules as $s): ?>
          <tr>
            <td><?= e($s['service_name']) ?></td>
            <td><?= WEEKDAY_NAMES[$s['weekday']] ?></td>
            <td><?= $s['occurrence'] !== null ? (OCCURRENCE_ORDINALS[$s['occurrence']] ?? $s['occurrence'] . 'th') : 'Every week' ?></td>
            <td><?= date('g:i A', strtotime($s['slot_time'])) ?></td>
            <td>
              <form method="POST" action="<?= url('admin/service-schedules.php') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
                <select name="is_active" onchange="this.form.submit()">
                  <option value="1" <?= $s['is_active'] ? 'selected' : '' ?>>Active</option>
                  <option value="0" <?= !$s['is_active'] ? 'selected' : '' ?>>Inactive</option>
                </select>
              </form>
            </td>
            <td>
              <button type="button" class="btn btn-outline btn-sm js-edit-schedule"
                data-id="<?= (int) $s['schedule_id'] ?>"
                data-service-id="<?= (int) $s['service_id'] ?>"
                data-weekday="<?= (int) $s['weekday'] ?>"
                data-occurrence="<?= $s['occurrence'] === null ? '' : (int) $s['occurrence'] ?>"
                data-time="<?= e(substr($s['slot_time'], 0, 5)) ?>"
                data-active="<?= $s['is_active'] ? '1' : '0' ?>">Edit</button>
              <button type="button" class="btn btn-danger btn-sm js-delete-schedule"
                data-id="<?= $s['schedule_id'] ?>"
                data-label="<?= e($s['service_name'] . ' — ' . WEEKDAY_NAMES[$s['weekday']] . ' ' . date('g:i A', strtotime($s['slot_time']))) ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($schedules)): ?>
          <tr><td colspan="6" class="text-muted">No regular schedules configured yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog class="modal" id="editScheduleModal" aria-labelledby="editScheduleTitle">
  <div class="modal-head">
    <h3 id="editScheduleTitle">Edit Regular Schedule</h3>
    <button type="button" class="modal-close" id="editScheduleClose" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('admin/service-schedules.php') ?>" id="editScheduleForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="schedule_id" id="editScheduleId">
      <div class="form-group"><label for="editScheduleService">Service</label>
        <select name="service_id" id="editScheduleService" required>
          <?php foreach ($services as $s): ?><option value="<?= (int) $s['service_id'] ?>"><?= e($s['service_name']) ?> (<?= e($s['category']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label for="editScheduleWeekday">Weekday</label>
        <select name="weekday" id="editScheduleWeekday" required>
          <?php foreach (WEEKDAY_NAMES as $i => $name): ?><option value="<?= $i ?>"><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label for="editScheduleOccurrence">Occurrence</label>
        <select name="occurrence" id="editScheduleOccurrence">
          <option value="">Every week</option>
          <?php foreach (OCCURRENCE_ORDINALS as $i => $name): ?><option value="<?= $i ?>"><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label for="editScheduleTime">Time</label><input type="time" name="slot_time" id="editScheduleTime" required></div>
      <div class="form-group"><label for="editScheduleActive">Status</label>
        <select name="is_active" id="editScheduleActive" required><option value="1">Active</option><option value="0">Inactive</option></select>
      </div>
      <div class="flex gap-3" style="justify-content:flex-end; margin-top:20px;">
        <button type="button" class="btn btn-outline" id="editScheduleCancel">Cancel</button>
        <button type="submit" class="btn btn-primary" id="editScheduleSubmit">Save Changes</button>
      </div>
    </form>
  </div>
</dialog>

<!-- Delete-confirm modal -->
<dialog id="deleteScheduleModal" style="max-width:420px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Remove Schedule?</h3>
  <p id="deleteScheduleMsg" style="color:var(--text-muted,#555);"></p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" id="deleteScheduleCancel">Cancel</button>
    <form method="POST" action="<?= url('admin/service-schedules.php') ?>" id="deleteScheduleForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="schedule_id" id="deleteScheduleId">
      <button type="submit" class="btn btn-danger">Yes, Remove</button>
    </form>
  </div>
</dialog>
<script>
(function () {
  var editModal = document.getElementById('editScheduleModal');
  var editTrigger = null;
  document.querySelectorAll('.js-edit-schedule').forEach(function (btn) {
    btn.addEventListener('click', function () {
      editTrigger = this;
      document.getElementById('editScheduleId').value = this.dataset.id;
      document.getElementById('editScheduleService').value = this.dataset.serviceId;
      document.getElementById('editScheduleWeekday').value = this.dataset.weekday;
      document.getElementById('editScheduleOccurrence').value = this.dataset.occurrence;
      document.getElementById('editScheduleTime').value = this.dataset.time;
      document.getElementById('editScheduleActive').value = this.dataset.active;
      document.getElementById('editScheduleSubmit').disabled = false;
      document.getElementById('editScheduleSubmit').textContent = 'Save Changes';
      editModal.showModal();
      document.getElementById('editScheduleService').focus();
    });
  });
  function closeEditModal() {
    editModal.close();
    if (editTrigger) editTrigger.focus();
  }
  document.getElementById('editScheduleClose').addEventListener('click', closeEditModal);
  document.getElementById('editScheduleCancel').addEventListener('click', closeEditModal);
  document.getElementById('editScheduleForm').addEventListener('submit', function () {
    var submit = document.getElementById('editScheduleSubmit');
    if (submit.disabled) return;
    submit.disabled = true;
    submit.textContent = 'Saving...';
  });

  var modal = document.getElementById('deleteScheduleModal');
  document.querySelectorAll('.js-delete-schedule').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('deleteScheduleId').value = this.dataset.id;
      document.getElementById('deleteScheduleMsg').textContent = 'Remove "' + this.dataset.label + '"? This cannot be undone.';
      modal.showModal();
    });
  });
  document.getElementById('deleteScheduleCancel').addEventListener('click', function () { modal.close(); });
})();
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
