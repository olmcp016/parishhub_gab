<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT u.*, p.parishioner_id, p.marital_status, p.occupation
        FROM users u JOIN parishioners p ON u.user_id = p.user_id WHERE 1=1";
$params = [];
if ($search) {
    $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($statusFilter) {
    $sql .= ' AND u.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY u.lastname ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$parishioners = $stmt->fetchAll();

$active = 'parishioners';
$pageTitle = 'Manage Parishioners';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Parishioners</h3></div>
  <form method="GET" class="mb-3">
    <div class="form-row">
      <div class="form-group"><input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by name or email..."></div>
      <div class="form-group">
        <select name="status">
          <option value="">All Statuses</option>
          <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
        </select>
      </div>
      <div class="form-group" style="max-width:140px;"><button class="btn btn-primary btn-block">Search</button></div>
    </div>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Marital Status</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($parishioners as $p): ?>
          <tr>
            <td><?= e($p['firstname']) ?> <?= e($p['lastname']) ?></td>
            <td><?= e($p['email']) ?></td>
            <td><?= e($p['phone'] ?? '—') ?></td>
            <td><?= e($p['marital_status'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $p['status']==='active'?'approved':'rejected' ?>"><?= e($p['status']) ?></span></td>
            <td><a href="<?= url('secretary/parishioner-detail.php?id=' . $p['parishioner_id']) ?>" class="btn btn-outline btn-sm">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($parishioners)): ?><p class="text-muted text-center mt-3">No parishioners found.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
