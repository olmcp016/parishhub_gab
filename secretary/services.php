<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'update';

    if ($action === 'toggle_donations') {
        $enabled = !empty($_POST['donation_enabled']) ? '1' : '0';
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('donation_enabled', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")->execute([$enabled]);
        logActivity(currentUser()['user_id'], 'Updated donation feature setting (' . ($enabled === '1' ? 'enabled' : 'disabled') . ')', 'Settings');
        flash('success', 'Donation settings updated.');
        redirect(url('secretary/services.php'));
    }

    if ($action === 'add') {
        $serviceName = trim($_POST['service_name'] ?? '');
        $category = $_POST['category'] ?? '';
        $fee = is_numeric($_POST['fee'] ?? '') ? (float) $_POST['fee'] : null;
        $validCategories = ['Mass Intention', 'Wedding', 'Baptism', 'Funeral', 'Blessing', 'Confirmation', 'First Communion'];

        if ($serviceName === '' || !in_array($category, $validCategories, true) || $fee === null || $fee < 0) {
            flash('error', 'Please enter a service name, choose a category, and enter a valid fee (0 or more).');
            redirect(url('secretary/services.php'));
        }

        db()->prepare(
            "INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $serviceName, $category, trim($_POST['description'] ?? '') ?: null, $fee,
            trim($_POST['requirements'] ?? '') ?: null, (int) ($_POST['duration_minutes'] ?: 60),
        ]);
        logActivity(currentUser()['user_id'], "Added service: $serviceName", 'Services');
        flash('success', 'Service added.');
        redirect(url('secretary/services.php'));
    }

    if ($action === 'delete') {
        $id = (int) $_POST['service_id'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM appointments a WHERE a.service_id = ? AND a.status_id NOT IN (3,7)');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', 'This service has active appointments and cannot be deleted. Deactivate it instead.');
            redirect(url('secretary/services.php'));
        }
        db()->prepare('DELETE FROM services WHERE service_id = ?')->execute([$id]);
        logActivity(currentUser()['user_id'], "Deleted service #$id", 'Services');
        flash('success', 'Service deleted.');
        redirect(url('secretary/services.php'));
    }

    // action === 'update' (existing edit-in-place form)
    $id = (int) $_POST['service_id'];
    db()->prepare("UPDATE services SET service_name=?, fee=?, description=?, requirements=?, is_active=? WHERE service_id=?")
        ->execute([$_POST['service_name'], $_POST['fee'], $_POST['description'], trim($_POST['requirements'] ?? '') ?: null, !empty($_POST['is_active']) ? 1 : 0, $id]);
    flash('success', 'Service updated.');
    redirect(url('secretary/services.php'));
}

$services = db()->query('SELECT * FROM services ORDER BY category, service_name')->fetchAll();
$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

$active = 'services';
$pageTitle = 'Manage Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
    <h3 style="margin:0;">Accept Online Donations</h3>
    <span class="badge <?= $donationEnabled ? 'badge-verified' : 'badge-cancelled' ?>" style="font-size:12px; padding:4px 10px;">
      <?= $donationEnabled ? 'ON' : 'OFF' ?>
    </span>
  </div>
  <p class="helper-text" style="margin-top:0; margin-bottom:16px;">
    Controls whether parishioners see the &ldquo;Donate Now&rdquo; button on their dashboard and can submit online donations.
  </p>
  <form method="POST" action="<?= url('secretary/services.php') ?>" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="toggle_donations">
    <label style="display:flex; align-items:center; gap:8px; font-weight:500; font-size:14px; cursor:pointer; text-transform:none;">
      <input type="checkbox" name="donation_enabled" value="1" <?= $donationEnabled ? 'checked' : '' ?> style="width:auto;">
      Enable the Donate button for parishioners
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <h3>All Services</h3>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('addServiceModal').showModal()">+ Add Service</button>
  </div>
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
            <td style="display:flex;gap:6px;flex-wrap:wrap;">
              <button class="btn btn-outline btn-sm js-edit-service" data-id="<?= $s['service_id'] ?>">Edit</button>
              <button class="btn btn-danger btn-sm js-delete-service" data-id="<?= $s['service_id'] ?>" data-name="<?= e($s['service_name']) ?>">Delete</button>
            </td>
          </tr>
          <tr id="edit-<?= $s['service_id'] ?>" class="service-edit-row" style="display:none; background: var(--cream);">
            <td colspan="5">
              <form method="POST" action="<?= url('secretary/services.php') ?>" class="form-row" style="align-items:end;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="service_id" value="<?= $s['service_id'] ?>">
                <div class="form-group"><label>Name</label><input type="text" name="service_name" value="<?= e($s['service_name']) ?>" required></div>
                <div class="form-group"><label>Fee</label><input type="number" name="fee" value="<?= e((string)$s['fee']) ?>" step="0.01" required></div>
                <div class="form-group"><label>Description</label><input type="text" name="description" value="<?= e($s['description']) ?>"></div>
                <div class="form-group"><label>Requirements (comma-separated)</label><input type="text" name="requirements" value="<?= e($s['requirements']) ?>" placeholder="e.g. Baptismal Certificate, Marriage License"></div>
                <div class="form-group">
                  <label><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?> style="width:auto; display:inline-block;"> Active</label>
                </div>
                <div class="form-group">
                  <button type="button" class="btn btn-outline btn-sm js-cancel-edit" data-id="<?= $s['service_id'] ?>">Cancel</button>
                  <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog class="modal" id="addServiceModal">
  <div class="modal-head">
    <h3>Add Service</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('addServiceModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('secretary/services.php') ?>" id="addServiceForm" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Service Name</label><input type="text" name="service_name" required></div>
      <div class="form-group">
        <label>Category</label>
        <select name="category" required>
          <option value="">-- Select --</option>
          <option>Mass Intention</option>
          <option>Wedding</option>
          <option>Baptism</option>
          <option>Funeral</option>
          <option>Blessing</option>
          <option>Confirmation</option>
          <option>First Communion</option>
        </select>
      </div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>Fee (₱)</label><input type="number" name="fee" step="0.01" min="0" required></div>
        <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="60" min="1"></div>
      </div>
      <div class="form-group"><label>Requirements</label><input type="text" name="requirements" placeholder="Comma-separated list"></div>
      <button type="submit" class="btn btn-primary btn-block">Add Service</button>
    </form>
  </div>
</dialog>

<dialog class="modal" id="deleteServiceModal">
  <div class="modal-head">
    <h3>Delete Service</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('deleteServiceModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;">Are you sure you want to delete <strong id="deleteServiceName"></strong>? This cannot be undone.</p>
    <p class="text-muted" style="font-size:13px;">Services with active appointments cannot be deleted — deactivate them instead.</p>
    <form method="POST" action="<?= url('secretary/services.php') ?>" id="deleteServiceForm">
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
(function () {
  // Add service validation
  var form = document.getElementById('addServiceForm');
  var required = [
    ['[name="service_name"]', 'the service name'],
    ['[name="category"]', 'a category'],
    ['[name="fee"]', 'a fee'],
  ];
  clearFieldErrorOnInput(form, required.map(function (f) { return f[0]; }));
  form.addEventListener('submit', function (e) {
    var firstInvalid = validateRequiredFields(form, required);
    if (firstInvalid) {
      e.preventDefault();
      firstInvalid.focus();
    }
  });

  // Inline edit handling
  document.querySelectorAll('.js-edit-service').forEach(function (btn) {
    btn.addEventListener('click', function () {
      // Close all other edit rows
      document.querySelectorAll('.service-edit-row').forEach(function (row) {
        row.style.display = 'none';
      });
      // Open this one
      document.getElementById('edit-' + this.dataset.id).style.display = 'table-row';
    });
  });

  document.querySelectorAll('.js-cancel-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('edit-' + this.dataset.id).style.display = 'none';
    });
  });

  document.querySelectorAll('.js-delete-service').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('deleteServiceId').value = this.dataset.id;
      document.getElementById('deleteServiceName').textContent = this.dataset.name;
      document.getElementById('deleteServiceModal').showModal();
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
