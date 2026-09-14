<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Admin');

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
        flash('success', 'Regular slot added.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE service_schedules SET is_active = ? WHERE schedule_id = ?')
            ->execute([!empty($_POST['is_active']) ? 1 : 0, $_POST['schedule_id']]);
        flash('success', 'Slot updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM service_schedules WHERE schedule_id = ?')->execute([$_POST['schedule_id']]);
        flash('success', 'Slot removed.');
    }
    redirect(url('admin/service-schedules.php'));
}

$services = db()->query(
    "SELECT service_id, service_name, category FROM services
     WHERE category IN ('Baptism', 'Wedding', 'Blessing', 'Confirmation') AND is_active = TRUE
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
  <div class="card-header"><h3>Add Regular Slot</h3></div>
  <p class="helper-text" style="margin-top:-6px;">These are the fixed slots parishioners see when they choose "Regular" for Wedding, Baptism, House Blessing, or Confirmation. Add one row per recurring slot — e.g. add "1st Saturday, 9:00 AM" and "3rd Saturday, 9:00 AM" separately for a "1st and 3rd Saturday" rule.</p>
  <form method="POST" action="<?= url('admin/service-schedules.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group">
      <label>Service</label>
      <select name="service_id" required>
        <?php foreach ($services as $s): ?>
          <option value="<?= $s['service_id'] ?>"><?= e($s['service_name']) ?> (<?= e($s['category']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Weekday</label>
      <select name="weekday" required>
        <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $name): ?>
          <option value="<?= $i ?>"><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Occurrence</label>
      <select name="occurrence">
        <option value="">Every week</option>
        <option value="1">1st</option>
        <option value="2">2nd</option>
        <option value="3">3rd</option>
        <option value="4">4th</option>
        <option value="5">5th</option>
      </select>
    </div>
    <div class="form-group">
      <label>Time</label>
      <input type="time" name="slot_time" required>
    </div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>Configured Slots</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Weekday</th><th>Occurrence</th><th>Time</th><th>Active</th><th></th></tr></thead>
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
              <form method="POST" action="<?= url('admin/service-schedules.php') ?>" onsubmit="return confirm('Remove this slot?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="schedule_id" value="<?= $s['schedule_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($schedules)): ?>
          <tr><td colspan="6" class="text-muted">No Regular slots configured yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
