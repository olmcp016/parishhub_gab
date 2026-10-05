<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer');

$userId = (int) currentUser()['user_id'];
$redirectUrl = url('treasurer/settings.php');

function treasurerPhotoPath(?string $photo): ?string
{
    if (!$photo) return null;
    $root = realpath(__DIR__ . '/../public/uploads');
    $path = realpath(__DIR__ . '/../' . ltrim($photo, '/'));
    return ($root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) ? $path : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'remove_photo') {
        $stmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $old = $stmt->fetchColumn();
        db()->prepare('UPDATE users SET profile_photo = NULL WHERE user_id = ?')->execute([$userId]);
        if (($path = treasurerPhotoPath($old)) !== null) @unlink($path);
        flash('success', 'Profile photo removed.');
        redirect($redirectUrl);
    }
    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        $lastname = count($parts) > 1 ? array_pop($parts) : '';
        $firstname = trim(implode(' ', $parts));
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        if ($firstname === '' || $lastname === '') { flash('error', 'Full name is required.'); redirect($redirectUrl); }
        if ($email === '') { flash('error', 'Email address is required.'); redirect($redirectUrl); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a valid email address.'); redirect($redirectUrl); }
        if ($phone !== null && !preg_match('/^09\d{9}$/', $phone)) { flash('error', 'Enter a valid 11-digit Philippine mobile number starting with 09.'); redirect($redirectUrl); }
        $stmt = db()->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetchColumn()) { flash('error', 'This email address is already being used.'); redirect($redirectUrl); }
        $stmt = db()->prepare('SELECT profile_photo FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $old = $stmt->fetchColumn();
        $newPhoto = $old;
        $uploaded = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $file = $_FILES['profile_photo'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
            if ($finfo) finfo_close($finfo);
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) { flash('error', 'Please upload a valid JPG, PNG, or WEBP image up to 2 MB.'); redirect($redirectUrl); }
            $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
            $destination = __DIR__ . '/../public/uploads/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) { flash('error', 'The profile photo could not be saved. Please try again.'); redirect($redirectUrl); }
            $newPhoto = 'public/uploads/' . $filename;
            $uploaded = $destination;
        }
        try { db()->prepare('UPDATE users SET firstname = ?, lastname = ?, email = ?, phone = ?, profile_photo = ? WHERE user_id = ?')->execute([$firstname, $lastname, $email, $phone, $newPhoto, $userId]); }
        catch (Throwable $e) { if ($uploaded && is_file($uploaded)) @unlink($uploaded); error_log($e->getMessage()); flash('error', 'Profile changes could not be saved. Please try again.'); redirect($redirectUrl); }
        if ($uploaded && ($path = treasurerPhotoPath($old)) !== null) @unlink($path);
        $_SESSION['user']['firstname'] = $firstname; $_SESSION['user']['lastname'] = $lastname; $_SESSION['user']['email'] = $email;
        flash('success', 'Profile updated successfully.');
        redirect($redirectUrl);
    }
    if ($action === 'password') {
        $stmt = db()->prepare('SELECT password FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        if (!password_verify($_POST['current_password'] ?? '', (string) $stmt->fetchColumn())) { flash('error', 'Current password is incorrect.'); redirect($redirectUrl); }
        if (strlen($_POST['new_password'] ?? '') < 8) { flash('error', 'New password must be at least 8 characters.'); redirect($redirectUrl); }
        if (($_POST['new_password'] ?? '') !== ($_POST['confirm_password'] ?? '')) { flash('error', 'New passwords do not match.'); redirect($redirectUrl); }
        db()->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([password_hash($_POST['new_password'], PASSWORD_BCRYPT), $userId]);
        flash('success', 'Password changed successfully.');
        redirect($redirectUrl);
    }
}

$stmt = db()->prepare('SELECT firstname, lastname, email, phone, profile_photo FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$profile = $stmt->fetch();
$initials = mb_strtoupper(mb_substr($profile['firstname'] ?? '', 0, 1) . mb_substr($profile['lastname'] ?? '', 0, 1));
$photoUrl = treasurerPhotoPath($profile['profile_photo'] ?? null) ? url(ltrim($profile['profile_photo'], '/')) : null;
$active = 'settings';
$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>
<div class="card">
  <div class="card-header"><h3>Account Settings</h3></div>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 32px;">
    
    <div>
      <h4 style="margin-top: 0; margin-bottom: 20px; color: var(--text-muted, #555);">Profile Information</h4>
      <form method="POST" enctype="multipart/form-data" id="cashierProfileForm">
        <?= csrfField() ?><input type="hidden" name="action" value="profile">
        <div class="profile-photo-editor"><?php if ($photoUrl): ?><img class="profile-photo-preview" src="<?= e($photoUrl) ?>" alt="Profile photo"><?php else: ?><div class="profile-photo-preview profile-initials-avatar"><?= e($initials ?: 'C') ?></div><?php endif; ?><div><label for="cashierProfilePhoto">Profile Photo</label><input type="file" name="profile_photo" id="cashierProfilePhoto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><p class="helper-text">JPG, PNG, or WEBP up to 2 MB.</p><?php if ($photoUrl): ?><button type="button" class="btn btn-outline btn-sm" id="removeCashierPhoto">Remove Photo</button><?php endif; ?></div></div>
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required value="<?= e(trim(($profile['firstname'] ?? '') . ' ' . ($profile['lastname'] ?? ''))) ?>"></div>
        <div class="form-group"><label>Email Address</label><input type="email" name="email" required value="<?= e($profile['email'] ?? '') ?>"></div>
        <div class="form-group"><label>Contact Number</label><input type="tel" name="phone" inputmode="numeric" value="<?= e($profile['phone'] ?? '') ?>"></div>
        <button class="btn btn-primary" type="submit">Save Profile Changes</button>
      </form>
    </div>

    <div>
      <h4 style="margin-top: 0; margin-bottom: 20px; color: var(--text-muted, #555);">Change Password</h4>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="password">
        <div class="form-group"><label>Current Password *</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="form-group"><label>New Password *</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
        <div class="form-group"><label>Confirm New Password *</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit">Update Password</button>
      </form>
    </div>

  </div>
</div>
<dialog class="modal" id="removeCashierPhotoModal"><div class="modal-head"><h3>Remove Profile Photo?</h3><button type="button" class="modal-close" id="closeCashierPhoto">✕</button></div><div class="modal-body"><p>Your current profile photo will be removed. Your initials will be shown instead.</p><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="remove_photo"><div class="flex gap-3" style="justify-content:flex-end;"><button type="button" class="btn btn-outline" id="cancelCashierPhoto">Cancel</button><button class="btn btn-danger" type="submit">Remove Photo</button></div></form></div></dialog>
<script>(function(){var f=document.getElementById('cashierProfilePhoto'),p=document.querySelector('.profile-photo-preview');if(f)f.addEventListener('change',function(){var x=f.files&&f.files[0];if(!x)return;if(!/^image\/(jpeg|png|webp)$/.test(x.type)||x.size>2097152){f.value='';return;}var r=new FileReader();r.onload=function(){if(p.tagName.toLowerCase()==='img')p.src=r.result;else{var i=document.createElement('img');i.className=p.className;i.alt='Profile photo preview';i.src=r.result;p.replaceWith(i);p=i;}};r.readAsDataURL(x);});var m=document.getElementById('removeCashierPhotoModal'),b=document.getElementById('removeCashierPhoto');if(b)b.onclick=function(){m.showModal();};document.getElementById('closeCashierPhoto').onclick=function(){m.close();};document.getElementById('cancelCashierPhoto').onclick=function(){m.close();};})();</script>
<?php include __DIR__ . '/../includes/dash-end.php'; include __DIR__ . '/../includes/footer.php'; ?>
