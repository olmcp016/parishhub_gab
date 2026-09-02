<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int) $_POST['service_id'];
    db()->prepare("UPDATE services SET service_name=?, fee=?, description=?, is_active=? WHERE service_id=?")
        ->execute([$_POST['service_name'], $_POST['fee'], $_POST['description'], !empty($_POST['is_active']) ? 1 : 0, $id]);
    flash('success', 'Service updated.');
    redirect(url('secretary/services.php'));
}

$services = db()->query('SELECT * FROM services ORDER BY category, service_name')->fetchAll();

$active = 'services';
$pageTitle = 'Manage Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>All Services</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Service</th><th>Category</th><th>Fee</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><?= e($s['service_name']) ?></td>
            <td><?= e($s['category']) ?></td>
            <td><?= money($s['fee']) ?></td>
            <td><?= $s['is_active'] ? 'Yes' : 'No' ?></td>
            <td><button class="btn btn-outline btn-sm" onclick="document.getElementById('edit-<?= $s['service_id'] ?>').style.display='table-row'">Edit</button></td>
          </tr>
          <tr id="edit-<?= $s['service_id'] ?>" style="display:none; background: var(--cream);">
            <td colspan="5">
              <form method="POST" action="<?= url('secretary/services.php') ?>" class="form-row" style="align-items:end;">
                <?= csrfField() ?>
                <input type="hidden" name="service_id" value="<?= $s['service_id'] ?>">
                <div class="form-group"><label>Name</label><input type="text" name="service_name" value="<?= e($s['service_name']) ?>" required></div>
                <div class="form-group"><label>Fee</label><input type="number" name="fee" value="<?= e((string)$s['fee']) ?>" step="0.01" required></div>
                <div class="form-group"><label>Description</label><input type="text" name="description" value="<?= e($s['description']) ?>"></div>
                <div class="form-group">
                  <label><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> style="width:auto; display:inline-block;"> Active</label>
                </div>
                <div class="form-group"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
