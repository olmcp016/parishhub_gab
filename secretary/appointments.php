<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';

/**
 * The main list only ever shows appointments whose date hasn't passed yet
 * (sorted newest-requested-first) — once the date passes, it drops off here
 * automatically and only ever shows up in the History modal instead, so the
 * working list never accumulates old, no-longer-actionable rows. History is
 * its own AJAX-loaded fragment (same page, ?history=1) with its own filters
 * and pagination, requested only from inside the History modal — a normal
 * page load always shows the upcoming list regardless of that param.
 */
$isHistoryFragment = isDetailModalRequest() && ($_GET['history'] ?? '') === '1';

function buildAppointmentsQuery(bool $history, string $statusFilter, string $search): array
{
    $sql = "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            JOIN parishioners par ON a.parishioner_id = par.parishioner_id
            JOIN users u ON par.user_id = u.user_id
            JOIN appointment_status st ON a.status_id = st.status_id
            LEFT JOIN priests p ON a.priest_id = p.priest_id
            WHERE s.category != 'Donation' AND a.appointment_date " . ($history ? '<' : '>=') . ' CURRENT_DATE';
    $params = [];
    if ($statusFilter) {
        $sql .= ' AND st.status_name = ?';
        $params[] = $statusFilter;
    }
    if ($search) {
        $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR a.guest_name LIKE ? OR s.service_name LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    return [$sql, $params];
}

function fetchAppointmentsPage(bool $history, string $statusFilter, string $search): array
{
    [$sql, $params] = buildAppointmentsQuery($history, $statusFilter, $search);

    $countStmt = db()->prepare(str_replace(
        'SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name',
        'SELECT COUNT(*)',
        $sql
    ));
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), 10);

    $sql .= ' ORDER BY ' . ($history ? 'a.appointment_date DESC' : 'a.created_at DESC') . ' LIMIT ? OFFSET ?';
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
        <thead><tr><th>#</th><th>Parishioner</th><th>Service</th><th>Date</th><th>Priest</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td>#<?= $a['appointment_id'] ?></td>
              <td>
                <?php if ($a['guest_name']): ?>
                  <?= e($a['guest_name']) ?> <span class="text-muted">(guest)</span><br><span class="text-muted" style="font-size:12px;"><?= e($a['guest_email'] ?: $a['guest_phone']) ?></span>
                <?php else: ?>
                  <?= e($a['firstname']) ?> <?= e($a['lastname']) ?><br><span class="text-muted" style="font-size:12px;"><?= e($a['email']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?> <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
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

if ($isHistoryFragment) {
    // ---- AJAX fragment for the History modal only — bare content, no page shell ----
    [$historyAppointments, $historyPagination] = fetchAppointmentsPage(true, $statusFilter, $search);
    $historyPaginationUrl = url('secretary/appointments.php') . '?' . http_build_query(array_filter(['status' => $statusFilter, 'search' => $search])) . '&history=1';
    ?>
    <form method="GET" action="<?= url('secretary/appointments.php') ?>" class="form-row mb-3 js-history-filter">
      <input type="hidden" name="history" value="1">
      <div class="form-group">
        <label>Filter by Status</label>
        <select name="status">
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
      <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Search</button></div>
    </form>
    <?php renderAppointmentsTable($historyAppointments, $isSecretaryViewer); ?>
    <?= renderPagination($historyPagination, $historyPaginationUrl) ?>
    <?php
    exit;
}

// ---- Normal page load — the upcoming (not-yet-past-due) list ----
[$appointments, $pagination] = fetchAppointmentsPage(false, $statusFilter, $search);
$paginationUrl = url('secretary/appointments.php') . '?' . http_build_query(array_filter(['status' => $statusFilter, 'search' => $search]));

$active = 'appointments';
$pageTitle = 'Manage Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>All Appointments</h3>
    <button type="button" class="btn btn-outline btn-sm" onclick="openHistoryModal()">🕒 History</button>
  </div>
  <p class="helper-text" style="margin-top:-6px;">Shows only upcoming (not yet past-due) requests, newest requested first. Once an appointment's date has passed, it moves to History automatically.</p>

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

  <?php renderAppointmentsTable($appointments, $isSecretaryViewer); ?>
  <?php if (!empty($appointments)): ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>

<dialog class="modal modal-xl" id="historyModal">
  <div class="modal-head">
    <h3>Appointment History</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('historyModal').close()">✕</button>
  </div>
  <div class="modal-body" id="historyModalBody"></div>
</dialog>

<script>
function loadHistoryFragment(url) {
  var body = document.getElementById('historyModalBody');
  body.innerHTML = '<p class="text-muted" style="padding:30px; text-align:center;">Loading…</p>';
  fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (res) { return res.text(); })
    .then(function (html) { body.innerHTML = html; })
    .catch(function () {
      body.innerHTML = '<p class="text-muted" style="padding:30px; text-align:center;">Could not load history. Please try again.</p>';
    });
}
function openHistoryModal() {
  document.getElementById('historyModal').showModal();
  loadHistoryFragment('<?= url('secretary/appointments.php') ?>?history=1');
}
document.addEventListener('DOMContentLoaded', function () {
  var body = document.getElementById('historyModalBody');
  // Pagination links and the filter form inside the History fragment must
  // stay inside the modal (re-fetching in place) instead of navigating the
  // whole page away.
  body.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    if (link && body.contains(link)) {
      e.preventDefault();
      loadHistoryFragment(link.getAttribute('href'));
    }
  });
  body.addEventListener('submit', function (e) {
    var form = e.target.closest('.js-history-filter');
    if (!form) return;
    e.preventDefault();
    var qs = new URLSearchParams(new FormData(form)).toString();
    loadHistoryFragment(form.getAttribute('action') + '?' + qs);
  });
});
</script>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
