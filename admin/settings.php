<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$adminId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($_POST as $key => $value) {
        if ($key === 'csrf_token') continue;
        $stmt = db()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
        );
        $stmt->execute([$key, $value]);
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
