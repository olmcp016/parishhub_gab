<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$isAjax = ($_POST['ajax'] ?? '') === '1';

function priestsRespondError(bool $isAjax, string $message, string $redirectUrl, array $fields = []): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message, 'errors' => $fields]);
        exit;
    }
    flash('error', $message);
    redirect($redirectUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $fullName = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $errors = [];
        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($title === '') $errors['title'] = 'Title is required.';
        if (!preg_match('/^09\d{9}$/', $contact)) $errors['contact_number'] = 'Enter a valid 11-digit Philippine mobile number starting with 09.';
        if ($email === '') $errors['email'] = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if ($errors) priestsRespondError($isAjax, 'Please correct the highlighted fields.', url('admin/priests.php'), $errors);

        $stmt = db()->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) UNION ALL SELECT 1 FROM priests WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email, $email]);
        if ($stmt->fetchColumn()) priestsRespondError($isAjax, 'This email address is already being used.', url('admin/priests.php'), ['email' => 'This email address is already being used.']);

        $stmt = db()->prepare(
            "INSERT INTO priests (full_name, title, contact_number, email) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$fullName, $title, $contact, $email]);
        $priestId = (int) db()->lastInsertId();
        logActivity(currentUser()['user_id'], "Added priest: $title $fullName", 'Priests');

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Priest added successfully.',
                'priest' => [
                    'priest_id' => $priestId,
                    'full_name' => $fullName,
                    'title' => $title,
                    'contact_number' => $contact,
                    'email' => $email,
                    'status' => 'active',
                ],
            ]);
            exit;
        }
        flash('success', 'Priest added successfully.');
    } elseif ($action === 'edit') {
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        
        if (!in_array($status, ['active', 'on_leave', 'inactive'], true)) {
            flash('error', 'Invalid priest status.');
            redirect(url('admin/priests.php'));
        }
        
        db()->prepare('UPDATE priests SET title = ?, full_name = ?, contact_number = ?, status = ? WHERE priest_id = ?')->execute([$title, $fullName, $contact, $status, $priestId]);
        flash('success', 'Priest details updated.');
    } elseif ($action === 'delete') {
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        // Ensure no active/pending appointments use this priest
        $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE priest_id = ? AND status_id NOT IN (3, 6, 7)");
        $stmt->execute([$priestId]);
        if ($stmt->fetchColumn() > 0) {
            flash('error', 'Cannot remove priest: they are assigned to active or upcoming appointments.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT user_id FROM priests WHERE priest_id = ? FOR UPDATE");
                $stmt->execute([$priestId]);
                $uid = $stmt->fetchColumn();
                if ($uid === false) throw new RuntimeException('Priest not found.');
                $pdo->prepare("DELETE FROM priests WHERE priest_id = ?")->execute([$priestId]);
                if ($uid) $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
                flash('error', 'Could not remove the priest. Please try again.');
                redirect(url('admin/priests.php'));
            }
            logActivity(currentUser()['user_id'], "Removed priest #$priestId", 'Priests');
            flash('success', 'Priest removed.');
        }
        redirect(url('admin/priests.php'));
    } elseif ($action === 'create_login') {
        // Gives an existing priest record a real account (role "Priest") —
        // a view-only schedule/Mass Intention portal, see priest/*.php.
        // Not done automatically for every priest: staff explicitly grants
        // it here, confirming/correcting the priest's own email first.
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $email = trim($_POST['login_email'] ?? '');

        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM priests WHERE priest_id = ? FOR UPDATE');
        $stmt->execute([$priestId]);
        $priest = $stmt->fetch();
        if (!$priest) {
            $pdo->rollBack();
            flash('error', 'Priest not found.');
            redirect(url('admin/priests.php'));
        }
        if (!empty($priest['user_id'])) {
            $pdo->rollBack();
            flash('error', 'This priest already has a login.');
            redirect(url('admin/priests.php'));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pdo->rollBack();
            flash('error', 'Please enter a valid email address for the login.');
            redirect(url('admin/priests.php'));
        }
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            flash('error', "An account with $email already exists — use a different email, or that user may already be linked elsewhere.");
            redirect(url('admin/priests.php'));
        }

        $priestRoleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Priest'")->fetchColumn();
        $tempPassword = bin2hex(random_bytes(5)); // 10-char random, shown once below
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (role_id, firstname, lastname, email, password, phone, status) VALUES (?, ?, '', ?, ?, ?, 'active')"
            );
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
            redirect(url('admin/priests.php'));
        }

        logActivity(currentUser()['user_id'], "Created a Priest login for {$priest['title']} {$priest['full_name']} ($email)", 'Priests');
        flash('success', "Login created for {$priest['title']} {$priest['full_name']}. Email: $email — Temporary password: $tempPassword (please relay this to the priest securely; it will not be shown again).");
    } elseif ($action === 'manage_login') {
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        $operation = $_POST['login_operation'] ?? '';
        if (!in_array($operation, ['activate', 'deactivate', 'reset'], true)) {
            flash('error', 'Invalid portal login action.');
            redirect(url('admin/priests.php'));
        }
        $stmt = db()->prepare('SELECT p.title, p.full_name, p.user_id, u.email, u.status AS user_status FROM priests p LEFT JOIN users u ON u.user_id = p.user_id WHERE p.priest_id = ?');
        $stmt->execute([$priestId]);
        $account = $stmt->fetch();
        if (!$account || empty($account['user_id'])) {
            flash('error', 'This priest does not have a linked portal account.');
            redirect(url('admin/priests.php'));
        }
        if ($operation === 'activate' || $operation === 'deactivate') {
            $newStatus = $operation === 'activate' ? 'active' : 'inactive';
            db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, (int) $account['user_id']]);
            logActivity(currentUser()['user_id'], ucfirst($operation) . "d Priest portal access for {$account['title']} {$account['full_name']}", 'Priests');
            flash('success', $operation === 'activate' ? 'Priest portal access reactivated.' : 'Priest portal access deactivated.');
        } else {
            $tempPassword = bin2hex(random_bytes(5));
            db()->prepare('UPDATE users SET password = ?, status = \'active\' WHERE user_id = ?')->execute([password_hash($tempPassword, PASSWORD_BCRYPT), (int) $account['user_id']]);
            logActivity(currentUser()['user_id'], "Reset Priest portal access for {$account['title']} {$account['full_name']}", 'Priests');
            flash('success', "Portal access reset for {$account['title']} {$account['full_name']}. Temporary password: $tempPassword (please relay this securely; it will not be shown again).");
        }
    }
    redirect(url('admin/priests.php'));
}

$priests = db()->query('SELECT p.*, u.email AS login_email, u.status AS login_status FROM priests p LEFT JOIN users u ON u.user_id = p.user_id ORDER BY p.full_name')->fetchAll();

$active = 'priests';
$pageTitle = 'Manage Priests';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>All Priests</h3>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('addPriestModal').showModal()">+ Add Priest</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Contact</th><th>Status</th><th>Portal Login</th><th>Actions</th></tr></thead>
      <tbody id="priestsTableBody">
        <?php foreach ($priests as $p): ?>
          <tr>
            <td><?= e($p['title']) ?> <?= e($p['full_name']) ?></td>
            <td><?= e($p['contact_number'] ?? '—') ?><br><span class="text-muted" style="font-size:12px;"><?= e($p['email'] ?? '') ?></span></td>
            <td>
              <?php if ($p['status'] === 'active'): ?>
                <span class="badge badge-verified">Active</span>
              <?php elseif ($p['status'] === 'on_leave'): ?>
                <span class="badge" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba;">On Leave</span>
              <?php else: ?>
                <span class="badge badge-cancelled">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($p['user_id'])): ?>
                <div class="priest-login-summary">
                  <span class="badge <?= ($p['login_status'] ?? '') === 'active' ? 'badge-verified' : 'badge-cancelled' ?>"><?= e(ucfirst($p['login_status'] ?? 'Unknown')) ?></span>
                  <button type="button" class="btn btn-outline btn-sm js-manage-login" data-priest-id="<?= (int) $p['priest_id'] ?>" data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>" data-login-email="<?= e($p['login_email'] ?? '') ?>" data-login-status="<?= e($p['login_status'] ?? '') ?>">Manage Login</button>
                </div>
              <?php else: ?>
                <div class="priest-login-summary"><span class="text-muted">Not Created</span><button type="button" class="btn btn-outline btn-sm js-create-login" data-priest-id="<?= (int) $p['priest_id'] ?>" data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>" data-priest-email="<?= e($p['email'] ?? '') ?>">Create Login</button></div>
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
                  data-priest-name="<?= e(trim(($p['title'] ?? '') . ' ' . ($p['full_name'] ?? ''))) ?>">Delete</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($priests)): ?><p class="text-muted text-center mt-3" id="priestsEmptyState">No priests yet.</p><?php endif; ?>
</div>

<dialog class="modal" id="editPriestModal">
  <div class="modal-head">
    <h3>Edit Priest</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('editPriestModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form method="POST" action="<?= url('admin/priests.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
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
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Full Name <span aria-hidden="true">*</span></label><input type="text" name="full_name" required><small class="field-error" data-error-for="full_name"></small></div>
      <div class="form-group"><label>Title <span aria-hidden="true">*</span></label><input type="text" name="title" value="Rev. Fr." required><small class="field-error" data-error-for="title"></small></div>
      <div class="form-group"><label>Contact # <span aria-hidden="true">*</span></label><input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" required><small class="field-error" data-error-for="contact_number"></small></div>
      <div class="form-group"><label>Email <span aria-hidden="true">*</span></label><input type="email" name="email" required><small class="field-error" data-error-for="email"></small></div>
      <div id="addPriestError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>
      <button type="submit" class="btn btn-primary btn-block" id="addPriestSubmitBtn">Add Priest</button>
    </form>
  </div>
</dialog>

<dialog class="modal" id="removePriestModal" aria-labelledby="removePriestTitle">
  <div class="modal-head">
    <h3 id="removePriestTitle">Remove Priest</h3>
    <button type="button" class="modal-close" id="removePriestClose" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;">Are you sure you want to remove this priest?</p>
    <p style="font-size:17px; font-weight:700; color:var(--brown-dark); margin:18px 0;" id="removePriestName"></p>
    <p class="text-muted" style="margin-bottom:20px;">This removes the priest record and any linked priest portal login. Priests with active or upcoming appointments cannot be removed.</p>
    <form method="POST" action="<?= url('admin/priests.php') ?>" id="removePriestForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="priest_id" id="removePriestId" value="">
      <div class="flex gap-3" style="justify-content:flex-end;">
        <button type="button" class="btn btn-outline" id="removePriestCancel">Cancel</button>
        <button type="submit" class="btn btn-danger" id="removePriestSubmit">Remove Priest</button>
      </div>
    </form>
  </div>
</dialog>

<dialog class="modal" id="createLoginModal" aria-labelledby="createLoginTitle">
  <div class="modal-head"><h3 id="createLoginTitle">Create Priest Portal Login</h3><button type="button" class="modal-close js-close-login-modal" aria-label="Close">✕</button></div>
  <div class="modal-body">
    <p><strong>Priest</strong><br><span id="createLoginPriestName"></span></p>
    <form method="POST" action="<?= url('admin/priests.php') ?>" id="createLoginForm">
      <?= csrfField() ?><input type="hidden" name="action" value="create_login"><input type="hidden" name="priest_id" id="createLoginPriestId">
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
    <p><strong>Portal Email</strong><br><span id="manageLoginEmail"></span></p>
    <p><strong>Account Status</strong><br><span id="manageLoginStatus"></span></p>
    <form method="POST" action="<?= url('admin/priests.php') ?>" id="manageLoginForm">
      <?= csrfField() ?><input type="hidden" name="action" value="manage_login"><input type="hidden" name="priest_id" id="manageLoginPriestId"><input type="hidden" name="login_operation" id="manageLoginOperation">
      <div class="flex gap-2" style="justify-content:flex-end; flex-wrap:wrap;"><button type="submit" class="btn btn-outline js-login-operation" data-operation="reset">Reset Access</button><button type="submit" class="btn btn-danger js-login-operation" data-operation="deactivate" id="manageLoginToggle">Deactivate Portal Access</button></div>
    </form>
  </div>
</dialog>

<script>
var removePriestModal = document.getElementById('removePriestModal');
var removePriestTrigger = null;

function closeRemovePriestModal() {
  if (removePriestModal && removePriestModal.open) removePriestModal.close();
  if (removePriestTrigger) {
    removePriestTrigger.focus();
    removePriestTrigger = null;
  }
}

document.addEventListener('click', function (event) {
  var trigger = event.target.closest('.js-remove-priest');
  if (!trigger || !removePriestModal) return;
  removePriestTrigger = trigger;
  document.getElementById('removePriestId').value = trigger.dataset.priestId || '';
  document.getElementById('removePriestName').textContent = trigger.dataset.priestName || 'this priest';
  document.getElementById('removePriestSubmit').disabled = false;
  document.getElementById('removePriestSubmit').textContent = 'Remove Priest';
  removePriestModal.showModal();
  document.getElementById('removePriestCancel').focus();
});

document.getElementById('removePriestClose').addEventListener('click', closeRemovePriestModal);
document.getElementById('removePriestCancel').addEventListener('click', closeRemovePriestModal);
removePriestModal.addEventListener('click', function (event) {
  if (event.target === removePriestModal) closeRemovePriestModal();
});
document.getElementById('removePriestForm').addEventListener('submit', function () {
  var submit = document.getElementById('removePriestSubmit');
  if (submit.disabled) return;
  submit.disabled = true;
  submit.textContent = 'Removing...';
});

var createLoginModal = document.getElementById('createLoginModal');
var manageLoginModal = document.getElementById('manageLoginModal');
document.addEventListener('click', function (event) {
  var create = event.target.closest('.js-create-login');
  if (create) {
    document.getElementById('createLoginPriestId').value = create.dataset.priestId || '';
    document.getElementById('createLoginPriestName').textContent = create.dataset.priestName || '';
    document.getElementById('createLoginEmail').value = create.dataset.priestEmail || '';
    document.getElementById('createLoginSubmit').disabled = false;
    createLoginModal.showModal();
    document.getElementById('createLoginEmail').focus();
    return;
  }
  var manage = event.target.closest('.js-manage-login');
  if (manage) {
    document.getElementById('manageLoginPriestId').value = manage.dataset.priestId || '';
    document.getElementById('manageLoginPriestName').textContent = manage.dataset.priestName || '';
    document.getElementById('manageLoginEmail').textContent = manage.dataset.loginEmail || '—';
    var status = manage.dataset.loginStatus || 'unknown';
    document.getElementById('manageLoginStatus').textContent = status.charAt(0).toUpperCase() + status.slice(1);
    var toggle = document.getElementById('manageLoginToggle');
    toggle.dataset.operation = status === 'active' ? 'deactivate' : 'activate';
    toggle.textContent = status === 'active' ? 'Deactivate Portal Access' : 'Reactivate Portal Access';
    toggle.className = status === 'active' ? 'btn btn-danger js-login-operation' : 'btn btn-primary js-login-operation';
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
  button.addEventListener('click', function () {
    document.getElementById('manageLoginOperation').value = button.dataset.operation;
  });
});
document.getElementById('createLoginForm').addEventListener('submit', function () {
  document.getElementById('createLoginSubmit').disabled = true;
  document.getElementById('createLoginSubmit').textContent = 'Creating...';
});

document.querySelectorAll('.js-edit-priest').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('editPriestId').value = this.dataset.priestId;
    document.getElementById('editPriestTitle').value = this.dataset.priestTitle;
    document.getElementById('editPriestName').value = this.dataset.priestName;
    document.getElementById('editPriestContact').value = this.dataset.priestContact;
    document.getElementById('editPriestStatus').value = this.dataset.priestStatus;
    document.getElementById('editPriestModal').showModal();
  });
});

document.getElementById('addPriestForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var form = e.target;
  var errorBox = document.getElementById('addPriestError');
  var submitBtn = document.getElementById('addPriestSubmitBtn');
  var fields = {
    full_name: 'Full name is required.',
    title: 'Title is required.',
    contact_number: 'Enter a valid 11-digit Philippine mobile number starting with 09.',
    email: 'Email address is required.'
  };
  form.querySelectorAll('[data-error-for]').forEach(function (node) { node.textContent = ''; });
  var fullName = form.elements.full_name.value.trim();
  var title = form.elements.title.value.trim();
  var contact = form.elements.contact_number.value.trim();
  var email = form.elements.email.value.trim();
  var clientErrors = {};
  if (!fullName) clientErrors.full_name = fields.full_name;
  if (!title) clientErrors.title = fields.title;
  if (!/^09\d{9}$/.test(contact)) clientErrors.contact_number = fields.contact_number;
  if (!email) clientErrors.email = fields.email;
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) clientErrors.email = 'Enter a valid email address.';
  Object.keys(clientErrors).forEach(function (name) { form.querySelector('[data-error-for="' + name + '"]').textContent = clientErrors[name]; });
  if (Object.keys(clientErrors).length) return;
  errorBox.style.display = 'none';
  submitBtn.disabled = true;
  var originalText = submitBtn.textContent;
  submitBtn.textContent = 'Please wait...';

  fetch('<?= url('admin/priests.php') ?>', { method: 'POST', body: new FormData(form) })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      submitBtn.disabled = false;
      submitBtn.textContent = originalText;
      if (data.success) {
        window.location.reload();
      } else {
        errorBox.textContent = data.message;
        errorBox.style.display = 'block';
        Object.keys(data.errors || {}).forEach(function (name) {
          var node = form.querySelector('[data-error-for="' + name + '"]');
          if (node) node.textContent = data.errors[name];
        });
      }
    })
    .catch(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = originalText;
      errorBox.textContent = 'Something went wrong adding the priest. Please try again.';
      errorBox.style.display = 'block';
    });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
