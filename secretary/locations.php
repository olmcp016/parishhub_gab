<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary');

$userId = currentUser()['user_id'];
$locationCategories = ['barangay' => 'Barangay', 'school' => 'School', 'chapel' => 'Chapel', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    $category = $_POST['location_category'] ?? 'other';
    if (in_array($action, ['add', 'update'], true) && !array_key_exists($category, $locationCategories)) {
        flash('error', 'Please choose a valid location category.');
        redirect(url('secretary/locations.php'));
    }

    if ($action === 'add') {
        db()->prepare('INSERT INTO locations (name, location_category, notes) VALUES (?, ?, ?)')
            ->execute([trim($_POST['name']), $category, trim($_POST['notes'] ?? '') ?: null]);
        logActivity($userId, "Added location: " . trim($_POST['name']), 'Locations');
        flash('success', 'Location added.');
    } elseif ($action === 'update') {
        db()->prepare('UPDATE locations SET name = ?, location_category = ?, notes = ? WHERE location_id = ?')
            ->execute([trim($_POST['name']), $category, trim($_POST['notes'] ?? '') ?: null, $_POST['location_id']]);
        flash('success', 'Location updated.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE locations SET is_active = ? WHERE location_id = ?')
            ->execute([!empty($_POST['is_active']) ? 1 : 0, $_POST['location_id']]);
        flash('success', 'Location updated.');
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('SELECT name FROM locations WHERE location_id = ?');
        $stmt->execute([$_POST['location_id']]);
        $deletedName = $stmt->fetchColumn();
        db()->prepare('DELETE FROM locations WHERE location_id = ?')->execute([$_POST['location_id']]);
        logActivity($userId, "Deleted location: " . ($deletedName ?: '#' . $_POST['location_id']), 'Locations');
        flash('success', 'Location removed.');
    }
    $backPage = max(1, (int) ($_POST['page'] ?? 1));
    redirect(url('secretary/locations.php') . ($backPage > 1 ? '?page=' . $backPage : ''));
}

$perPage     = 10;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$totalCount  = (int) db()->query('SELECT COUNT(*) FROM locations')->fetchColumn();
$totalPages  = max(1, (int) ceil($totalCount / $perPage));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $perPage;

$stmt = db()->prepare('SELECT * FROM locations ORDER BY name LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$locations = $stmt->fetchAll();

$active = 'locations';
$pageTitle = 'Manage Locations';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Add Location</h3></div>
  <p class="helper-text" style="margin-top:-6px;">These locations become available in the Add Event form's Location dropdown. Deactivating (rather than deleting) a location keeps past events' location intact while hiding it from new events.</p>
  <form method="POST" action="<?= url('secretary/locations.php') ?>" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group" style="flex: 2 1 200px; margin-bottom:0;"><label>Name</label><input type="text" name="name" required placeholder="e.g. Barangay Chapel"></div>
    <div class="form-group" style="flex: 1 1 150px; margin-bottom:0;"><label>Category</label><select name="location_category" required><?php foreach ($locationCategories as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="flex: 2 1 200px; margin-bottom:0;"><label>Notes (optional)</label><input type="text" name="notes" placeholder="e.g. Seats 50"></div>
    <div class="form-group" style="flex: 0 0 auto; margin-bottom:0;"><button type="submit" class="btn btn-primary" style="padding:12px 24px; height:47.5px;">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Locations</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Category</th><th>Notes</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($locations as $loc): ?>
          <tr id="row-<?= $loc['location_id'] ?>">
            <td>
              <span class="view-mode"><?= e($loc['name']) ?></span>
              <form method="POST" action="<?= url('secretary/locations.php') ?>" class="edit-mode" style="display:none;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="text" name="name" value="<?= e($loc['name']) ?>" required style="margin-bottom:6px;">
                <select name="location_category" required style="margin-bottom:6px;"><?php foreach ($locationCategories as $value => $label): ?><option value="<?= e($value) ?>" <?= ($loc['location_category'] ?? 'other') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <input type="text" name="notes" value="<?= e($loc['notes'] ?? '') ?>" placeholder="Notes">
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                <button type="button" class="btn btn-outline btn-sm mt-2" onclick="toggleEdit(<?= $loc['location_id'] ?>, false)">Cancel</button>
              </form>
            </td>
            <td class="view-mode"><?= e($locationCategories[$loc['location_category'] ?? 'other'] ?? 'Other') ?></td>
            <td class="view-mode"><?= e($loc['notes'] ?? '—') ?></td>
            <td>
              <form method="POST" action="<?= url('secretary/locations.php') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <select name="is_active" onchange="this.form.submit()">
                  <option value="1" <?= $loc['is_active'] ? 'selected' : '' ?>>Active</option>
                  <option value="0" <?= !$loc['is_active'] ? 'selected' : '' ?>>Inactive</option>
                </select>
              </form>
            </td>
            <td class="view-mode" style="white-space:nowrap;">
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEdit(<?= $loc['location_id'] ?>, true)">Edit</button>
              <button type="button" class="btn btn-danger btn-sm js-delete-location" data-id="<?= $loc['location_id'] ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($locations)): ?>
          <tr><td colspan="5" class="text-muted">No locations added yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination" style="display:flex; align-items:center; justify-content:space-between; padding:14px 0 4px; gap:8px; flex-wrap:wrap;">
    <span class="text-muted" style="font-size:13px;">
      Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCount) ?> of <?= $totalCount ?> locations
    </span>
    <div style="display:flex; gap:4px; align-items:center;">
      <?php
        $baseUrl = url('secretary/locations.php');
        $prevPage = $page - 1;
        $nextPage = $page + 1;
      ?>
      <a href="<?= $baseUrl ?>?page=<?= $prevPage ?>"
         class="btn btn-outline btn-sm<?= $page <= 1 ? ' disabled' : '' ?>"
         <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>← Prev</a>

      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="btn btn-primary btn-sm" style="cursor:default;"><?= $i ?></span>
        <?php elseif (abs($i - $page) <= 2 || $i === 1 || $i === $totalPages): ?>
          <a href="<?= $baseUrl ?>?page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a>
        <?php elseif (abs($i - $page) === 3): ?>
          <span class="btn btn-outline btn-sm" style="pointer-events:none;">…</span>
        <?php endif; ?>
      <?php endfor; ?>

      <a href="<?= $baseUrl ?>?page=<?= $nextPage ?>"
         class="btn btn-outline btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>"
         <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next →</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<dialog id="deleteLocationModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Delete Location?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to delete this location?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteLocationModal').close()">Cancel</button>
    <form method="POST" action="<?= url('secretary/locations.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="location_id" id="deleteLocationId">
      <input type="hidden" name="page" value="<?= $page ?>">
      <button type="submit" class="btn btn-danger">Yes, Delete</button>
    </form>
  </div>
</dialog>

<script>
function toggleEdit(id, editing) {
  var row = document.getElementById('row-' + id);
  row.querySelectorAll('.view-mode').forEach(function (el) { el.style.display = editing ? 'none' : ''; });
  row.querySelectorAll('.edit-mode').forEach(function (el) { el.style.display = editing ? 'block' : 'none'; });
}

document.querySelectorAll('.js-delete-location').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('deleteLocationId').value = this.dataset.id;
    document.getElementById('deleteLocationModal').showModal();
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
