<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        db()->prepare('INSERT INTO projects (project_name, description, target_amount, created_by) VALUES (?, ?, ?, ?)')
            ->execute([trim($_POST['project_name']), trim($_POST['description'] ?? '') ?: null, (float) $_POST['target_amount'], $userId]);
        flash('success', 'Project added.');
    } elseif ($action === 'update') {
        db()->prepare('UPDATE projects SET project_name = ?, description = ?, target_amount = ? WHERE project_id = ?')
            ->execute([trim($_POST['project_name']), trim($_POST['description'] ?? '') ?: null, (float) $_POST['target_amount'], $_POST['project_id']]);
        flash('success', 'Project updated.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE projects SET is_active = ? WHERE project_id = ?')
            ->execute([!empty($_POST['is_active']) ? 1 : 0, $_POST['project_id']]);
        flash('success', 'Project updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM projects WHERE project_id = ?')->execute([$_POST['project_id']]);
        flash('success', 'Project removed.');
    }
    redirect(url('secretary/projects.php'));
}

$projects = db()->query(
    "SELECT pr.*,
       COALESCE((SELECT SUM(p.amount) FROM payments p
                 JOIN appointments a ON p.appointment_id = a.appointment_id
                 JOIN donations d ON d.appointment_id = a.appointment_id
                 WHERE d.project_id = pr.project_id AND p.payment_status = 'verified'), 0) AS raised,
       (SELECT COUNT(*) FROM payments p
                 JOIN appointments a ON p.appointment_id = a.appointment_id
                 JOIN donations d ON d.appointment_id = a.appointment_id
                 WHERE d.project_id = pr.project_id AND p.payment_status = 'verified') AS donor_count
     FROM projects pr ORDER BY pr.is_active DESC, pr.created_at DESC"
)->fetchAll();

$active = 'projects';
$pageTitle = 'Ongoing Projects';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width:640px;">
  <div class="card-header"><h3>Add Project</h3></div>
  <form method="POST" action="<?= url('secretary/projects.php') ?>">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Project Name</label><input type="text" name="project_name" required placeholder="e.g. Church Renovation"></div>
    <div class="form-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>
    <div class="form-group"><label>Target Amount (₱)</label><input type="number" name="target_amount" min="0" step="0.01" required></div>
    <button type="submit" class="btn btn-primary">Add Project</button>
  </form>
</div>

<div class="grid-3">
  <?php foreach ($projects as $p): ?>
    <?php
      $raised = (float) $p['raised'];
      $target = (float) $p['target_amount'];
      $remaining = max(0, $target - $raised);
      $progress = $target > 0 ? min(100, round($raised / $target * 100, 1)) : 0;
    ?>
    <div class="card">
      <div class="flex-between" style="align-items:flex-start;">
        <h3 style="margin:0;"><?= e($p['project_name']) ?></h3>
        <span class="badge badge-<?= $p['is_active'] ? 'verified' : 'cancelled' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
      </div>
      <p style="font-size:13.5px; color:var(--brown-mid);"><?= e($p['description'] ?? '') ?></p>
      <div style="background:var(--cream-dark); border-radius:6px; height:10px; overflow:hidden; margin:10px 0;">
        <div style="background:var(--gold); height:100%; width:<?= $progress ?>%;"></div>
      </div>
      <p style="font-size:13px; margin:4px 0;"><strong><?= money($raised) ?></strong> raised of <?= money($target) ?> (<?= $progress ?>%)</p>
      <p style="font-size:13px; margin:4px 0;">Remaining: <?= money($remaining) ?> · <?= (int) $p['donor_count'] ?> donation(s)</p>
      <div class="flex gap-2 mt-3">
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('editProject-<?= $p['project_id'] ?>').showModal()">Edit</button>
        <form method="POST" action="<?= url('secretary/projects.php') ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="project_id" value="<?= $p['project_id'] ?>">
          <input type="hidden" name="is_active" value="<?= $p['is_active'] ? 0 : 1 ?>">
          <button type="submit" class="btn btn-outline btn-sm"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <form method="POST" action="<?= url('secretary/projects.php') ?>" onsubmit="return confirm('Delete this project?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="project_id" value="<?= $p['project_id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    </div>
    <dialog class="modal" id="editProject-<?= $p['project_id'] ?>">
      <div class="modal-head">
        <h3>Edit Project</h3>
        <button type="button" class="modal-close" onclick="document.getElementById('editProject-<?= $p['project_id'] ?>').close()">✕</button>
      </div>
      <div class="modal-body">
        <form method="POST" action="<?= url('secretary/projects.php') ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="project_id" value="<?= $p['project_id'] ?>">
          <div class="form-group"><label>Project Name</label><input type="text" name="project_name" value="<?= e($p['project_name']) ?>" required></div>
          <div class="form-group"><label>Description</label><textarea name="description" rows="3"><?= e($p['description'] ?? '') ?></textarea></div>
          <div class="form-group"><label>Target Amount (₱)</label><input type="number" name="target_amount" min="0" step="0.01" value="<?= e((string)$p['target_amount']) ?>" required></div>
          <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
      </div>
    </dialog>
  <?php endforeach; ?>
  <?php if (empty($projects)): ?>
    <p class="text-muted">No projects yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
