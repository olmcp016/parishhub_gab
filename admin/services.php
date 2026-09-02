<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    db()->prepare(
        "INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $_POST['service_name'], $_POST['category'], $_POST['description'] ?: null,
        $_POST['fee'], $_POST['requirements'] ?: null, $_POST['duration_minutes'] ?: 60,
    ]);
    flash('success', 'Service added.');
    redirect(url('admin/services.php'));
}

$services = db()->query('SELECT * FROM services ORDER BY category, service_name')->fetchAll();

$active = 'services';
$pageTitle = 'Manage Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Add New Service</h3></div>
  <form method="POST" action="<?= url('admin/services.php') ?>">
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group"><label>Service Name</label><input type="text" name="service_name" required></div>
      <div class="form-group">
        <label>Category</label>
        <select name="category" required>
          <option>Mass Intention</option>
          <option>Wedding</option>
          <option>Baptism</option>
          <option>Funeral</option>
          <option>Blessing</option>
          <option>Confirmation</option>
          <option>First Communion</option>
        </select>
      </div>
    </div>
    <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
    <div class="form-row">
      <div class="form-group"><label>Fee (₱)</label><input type="number" name="fee" step="0.01" required></div>
      <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="60"></div>
    </div>
    <div class="form-group"><label>Requirements</label><input type="text" name="requirements" placeholder="Comma-separated list"></div>
    <button type="submit" class="btn btn-primary">Add Service</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Services</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Category</th><th>Fee</th><th>Duration</th><th>Active</th></tr></thead>
      <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><?= e($s['service_name']) ?></td>
            <td><?= e($s['category']) ?></td>
            <td><?= money($s['fee']) ?></td>
            <td><?= $s['duration_minutes'] ?> min</td>
            <td><?= $s['is_active'] ? '✔' : '✖' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted mt-2" style="font-size:13px;">Tip: to edit or deactivate an existing service, use the Secretary → Services page (Admin has the same access).</p>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
