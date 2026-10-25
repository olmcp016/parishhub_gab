<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/requirements-review.php';
requireRole('Secretary', 'Admin');

// Requirements revision queues (filter values "review:<status>"), see includes/requirements-review.php.
const REQUIREMENTS_REVIEW_FILTERS = ['review:pending_review' => 'Pending Review', 'review:awaiting_revision' => 'Awaiting Revision', 'review:ready_for_rereview' => 'Ready for Re-Review'];

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';

function buildAppointmentsQuery(string $statusFilter, string $search): array
{
    $sql = "SELECT a.*, s.service_name, s.category, u.firstname, u.lastname, u.email, st.status_name, p.full_name AS priest_name,
                   ic.status AS cert_status, ic.document_id AS cert_document_id
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            JOIN parishioners par ON a.parishioner_id = par.parishioner_id
            JOIN users u ON par.user_id = u.user_id
            JOIN appointment_status st ON a.status_id = st.status_id
            LEFT JOIN priests p ON a.priest_id = p.priest_id
            LEFT JOIN issued_certificates ic ON ic.appointment_id = a.appointment_id
            WHERE s.category != 'Donation'";
    $params = [];
    if ($statusFilter === 'action_needed') {
        $sql .= " AND (
            a.status_id = 1
            OR a.requirements_review_status = 'ready_for_rereview'
            OR (
                (s.category IN ('Wedding', 'Baptism', 'Confirmation', 'Funeral') OR s.service_name LIKE '%Certificate%')
                AND a.status_id IN (2, 4)
                AND (ic.document_id IS NULL OR ic.status NOT IN ('generated', 'ready_for_pickup', 'claimed'))
            )
        )";
    } elseif (str_starts_with($statusFilter, 'review:')) {
        $review = substr($statusFilter, 7);
        if ($review === 'pending_review') {
            $sql .= " AND a.status_id = 1 AND a.requirements_review_status IS NULL AND s.category NOT IN ('Mass Intention', 'Donation')";
        } elseif (in_array($review, ['awaiting_revision', 'ready_for_rereview'], true)) {
            $sql .= " AND a.status_id = 1 AND a.requirements_review_status = ? AND s.category NOT IN ('Mass Intention', 'Donation')";
            $params[] = $review;
        }
    } elseif ($statusFilter === 'Cancelled') {
        $sql .= " AND st.status_name IN ('Cancelled', 'Rejected')";
    } elseif ($statusFilter) {
        $sql .= " AND st.status_name = ?";
        $params[] = $statusFilter;
    }
    if ($search) {
        $hasOutsidePriest = outsidePriestAvailable();
        $outsideSql = $hasOutsidePriest ? ' OR a.outside_priest_name LIKE ?' : '';
        $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR a.guest_name LIKE ? OR s.service_name LIKE ?{$outsideSql})";
        $lk = likeSafe($search); $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
        if ($hasOutsidePriest) $params[] = $lk;
    }
    return [$sql, $params];
}

function fetchAppointmentsPage(string $statusFilter, string $search): array
{
    [$sql, $params] = buildAppointmentsQuery($statusFilter, $search);

    $countSql = preg_replace('/^SELECT\s+.*?\s+FROM\s+/is', 'SELECT COUNT(*) FROM ', $sql, 1);
    $countStmt = db()->prepare($countSql);
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
        <thead><tr><th>Parishioner</th><th>Service</th><th>Status</th><th>Processing</th><th style="text-align:right;">Action</th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a):
            $isCertEligible = in_array($a['category'], ['Wedding', 'Baptism', 'Confirmation', 'Funeral'], true) || str_contains($a['service_name'], 'Certificate');
            $hasDocs = !in_array($a['category'], ['Mass Intention', 'Donation'], true);
            $certDone = !empty($a['cert_document_id']) && in_array($a['cert_status'], ['generated', 'ready_for_pickup', 'claimed'], true);
            $needsCert = $isCertEligible && in_array((int) $a['status_id'], [2, 4], true) && !$certDone;
            $needsAction = (int) $a['status_id'] === 1 || $needsCert || ($a['requirements_review_status'] ?? '') === 'ready_for_rereview';
            $bookedDate = !empty($a['created_at']) ? date('M d, Y', strtotime($a['created_at'])) : '—';
          ?>
            <tr>
              <td>
                <div style="display:flex; align-items:flex-start; gap:8px;">
                  <?php if ($needsAction): ?>
                    <span title="Action needed" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#d97706; margin-top:5px; flex-shrink:0;"></span>
                  <?php else: ?>
                    <span title="Complete" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a; margin-top:5px; flex-shrink:0;"></span>
                  <?php endif; ?>
                  <div>
                    <div style="font-weight:600; font-size:13.5px; color:#2c3e50;">
                      <?php if ($a['guest_name']): ?>
                        <?= e($a['guest_name']) ?> <span class="text-muted" style="font-weight:normal; font-size:12px;">(guest)</span>
                      <?php else: ?>
                        <?= e($a['firstname']) ?> <?= e($a['lastname']) ?>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:12px; margin-top:2px;">
                      Booked: <?= e($bookedDate) ?><?php if (!empty($a['appointment_date']) && in_array($a['category'], ['Wedding', 'Baptism', 'Funeral'], true)): ?> &bull; Sched: <?= e(date('M d, Y', strtotime($a['appointment_date']))) ?><?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div style="font-weight:600; font-size:13.5px;"><?= e($a['service_name']) ?></div>
                <?php if (!empty($a['schedule_type'])): ?>
                  <span class="text-muted" style="font-size:11px;"><?= e($a['schedule_type']) ?> Schedule</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($a['category'] === 'Mass Intention'):
                  $display = massIntentionStatusDisplay($a['status_name'], true, $isSecretaryViewer);
                ?>
                  <span class="badge badge-<?= $display[1] ?>"><?= $display[0] ?></span>
                <?php else: ?>
                  <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
                <?php endif; ?>
                <?php if ((int) $a['status_id'] === 2 && !empty($a['appointment_date']) && $a['appointment_date'] < date('Y-m-d')): ?>
                  <div style="margin-top:3px;"><span class="badge badge-warning" style="background:#b45309; color:#fff; font-size:10px;">Needs Reschedule</span></div>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                  <?php if ((int) $a['status_id'] === 1): ?>
                    <?php if (($a['requirements_review_status'] ?? '') === 'ready_for_rereview'): ?>
                      <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:11.5px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:5px;" title="Applicant submitted updated documents">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                        Re-Review Ready
                      </span>
                    <?php elseif (($a['requirements_review_status'] ?? '') === 'awaiting_revision'): ?>
                      <span class="badge" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:11.5px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:5px;" title="Awaiting applicant revision">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Awaiting Revision
                      </span>
                    <?php elseif ($hasDocs): ?>
                      <span class="badge" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; font-size:11.5px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:5px;" title="Documents not yet verified">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Docs Pending
                      </span>
                    <?php else: ?>
                      <span class="badge badge-pending" style="font-size:11.5px; padding:3px 8px;">Pending Approval</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($hasDocs): ?>
                      <span class="badge badge-approved" style="font-size:11.5px; padding:3px 8px; display:inline-flex; align-items:center; gap:5px;" title="All documents verified">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Docs Verified
                      </span>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($isCertEligible): ?>
                    <?php if ($certDone): ?>
                      <span class="badge badge-verified" style="font-size:11.5px; padding:3px 8px; display:inline-flex; align-items:center; gap:5px;" title="Official Certificate generated">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Cert Ready
                      </span>
                    <?php elseif (in_array((int) $a['status_id'], [2, 4], true)): ?>
                      <span class="badge" style="background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; font-size:11.5px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:5px;" title="Certificate not yet generated">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Cert Needed
                      </span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
              <td style="text-align:right;"><a href="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= url('secretary/appointment-detail.php?id=' . $a['appointment_id']) ?>" data-title="<?= e('Appointment #' . $a['appointment_id'] . ' — ' . $a['service_name']) ?>">Review</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($appointments)): ?><p class="text-muted text-center mt-3">No appointments found.</p><?php endif; ?>
    <?php
}

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
    <div class="form-group" style="flex: 1 1 180px; margin-bottom:0;">
      <label>Filter by Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Appointments</option>
        <option value="action_needed" <?= $statusFilter === 'action_needed' ? 'selected' : '' ?>>● Action Needed</option>
        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending Review</option>
        <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
        <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
        <option value="Cancelled" <?= in_array($statusFilter, ['Cancelled', 'Rejected'], true) ? 'selected' : '' ?>>Cancelled / Rejected</option>
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

  <div data-live-refresh-id="secretary-appointments-table">
    <?php renderAppointmentsTable($appointments, $isSecretaryViewer); ?>
    <?php if (!empty($appointments)): ?>
      <?= renderPagination($pagination, $paginationUrl) ?>
    <?php endif; ?>
  </div>
</div>


<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/secretary-modal-shells.php'; ?>
<script src="<?= url('public/js/live-refresh.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/live-refresh.js') ?>"></script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
