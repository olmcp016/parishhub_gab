<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

/** Categories an Admin can add a service under (the options of the Add New Service form). */
const ADMIN_SERVICE_CATEGORIES = ['Mass Intention', 'Wedding', 'Baptism', 'Funeral', 'Blessing', 'Confirmation', 'First Communion', 'Wake', 'Anointing'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int) $_POST['service_id'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM appointments a WHERE a.service_id = ? AND a.status_id NOT IN (3,7)');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', 'This service has active appointments and cannot be deleted. Deactivate it instead.');
            redirect(url('admin/services.php'));
        }
        db()->prepare('DELETE FROM services WHERE service_id = ?')->execute([$id]);
        logActivity(currentUser()['user_id'], "Deleted service #$id", 'Services');
        flash('success', 'Service deleted.');
        redirect(url('admin/services.php'));
    }

    // Add: the same checks as the Secretary's Manage Services page, with the categories this form offers.
    $serviceName = trim((string) ($_POST['service_name'] ?? ''));
    $category = (string) ($_POST['category'] ?? '');
    $fee = is_numeric($_POST['fee'] ?? '') ? (float) $_POST['fee'] : null;
    $duration = (int) ($_POST['duration_minutes'] ?? 0) ?: 60;
    if ($serviceName === '' || !in_array($category, ADMIN_SERVICE_CATEGORIES, true) || $fee === null || $fee < 0 || $duration < 1) {
        flash('error', 'Please enter a service name, choose a category, and enter a valid fee (0 or more).');
        redirect(url('admin/services.php?add=1'));
    }
    db()->prepare(
        "INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $serviceName, $category, trim((string) ($_POST['description'] ?? '')) ?: null,
        $fee, trim((string) ($_POST['requirements'] ?? '')) ?: null, $duration,
    ]);
    logActivity(currentUser()['user_id'], "Added service: $serviceName", 'Services');
    flash('success', 'Service added.');
    redirect(url('admin/services.php'));
}

$services = db()->query("SELECT * FROM services WHERE category != 'Donation' ORDER BY category, service_name")->fetchAll();

$active = 'services';
$pageTitle = 'Manage Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php $addFormOpen = isset($_GET['add']); // reopened after a validation error ?>
<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h3 style="margin:0;">Add New Service</h3>
    <button type="button" class="btn btn-outline btn-sm" id="addServiceToggle" aria-expanded="<?= $addFormOpen ? 'true' : 'false' ?>" aria-controls="addServiceForm" style="display:inline-flex; align-items:center; gap:6px;">
      <i data-lucide="<?= $addFormOpen ? 'chevron-up' : 'plus' ?>" id="addServiceToggleIcon" style="width:16px; height:16px;"></i>
      <span id="addServiceToggleText"><?= $addFormOpen ? 'Hide Form' : 'Add New Service' ?></span>
    </button>
  </div>
  <form method="POST" action="<?= url('admin/services.php') ?>" id="addServiceForm" <?= $addFormOpen ? '' : 'hidden' ?>>
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group"><label for="addServiceName">Service Name</label><input type="text" name="service_name" id="addServiceName" required></div>
      <div class="form-group">
        <label for="addServiceCategory">Category</label>
        <select name="category" id="addServiceCategory" required>
          <?php foreach (ADMIN_SERVICE_CATEGORIES as $categoryOption): ?><option><?= e($categoryOption) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group"><label for="addServiceDescription">Description</label><textarea name="description" id="addServiceDescription" rows="2"></textarea></div>
    <div class="form-row">
      <div class="form-group"><label for="addServiceFee">Fee (₱)</label><input type="number" name="fee" id="addServiceFee" step="0.01" min="0" required></div>
      <div class="form-group"><label for="addServiceDuration">Duration (minutes)</label><input type="number" name="duration_minutes" id="addServiceDuration" min="1" value="60"></div>
    </div>
    <div class="form-group"><label for="addServiceRequirements">Requirements</label><input type="text" name="requirements" id="addServiceRequirements" placeholder="Comma-separated list"></div>
    <button type="submit" class="btn btn-primary">Add Service</button>
  </form>
</div>
<script>
// Add New Service: collapsed until the toggle is used (a native button, so Enter/Space work too).
(function () {
  var toggle = document.getElementById('addServiceToggle'), form = document.getElementById('addServiceForm');
  if (!toggle || !form) return;
  toggle.addEventListener('click', function () {
    var open = form.hidden;
    form.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.getElementById('addServiceToggleText').textContent = open ? 'Hide Form' : 'Add New Service';
    var icon = toggle.querySelector('[data-lucide], svg');
    if (icon) {
      var fresh = document.createElement('i');
      fresh.setAttribute('data-lucide', open ? 'chevron-up' : 'plus');
      fresh.style.width = fresh.style.height = '16px';
      icon.replaceWith(fresh);
      if (window.lucide) lucide.createIcons();
    }
    if (open) document.getElementById('addServiceName').focus();
  });
})();
</script>

<div class="card">
  <div class="card-header"><h3>All Services</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Category</th><th>Fee</th><th>Duration</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><?= e($s['service_name']) ?></td>
            <td><?= e($s['category']) ?></td>
            <td><?= money($s['fee']) ?></td>
            <td><?= $s['duration_minutes'] ?> min</td>
            <td><?= $s['is_active'] ? '✔' : '✖' ?></td>
            <td><button class="btn btn-danger btn-sm js-delete-service" data-id="<?= $s['service_id'] ?>" data-name="<?= e($s['service_name']) ?>">Delete</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted mt-2" style="font-size:13px;">Tip: to edit or deactivate an existing service, use the Secretary → Services page (Admin has the same access).</p>
</div>

<dialog class="modal" id="deleteServiceModal">
  <div class="modal-head">
    <h3>Delete Service</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('deleteServiceModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;">Are you sure you want to delete <strong id="deleteServiceName"></strong>? This cannot be undone.</p>
    <p class="text-muted" style="font-size:13px;">Services with active appointments cannot be deleted — deactivate them instead.</p>
    <form method="POST" action="<?= url('admin/services.php') ?>" id="deleteServiceForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="service_id" id="deleteServiceId">
      <div class="flex gap-3" style="justify-content:flex-end; margin-top:16px;">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteServiceModal').close()">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</dialog>
<script>
document.querySelectorAll('.js-delete-service').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('deleteServiceId').value = this.dataset.id;
    document.getElementById('deleteServiceName').textContent = this.dataset.name;
    document.getElementById('deleteServiceModal').showModal();
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
