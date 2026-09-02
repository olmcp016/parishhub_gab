<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name
        FROM appointments a
        JOIN services s ON a.service_id = s.service_id
        JOIN parishioners par ON a.parishioner_id = par.parishioner_id
        JOIN users u ON par.user_id = u.user_id
        JOIN appointment_status st ON a.status_id = st.status_id
        LEFT JOIN priests p ON a.priest_id = p.priest_id
        WHERE 1=1";
$params = [];

if ($statusFilter) {
    $sql .= ' AND st.status_name = ?';
    $params[] = $statusFilter;
}
if ($search) {
    $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR s.service_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= ' ORDER BY a.appointment_date DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$statuses = db()->query('SELECT * FROM appointment_status')->fetchAll();

$active = 'appointments';
$pageTitle = 'Manage Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>All Appointments</h3></div>

  <form method="GET" class="form-row mb-3">
    <div class="form-group">
      <label>Filter by Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s['status_name']) ?>" <?= $statusFilter===$s['status_name']?'selected':'' ?>><?= e($s['status_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Search</label>
      <input type="text" name="search" value="<?= e($search) ?>" placeholder="Name or service...">
    </div>
    <div class="form-group" style="align-self:end;">
      <button class="btn btn-primary">Search</button>
    </div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Parishioner</th><th>Service</th><th>Date</th><th>Priest</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($appointments as $a): ?>
          <tr>
            <td>#<?= $a['appointment_id'] ?></td>
            <td><?= e($a['firstname']) ?> <?= e($a['lastname']) ?><br><span class="text-muted" style="font-size:12px;"><?= e($a['email']) ?></span></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= formatDate($a['appointment_date']) ?> <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
            <td><?= e($a['priest_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span></td>
            <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($appointments)): ?><p class="text-muted text-center mt-3">No appointments found.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
