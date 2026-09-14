<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        db()->prepare('INSERT INTO locations (name, notes) VALUES (?, ?)')
            ->execute([trim($_POST['name']), trim($_POST['notes'] ?? '') ?: null]);
        flash('success', 'Location added.');
    } elseif ($action === 'update') {
        db()->prepare('UPDATE locations SET name = ?, notes = ? WHERE location_id = ?')
            ->execute([trim($_POST['name']), trim($_POST['notes'] ?? '') ?: null, $_POST['location_id']]);
        flash('success', 'Location updated.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE locations SET is_active = ? WHERE location_id = ?')
            ->execute([!empty($_POST['is_active']) ? 1 : 0, $_POST['location_id']]);
        flash('success', 'Location updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM locations WHERE location_id = ?')->execute([$_POST['location_id']]);
        flash('success', 'Location removed.');
    }
    redirect(url('secretary/locations.php'));
}

$locations = db()->query('SELECT * FROM locations ORDER BY name')->fetchAll();

$active = 'locations';
$pageTitle = 'Manage Locations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Add Location</h3></div>
  <p class="helper-text" style="margin-top:-6px;">These locations become available in the Add Event form's Location dropdown. Deactivating (rather than deleting) a location keeps past events' location intact while hiding it from new events.</p>
  <form method="POST" action="<?= url('secretary/locations.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Barangay Chapel"></div>
    <div class="form-group"><label>Notes (optional)</label><input type="text" name="notes" placeholder="e.g. Seats 50"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Locations</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Notes</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($locations as $loc): ?>
          <tr id="row-<?= $loc['location_id'] ?>">
            <td>
              <span class="view-mode"><?= e($loc['name']) ?></span>
              <form method="POST" action="<?= url('secretary/locations.php') ?>" class="edit-mode" style="display:none;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                <input type="text" name="name" value="<?= e($loc['name']) ?>" required style="margin-bottom:6px;">
                <input type="text" name="notes" value="<?= e($loc['notes'] ?? '') ?>" placeholder="Notes">
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                <button type="button" class="btn btn-outline btn-sm mt-2" onclick="toggleEdit(<?= $loc['location_id'] ?>, false)">Cancel</button>
              </form>
            </td>
            <td class="view-mode"><?= e($loc['notes'] ?? '—') ?></td>
            <td>
              <form method="POST" action="<?= url('secretary/locations.php') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                <select name="is_active" onchange="this.form.submit()">
                  <option value="1" <?= $loc['is_active'] ? 'selected' : '' ?>>Active</option>
                  <option value="0" <?= !$loc['is_active'] ? 'selected' : '' ?>>Inactive</option>
                </select>
              </form>
            </td>
            <td class="view-mode" style="white-space:nowrap;">
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEdit(<?= $loc['location_id'] ?>, true)">Edit</button>
              <form method="POST" action="<?= url('secretary/locations.php') ?>" onsubmit="return confirm('Delete this location?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($locations)): ?>
          <tr><td colspan="4" class="text-muted">No locations added yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleEdit(id, editing) {
  var row = document.getElementById('row-' + id);
  row.querySelectorAll('.view-mode').forEach(function (el) { el.style.display = editing ? 'none' : ''; });
  row.querySelectorAll('.edit-mode').forEach(function (el) { el.style.display = editing ? 'block' : 'none'; });
}
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
