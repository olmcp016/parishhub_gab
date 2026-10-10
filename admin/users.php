<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$adminId = (int) currentUser()['user_id'];

/**
 * True when removing this user's active Admin status (suspending, demoting or
 * deleting) would leave the system with no active Admin (audit M-08).
 */
function adminChangeWouldLeaveNoAdmin(int $userId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ? AND r.role_name = 'Admin' AND u.status = 'active'"
    );
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        return false; // not an active admin, so nothing is removed
    }
    $others = db()->prepare(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'Admin' AND u.status = 'active' AND u.user_id <> ?"
    );
    $others->execute([$userId]);
    return (int) $others->fetchColumn() === 0;
}

/** True when the signed-in admin's password matches. Used before role changes and deletes. */
function adminPasswordMatches(int $adminId, string $password): bool
{
    $row = db()->prepare('SELECT password FROM users WHERE user_id = ?');
    $row->execute([$adminId]);
    $hash = $row->fetchColumn();
    return $hash && $password !== '' && password_verify($password, $hash);
}
$isAjax  = ($_POST['ajax'] ?? '') === '1';

// Helper for priest AJAX error responses
function personnelRespondError(bool $isAjax, string $msg, string $url, array $fields = []): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg, 'errors' => $fields]);
        exit;
    }
    flash('error', $msg);
    redirect($url);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── STAFF ACTIONS ─────────────────────────────────────────────────────────
    if ($action === 'create') {
        // Only staff roles can be created here. Parishioner and Priest accounts
        // have their own flows, which also create the linked rows (M-08).
        $createRole = db()->prepare("SELECT 1 FROM roles WHERE role_id = ? AND role_name NOT IN ('Parishioner', 'Priest')");
        $createRole->execute([(int) ($_POST['role_id'] ?? 0)]);
        if (!$createRole->fetchColumn()) {
            flash('error', 'Please choose a valid staff role.');
            redirect(url('admin/users.php'));
        }
        if (!filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
            redirect(url('admin/users.php'));
        }
        if (trim($_POST['firstname'] ?? '') === '' || trim($_POST['lastname'] ?? '') === '') {
            flash('error', 'Please enter both first and last name.');
            redirect(url('admin/users.php'));
        }
        try {
            $tempPassword = bin2hex(random_bytes(5));
            $hash  = password_hash($tempPassword, PASSWORD_BCRYPT);
            $fname = ucwords(strtolower(trim($_POST['firstname'])));
            $lname = ucwords(strtolower(trim($_POST['lastname'])));
            $stmt  = db()->prepare(
                "INSERT INTO users (role_id, firstname, lastname, email, password, phone, status, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', 1)"
            );
            $stmt->execute([$_POST['role_id'], $fname, $lname, trim($_POST['email']), $hash, trim($_POST['phone']) ?: null]);
            $newId = db()->lastInsertId();
            if ((int) $_POST['role_id'] === 1) {
                db()->prepare('INSERT INTO parishioners (user_id) VALUES (?)')->execute([$newId]);
            }
            logActivity($adminId, "Created staff account: {$fname} {$lname}", 'User Management');
            $_SESSION['temp_password_info'] = [
                'name'     => trim($fname . ' ' . $lname),
                'email'    => trim($_POST['email']),
                'password' => $tempPassword,
            ];
            flash('success', 'Staff account created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Failed to create user (email may already exist).');
        }
        redirect(url('admin/users.php'));

    } elseif ($action === 'status') {
        $targetId  = (int) ($_POST['user_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if (!in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
            flash('error', 'Invalid status.');
            redirect(url('admin/users.php'));
        }
        if ($targetId === $adminId) {
            flash('error', 'You cannot change the status of your own account.');
            redirect(url('admin/users.php'));
        }
        if ($newStatus !== 'active' && adminChangeWouldLeaveNoAdmin($targetId)) {
            flash('error', 'This is the only active Admin. Make another Admin active first.');
            redirect(url('admin/users.php'));
        }
        db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, $targetId]);
        logActivity($adminId, "Updated status of user #{$targetId} to {$newStatus}", 'User Management');
        flash('success', 'User status updated.');
        redirect(url('admin/users.php'));

    } elseif ($action === 'role') {
        $targetId  = (int) ($_POST['user_id'] ?? 0);
        $newRoleId = (int) ($_POST['role_id'] ?? 0);
        if (!adminPasswordMatches($adminId, $_POST['admin_password'] ?? '')) {
            flash('error', 'Incorrect password. Role change cancelled.');
            redirect(url('admin/users.php'));
        }
        if ($targetId === $adminId) {
            flash('error', 'You cannot change your own role.');
            redirect(url('admin/users.php'));
        }
        $stmt = db()->prepare("SELECT 1 FROM roles WHERE role_id = ? AND role_name NOT IN ('Parishioner', 'Priest')");
        $stmt->execute([$newRoleId]);
        if (!$stmt->fetchColumn()) {
            flash('error', 'Please choose a valid staff role.');
            redirect(url('admin/users.php'));
        }
        $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $targetUser = $stmt->fetch();
        $stmt = db()->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $stmt->execute([$newRoleId]);
        $newRoleName = $stmt->fetchColumn();
        if ($newRoleName !== 'Admin' && adminChangeWouldLeaveNoAdmin($targetId)) {
            flash('error', 'This is the only active Admin. Make another Admin active before changing this role.');
            redirect(url('admin/users.php'));
        }
        db()->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')->execute([$newRoleId, $targetId]);
        $targetLabel = $targetUser ? ($targetUser['firstname'] . ' ' . $targetUser['lastname']) : ('#' . $_POST['user_id']);
        logActivity($adminId, "Changed role of $targetLabel to " . roleLabel($newRoleName ?: ''), 'User Management');
        flash('success', 'User role updated.');
        redirect(url('admin/users.php'));

    } elseif ($action === 'delete') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId === $adminId) {
            flash('error', 'You cannot delete your own account.');
            redirect(url('admin/users.php'));
        }
        if (adminChangeWouldLeaveNoAdmin($targetId)) {
            flash('error', 'This is the only active Admin, so it cannot be deleted.');
            redirect(url('admin/users.php'));
        }
        $adminPw   = $_POST['admin_password'] ?? '';
        $row       = db()->prepare('SELECT password FROM users WHERE user_id = ?');
        $row->execute([$adminId]);
        $adminHash = $row->fetchColumn();
        if (!$adminHash || !password_verify($adminPw, $adminHash)) {
            flash('error', 'Incorrect password. Deletion cancelled.');
            redirect(url('admin/users.php'));
        }
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$_POST['user_id']]);
        logActivity($adminId, "Deleted user #{$_POST['user_id']}", 'User Management');
        flash('success', 'User deleted.');
        redirect(url('admin/users.php'));

    } elseif ($action === 'reset') {
        $chars        = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 10; $i++) {
            $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash     = password_hash($tempPassword, PASSWORD_BCRYPT);
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $stmt     = db()->prepare('SELECT firstname, lastname, email FROM users WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            flash('error', 'User not found.');
            redirect(url('admin/users.php'));
        }
        db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ?')->execute([$hash, $targetId]);
        logActivity($adminId, "Reset password for {$targetUser['firstname']} {$targetUser['lastname']} (#{$targetId})", 'User Management');
        $_SESSION['temp_password_info'] = [
            'context'  => 'reset',
            'name'     => trim($targetUser['firstname'] . ' ' . $targetUser['lastname']),
            'email'    => $targetUser['email'],
            'password' => $tempPassword,
        ];
        flash('success', 'Password reset. Hand the temporary password to the staff member securely.');
        redirect(url('admin/users.php'));

    // ── PRIEST ACTIONS ─────────────────────────────────────────────────────────
    } elseif ($action === 'priest_add') {
        $fullName = trim($_POST['full_name'] ?? '');
        $title    = trim($_POST['title'] ?? '');
        $contact  = trim($_POST['contact_number'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $errors   = [];
        if ($fullName === '') $errors['full_name']        = 'Full name is required.';
        if ($title    === '') $errors['title']            = 'Title is required.';
        if (!preg_match('/^09\d{9}$/', $contact)) $errors['contact_number'] = 'Enter a valid 11-digit Philippine mobile number starting with 09.';
        if ($email    === '') $errors['email']            = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if ($errors) personnelRespondError($isAjax, 'Please correct the highlighted fields.', url('admin/users.php') . '?tab=priests', $errors);

        $stmt = db()->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) UNION ALL SELECT 1 FROM priests WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email, $email]);
        if ($stmt->fetchColumn()) personnelRespondError($isAjax, 'This email address is already being used.', url('admin/users.php') . '?tab=priests', ['email' => 'This email address is already being used.']);

        $stmt = db()->prepare("INSERT INTO priests (full_name, title, contact_number, email) VALUES (?, ?, ?, ?)");
        $stmt->execute([$fullName, $title, $contact, $email]);
        $priestId = (int) db()->lastInsertId();
        logActivity($adminId, "Added priest: $title $fullName", 'Priests');

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Priest added successfully.',
                'priest'  => [
                    'priest_id'      => $priestId,
                    'full_name'      => $fullName,
                    'title'          => $title,
                    'contact_number' => $contact,
                    'email'          => $email,
                    'status'         => 'active',
                ],
            ]);
            exit;
        }
        flash('success', 'Priest added successfully.');
        redirect(url('admin/users.php') . '?tab=priests');

    } elseif ($action === 'priest_edit') {
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $status   = $_POST['status'] ?? '';
        $title    = trim($_POST['title'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $contact  = trim($_POST['contact_number'] ?? '');
        if (!in_array($status, ['active', 'on_leave', 'inactive'], true)) {
            flash('error', 'Invalid priest status.');
            redirect(url('admin/users.php') . '?tab=priests');
        }
        db()->prepare('UPDATE priests SET title = ?, full_name = ?, contact_number = ?, status = ? WHERE priest_id = ?')
            ->execute([$title, $fullName, $contact, $status, $priestId]);
        flash('success', 'Priest details updated.');
        redirect(url('admin/users.php') . '?tab=priests');

    } elseif ($action === 'priest_delete') {
        $adminPw   = $_POST['admin_password'] ?? '';
        $row       = db()->prepare('SELECT password FROM users WHERE user_id = ?');
        $row->execute([$adminId]);
        $adminHash = $row->fetchColumn();
        if (!$adminHash || !password_verify($adminPw, $adminHash)) {
            flash('error', 'Incorrect password. Archive cancelled.');
            redirect(url('admin/users.php') . '?tab=priests');
        }
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND status_id NOT IN (3, 6, 7)");
        $stmt->execute([$priestId]);
        if ($stmt->fetchColumn() > 0) {
            flash('error', 'Cannot archive priest: they are assigned to active or upcoming appointments.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT user_id FROM priests WHERE priest_id = ? FOR UPDATE");
                $stmt->execute([$priestId]);
                $uid = $stmt->fetchColumn();
                if ($uid === false) throw new RuntimeException('Priest not found.');
                $pdo->prepare("UPDATE priests SET status = 'inactive' WHERE priest_id = ?")->execute([$priestId]);
                if ($uid) $pdo->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ?")->execute([$uid]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
                flash('error', 'Could not archive the priest. Please try again.');
                redirect(url('admin/users.php') . '?tab=priests');
            }
            logActivity($adminId, "Archived priest #$priestId", 'Priests');
            flash('success', 'Priest archived. Their record and appointment history are preserved. You can restore them by editing their status.');
        }
        redirect(url('admin/users.php') . '?tab=priests');

    } elseif ($action === 'priest_create_login') {
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $email    = trim($_POST['login_email'] ?? '');
        $pdo      = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM priests WHERE priest_id = ? FOR UPDATE');
        $stmt->execute([$priestId]);
        $priest = $stmt->fetch();
        if (!$priest) { $pdo->rollBack(); flash('error', 'Priest not found.'); redirect(url('admin/users.php') . '?tab=priests'); }
        if (!empty($priest['user_id'])) { $pdo->rollBack(); flash('error', 'This priest already has a login.'); redirect(url('admin/users.php') . '?tab=priests'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $pdo->rollBack(); flash('error', 'Please enter a valid email address.'); redirect(url('admin/users.php') . '?tab=priests'); }
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) { $pdo->rollBack(); flash('error', "An account with $email already exists."); redirect(url('admin/users.php') . '?tab=priests'); }

        $priestRoleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Priest'")->fetchColumn();
        $chars        = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 10; $i++) { $tempPassword .= $chars[random_int(0, strlen($chars) - 1)]; }
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (role_id, firstname, lastname, email, password, phone, status, must_change_password) VALUES (?, ?, '', ?, ?, ?, 'active', 1)");
            $stmt->execute([$priestRoleId, trim($priest['title'] . ' ' . $priest['full_name']), $email, $hash, $priest['contact_number']]);
            $newUserId = (int) $pdo->lastInsertId();
            $link = $pdo->prepare('UPDATE priests SET user_id = ?, email = ? WHERE priest_id = ? AND user_id IS NULL');
            $link->execute([$newUserId, $email, $priestId]);
            if ($link->rowCount() !== 1) throw new RuntimeException('Priest login link changed concurrently.');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            flash('error', 'Could not create the login. Please try again.');
            redirect(url('admin/users.php') . '?tab=priests');
        }
        logActivity($adminId, "Created a Priest login for {$priest['title']} {$priest['full_name']} ($email)", 'Priests');
        $_SESSION['temp_password_info'] = [
            'name'     => trim($priest['title'] . ' ' . $priest['full_name']),
            'email'    => $email,
            'password' => $tempPassword,
        ];
        flash('success', 'Login created successfully.');
        redirect(url('admin/users.php') . '?tab=priests');

    } elseif ($action === 'priest_manage_login') {
        $priestId  = (int) ($_POST['priest_id'] ?? 0);
        $operation = $_POST['login_operation'] ?? '';
        if (!in_array($operation, ['activate', 'deactivate', 'reset'], true)) {
            flash('error', 'Invalid portal login action.');
            redirect(url('admin/users.php') . '?tab=priests');
        }
        $stmt = db()->prepare('SELECT p.title, p.full_name, p.user_id, u.email, u.status AS user_status FROM priests p LEFT JOIN users u ON u.user_id = p.user_id WHERE p.priest_id = ?');
        $stmt->execute([$priestId]);
        $account = $stmt->fetch();
        if (!$account || empty($account['user_id'])) {
            flash('error', 'This priest does not have a linked portal account.');
            redirect(url('admin/users.php') . '?tab=priests');
        }
        if ($operation === 'activate' || $operation === 'deactivate') {
            $newStatus = $operation === 'activate' ? 'active' : 'inactive';
            db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, (int) $account['user_id']]);
            logActivity($adminId, ucfirst($operation) . "d Priest portal access for {$account['title']} {$account['full_name']}", 'Priests');
            flash('success', $operation === 'activate' ? 'Priest portal access reactivated.' : 'Priest portal access deactivated.');
        } else {
            $chars        = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            $tempPassword = '';
            for ($i = 0; $i < 10; $i++) { $tempPassword .= $chars[random_int(0, strlen($chars) - 1)]; }
            db()->prepare("UPDATE users SET password = ?, status = 'active', must_change_password = 1 WHERE user_id = ?")
                ->execute([password_hash($tempPassword, PASSWORD_BCRYPT), (int) $account['user_id']]);
            logActivity($adminId, "Reset Priest portal access for {$account['title']} {$account['full_name']}", 'Priests');
            $_SESSION['temp_password_info'] = [
                'name'     => trim($account['title'] . ' ' . $account['full_name']),
                'email'    => $account['email'],
                'password' => $tempPassword,
            ];
            flash('success', 'Portal access reset successfully.');
        }
        redirect(url('admin/users.php') . '?tab=priests');
    }

    redirect(url('admin/users.php'));
}

// ── GET: data queries ─────────────────────────────────────────────────────────
$roles      = db()->query('SELECT * FROM roles')->fetchAll();
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

$priests = db()->query(
    'SELECT p.*, u.email AS login_email, u.status AS login_status
     FROM priests p LEFT JOIN users u ON u.user_id = p.user_id
     ORDER BY p.full_name'
)->fetchAll();

$activeTab  = $_GET['tab'] ?? 'staff';
$active     = 'personnel';
$pageTitle  = 'Parish Personnel';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<?php
// Inline styles for the tab navigation
?>
<style>
.tab-nav { display:flex; gap:0; border-bottom:2px solid var(--border,#e0d8cc); margin-bottom:24px; }
.tab-nav-btn {
  padding:10px 22px; background:none; border:none; border-bottom:3px solid transparent;
  margin-bottom:-2px; font-size:14px; font-weight:600; color:var(--text-muted,#7a6a54);
  cursor:pointer; transition:color .15s,border-color .15s; font-family:inherit;
}
.tab-nav-btn.active { color:var(--accent,#7a5c1e); border-bottom-color:var(--accent,#7a5c1e); }
.tab-nav-btn:hover:not(.active) { color:var(--text-primary,#3b2f1e); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
</style>

<div class="tab-nav">
  <button class="tab-nav-btn" data-tab="staff">Administration Staff</button>
  <button class="tab-nav-btn" data-tab="priests">Clergy / Priests</button>
</div>

<!-- ══════════════════════ TAB 1: STAFF ══════════════════════ -->
<div class="tab-pane" id="tab-staff">

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
              <form method="POST" action="<?= url('admin/users.php') ?>" class="role-change-form" style="display:inline-flex;gap:6px;">
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
              <form method="POST" action="<?= url('admin/users.php') ?>" style="display:inline-flex;gap:6px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <select name="status" onchange="this.form.submit()">
                  <option value="active"    <?= $u['status']==='active'   ?'selected':'' ?>>Active</option>
                  <option value="inactive"  <?= $u['status']==='inactive' ?'selected':'' ?>>Inactive</option>
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
              <button type="button" class="btn btn-danger btn-sm js-delete-user"
                data-id="<?= $u['user_id'] ?>"
                data-name="<?= e($u['firstname'] . ' ' . $u['lastname']) ?>">Delete</button>
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

</div><!-- /tab-staff -->

<!-- ══════════════════════ TAB 2: PRIESTS ══════════════════════ -->
<div class="tab-pane" id="tab-priests">

<div class="card">
  <div class="card-header">
    <h3>All Priests</h3>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('addPriestModal').showModal()">+ Add Priest</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Status</th><th>Portal Login</th><th>Actions</th></tr></thead>
      <tbody id="priestsTableBody">
        <?php foreach ($priests as $p): ?>
          <tr>
            <td><?= e($p['title']) ?> <?= e($p['full_name']) ?></td>
            <td>
              <?php if ($p['status'] === 'active'): ?>
                <span class="badge badge-verified">Active</span>
              <?php elseif ($p['status'] === 'on_leave'): ?>
                <span class="badge" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba;">On Leave</span>
              <?php else: ?>
                <span class="badge badge-cancelled">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($p['user_id'])): ?>
                <button type="button" class="btn btn-outline btn-sm js-manage-login"
                  data-priest-id="<?= (int) $p['priest_id'] ?>"
                  data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>"
                  data-priest-contact="<?= e($p['contact_number'] ?? '') ?>"
                  data-login-email="<?= e($p['login_email'] ?? '') ?>"
                  data-login-status="<?= e($p['login_status'] ?? '') ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2d7a46" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>Manage Account
                </button>
              <?php else: ?>
                <button type="button" class="btn btn-outline btn-sm js-create-login"
                  data-priest-id="<?= (int) $p['priest_id'] ?>"
                  data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>"
                  data-priest-email="<?= e($p['email'] ?? '') ?>">+ Create Login</button>
              <?php endif; ?>
            </td>
            <td>
              <div class="flex gap-2">
                <button type="button" class="btn btn-outline btn-sm js-edit-priest"
                  data-priest-id="<?= (int) $p['priest_id'] ?>"
                  data-priest-title="<?= e($p['title'] ?? '') ?>"
                  data-priest-name="<?= e($p['full_name'] ?? '') ?>"
                  data-priest-contact="<?= e($p['contact_number'] ?? '') ?>"
                  data-priest-status="<?= e($p['status'] ?? 'active') ?>">Edit</button>
                <button type="button" class="btn btn-danger btn-sm js-remove-priest"
                  data-priest-id="<?= (int) $p['priest_id'] ?>"
                  data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>">Archive</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($priests)): ?><tr><td colspan="4" class="text-muted text-center">No priests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /tab-priests -->

<!-- ══════════════════ STAFF MODALS ══════════════════ -->

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
        <div class="form-group"><label>First Name</label><input type="text" name="firstname" required style="text-transform:capitalize;"></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="lastname" required style="text-transform:capitalize;"></div>
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

<dialog id="createStaffConfirmModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Create Account?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to create this staff account?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('createStaffConfirmModal').close()">Cancel</button>
    <button type="button" class="btn btn-success" onclick="document.getElementById('createStaffConfirmModal').close(); document.getElementById('createStaffForm').submit();">Yes, Create</button>
  </div>
</dialog>

<!-- Delete-confirm modal (staff) -->
<dialog id="deleteUserModal" style="max-width:440px;padding:28px;border-radius:8px;border:none;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
  <h3 style="margin-top:0;">Delete User?</h3>
  <p id="deleteUserMsg" style="color:var(--text-muted,#555);"></p>
  <form method="POST" action="<?= url('admin/users.php') ?>" id="deleteUserForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="deleteUserId">
    <div class="form-group" style="margin-top:4px;">
      <label for="deleteAdminPw" style="font-weight:600;">Enter your password to confirm</label>
      <input type="password" id="deleteAdminPw" name="admin_password" autocomplete="current-password" required placeholder="Your admin password">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
      <button type="button" class="btn btn-outline" id="deleteUserCancel">Cancel</button>
      <button type="submit" class="btn btn-danger">Delete Account</button>
    </div>
  </form>
</dialog>

<!-- Reset Password Confirm Modal (staff) -->
<dialog id="resetPwModal" style="max-width:440px;padding:24px;border-radius:8px;border:none;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
  <h3 style="margin-top:0;">Reset Password?</h3>
  <p id="resetPwMsg" style="color:var(--text-muted,#555);margin-bottom:8px;"></p>
  <p style="font-size:13px;color:var(--text-muted,#777);margin-top:0;">A strong temporary password will be generated. The staff member <strong>must change it</strong> on their next login.</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px;">
    <button type="button" class="btn btn-outline" id="resetPwCancel">Cancel</button>
    <button type="button" class="btn btn-primary" id="resetPwConfirm">Yes, Reset Password</button>
  </div>
</dialog>

<!-- Role Change Confirmation (staff) -->
<dialog class="modal" id="roleConfirmModal">
  <div class="modal-head">
    <h3>Confirm Role Change</h3>
    <button type="button" class="modal-close" onclick="cancelRoleChange()">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;" id="roleConfirmMessage"></p>
    <div class="form-group">
      <label for="roleAdminPw">Enter your password to confirm</label>
      <input type="password" id="roleAdminPw" autocomplete="current-password" placeholder="Your admin password" required>
    </div>
    <div class="flex gap-3" style="justify-content:flex-end;">
      <button type="button" class="btn btn-outline" onclick="cancelRoleChange()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="applyRoleChange()">Confirm</button>
    </div>
  </div>
</dialog>

<!-- ══════════════════ PRIEST MODALS ══════════════════ -->

<dialog class="modal" id="editPriestModal">
  <div class="modal-head">
    <h3>Edit Priest</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('editPriestModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('admin/users.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="priest_edit">
      <input type="hidden" name="priest_id" id="editPriestId">
      <div class="form-group"><label>Title <span aria-hidden="true">*</span></label><input type="text" name="title" id="editPriestTitle" required></div>
      <div class="form-group"><label>Full Name <span aria-hidden="true">*</span></label><input type="text" name="full_name" id="editPriestName" required></div>
      <div class="form-group"><label>Contact #</label><input type="tel" name="contact_number" id="editPriestContact" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}"></div>
      <div class="form-group"><label>Status <span aria-hidden="true">*</span></label>
        <select name="status" id="editPriestStatus" required>
          <option value="active">Active</option>
          <option value="on_leave">On Leave</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="flex gap-3" style="justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('editPriestModal').close()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Priest</button>
      </div>
    </form>
  </div>
</dialog>

<dialog class="modal" id="addPriestModal">
  <div class="modal-head">
    <h3>Add Priest</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('addPriestModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form id="addPriestForm" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="ajax" value="1">
      <input type="hidden" name="action" value="priest_add">
      <div class="form-group"><label>Full Name <span aria-hidden="true">*</span></label><input type="text" name="full_name" required><small class="field-error" data-error-for="full_name"></small></div>
      <div class="form-group"><label>Title <span aria-hidden="true">*</span></label><input type="text" name="title" value="Rev. Fr." required><small class="field-error" data-error-for="title"></small></div>
      <div class="form-group"><label>Contact # <span aria-hidden="true">*</span></label><input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" required><small class="field-error" data-error-for="contact_number"></small></div>
      <div class="form-group"><label>Email <span aria-hidden="true">*</span></label><input type="email" name="email" required><small class="field-error" data-error-for="email"></small></div>
      <div id="addPriestError" class="alert" style="display:none;background:var(--danger-bg);color:var(--danger);border:1px solid #f5c2c2;"></div>
      <button type="submit" class="btn btn-primary btn-block" id="addPriestSubmitBtn">Add Priest</button>
    </form>
  </div>
</dialog>

<!-- Archive priest modal (with admin password) -->
<dialog class="modal" id="removePriestModal" aria-labelledby="removePriestTitle">
  <div class="modal-head">
    <h3 id="removePriestTitle">Archive Priest</h3>
    <button type="button" class="modal-close" id="removePriestClose" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;">Are you sure you want to archive this priest?</p>
    <p style="font-size:17px;font-weight:700;color:var(--brown-dark);margin:18px 0;" id="removePriestName"></p>
    <p class="text-muted" style="margin-bottom:20px;">Archiving sets their status to <strong>Inactive</strong> and deactivates any linked portal login. Their record and appointment history are <strong>preserved</strong> and can be restored by editing their status.</p>
    <form method="POST" action="<?= url('admin/users.php') ?>" id="removePriestForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="priest_delete">
      <input type="hidden" name="priest_id" id="removePriestId" value="">
      <div class="form-group" style="margin-top:4px;">
        <label for="removePriestAdminPw" style="font-weight:600;">Enter your password to confirm</label>
        <input type="password" id="removePriestAdminPw" name="admin_password" autocomplete="current-password" required placeholder="Your admin password">
      </div>
      <div class="flex gap-3" style="justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-outline" id="removePriestCancel">Cancel</button>
        <button type="submit" class="btn btn-danger" id="removePriestSubmit">Archive Priest</button>
      </div>
    </form>
  </div>
</dialog>

<dialog class="modal" id="createLoginModal" aria-labelledby="createLoginTitle">
  <div class="modal-head"><h3 id="createLoginTitle">Create Priest Portal Login</h3><button type="button" class="modal-close js-close-login-modal" aria-label="Close">✕</button></div>
  <div class="modal-body">
    <p><strong>Priest</strong><br><span id="createLoginPriestName"></span></p>
    <form method="POST" action="<?= url('admin/users.php') ?>" id="createLoginForm">
      <?= csrfField() ?><input type="hidden" name="action" value="priest_create_login"><input type="hidden" name="priest_id" id="createLoginPriestId">
      <div class="form-group"><label for="createLoginEmail">Login Email</label><input type="email" name="login_email" id="createLoginEmail" required></div>
      <p class="text-muted">This will create a Priest Portal account for this priest.</p>
      <div class="flex gap-3" style="justify-content:flex-end;"><button type="button" class="btn btn-outline js-close-login-modal">Cancel</button><button type="submit" class="btn btn-primary" id="createLoginSubmit">Create Login</button></div>
    </form>
  </div>
</dialog>

<dialog class="modal" id="manageLoginModal" aria-labelledby="manageLoginTitle">
  <div class="modal-head"><h3 id="manageLoginTitle">Manage Priest Login</h3><button type="button" class="modal-close js-close-login-modal" aria-label="Close">✕</button></div>
  <div class="modal-body">
    <p><strong>Priest</strong><br><span id="manageLoginPriestName"></span></p>
    <p><strong>Contact Number</strong><br>
      <a href="" id="manageLoginContactLink" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:var(--primary);">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
        <span id="manageLoginContact"></span>
      </a>
    </p>
    <p><strong>Portal Email</strong><br><span id="manageLoginEmail"></span></p>
    <p><strong>Account Status</strong><br><span id="manageLoginStatus"></span></p>
    <form method="POST" action="<?= url('admin/users.php') ?>" id="manageLoginForm">
      <?= csrfField() ?><input type="hidden" name="action" value="priest_manage_login"><input type="hidden" name="priest_id" id="manageLoginPriestId"><input type="hidden" name="login_operation" id="manageLoginOperation">
      <div class="flex gap-2" style="justify-content:flex-end;flex-wrap:wrap;"><button type="submit" class="btn btn-outline js-login-operation" data-operation="reset">Reset Access</button><button type="submit" class="btn btn-danger js-login-operation" data-operation="deactivate" id="manageLoginToggle">Deactivate Portal Access</button></div>
    </form>
  </div>
</dialog>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
(function () {
  var initialTab = <?= json_encode($activeTab) ?>;
  var btns  = document.querySelectorAll('.tab-nav-btn');
  var panes = document.querySelectorAll('.tab-pane');

  function activateTab(name) {
    btns.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    panes.forEach(function (p) { p.classList.toggle('active', p.id === 'tab-' + name); });
  }

  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      activateTab(this.dataset.tab);
      // update URL without reload so a flash message stays visible
      try { history.replaceState(null, '', location.pathname + (this.dataset.tab !== 'staff' ? '?tab=' + this.dataset.tab : '')); } catch (e) {}
    });
  });

  activateTab(initialTab);
})();

// ── Staff: role change ────────────────────────────────────────────────────────
var pendingRoleSelect = null;
function confirmRoleChange(selectEl) {
  pendingRoleSelect = selectEl;
  document.getElementById('roleConfirmMessage').textContent =
    'Are you sure you want to assign ' + selectEl.dataset.userName + ' as ' + selectEl.options[selectEl.selectedIndex].text + '?';
  document.getElementById('roleConfirmModal').showModal();
}
function cancelRoleChange() {
  if (pendingRoleSelect) { pendingRoleSelect.value = pendingRoleSelect.dataset.originalValue; pendingRoleSelect = null; }
  document.getElementById('roleAdminPw').value = '';
  document.getElementById('roleConfirmModal').close();
}
function applyRoleChange() {
  // Role changes need the admin's password (audit M-08). The server checks it.
  var pwInput = document.getElementById('roleAdminPw');
  if (!pendingRoleSelect) { document.getElementById('roleConfirmModal').close(); return; }
  if (!pwInput.value) { reportInlineError(pwInput, 'Please enter your admin password to confirm this role change.'); return; }
  var form = pendingRoleSelect.form;
  var hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = 'admin_password';
  hidden.value = pwInput.value;
  form.appendChild(hidden);
  pwInput.value = '';
  document.getElementById('roleConfirmModal').close();
  form.submit();
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.role-select').forEach(function (sel) { sel.dataset.originalValue = sel.value; });

  // Staff: reset password modal
  var resetPwModal     = document.getElementById('resetPwModal');
  var pendingResetForm = null;
  document.querySelectorAll('.js-reset-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      pendingResetForm = this.nextElementSibling;
      document.getElementById('resetPwMsg').textContent = 'Reset the password for ' + this.dataset.name + '?';
      resetPwModal.showModal();
    });
  });
  document.getElementById('resetPwCancel').addEventListener('click', function () { pendingResetForm = null; resetPwModal.close(); });
  document.getElementById('resetPwConfirm').addEventListener('click', function () { resetPwModal.close(); if (pendingResetForm) pendingResetForm.submit(); });
  resetPwModal.addEventListener('click', function (e) { if (e.target === resetPwModal) { pendingResetForm = null; resetPwModal.close(); } });

  // Staff: delete modal
  var deleteModal = document.getElementById('deleteUserModal');
  function closeDeleteUserModal() { document.getElementById('deleteAdminPw').value = ''; deleteModal.close(); }
  document.querySelectorAll('.js-delete-user').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('deleteUserId').value = this.dataset.id;
      document.getElementById('deleteUserMsg').textContent = 'Permanently delete "' + this.dataset.name + '"? This cannot be undone.';
      document.getElementById('deleteAdminPw').value = '';
      deleteModal.showModal();
      setTimeout(function () { document.getElementById('deleteAdminPw').focus(); }, 50);
    });
  });
  document.getElementById('deleteUserCancel').addEventListener('click', closeDeleteUserModal);
  deleteModal.addEventListener('click', function (e) { if (e.target === deleteModal) closeDeleteUserModal(); });

  // ── Priests ──────────────────────────────────────────────────────────────────
  var removePriestModal   = document.getElementById('removePriestModal');
  var removePriestTrigger = null;

  function closeRemovePriestModal() {
    if (removePriestModal.open) removePriestModal.close();
    var pw = document.getElementById('removePriestAdminPw');
    if (pw) pw.value = '';
    if (removePriestTrigger) { removePriestTrigger.focus(); removePriestTrigger = null; }
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('.js-remove-priest');
    if (!trigger) return;
    removePriestTrigger = trigger;
    document.getElementById('removePriestId').value        = trigger.dataset.priestId || '';
    document.getElementById('removePriestName').textContent = trigger.dataset.priestName || 'this priest';
    document.getElementById('removePriestAdminPw').value   = '';
    document.getElementById('removePriestSubmit').disabled = false;
    document.getElementById('removePriestSubmit').textContent = 'Archive Priest';
    removePriestModal.showModal();
    setTimeout(function () { document.getElementById('removePriestAdminPw').focus(); }, 50);
  });
  document.getElementById('removePriestClose').addEventListener('click', closeRemovePriestModal);
  document.getElementById('removePriestCancel').addEventListener('click', closeRemovePriestModal);
  removePriestModal.addEventListener('click', function (e) { if (e.target === removePriestModal) closeRemovePriestModal(); });
  document.getElementById('removePriestForm').addEventListener('submit', function () {
    var submit = document.getElementById('removePriestSubmit');
    if (submit.disabled) return;
    submit.disabled  = true;
    submit.textContent = 'Archiving...';
  });

  // Priests: add form (AJAX)
  var createLoginModal = document.getElementById('createLoginModal');
  var manageLoginModal = document.getElementById('manageLoginModal');

  document.addEventListener('click', function (event) {
    var create = event.target.closest('.js-create-login');
    if (create) {
      document.getElementById('createLoginPriestId').value  = create.dataset.priestId || '';
      document.getElementById('createLoginPriestName').textContent = create.dataset.priestName || '';
      document.getElementById('createLoginEmail').value     = create.dataset.priestEmail || '';
      document.getElementById('createLoginSubmit').disabled = false;
      createLoginModal.showModal();
      document.getElementById('createLoginEmail').focus();
      return;
    }
    var manage = event.target.closest('.js-manage-login');
    if (manage) {
      document.getElementById('manageLoginPriestId').value  = manage.dataset.priestId || '';
      document.getElementById('manageLoginPriestName').textContent = manage.dataset.priestName || '';
      document.getElementById('manageLoginEmail').textContent = manage.dataset.loginEmail || '—';
      var contact = manage.dataset.priestContact || '';
      document.getElementById('manageLoginContact').textContent = contact || '—';
      if (contact) { document.getElementById('manageLoginContactLink').href = 'tel:' + contact; document.getElementById('manageLoginContactLink').style.pointerEvents = 'auto'; }
      else { document.getElementById('manageLoginContactLink').removeAttribute('href'); document.getElementById('manageLoginContactLink').style.pointerEvents = 'none'; }
      var status = manage.dataset.loginStatus || 'unknown';
      document.getElementById('manageLoginStatus').textContent = status.charAt(0).toUpperCase() + status.slice(1);
      var toggle = document.getElementById('manageLoginToggle');
      toggle.dataset.operation = status === 'active' ? 'deactivate' : 'activate';
      toggle.textContent = status === 'active' ? 'Deactivate Portal Access' : 'Reactivate Portal Access';
      toggle.className   = status === 'active' ? 'btn btn-danger js-login-operation' : 'btn btn-primary js-login-operation';
      manageLoginModal.showModal();
    }
  });

  document.querySelectorAll('.js-close-login-modal').forEach(function (button) {
    button.addEventListener('click', function () {
      if (createLoginModal.open) createLoginModal.close();
      if (manageLoginModal.open) manageLoginModal.close();
    });
  });
  document.querySelectorAll('.js-login-operation').forEach(function (button) {
    button.addEventListener('click', function () { document.getElementById('manageLoginOperation').value = button.dataset.operation; });
  });
  document.getElementById('createLoginForm').addEventListener('submit', function () {
    document.getElementById('createLoginSubmit').disabled  = true;
    document.getElementById('createLoginSubmit').textContent = 'Creating...';
  });

  document.querySelectorAll('.js-edit-priest').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('editPriestId').value      = this.dataset.priestId;
      document.getElementById('editPriestTitle').value   = this.dataset.priestTitle;
      document.getElementById('editPriestName').value    = this.dataset.priestName;
      document.getElementById('editPriestContact').value = this.dataset.priestContact;
      document.getElementById('editPriestStatus').value  = this.dataset.priestStatus;
      document.getElementById('editPriestModal').showModal();
    });
  });

  document.getElementById('addPriestForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var form      = e.target;
    var errorBox  = document.getElementById('addPriestError');
    var submitBtn = document.getElementById('addPriestSubmitBtn');
    form.querySelectorAll('[data-error-for]').forEach(function (node) { node.textContent = ''; });
    var fullName = form.elements.full_name.value.trim();
    var title    = form.elements.title.value.trim();
    var contact  = form.elements.contact_number.value.trim();
    var email    = form.elements.email.value.trim();
    var errs = {};
    if (!fullName) errs.full_name = 'Full name is required.';
    if (!title)    errs.title     = 'Title is required.';
    if (!/^09\d{9}$/.test(contact)) errs.contact_number = 'Enter a valid 11-digit Philippine mobile number starting with 09.';
    if (!email)    errs.email     = 'Email address is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errs.email = 'Enter a valid email address.';
    Object.keys(errs).forEach(function (name) {
      var node = form.querySelector('[data-error-for="' + name + '"]');
      if (node) node.textContent = errs[name];
    });
    if (Object.keys(errs).length) return;
    errorBox.style.display = 'none';
    submitBtn.disabled     = true;
    var origText           = submitBtn.textContent;
    submitBtn.textContent  = 'Please wait...';
    fetch('<?= url('admin/users.php') ?>', { method: 'POST', body: new FormData(form) })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        submitBtn.disabled    = false;
        submitBtn.textContent = origText;
        if (data.success) { window.location.reload(); }
        else {
          errorBox.textContent   = data.message;
          errorBox.style.display = 'block';
          Object.keys(data.errors || {}).forEach(function (name) {
            var node = form.querySelector('[data-error-for="' + name + '"]');
            if (node) node.textContent = data.errors[name];
          });
        }
      })
      .catch(function () {
        submitBtn.disabled    = false;
        submitBtn.textContent = origText;
        errorBox.textContent  = 'Something went wrong. Please try again.';
        errorBox.style.display = 'block';
      });
  });
});
</script>

<?php if (isset($_SESSION['temp_password_info'])):
    $tp      = $_SESSION['temp_password_info'];
    unset($_SESSION['temp_password_info']);
    $isReset = ($tp['context'] ?? '') === 'reset';
?>
<dialog class="modal" id="tempPasswordModal" style="max-width:450px;text-align:center;">
  <div class="modal-body" style="padding:30px 20px;">
    <div style="background:var(--bg-success);color:var(--success);width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
      <?php if ($isReset): ?>
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
      <?php else: ?>
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
      <?php endif; ?>
    </div>
    <h3 style="margin-top:0;"><?= $isReset ? 'Password Reset' : 'Account Created!' ?></h3>
    <p style="color:var(--text-muted);margin-bottom:20px;">
      <?= $isReset
        ? 'Temporary password for <strong>' . e($tp['name']) . '</strong>. Hand it over securely — they must change it on next login.'
        : 'Portal login for <strong>' . e($tp['name']) . '</strong> has been set up. Share the temporary password below.' ?>
    </p>
    <div style="background:var(--bg-secondary);padding:15px;border-radius:8px;border:1px dashed #ccc;margin-bottom:20px;">
      <div style="font-size:24px;font-weight:bold;letter-spacing:2px;font-family:monospace;color:var(--text-primary);" id="tempPwdText"><?= e($tp['password']) ?></div>
    </div>
    <button type="button" class="btn btn-primary btn-block" id="copyTempPwdBtn" style="margin-bottom:10px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
      Copy Password
    </button>
    <button type="button" class="btn btn-outline btn-block" onclick="document.getElementById('tempPasswordModal').close()">I've copied it</button>
  </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('tempPasswordModal');
  if (modal) {
    modal.showModal();
    document.getElementById('copyTempPwdBtn').addEventListener('click', function () {
      navigator.clipboard.writeText(document.getElementById('tempPwdText').textContent).then(function () {
        var btn = document.getElementById('copyTempPwdBtn');
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><polyline points="20 6 9 17 4 12"></polyline></svg> Copied!';
        btn.classList.replace('btn-primary', 'btn-success');
        setTimeout(function () {
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
