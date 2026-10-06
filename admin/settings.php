<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$adminId = currentUser()['user_id'];

// Strict allowlist — only these keys may ever be written to the settings table.
// Adding a key to the form without adding it here has no effect.
const SETTINGS_ALLOWED_KEYS = [
    'parish_name', 'parish_address', 'office_hours',
    'contact_number', 'contact_email', 'mass_schedule',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $stmt = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
    );
    foreach (SETTINGS_ALLOWED_KEYS as $key) {
        if (!array_key_exists($key, $_POST)) continue;
        $stmt->execute([$key, trim($_POST[$key])]);
    }
    logActivity($adminId, 'Updated system settings', 'Settings');
    flash('success', 'Settings updated.');
    redirect(url('admin/settings.php'));
}

$rows = db()->query('SELECT * FROM settings')->fetchAll();
$map = [];
foreach ($rows as $r) { $map[$r['setting_key']] = $r['setting_value']; }

$active = 'settings';
$pageTitle = 'System Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Parish Information</h3></div>
  <form method="POST" action="<?= url('admin/settings.php') ?>">
    <?= csrfField() ?>
    <div class="form-group"><label>Parish Name</label><input type="text" name="parish_name" value="<?= e($map['parish_name'] ?? '') ?>"></div>
    <div class="form-group"><label>Parish Address</label><input type="text" name="parish_address" value="<?= e($map['parish_address'] ?? '') ?>"></div>
    <div class="form-group"><label>Office Hours</label><input type="text" name="office_hours" value="<?= e($map['office_hours'] ?? '') ?>"></div>
    <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" value="<?= e($map['contact_number'] ?? '') ?>"></div>
    <div class="form-group"><label>Contact Email</label><input type="text" name="contact_email" value="<?= e($map['contact_email'] ?? '') ?>"></div>
    <div class="form-group"><label>Mass Schedule</label><textarea name="mass_schedule" rows="2"><?= e($map['mass_schedule'] ?? '') ?></textarea></div>
    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>



<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
