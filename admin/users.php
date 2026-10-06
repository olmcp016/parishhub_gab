<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$adminId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $tempPassword = bin2hex(random_bytes(5));
            $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
            
            $fname = ucwords(strtolower(trim($_POST['firstname'])));
            $lname = ucwords(strtolower(trim($_POST['lastname'])));
            
            $stmt = db()->prepare(
                "INSERT INTO users (role_id, firstname, lastname, email, password, phone, status, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 'active', 1)"
            );
            $stmt->execute([$_POST['role_id'], $fname, $lname, trim($_POST['email']), $hash, trim($_POST['phone']) ?: null]);
            $newId = db()->lastInsertId();
            if ((int)$_POST['role_id'] === 1) {
                db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)')->execute([$newId]);
            }
            logActivity($adminId, "Created staff account: {$fname} {$lname}", 'User Management');
            
            $_SESSION['temp_password_info'] = [
                'name' => trim($fname . ' ' . $lname),
                'email' => trim($_POST['email']),
                'password' => $tempPassword
            ];
            flash('success', 'Staff account created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Failed to create user (email may already exist).');
        }
    } elseif ($action === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$_POST['status'], $_POST['user_id']]);
        logActivity($adminId, "Updated status of user #{$_POST['user_id']} to {$_POST['status']}", 'User Management');
        flash('success', 'User status updated.');
    } elseif ($action === 'role') {
        // Look up names before applying the change so the log line is
        // meaningful on its own ("who" and "to what"), not just raw ids.
        $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
        $stmt->execute([$_POST['user_id']]);
        $targetUser = $stmt->fetch();
        $stmt = db()->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $stmt->execute([$_POST['role_id']]);
        $newRoleName = $stmt->fetchColumn();

        db()->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')->execute([$_POST['role_id'], $_POST['user_id']]);

        $targetLabel = $targetUser ? ($targetUser['firstname'] . ' ' . $targetUser['lastname']) : ('#' . $_POST['user_id']);
        logActivity($adminId, "Changed role of $targetLabel to " . roleLabel($newRoleName ?: ''), 'User Management');
        flash('success', 'User role updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$_POST['user_id']]);
        logActivity($adminId, "Deleted user #{$_POST['user_id']}", 'User Management');
        flash('success', 'User deleted.');
    } elseif ($action === 'reset') {
        // Generate a strong 10-char temporary password: uppercase, lowercase, digits
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 10; $i++) {
            $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

        $targetId = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT firstname, lastname, email FROM users WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            flash('error', 'User not found.');
            redirect(url('admin/users.php'));
        }

        db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ?')
            ->execute([$hash, $targetId]);
        logActivity($adminId, "Reset password for {$targetUser['firstname']} {$targetUser['lastname']} (#{$targetId})", 'User Management');

        $_SESSION['temp_password_info'] = [
            'context'  => 'reset',
            'name'     => trim($targetUser['firstname'] . ' ' . $targetUser['lastname']),
            'email'    => $targetUser['email'],
            'password' => $tempPassword,
        ];
        flash('success', 'Password reset. Hand the temporary password to the staff member securely.');
    }
    redirect(url('admin/users.php'));
}

// This page manages STAFF accounts only (Admin/Secretary/Cashier/etc.) —
// ordinary Parishioner accounts have their own dedicated management page
// (secretary/parishioners.php), and Priests have their own page (admin/priests.php).
$roles = db()->query('SELECT * FROM roles')->fetchAll();
$staffRoles = array_values(array_filter($roles, fn($r) => !in_array($r['role_name'], ['Parishioner', 'Priest'])));

$totalStaff = (int) db()->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name NOT IN ('Parishioner', 'Priest')"
)->fetchColumn();
$pagination = paginate($totalStaff, 10);
$stmt = db()->prepare(
    "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id
     WHERE r.role_name NOT IN ('Parishioner', 'Priest')
     ORDER BY u.created_at DESC LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $pagination['limit'], PDO::PARAM_INT);
$stmt->bindValue(2, $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$active = 'users';
$pageTitle = 'Users & Roles';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>All Staff</h3>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('addStaffModal').showModal()">+ Add Staff</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['firstname']) ?> <?= e($u['lastname']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td>
              <form method="POST" action="<?= url('admin/users.php') ?>" class="role-change-form" style="display:inline-flex; gap:6px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <select name="role_id" class="role-select" data-user-name="<?= e($u['firstname'] . ' ' . $u['lastname']) ?>" onchange="confirmRoleChange(this)">
                  <?php foreach ($staffRoles as $r): ?>
                    <option value="<?= $r['role_id'] ?>" <?= $r['role_id']==$u['role_id']?'selected':'' ?>><?= e(roleLabel($r['role_name'])) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <form method="POST" action="<?= url('admin/users.php') ?>" style="display:inline-flex; gap:6px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <select name="status" onchange="this.form.submit()">
                  <option value="active" <?= $u['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $u['status']==='inactive'?'selected':'' ?>>Inactive</option>
                  <option value="suspended" <?= $u['status']==='suspended'?'selected':'' ?>>Suspended</option>
                </select>
              </form>
            </td>
            <td style="display:flex;gap:6px;flex-wrap:wrap;">
              <button type="button" class="btn btn-outline btn-sm js-reset-pw"
                data-id="<?= $u['user_id'] ?>"
                data-name="<?= e($u['firstname'] . ' ' . $u['lastname']) ?>">Reset Password</button>
              <form method="POST" action="<?= url('admin/users.php') ?>" class="fp-reset-form" style="display:none;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
              </form>
              <button type="button" class="btn btn-danger btn-sm js-delete-user" data-id="<?= $u['user_id'] ?>" data-name="<?= e($u['firstname'] . ' ' . $u['lastname']) ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="text-muted">No staff accounts yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= renderPagination($pagination, url('admin/users.php')) ?>
</div>

<!-- ===================== Add Staff Modal ===================== -->
<dialog class="modal" id="addStaffModal">
  <div class="modal-head">
    <h3>Add Staff Account</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('addStaffModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('admin/users.php') ?>" id="createStaffForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div class="form-group"><label>First Name</label><input type="text" name="firstname" required style="text-transform: capitalize;"></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="lastname" required style="text-transform: capitalize;"></div>
      </div>
      <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
      <div class="form-group"><label>Phone (optional)</label><input type="tel" name="phone" pattern="^09\d{9}$" maxlength="11" placeholder="09xxxxxxxxx"></div>
      <div class="form-group">
        <label>Role</label>
        <select name="role_id" required>
          <option value="" disabled selected>Select Role</option>
          <?php foreach ($staffRoles as $r): ?><option value="<?= $r['role_id'] ?>"><?= e(roleLabel($r['role_name'])) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="btn btn-primary btn-block" onclick="document.getElementById('createStaffConfirmModal').showModal()">Create Staff Account</button>
    </form>
  </div>
</dialog>

<!-- Create Staff Confirm Modal -->
<dialog id="createStaffConfirmModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Create Account?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to create this staff account?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('createStaffConfirmModal').close()">Cancel</button>
    <button type="button" class="btn btn-success" onclick="document.getElementById('createStaffConfirmModal').close(); document.getElementById('createStaffForm').submit();">Yes, Create</button>
  </div>
</dialog>

<!-- Delete-confirm modal -->
<dialog id="deleteUserModal" style="max-width:420px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Delete User?</h3>
  <p id="deleteUserMsg" style="color:var(--text-muted,#555);"></p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" id="deleteUserCancel">Cancel</button>
    <form method="POST" action="<?= url('admin/users.php') ?>" id="deleteUserForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="user_id" id="deleteUserId">
      <button type="submit" class="btn btn-danger">Yes, Delete</button>
    </form>
  </div>
</dialog>

<!-- Reset Password Confirm Modal -->
<dialog id="resetPwModal" style="max-width:440px;padding:24px;border-radius:8px;border:none;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
  <h3 style="margin-top:0;">Reset Password?</h3>
  <p id="resetPwMsg" style="color:var(--text-muted,#555);margin-bottom:8px;"></p>
  <p style="font-size:13px;color:var(--text-muted,#777);margin-top:0;">A strong temporary password will be generated. The staff member <strong>must change it</strong> on their next login.</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px;">
    <button type="button" class="btn btn-outline" id="resetPwCancel">Cancel</button>
    <button type="button" class="btn btn-primary" id="resetPwConfirm">Yes, Reset Password</button>
  </div>
</dialog>

<!-- ===================== Role Change Confirmation ===================== -->
<dialog class="modal" id="roleConfirmModal">
  <div class="modal-head">
    <h3>Confirm Role Change</h3>
    <button type="button" class="modal-close" onclick="cancelRoleChange()">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;" id="roleConfirmMessage"></p>
    <div class="flex gap-3" style="justify-content:flex-end;">
      <button type="button" class="btn btn-outline" onclick="cancelRoleChange()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyRoleChange()">Confirm</button>
    </div>
  </div>
</dialog>

<script>
var pendingRoleSelect = null;

function confirmRoleChange(selectEl) {
  pendingRoleSelect = selectEl;
  var userName = selectEl.dataset.userName;
  var roleName = selectEl.options[selectEl.selectedIndex].text;
  document.getElementById('roleConfirmMessage').textContent =
    'Are you sure you want to assign ' + userName + ' as ' + roleName + '?';
  document.getElementById('roleConfirmModal').showModal();
}

function cancelRoleChange() {
  if (pendingRoleSelect) {
    pendingRoleSelect.value = pendingRoleSelect.dataset.originalValue;
    pendingRoleSelect = null;
  }
  document.getElementById('roleConfirmModal').close();
}

function applyRoleChange() {
  if (pendingRoleSelect) {
    pendingRoleSelect.form.submit();
  }
  document.getElementById('roleConfirmModal').close();
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.role-select').forEach(function (sel) {
    sel.dataset.originalValue = sel.value;
  });

  // Reset-password modal
  var resetPwModal   = document.getElementById('resetPwModal');
  var pendingResetForm = null;
  document.querySelectorAll('.js-reset-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      pendingResetForm = this.nextElementSibling; // the hidden <form> right after the button
      document.getElementById('resetPwMsg').textContent =
        'Reset the password for ' + this.dataset.name + '?';
      resetPwModal.showModal();
    });
  });
  document.getElementById('resetPwCancel').addEventListener('click', function () {
    pendingResetForm = null;
    resetPwModal.close();
  });
  document.getElementById('resetPwConfirm').addEventListener('click', function () {
    resetPwModal.close();
    if (pendingResetForm) { pendingResetForm.submit(); }
  });
  resetPwModal.addEventListener('click', function (e) {
    if (e.target === resetPwModal) { pendingResetForm = null; resetPwModal.close(); }
  });

  var deleteModal = document.getElementById('deleteUserModal');
  document.querySelectorAll('.js-delete-user').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('deleteUserId').value = this.dataset.id;
      document.getElementById('deleteUserMsg').textContent = 'Permanently delete "' + this.dataset.name + '"? This cannot be undone.';
      deleteModal.showModal();
    });
  });
  if (document.getElementById('deleteUserCancel')) {
    document.getElementById('deleteUserCancel').addEventListener('click', function () { deleteModal.close(); });
  }
});
</script>

<?php if (isset($_SESSION['temp_password_info'])):
    $tp = $_SESSION['temp_password_info'];
    unset($_SESSION['temp_password_info']);
    $isReset = ($tp['context'] ?? '') === 'reset';
?>
<dialog class="modal" id="tempPasswordModal" style="max-width:450px; text-align:center;">
  <div class="modal-body" style="padding: 30px 20px;">
    <div style="background:var(--bg-success); color:var(--success); width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
      <?php if ($isReset): ?>
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
      <?php else: ?>
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
      <?php endif; ?>
    </div>
    <h3 style="margin-top:0;"><?= $isReset ? 'Password Reset' : 'Staff Account Created!' ?></h3>
    <p style="color:var(--text-muted); margin-bottom:20px;">
      <?php if ($isReset): ?>
        Temporary password for <strong><?= e($tp['name']) ?></strong> is shown below. Hand it over securely — they must change it on their next login.
      <?php else: ?>
        Portal login for <strong><?= e($tp['name']) ?></strong> has been generated. Please provide them with the following temporary password.
      <?php endif; ?>
    </p>
    <div style="background:var(--bg-secondary); padding:15px; border-radius:8px; border:1px dashed #ccc; margin-bottom:20px;">
      <div style="font-size:24px; font-weight:bold; letter-spacing:2px; font-family:monospace; color:var(--text-primary);" id="tempPwdText"><?= e($tp['password']) ?></div>
    </div>
    <button type="button" class="btn btn-primary btn-block" id="copyTempPwdBtn" style="margin-bottom:10px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
      Copy Password
    </button>
    <button type="button" class="btn btn-outline btn-block" onclick="document.getElementById('tempPasswordModal').close()">I've copied it</button>
  </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('tempPasswordModal');
    if (modal) {
        modal.showModal();
        document.getElementById('copyTempPwdBtn').addEventListener('click', function() {
            var pwd = document.getElementById('tempPwdText').textContent;
            navigator.clipboard.writeText(pwd).then(function() {
                var btn = document.getElementById('copyTempPwdBtn');
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><polyline points="20 6 9 17 4 12"></polyline></svg> Copied!';
                btn.classList.replace('btn-primary', 'btn-success');
                setTimeout(() => {
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg> Copy Password';
                    btn.classList.replace('btn-success', 'btn-primary');
                }, 2000);
            });
        });
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
