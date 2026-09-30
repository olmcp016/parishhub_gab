<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'profile';
    if ($action === 'remove_photo') {
        $stmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $oldPhoto = $stmt->fetchColumn();
        db()->prepare('UPDATE users SET profile_photo = NULL WHERE user_id = ?')->execute([$userId]);
        if ($oldPhoto) {
            $oldPath = __DIR__ . '/../' . ltrim($oldPhoto, '/');
            if (is_file($oldPath) && str_starts_with(realpath($oldPath) ?: '', realpath(__DIR__ . '/../public/uploads') . DIRECTORY_SEPARATOR)) @unlink($oldPath);
        }
        flash('success', 'Profile photo removed.');
        redirect(url('secretary/settings.php'));
    }

    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nameParts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $lastname = count($nameParts) > 1 ? array_pop($nameParts) : '';
        $firstname = trim(implode(' ', $nameParts));
        $phone = trim($_POST['phone'] ?? '') ?: null;
        if ($firstname === '' || $lastname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid full name and email address.');
            redirect(url('secretary/settings.php'));
        }
        $emailCheck = db()->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ?');
        $emailCheck->execute([$email, $userId]);
        if ($emailCheck->fetchColumn()) {
            flash('error', 'That email address is already in use.');
            redirect(url('secretary/settings.php'));
        }

        $stmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $oldPhoto = $stmt->fetchColumn();
        $newPhoto = $oldPhoto;
        $uploadedPath = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $file = $_FILES['profile_photo'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                if ($finfo) finfo_close($finfo);
                flash('error', 'Please upload a valid JPG, PNG, or WEBP image up to 2 MB.');
                redirect(url('secretary/settings.php'));
            }
            finfo_close($finfo);
            $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
            $uploadDir = __DIR__ . '/../public/uploads/';
            $destination = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                flash('error', 'The profile photo could not be saved. Please try again.');
                redirect(url('secretary/settings.php'));
            }
            $newPhoto = 'public/uploads/' . $filename;
            $uploadedPath = $destination;
        }
        try {
            db()->prepare('UPDATE users SET firstname = ?, lastname = ?, email = ?, phone = ?, profile_photo = ? WHERE user_id = ?')
                ->execute([$firstname, $lastname, $email, $phone, $newPhoto, $userId]);
        } catch (Throwable $e) {
            if ($uploadedPath && is_file($uploadedPath)) @unlink($uploadedPath);
            error_log($e->getMessage());
            flash('error', 'Profile changes could not be saved. Please try again.');
            redirect(url('secretary/settings.php'));
        }
        if ($uploadedPath && $oldPhoto) {
            $oldPath = __DIR__ . '/../' . ltrim($oldPhoto, '/');
            if (is_file($oldPath) && str_starts_with(realpath($oldPath) ?: '', realpath(__DIR__ . '/../public/uploads') . DIRECTORY_SEPARATOR)) @unlink($oldPath);
        }
        $_SESSION['user']['firstname'] = $firstname;
        $_SESSION['user']['lastname'] = $lastname;
        $_SESSION['user']['email'] = $email;
        flash('success', 'Profile updated successfully.');
        redirect(url('secretary/settings.php'));
    }

    $enabled = !empty($_POST['donation_enabled']) ? '1' : '0';
    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('donation_enabled', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")->execute([$enabled]);
    logActivity($userId, 'Updated donation feature setting (' . ($enabled === '1' ? 'enabled' : 'disabled') . ')', 'Settings');
    flash('success', 'Settings updated.');
    redirect(url('secretary/settings.php'));
}

$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
$stmt = db()->prepare('SELECT firstname, lastname, email, phone, profile_photo FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$profile = $stmt->fetch();
$initials = mb_strtoupper(mb_substr($profile['firstname'] ?? '', 0, 1) . mb_substr($profile['lastname'] ?? '', 0, 1));
$photoUrl = null;
if (!empty($profile['profile_photo'])) {
    $photoPath = __DIR__ . '/../' . ltrim($profile['profile_photo'], '/');
    if (is_file($photoPath)) $photoUrl = url(ltrim($profile['profile_photo'], '/'));
}

$active = 'settings';
$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 640px;">
  <div class="card-header"><h3>Profile &amp; Account Settings</h3></div>
  <form method="POST" action="<?= url('secretary/settings.php') ?>" enctype="multipart/form-data" id="secretaryProfileForm">
    <?= csrfField() ?><input type="hidden" name="action" value="profile">
    <div class="profile-photo-editor">
      <?php if ($photoUrl): ?><img class="profile-photo-preview" src="<?= e($photoUrl) ?>" alt="Profile photo">
      <?php else: ?><div class="profile-photo-preview profile-initials-avatar"><?= e($initials ?: 'S') ?></div><?php endif; ?>
      <div><label for="profilePhoto">Profile Photo</label><input type="file" name="profile_photo" id="profilePhoto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><p class="helper-text">JPG, PNG, or WEBP up to 2 MB.</p><?php if ($photoUrl): ?><button type="button" class="btn btn-outline btn-sm" id="removeProfilePhoto">Remove Photo</button><?php endif; ?></div>
    </div>
    <div class="form-group"><label>Full Name</label><input type="text" name="full_name" value="<?= e(trim(($profile['firstname'] ?? '') . ' ' . ($profile['lastname'] ?? ''))) ?>" required></div>
    <div class="form-group"><label>Email Address</label><input type="email" name="email" value="<?= e($profile['email'] ?? '') ?>" required></div>
    <div class="form-group"><label>Contact Number</label><input type="tel" name="phone" value="<?= e($profile['phone'] ?? '') ?>"></div>
    <button type="submit" class="btn btn-primary">Save Profile Changes</button>
  </form>
</div>

<dialog class="modal" id="removeProfileModal" aria-labelledby="removeProfileTitle"><div class="modal-head"><h3 id="removeProfileTitle">Remove Profile Photo?</h3><button type="button" class="modal-close" id="closeRemoveProfile">✕</button></div><div class="modal-body"><p>Your current profile photo will be removed. Your initials will be shown instead.</p><form method="POST" action="<?= url('secretary/settings.php') ?>"><?= csrfField() ?><input type="hidden" name="action" value="remove_photo"><div class="flex gap-3" style="justify-content:flex-end;"><button type="button" class="btn btn-outline" id="cancelRemoveProfile">Cancel</button><button type="submit" class="btn btn-danger">Remove Photo</button></div></form></div></dialog>

<script>
(function () {
  var file = document.getElementById('profilePhoto');
  var preview = document.querySelector('.profile-photo-preview');
  if (file) file.addEventListener('change', function () {
    var selected = file.files && file.files[0];
    if (!selected) return;
    if (!/^image\/(jpeg|png|webp)$/.test(selected.type) || selected.size > 2 * 1024 * 1024) { file.value = ''; return; }
    var reader = new FileReader();
    reader.onload = function () {
      if (preview.tagName.toLowerCase() === 'img') preview.src = reader.result;
      else { var image = document.createElement('img'); image.className = preview.className; image.alt = 'Profile photo preview'; image.src = reader.result; preview.replaceWith(image); preview = image; }
    };
    reader.readAsDataURL(selected);
  });
  var modal = document.getElementById('removeProfileModal');
  var remove = document.getElementById('removeProfilePhoto');
  if (remove) remove.addEventListener('click', function () { modal.showModal(); });
  document.getElementById('closeRemoveProfile').addEventListener('click', function () { modal.close(); });
  document.getElementById('cancelRemoveProfile').addEventListener('click', function () { modal.close(); });
})();
</script>

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
