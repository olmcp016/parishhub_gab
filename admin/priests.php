<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$isAjax = ($_POST['ajax'] ?? '') === '1';

function priestsRespondError(bool $isAjax, string $message, string $redirectUrl): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
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
        $title = trim($_POST['title'] ?? '') ?: 'Rev. Fr.';
        $contact = trim($_POST['contact_number'] ?? '') ?: null;
        $email = trim($_POST['email'] ?? '') ?: null;

        if ($fullName === '') {
            priestsRespondError($isAjax, 'Please enter the priest\'s full name.', url('admin/priests.php'));
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            priestsRespondError($isAjax, 'Please enter a valid email address.', url('admin/priests.php'));
        }

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
                'message' => 'Priest added.',
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
        flash('success', 'Priest added.');
    } elseif ($action === 'status') {
        db()->prepare('UPDATE priests SET status = ? WHERE priest_id = ?')->execute([$_POST['status'], $_POST['priest_id']]);
        flash('success', 'Priest status updated.');
    }
    redirect(url('admin/priests.php'));
}

$priests = db()->query('SELECT * FROM priests ORDER BY full_name')->fetchAll();

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
      <thead><tr><th>Name</th><th>Contact</th><th>Status</th></tr></thead>
      <tbody id="priestsTableBody">
        <?php foreach ($priests as $p): ?>
          <tr>
            <td><?= e($p['title']) ?> <?= e($p['full_name']) ?></td>
            <td><?= e($p['contact_number'] ?? '—') ?><br><span class="text-muted" style="font-size:12px;"><?= e($p['email'] ?? '') ?></span></td>
            <td>
              <form method="POST" action="<?= url('admin/priests.php') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="priest_id" value="<?= $p['priest_id'] ?>">
                <select name="status" onchange="this.form.submit()">
                  <option value="active" <?= $p['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="on_leave" <?= $p['status']==='on_leave'?'selected':'' ?>>On Leave</option>
                  <option value="inactive" <?= $p['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($priests)): ?><p class="text-muted text-center mt-3" id="priestsEmptyState">No priests yet.</p><?php endif; ?>
</div>

<dialog class="modal" id="addPriestModal">
  <div class="modal-head">
    <h3>Add Priest</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('addPriestModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <form id="addPriestForm">
      <?= csrfField() ?>
      <input type="hidden" name="ajax" value="1">
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
      <div class="form-group"><label>Title</label><input type="text" name="title" value="Rev. Fr." required></div>
      <div class="form-group"><label>Contact #</label><input type="tel" name="contact_number"></div>
      <div class="form-group"><label>Email</label><input type="email" name="email"></div>
      <div id="addPriestError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>
      <button type="submit" class="btn btn-primary btn-block" id="addPriestSubmitBtn">Add Priest</button>
    </form>
  </div>
</dialog>

<script>
document.getElementById('addPriestForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var form = e.target;
  var errorBox = document.getElementById('addPriestError');
  var submitBtn = document.getElementById('addPriestSubmitBtn');
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
        var p = data.priest;
        var emptyState = document.getElementById('priestsEmptyState');
        if (emptyState) emptyState.remove();

        var tbody = document.getElementById('priestsTableBody');
        var tr = document.createElement('tr');

        var nameTd = document.createElement('td');
        nameTd.textContent = p.title + ' ' + p.full_name;

        var contactTd = document.createElement('td');
        contactTd.appendChild(document.createTextNode(p.contact_number || '—'));
        contactTd.appendChild(document.createElement('br'));
        var emailSpan = document.createElement('span');
        emailSpan.className = 'text-muted';
        emailSpan.style.fontSize = '12px';
        emailSpan.textContent = p.email || '';
        contactTd.appendChild(emailSpan);

        var statusTd = document.createElement('td');
        var statusForm = document.createElement('form');
        statusForm.method = 'POST';
        statusForm.action = '<?= url('admin/priests.php') ?>';
        statusForm.innerHTML = <?= json_encode(csrfField()) ?>
          + '<input type="hidden" name="action" value="status">'
          + '<input type="hidden" name="priest_id" value="' + p.priest_id + '">'
          + '<select name="status" onchange="this.form.submit()">'
          + '<option value="active" selected>Active</option>'
          + '<option value="on_leave">On Leave</option>'
          + '<option value="inactive">Inactive</option>'
          + '</select>';
        statusTd.appendChild(statusForm);

        tr.appendChild(nameTd);
        tr.appendChild(contactTd);
        tr.appendChild(statusTd);
        tbody.appendChild(tr);

        form.reset();
        form.querySelector('[name="title"]').value = 'Rev. Fr.';
        document.getElementById('addPriestModal').close();
      } else {
        errorBox.textContent = data.message;
        errorBox.style.display = 'block';
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
