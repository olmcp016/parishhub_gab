<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';

function buildAppointmentsQuery(string $statusFilter, string $search): array
{
    $sql = "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            JOIN parishioners par ON a.parishioner_id = par.parishioner_id
            JOIN users u ON par.user_id = u.user_id
            JOIN appointment_status st ON a.status_id = st.status_id
            LEFT JOIN priests p ON a.priest_id = p.priest_id
            WHERE s.category != 'Donation'";
    $params = [];
    if ($statusFilter) {
        $sql .= ' AND st.status_name = ?';
        $params[] = $statusFilter;
    }
    if ($search) {
        $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR a.guest_name LIKE ? OR s.service_name LIKE ?)';
        $lk = likeSafe($search); $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    }
    return [$sql, $params];
}

function fetchAppointmentsPage(string $statusFilter, string $search): array
{
    [$sql, $params] = buildAppointmentsQuery($statusFilter, $search);

    $countStmt = db()->prepare(str_replace(
        'SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name',
        'SELECT COUNT(*)',
        $sql
    ));
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), 10);

    $sql .= ' ORDER BY a.created_at DESC LIMIT ? OFFSET ?';
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    return [$stmt->fetchAll(), $pagination];
}

/** The shared table markup for both the main list and the History fragment. */
function renderAppointmentsTable(array $appointments, bool $isSecretaryViewer): void
{
    ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Parishioner</th><th>Service</th><th>Date</th><th>Priest</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td>
                <?php if ($a['guest_name']): ?>
                  <?= e($a['guest_name']) ?> <span class="text-muted">(guest)</span><br><span class="text-muted" style="font-size:12px;"><?= e($a['guest_email'] ?: $a['guest_phone']) ?></span>
                <?php else: ?>
                  <?= e($a['firstname']) ?> <?= e($a['lastname']) ?><br><span class="text-muted" style="font-size:12px;"><?= e($a['email']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?><?= $a['appointment_time'] ? ' ' . date('g:i A', strtotime($a['appointment_time'])) : '' ?></td>
              <td><?= e($a['priest_name'] ?? '—') ?></td>
              <td>
                <?php if ($a['schedule_type']): ?><span class="badge badge-<?= strtolower($a['schedule_type']) ?>"><?= e($a['schedule_type']) ?></span><?php endif; ?>
                <?php if ($a['category'] === 'Mass Intention'):
                  // Mass Intention payments are the Cashier's responsibility —
                  // Secretary only sees whether it's approved yet, not the payment stage.
                  $display = massIntentionStatusDisplay($a['status_name'], true, $isSecretaryViewer);
                ?>
                  <span class="badge badge-<?= $display[1] ?>"><?= $display[0] ?></span>
                <?php else: ?>
                  <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
                <?php endif; ?>
              </td>
              <td><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" data-title="<?= e('Appointment #' . $a['appointment_id'] . ' — ' . $a['service_name']) ?>">Review</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($appointments)): ?><p class="text-muted text-center mt-3">No appointments found.</p><?php endif; ?>
    <?php
}

$statuses = db()->query('SELECT * FROM appointment_status')->fetchAll();

[$appointments, $pagination] = fetchAppointmentsPage($statusFilter, $search);
$paginationUrl = url('secretary/appointments.php') . '?' . http_build_query(array_filter(['status' => $statusFilter, 'search' => $search]));

$active = 'appointments';
$pageTitle = 'Manage Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>All Appointments</h3>
  </div>

  <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:16px;">
    <div class="form-group" style="flex: 1 1 150px; margin-bottom:0;">
      <label>Filter by Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s['status_name']) ?>" <?= $statusFilter===$s['status_name']?'selected':'' ?>><?= e($s['status_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex: 2 1 250px; margin-bottom:0;">
      <label>Search</label>
      <input type="text" name="search" value="<?= e($search) ?>" placeholder="Name or service...">
    </div>
    <div class="form-group" style="flex: 0 0 auto; margin-bottom:0;">
      <button class="btn btn-primary" style="padding:12px 24px; height:47.5px;">Search</button>
    </div>
  </form>

  <?php renderAppointmentsTable($appointments, $isSecretaryViewer); ?>
  <?php if (!empty($appointments)): ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>


<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/secretary-modal-shells.php'; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
