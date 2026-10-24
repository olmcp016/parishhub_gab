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
            OR a.requirements_review_status IN ('pending_review', 'ready_for_rereview')
            OR (
                (s.category IN ('Wedding', 'Baptism', 'Confirmation', 'Funeral') OR s.service_name LIKE '%Certificate%')
                AND a.status_id IN (2, 4)
                AND (ic.document_id IS NULL OR ic.status NOT IN ('generated', 'ready_for_pickup', 'claimed'))
            )
        )";
    } elseif ($statusFilter === 'cert_needed') {
        $sql .= " AND (s.category IN ('Wedding', 'Baptism', 'Confirmation', 'Funeral') OR s.service_name LIKE '%Certificate%')
                  AND a.status_id IN (2, 4)
                  AND (ic.document_id IS NULL OR ic.status NOT IN ('generated', 'ready_for_pickup', 'claimed'))";
    } elseif (isset(REQUIREMENTS_REVIEW_FILTERS[$statusFilter])) {
        // Same rules as the dashboard cards: pending appointments the Secretary reviews.
        $sql .= " AND a.status_id = 1 AND s.category NOT IN ('Mass Intention', 'Donation')";
        $reviewStatus = substr($statusFilter, strlen('review:'));
        if ($reviewStatus === 'pending_review') {
            if (requirementsReviewAvailable()) $sql .= ' AND a.requirements_review_status IS NULL';
        } elseif (requirementsReviewAvailable()) {
            $sql .= ' AND a.requirements_review_status = ?';
            $params[] = $reviewStatus;
        } else {
            $sql .= ' AND 1 = 0'; // queue does not exist until the database update is run
        }
    } elseif ($statusFilter) {
        $sql .= ' AND st.status_name = ?';
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
        <thead><tr><th>Parishioner</th><th>Service</th><th>Date</th><th>Priest</th><th>Status</th><th>Processing</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a):
            $isCertEligible = in_array($a['category'], ['Wedding', 'Baptism', 'Confirmation', 'Funeral'], true) || str_contains($a['service_name'], 'Certificate');
            $hasDocs = !in_array($a['category'], ['Mass Intention', 'Donation'], true);
            $certDone = !empty($a['cert_document_id']) && in_array($a['cert_status'], ['generated', 'ready_for_pickup', 'claimed'], true);
            $needsCert = $isCertEligible && in_array((int) $a['status_id'], [2, 4], true) && !$certDone;
            $needsDocs = (int) $a['status_id'] === 1 && $hasDocs;
            $needsAction = (int) $a['status_id'] === 1 || $needsCert || ($a['requirements_review_status'] ?? '') === 'ready_for_rereview';
          ?>
            <tr>
              <td>
                <div style="display:flex; align-items:flex-start; gap:6px;">
                  <?php if ($needsAction): ?>
                    <span title="Incomplete: action needed" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#d97706; margin-top:5px; flex-shrink:0;"></span>
                  <?php else: ?>
                    <span title="Processing complete" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a; margin-top:5px; flex-shrink:0;"></span>
                  <?php endif; ?>
                  <div>
                    <?php if ($a['guest_name']): ?>
                      <?= e($a['guest_name']) ?> <span class="text-muted">(guest)</span><br><span class="text-muted" style="font-size:12px;"><?= e($a['guest_email'] ?: $a['guest_phone']) ?></span>
                    <?php else: ?>
                      <?= e($a['firstname']) ?> <?= e($a['lastname']) ?><br><span class="text-muted" style="font-size:12px;"><?= e($a['email']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= !empty($a['appointment_date']) ? e(date('M d, Y', strtotime($a['appointment_date']))) : '—' ?></td>
              <td>
                <?php if (!empty($a['outside_priest_name'])): ?>
                  <?= e($a['outside_priest_name']) ?> <span class="text-muted" style="font-size:11px;">(Preferred Priest)</span>
                <?php else: ?>
                  <?= e($a['priest_name'] ?? '—') ?>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                  <?php if ($a['schedule_type']): ?><span class="badge badge-<?= strtolower($a['schedule_type']) ?>"><?= e($a['schedule_type']) ?></span><?php endif; ?>
                  <?php if ($a['category'] === 'Mass Intention'):
                    $display = massIntentionStatusDisplay($a['status_name'], true, $isSecretaryViewer);
                  ?>
                    <span class="badge badge-<?= $display[1] ?>"><?= $display[0] ?></span>
                  <?php else: ?>
                    <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
                  <?php endif; ?>
                  <?php if ((int) $a['status_id'] === 2 && !empty($a['appointment_date']) && $a['appointment_date'] < date('Y-m-d')): ?>
                    <span class="badge badge-warning" style="background:#b45309;color:#fff;">Needs Reschedule</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                  <?php if ((int) $a['status_id'] === 1): ?>
                    <?php if (($a['requirements_review_status'] ?? '') === 'ready_for_rereview'): ?>
                      <span class="badge" style="background:#0284c7; color:#fff; font-size:11px; padding:2px 6px;" title="Applicant submitted updated documents">🔄 Re-Review Ready</span>
                    <?php elseif (($a['requirements_review_status'] ?? '') === 'awaiting_revision'): ?>
                      <span class="badge badge-rejected" style="font-size:11px; padding:2px 6px;" title="Awaiting applicant revision">⏳ Awaiting Revision</span>
                    <?php elseif ($hasDocs): ?>
                      <span class="badge badge-pending" style="font-size:11px; padding:2px 6px;" title="Documents not yet verified">⚠️ Docs Pending</span>
                    <?php else: ?>
                      <span class="badge badge-pending" style="font-size:11px; padding:2px 6px;">Pending Approval</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($hasDocs): ?>
                      <span class="badge badge-approved" style="font-size:11px; padding:2px 6px;" title="All documents verified">✓ Docs Verified</span>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($isCertEligible): ?>
                    <?php if ($certDone): ?>
                      <span class="badge badge-verified" style="font-size:11px; padding:2px 6px;" title="Official Certificate generated">✓ Cert Ready</span>
                    <?php elseif (in_array((int) $a['status_id'], [2, 4], true)): ?>
                      <span class="badge" style="background:#ea580c; color:#fff; font-size:11px; padding:2px 6px;" title="Certificate not yet generated">⏳ Cert Pending</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
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
    <div class="form-group" style="flex: 1 1 180px; margin-bottom:0;">
      <label>Filter by Status / Processing</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Appointments</option>
        <option value="action_needed" <?= $statusFilter === 'action_needed' ? 'selected' : '' ?>>● Incomplete / Action Needed</option>
        <option value="cert_needed" <?= $statusFilter === 'cert_needed' ? 'selected' : '' ?>>⏳ Pending Certificate Generation</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s['status_name']) ?>" <?= $statusFilter===$s['status_name']?'selected':'' ?>><?= e($s['status_name']) ?></option>
        <?php endforeach; ?>
        <?php foreach (REQUIREMENTS_REVIEW_FILTERS as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>Requirements: <?= e($label) ?></option>
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
