<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $priestId = (int) $_POST['priest_id'];
        $startTime = $_POST['start_time'] ?: null;
        $endTime = $_POST['end_time'] ?: null;
        $reason = trim($_POST['reason'] ?? '') ?: null;

        $dates = [$_POST['unavailable_date']];
        if (!empty($_POST['repeat_weekly']) && !empty($_POST['repeat_until'])) {
            $cursor = new DateTime($_POST['unavailable_date']);
            $until = new DateTime($_POST['repeat_until']);
            $dates = [];
            while ($cursor <= $until) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor->modify('+7 days');
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
  <div class="card-header"><h3>Upcoming Unavailability</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Priest</th><th>Date</th><th>Time</th><th>Reason</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['title']) ?> <?= e($r['full_name']) ?></td>
            <td><?= formatDate($r['unavailable_date']) ?></td>
            <td><?= $r['start_time'] ? date('g:i A', strtotime($r['start_time'])) . '–' . date('g:i A', strtotime($r['end_time'])) : 'Whole day' ?></td>
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
