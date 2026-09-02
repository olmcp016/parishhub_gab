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
            logActivity($adminId, "Created user account: {$_POST['firstname']} {$_POST['lastname']}", 'User Management');
            flash('success', 'User created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Failed to create user (email may already exist).');
        }
    } elseif ($action === 'status') {
        db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$_POST['status'], $_POST['user_id']]);
        logActivity($adminId, "Updated status of user #{$_POST['user_id']} to {$_POST['status']}", 'User Management');
        flash('success', 'User status updated.');
    } elseif ($action === 'role') {
        db()->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')->execute([$_POST['role_id'], $_POST['user_id']]);
        logActivity($adminId, "Changed role of user #{$_POST['user_id']}", 'User Management');
        flash('success', 'User role updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$_POST['user_id']]);
        logActivity($adminId, "Deleted user #{$_POST['user_id']}", 'User Management');
        flash('success', 'User deleted.');
    }
    redirect(url('admin/users.php'));
}

$users = db()->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id ORDER BY u.created_at DESC")->fetchAll();
$roles = db()->query('SELECT * FROM roles')->fetchAll();

$active = 'users';
$pageTitle = 'Users & Roles';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Create New Staff Account</h3></div>
  <form method="POST" action="<?= url('admin/users.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-group"><label>First Name</label><input type="text" name="firstname" required></div>
    <div class="form-group"><label>Last Name</label><input type="text" name="lastname" required></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="8"></div>
    <div class="form-group">
      <label>Role</label>
      <select name="role_id" required>
        <?php foreach ($roles as $r): ?><option value="<?= $r['role_id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Phone</label><input type="tel" name="phone"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Create</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Users</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['firstname']) ?> <?= e($u['lastname']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td>
              <form method="POST" action="<?= url('admin/users.php') ?>" style="display:inline-flex; gap:6px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <select name="role_id" onchange="this.form.submit()">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['role_id'] ?>" <?= $r['role_id']==$u['role_id']?'selected':'' ?>><?= e($r['role_name']) ?></option>
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
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
