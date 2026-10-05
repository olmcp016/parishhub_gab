<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

$statusFilter = $_GET['status'] ?? '';

function fetchMyAppointmentsPage(int $parishionerId, string $statusFilter): array
{
    $sql = "SELECT a.*, s.service_name, s.fee, s.category, st.status_name, p.full_name AS priest_name
            FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            JOIN appointment_status st ON a.status_id = st.status_id
            LEFT JOIN priests p ON a.priest_id = p.priest_id
            WHERE a.parishioner_id = ? AND s.category != 'Donation'";
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

function renderMyAppointmentsTable(array $appointments): void
{
    ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Service</th><th>Date & Time</th><th>Priest</th><th>Fee</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td><?= e($a['guest_reference'] ?: 'Appointment #' . (int) $a['appointment_id']) ?></td>
              <td><?= e($a['service_name']) ?></td>
              <td><?= formatDate($a['appointment_date']) ?><?= $a['appointment_time'] ? ' · ' . date('g:i A', strtotime($a['appointment_time'])) : '' ?></td>
              <td><?= e($a['priest_name'] ?? '—') ?></td>
              <td><?php if ($a['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification<?php elseif (!empty($a['fee_snapshot'])): ?><?= money((float) ((json_decode($a['fee_snapshot'], true)['total'] ?? 0))) ?><?php else: ?><?= money($a['fee']) ?><?php endif; ?></td>
              <td>
                <?php if ($a['schedule_type']): ?><span class="badge badge-<?= strtolower($a['schedule_type']) ?>"><?= e($a['schedule_type']) ?></span><?php endif; ?>
                <?php if ($a['category'] === 'Mass Intention'): $miStatus = massIntentionStatusDisplay($a['status_name']); ?>
                  <span class="badge badge-<?= $miStatus[1] ?>"><?= e($miStatus[0]) ?></span>
                  <?php if ($a['status_name'] === 'Rejected' && $a['rejection_reason']): ?>
                    <div style="font-size:12px; margin-top:4px; color:var(--danger); max-width:200px; line-height:1.4;">
                      <strong>Reason:</strong> <?= e($a['rejection_reason']) ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge-<?= badgeClass($a['status_name']) ?>"><?= e($a['status_name']) ?></span>
                  <?php if ($a['status_name'] === 'Rejected' && $a['rejection_reason']): ?>
                    <div style="font-size:12px; margin-top:4px; color:var(--danger); max-width:200px; line-height:1.4;">
                      <strong>Reason:</strong> <?= e($a['rejection_reason']) ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php $detailUrl = url('parishioner/appointment-detail.php?id=' . $a['appointment_id']); ?>
                <?php $detailTitle = e($a['service_name'] . ' — #' . $a['appointment_id']); ?>
                <?php if ($a['status_name'] === 'Approved' && $a['category'] !== 'Mass Intention'): ?>
                  <a href="<?= $detailUrl ?>" class="btn btn-primary btn-sm js-view-modal" data-url="<?= $detailUrl ?>" data-title="<?= $detailTitle ?>">Proceed to Payment</a>
                <?php elseif ($a['status_name'] === 'Rejected'): ?>
                  <a href="<?= $detailUrl ?>" class="btn btn-outline btn-sm js-view-modal" data-url="<?= $detailUrl ?>" data-title="<?= $detailTitle ?>">View Details</a>
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

$paginationUrl = url('parishioner/appointments.php') . '?' . http_build_query(array_filter(['status' => $statusFilter]));
[$appointments, $pagination] = fetchMyAppointmentsPage($parishionerId, $statusFilter);

$active = 'appointments';
$pageTitle = 'My Appointments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?><div class="card">
  <div class="card-header" style="flex-wrap:wrap; gap:16px; justify-content:space-between;">
    <h3>My Appointments</h3>
    <form method="GET" action="<?= url('parishioner/appointments.php') ?>" style="display:flex; gap:8px; align-items:center;">
      <select name="status" class="form-control form-control-sm" style="min-width:150px; height:34px;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="Pending" <?= $statusFilter==='Pending'?'selected':'' ?>>Pending</option>
        <option value="Approved" <?= $statusFilter==='Approved'?'selected':'' ?>>Approved</option>
        <option value="Payment Verified" <?= $statusFilter==='Payment Verified'?'selected':'' ?>>Payment Verified</option>
        <option value="Confirmed" <?= $statusFilter==='Confirmed'?'selected':'' ?>>Confirmed</option>
        <option value="Completed" <?= $statusFilter==='Completed'?'selected':'' ?>>Completed</option>
        <option value="Cancelled" <?= $statusFilter==='Cancelled'?'selected':'' ?>>Cancelled</option>
        <option value="Rejected" <?= $statusFilter==='Rejected'?'selected':'' ?>>Rejected</option>
      </select>
      <a href="<?= url('parishioner/services.php') ?>" class="btn btn-primary btn-sm">+ New Booking</a>
    </form>
  </div>

  <?php if (empty($appointments)): ?>
    <div class="empty-state">
      <div class="icon">📅</div>
      <p>You haven't booked any appointments yet.</p>
    </div>
  <?php else: ?>
    <?php renderMyAppointmentsTable($appointments); ?>
    <?= renderPagination($pagination, $paginationUrl) ?>
  <?php endif; ?>
</div>


<?php include __DIR__ . '/../includes/detail-modal.php'; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
