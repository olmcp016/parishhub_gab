<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$id = (int) ($_GET['id'] ?? 0); // parishioner_id
$userId = currentUser()['user_id'];
$isAdmin = currentUser()['role_name'] === 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        flash('error', 'Only administrators can manage parishioner accounts.');
        redirect(url('secretary/parishioner-detail.php?id=' . $id));
    }
    verifyCsrf();

    $stmt = db()->prepare('SELECT user_id FROM parishioners WHERE parishioner_id = ?');
    $stmt->execute([$id]);
    $targetUserId = $stmt->fetchColumn();

    if ($targetUserId && in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended'], true)) {
        db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$_POST['status'], $targetUserId]);
        logActivity($userId, "Updated status of parishioner #$id to {$_POST['status']}", 'Parishioners');
        flash('success', 'Account status updated.');
    }
    redirect(url('secretary/parishioner-detail.php?id=' . $id));
}

$stmt = db()->prepare(
    "SELECT u.*, p.parishioner_id, p.baptism_date, p.confirmation_date, p.marital_status, p.occupation,
            p.emergency_contact_name, p.emergency_contact_number
     FROM users u JOIN parishioners p ON u.user_id = p.user_id WHERE p.parishioner_id = ?"
);
$stmt->execute([$id]);
$parishioner = $stmt->fetch();

if (!$parishioner) {
    flash('error', 'Parishioner not found.');
    redirect(url('secretary/parishioners.php'));
}

$stmt = db()->prepare(
    "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name, st.status_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.parishioner_id = ? ORDER BY a.appointment_date DESC"
);
$stmt->execute([$id]);
$appointments = $stmt->fetchAll();

$active = 'parishioners';
$pageTitle = $parishioner['firstname'] . ' ' . $parishioner['lastname'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header">
      <h3><?= e($parishioner['firstname']) ?> <?= e($parishioner['lastname']) ?></h3>
      <span class="badge badge-<?= $parishioner['status']==='active'?'approved':'rejected' ?>"><?= e($parishioner['status']) ?></span>
    </div>
    <p><strong>Email:</strong> <?= e($parishioner['email']) ?></p>
    <p><strong>Phone:</strong> <?= e($parishioner['phone'] ?: '—') ?></p>
    <p><strong>Address:</strong> <?= e($parishioner['address'] ?: '—') ?></p>
    <p><strong>Birthdate:</strong> <?= formatDate($parishioner['birthdate']) ?></p>
    <p><strong>Gender:</strong> <?= e($parishioner['gender'] ?: '—') ?></p>
    <p><strong>Marital Status:</strong> <?= e($parishioner['marital_status'] ?: '—') ?></p>
    <p><strong>Occupation:</strong> <?= e($parishioner['occupation'] ?: '—') ?></p>
    <p><strong>Emergency Contact:</strong> <?= e($parishioner['emergency_contact_name'] ?: '—') ?> <?= $parishioner['emergency_contact_number'] ? '(' . e($parishioner['emergency_contact_number']) . ')' : '' ?></p>
    <p><strong>Member Since:</strong> <?= formatDate($parishioner['created_at']) ?></p>
  </div>

  <?php if ($isAdmin): ?>
    <div class="card">
      <div class="card-header"><h3>Manage Account</h3></div>
      <form method="POST" action="<?= url('secretary/parishioner-detail.php?id=' . $id) ?>">
        <?= csrfField() ?>
        <div class="form-group">
          <label>Account Status</label>
          <select name="status" onchange="this.form.submit()">
            <option value="active" <?= $parishioner['status']==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $parishioner['status']==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="suspended" <?= $parishioner['status']==='suspended'?'selected':'' ?>>Suspended</option>
          </select>
        </div>
      </form>
      <p class="helper-text">To change this parishioner's role or permanently delete their account, use <a href="<?= url('admin/users.php') ?>">Users & Roles</a>.</p>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Appointment History</h3></div>
  <?php if (empty($appointments)): ?>
    <p class="text-muted text-center">No appointments yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Service</th><th>Date</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td>#<?= $a['appointment_id'] ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?> <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
              <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
