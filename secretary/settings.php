<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $enabled = !empty($_POST['donation_enabled']) ? '1' : '0';
    db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('donation_enabled', ?)
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
    )->execute([$enabled]);
    logActivity($userId, 'Updated donation feature setting (' . ($enabled === '1' ? 'enabled' : 'disabled') . ')', 'Settings');
    flash('success', 'Settings updated.');
    redirect(url('secretary/settings.php'));
}

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

$active = 'settings';
$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 640px;">
  <div class="card-header"><h3>Donation Feature</h3></div>
  <p class="helper-text" style="margin-top:-6px; margin-bottom:16px;">Control whether parishioners can see and use the "Donate Now" button on their dashboard.</p>
  <form method="POST" action="<?= url('secretary/settings.php') ?>">
    <?= csrfField() ?>
    <div class="form-group">
      <label class="radio-option" style="text-transform:none; font-weight:500; font-size:14px;">
        <input type="checkbox" name="donation_enabled" value="1" <?= $donationEnabled ? 'checked' : '' ?> style="width:auto;">
        Enable the Donate button for parishioners
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
