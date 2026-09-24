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
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $stmt = db()->prepare(
                "INSERT INTO users (role_id, firstname, lastname, email, password, phone, status) VALUES (?, ?, ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([$_POST['role_id'], $_POST['firstname'], $_POST['lastname'], $_POST['email'], $hash, $_POST['phone'] ?: null]);
            $newId = db()->lastInsertId();
            if ((int)$_POST['role_id'] === 1) {
                db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)')->execute([$newId]);
            }
            logActivity($adminId, "Created staff account: {$_POST['firstname']} {$_POST['lastname']}", 'User Management');
            flash('success', 'User created successfully.');
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
    }
    redirect(url('admin/users.php'));
}

// This page manages STAFF accounts only (Admin/Secretary/Cashier/etc.) —
// ordinary Parishioner accounts have their own dedicated management page
// (secretary/parishioners.php) and shouldn't be mixed into staff tooling.
$roles = db()->query('SELECT * FROM roles')->fetchAll();
$staffRoles = array_values(array_filter($roles, fn($r) => $r['role_name'] !== 'Parishioner'));

$totalStaff = (int) db()->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name != 'Parishioner'"
)->fetchColumn();
$pagination = paginate($totalStaff, 10);
$stmt = db()->prepare(
    "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id
     WHERE r.role_name != 'Parishioner'
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
            <td>
              <form method="POST" action="<?= url('admin/users.php') ?>" onsubmit="return confirm('Delete this user permanently?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
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
    <form method="POST" action="<?= url('admin/users.php') ?>" onsubmit="return confirm('Create this staff account?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div class="form-group"><label>First Name</label><input type="text" name="firstname" required></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="lastname" required></div>
      </div>
      <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
      <div class="form-group"><label>Phone (optional)</label><input type="tel" name="phone"></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="8"></div>
      <div class="form-group">
        <label>Role</label>
        <select name="role_id" required>
          <?php foreach ($staffRoles as $r): ?><option value="<?= $r['role_id'] ?>"><?= e(roleLabel($r['role_name'])) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create Staff Account</button>
    </form>
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
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
