<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

/**
 * The main list only ever shows appointments whose date hasn't passed yet
 * (newest requested first) — once the date passes, it drops off here
 * automatically and only ever shows up in the History modal instead, same
 * pattern as secretary/appointments.php. History is its own AJAX-loaded
 * fragment (same page, ?history=1) with its own status filter and
 * pagination; a normal page load always shows the upcoming list.
 */
$isHistoryFragment = isDetailModalRequest() && ($_GET['history'] ?? '') === '1';
$statusFilter = $_GET['status'] ?? '';

function fetchMyAppointmentsPage(int $parishionerId, bool $history, string $statusFilter): array
{
    $sql = "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            JOIN appointment_status st ON a.status_id = st.status_id
            LEFT JOIN priests p ON a.priest_id = p.priest_id
            WHERE a.parishioner_id = ? AND s.category != 'Donation' AND a.appointment_date " . ($history ? '<' : '>=') . ' CURRENT_DATE';
    $params = [$parishionerId];
    if ($statusFilter) {
        $sql .= ' AND st.status_name = ?';
        $params[] = $statusFilter;
    }

    $countSql = str_replace(
        "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name",
        'SELECT COUNT(*)',
        $sql
    );
    $countStmt = db()->prepare($countSql);
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

function renderMyAppointmentsTable(array $appointments): void
{
    ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Service</th><th>Date & Time</th><th>Priest</th><th>Fee</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td>#<?= $a['appointment_id'] ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?> · <?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><?= e($a['priest_name'] ?? '—') ?></td>
              <td><?= money($a['fee']) ?></td>
              <td>
                <?php if ($a['schedule_type']): ?><span class="badge badge-<?= strtolower($a['schedule_type']) ?>"><?= e($a['schedule_type']) ?></span><?php endif; ?>
                <?php if ($a['category'] === 'Mass Intention'): $miStatus = massIntentionStatusDisplay($a['status_name']); ?>
                  <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
                <?php else: ?>
                  <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php $detailUrl = url('parishioner/appointment-detail.php?id=' . $a['appointment_id']); ?>
                <?php $detailTitle = e($a['service_name'] . ' — #' . $a['appointment_id']); ?>
                <?php if ($a['status_name'] === 'Approved' && $a['category'] !== 'Mass Intention'): ?>
                  <a href="<?= $detailUrl ?>" class="btn btn-primary btn-sm js-view-modal" data-url="<?= $detailUrl ?>" data-title="<?= $detailTitle ?>">Proceed to Payment</a>
                <?php elseif ($a['status_name'] === 'Rejected'): ?>
                  <a href="<?= $detailUrl ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= $detailUrl ?>" data-title="<?= $detailTitle ?>">Update Documents</a>
                <?php else: ?>
                  <a href="<?= $detailUrl ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= $detailUrl ?>" data-title="<?= $detailTitle ?>">View</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($appointments)): ?><p class="text-muted text-center mt-3">Nothing here.</p><?php endif; ?>
    <?php
}

if ($isHistoryFragment) {
    // ---- AJAX fragment for the History modal only — bare content, no page shell ----
    [$historyAppointments, $historyPagination] = fetchMyAppointmentsPage($parishionerId, true, $statusFilter);
    $historyPaginationUrl = url('parishioner/appointments.php') . '?' . http_build_query(array_filter(['status' => $statusFilter])) . '&history=1';
    ?>
    <form method="GET" action="<?= url('parishioner/appointments.php') ?>" class="form-row mb-3 js-history-filter">
      <input type="hidden" name="history" value="1">
      <div class="form-group">
        <label>Filter by Status</label>
        <select name="status">
          <option value="">All</option>
          <option value="Completed" <?= $statusFilter==='Completed'?'selected':'' ?>>Completed</option>
          <option value="Cancelled" <?= $statusFilter==='Cancelled'?'selected':'' ?>>Cancelled</option>
          <option value="Rejected" <?= $statusFilter==='Rejected'?'selected':'' ?>>Rejected</option>
        </select>
      </div>
      <div class="form-group" style="align-self:end;"><button class="btn btn-primary">Filter</button></div>
    </form>
    <?php renderMyAppointmentsTable($historyAppointments); ?>
    <?= renderPagination($historyPagination, $historyPaginationUrl) ?>
    <?php
    exit;
}

// ---- Normal page load — the upcoming (not-yet-past-due) list ----
[$appointments, $pagination] = fetchMyAppointmentsPage($parishionerId, false, '');

$active = 'appointments';
$pageTitle = 'My Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header">
    <h3>My Appointments</h3>
    <div class="flex gap-2">
      <button type="button" class="btn btn-outline btn-sm" onclick="openHistoryModal()">🕒 History</button>
      <a href="<?= url('parishioner/services.php') ?>" class="btn btn-primary btn-sm">+ New Booking</a>
    </div>
  </div>

  <?php if (empty($appointments)): ?>
    <div class="empty-state">
      <div class="icon">📅</div>
      <p>You haven't booked any appointments yet.</p>
    </div>
  <?php else: ?>
    <?php renderMyAppointmentsTable($appointments); ?>
    <?= renderPagination($pagination, url('parishioner/appointments.php')) ?>
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
  loadHistoryFragment('<?= url('parishioner/appointments.php') ?>?history=1');
}
document.addEventListener('DOMContentLoaded', function () {
  var body = document.getElementById('historyModalBody');
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
