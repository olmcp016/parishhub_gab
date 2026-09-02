<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        db()->prepare(
            "INSERT INTO priests (full_name, title, specialization, contact_number, email) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $_POST['full_name'], $_POST['title'] ?: 'Rev. Fr.', $_POST['specialization'] ?: null,
            $_POST['contact_number'] ?: null, $_POST['email'] ?: null,
        ]);
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
  <div class="card-header"><h3>Add Priest</h3></div>
  <form method="POST" action="<?= url('admin/priests.php') ?>" class="form-row" style="align-items:end;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
    <div class="form-group"><label>Title</label><input type="text" name="title" value="Rev. Fr." required></div>
    <div class="form-group"><label>Specialization</label><input type="text" name="specialization" placeholder="e.g. Weddings, Baptisms"></div>
    <div class="form-group"><label>Contact #</label><input type="tel" name="contact_number"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Priests</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Specialization</th><th>Contact</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($priests as $p): ?>
          <tr>
            <td><?= e($p['title']) ?> <?= e($p['full_name']) ?></td>
            <td><?= e($p['specialization'] ?? '—') ?></td>
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
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
