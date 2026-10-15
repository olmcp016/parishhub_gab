<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/secretary-reports.php';
requireRole('Secretary', 'Admin');

$filters = reportFilters($_GET);
$report = reportData($filters);
$serviceOptions = db()->query("SELECT service_id, service_name FROM services WHERE category <> 'Donation' ORDER BY service_name")->fetchAll();
$serviceNames = array_column($serviceOptions, 'service_name', 'service_id');
$statusNames = array_map(static fn(array $s): string => $s['name'], $report['statuses']);
$filterSummary = reportFilterSummary($filters, $serviceNames, $statusNames);

if (($_GET['export'] ?? '') === 'pdf') {
    try {
        $pdfBytes = reportPdf($filters, $report, $filterSummary);
    } catch (Throwable $e) {
        error_log('Secretary report PDF failed: ' . $e->getMessage());
        flash('error', 'The PDF could not be created. Please try again or use Print Report.');
        redirect(url('secretary/reports.php') . '?' . http_build_query(reportQuery($filters)));
    }
    $filename = 'ParishHub_Secretary_Report_' . $filters['from'] . '_to_' . $filters['to'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $pdfBytes;
    exit;
}

$viewButton = static function (array $row): string {
    $detailUrl = url('secretary/appointment-detail.php?id=' . (int) $row['appointment_id']);
    return '<a href="' . e($detailUrl) . '" class="btn btn-outline btn-sm js-view-modal" data-url="' . e($detailUrl) . '" data-title="'
        . e('Appointment #' . (int) $row['appointment_id'] . ' — ' . $row['service_name']) . '">View</a>';
};
$statusBadge = static fn(array $row): string => '<span class="badge badge-' . e(badgeClass($row['status_name'])) . '">' . e($row['status_name']) . '</span>';
$reviewBadge = static function (?string $status): string {
    $state = requirementsReviewState($status, ['rejected' => 0, 'pending' => 0, 'approved' => 0]);
    return '<span class="badge badge-' . e($state['badge']) . '">' . e(reportReviewLabel($status)) . '</span>';
};
$rc = $report['requirement_counts'];
$af = $report['approval_flags'];
$serviceTotals = [
    'completed' => array_sum(array_column($report['services'], 'completed')),
    'upcoming' => array_sum(array_column($report['services'], 'upcoming')),
    'cancelled' => array_sum(array_column($report['services'], 'cancelled')),
];

$active = 'reports';
$pageTitle = 'Appointment Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="print-only report-print-head">
  <h2>Our Lady of Mt. Carmel Parish — Secretary Report</h2>
  <p><?= e($filterSummary) ?></p>
  <p>Generated <?= e(date('M j, Y g:i A')) ?></p>
</div>

<div class="card no-print">
  <div class="card-header">
    <h3>Report Filters</h3>
    <div class="flex gap-3">
      <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print Report</button>
      <a class="btn btn-primary btn-sm" href="<?= e(url('secretary/reports.php') . '?' . http_build_query(reportQuery($filters, ['export' => 'pdf']))) ?>">⬇ Export PDF</a>
    </div>
  </div>
  <form method="GET" action="<?= url('secretary/reports.php') ?>" class="report-filters">
    <div class="form-group">
      <label for="reportPeriod">Period</label>
      <select name="period" id="reportPeriod">
        <?php foreach (REPORT_PERIODS as $value => $label): ?><option value="<?= e($value) ?>" <?= $filters['period'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" data-report-period="daily weekly monthly yearly">
      <label for="reportDate">Date within period</label>
      <input type="date" name="date" id="reportDate" value="<?= e($filters['date']) ?>">
    </div>
    <div class="form-group" data-report-period="custom">
      <label for="reportFrom">From</label>
      <input type="date" name="from" id="reportFrom" value="<?= e($filters['from']) ?>">
    </div>
    <div class="form-group" data-report-period="custom">
      <label for="reportTo">To</label>
      <input type="date" name="to" id="reportTo" value="<?= e($filters['to']) ?>">
    </div>
    <div class="form-group">
      <label for="reportService">Service</label>
      <select name="service" id="reportService">
        <option value="">All services</option>
        <?php foreach ($serviceOptions as $s): ?><option value="<?= (int) $s['service_id'] ?>" <?= $filters['service_id'] === (int) $s['service_id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="reportStatus">Appointment Status</label>
      <select name="status" id="reportStatus">
        <option value="">All statuses</option>
        <?php foreach ($report['statuses'] as $id => $s): ?><option value="<?= (int) $id ?>" <?= $filters['status_id'] === (int) $id ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="reportRef">Booking Reference</label>
      <input type="text" name="ref" id="reportRef" value="<?= e($filters['ref']) ?>" placeholder="e.g. PH-1A2B3C4D" maxlength="40">
    </div>
    <div class="form-group report-filter-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <a href="<?= url('secretary/reports.php') ?>" class="btn btn-outline">Reset</a>
    </div>
  </form>
  <p class="text-muted report-scope">
    Showing <strong><?= e($filters['label']) ?></strong>. Bookings are counted by <strong>booking date</strong>; the Service Schedule uses the <strong>scheduled service date</strong>. Donations are not counted as appointments.
  </p>
</div>

<!-- 1. Appointment Summary -->
<div class="card">
  <div class="card-header"><h3>1. Appointment Summary</h3> <span class="report-basis">By booking date</span></div>
  <div class="stat-grid">
    <div class="stat-card dark"><div class="stat-label">Total Appointments</div><div class="stat-value"><?= (int) $report['total'] ?></div></div>
    <?php foreach ($report['statuses'] as $s): ?>
      <div class="stat-card"><div class="stat-label"><?= e($s['name']) ?></div><div class="stat-value"><?= (int) $s['count'] ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php if ($report['mass_intentions']): ?><p class="text-muted report-note">Includes <?= (int) $report['mass_intentions'] ?> Mass Intention<?= $report['mass_intentions'] === 1 ? '' : 's' ?>.</p><?php endif; ?>
  <h4 class="report-subhead">Bookings by <?= $report['trend_by_month'] ? 'Month' : 'Day' ?></h4>
  <?php if ($report['trend']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th><?= $report['trend_by_month'] ? 'Month' : 'Booking Date' ?></th><th>Bookings</th></tr></thead>
        <tbody>
          <?php foreach ($report['trend'] as $t): ?><tr><td><?= e($t['label']) ?></td><td><?= (int) $t['count'] ?></td></tr><?php endforeach; ?>
          <tr class="report-total"><td>Total</td><td><?= (int) $report['total'] ?></td></tr>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No appointments were booked in this period<?= $filters['service_id'] || $filters['status_id'] || $filters['ref'] !== '' ? ' that match these filters' : '' ?>.</p>
  <?php endif; ?>
</div>

<!-- 2. Services Summary -->
<div class="card">
  <div class="card-header"><h3>2. Services Summary</h3> <span class="report-basis">By booking date</span></div>
  <?php if ($report['services']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Category</th><th>Total Bookings</th><th>Completed</th><th>Upcoming</th><th>Cancelled</th></tr></thead>
        <tbody>
          <?php foreach ($report['services'] as $s): ?>
            <tr><td><?= e($s['service']) ?></td><td><?= e($s['category']) ?></td><td><?= (int) $s['total'] ?></td><td><?= (int) $s['completed'] ?></td><td><?= (int) $s['upcoming'] ?></td><td><?= (int) $s['cancelled'] ?></td></tr>
          <?php endforeach; ?>
          <tr class="report-total"><td>Total</td><td></td><td><?= (int) $report['total'] ?></td><td><?= $serviceTotals['completed'] ?></td><td><?= $serviceTotals['upcoming'] ?></td><td><?= $serviceTotals['cancelled'] ?></td></tr>
        </tbody>
      </table>
    </div>
    <p class="text-muted report-note">Upcoming = Pending, Approved, Payment Verified or Confirmed with a service date from today onward.</p>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No service bookings in this period.</p>
  <?php endif; ?>
</div>

<!-- 3. Requirements Monitoring -->
<div class="card">
  <div class="card-header"><h3>3. Requirements Monitoring</h3> <span class="report-basis">By booking date</span></div>
  <?php if (!$report['review_available']): ?>
    <p class="text-muted report-note">Awaiting Revision and Ready for Re-Review need the requirements review database update (database/migration_requirements_review.sql); they show “—” until it is run.</p>
  <?php endif; ?>
  <div class="stat-grid">
    <div class="stat-card review-pending"><div class="stat-label">Pending Document Reviews</div><div class="stat-value"><?= (int) $rc['pending_docs'] ?></div><div class="text-muted report-card-note"><?= (int) $rc['pending_doc_items'] ?> item<?= $rc['pending_doc_items'] === 1 ? '' : 's' ?> to review</div></div>
    <div class="stat-card review-awaiting"><div class="stat-label">Awaiting Revision</div><div class="stat-value"><?= $rc['awaiting_revision'] ?? '—' ?></div></div>
    <div class="stat-card review-ready"><div class="stat-label">Ready for Re-Review</div><div class="stat-value"><?= $rc['ready_for_rereview'] ?? '—' ?></div></div>
    <div class="stat-card"><div class="stat-label">Incomplete Requirements</div><div class="stat-value"><?= (int) $rc['incomplete'] ?></div></div>
  </div>
  <?php if ($report['requirements']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Service</th><th>Applicant</th><th>Pending Docs</th><th>Review Status</th><th>Outstanding Requirements</th><th class="report-col-action"></th></tr></thead>
        <tbody>
          <?php foreach ($report['requirements'] as $r): ?>
            <tr>
              <td><?= e(reportReference($r['row'])) ?></td>
              <td><?= e($r['row']['service_name']) ?></td>
              <td><?= e(reportApplicant($r['row'])) ?></td>
              <td><?= (int) $r['pending_docs'] ?></td>
              <td><?= $reviewBadge($r['review_status']) ?></td>
              <td><?= $r['outstanding'] ? e(implode(' ', $r['outstanding'])) : '<span class="text-muted">—</span>' ?></td>
              <td class="report-col-action"><?= $viewButton($r['row']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No pending appointments with requirements to review in this period.</p>
  <?php endif; ?>
  <p class="text-muted report-note">Pending appointments only. Mass Intentions are not included (handled by the Cashier).</p>
</div>

<!-- 4. Scheduling Report -->
<div class="card">
  <div class="card-header"><h3>4. Scheduling Report</h3> <span class="report-basis">By service date</span></div>
  <h4 class="report-subhead">Service Schedule — services dated <?= e(formatDate($filters['from'])) ?><?= $filters['from'] !== $filters['to'] ? ' to ' . e(formatDate($filters['to'])) : '' ?></h4>
  <?php if ($report['schedule']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service Date</th><th>Time</th><th>Reference</th><th>Service</th><th>Applicant</th><th>Location</th><th>Priest</th><th>Status</th><th class="report-col-action"></th></tr></thead>
        <tbody>
          <?php foreach ($report['schedule'] as $r): ?>
            <tr<?= $r['appointment_date'] >= $filters['today'] ? '' : ' class="text-muted"' ?>>
              <td><?= e(formatDate($r['appointment_date'])) ?></td>
              <td><?= e(reportTime($r['appointment_time']) ?: 'To be announced') ?></td>
              <td><?= e(reportReference($r)) ?></td>
              <td><?= e($r['service_name']) ?></td>
              <td><?= e(reportApplicant($r)) ?></td>
              <td><?= e($r['location_address'] ?: '—') ?></td>
              <td><?= e($r['priest_name'] ?: '—') ?></td>
              <td><?= $statusBadge($r) ?></td>
              <td class="report-col-action"><?= $viewButton($r) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No services are scheduled in this period.</p>
  <?php endif; ?>

  <h4 class="report-subhead">Awaiting Final Schedule <span class="report-basis">By booking date — no service date or time yet</span></h4>
  <?php if ($report['awaiting_schedule']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Service</th><th>Applicant</th><th>Booked</th><th>Service Date</th><th>Status</th><th class="report-col-action"></th></tr></thead>
        <tbody>
          <?php foreach ($report['awaiting_schedule'] as $r): ?>
            <tr>
              <td><?= e(reportReference($r)) ?></td>
              <td><?= e($r['service_name']) ?></td>
              <td><?= e(reportApplicant($r)) ?></td>
              <td><?= e(formatDate($r['created_at'])) ?></td>
              <td><?= $r['appointment_date'] ? e(formatDate($r['appointment_date'])) . ' <span class="text-muted">(time to be set)</span>' : '<span class="text-muted">To be scheduled</span>' ?></td>
              <td><?= $statusBadge($r) ?></td>
              <td class="report-col-action"><?= $viewButton($r) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">Every active booking in this period has a final schedule.</p>
  <?php endif; ?>
</div>

<!-- 5. Pending and Overdue -->
<div class="card">
  <div class="card-header"><h3>5. Pending and Overdue</h3> <span class="report-basis">By booking date</span></div>
  <div class="stat-grid">
    <div class="stat-card review-pending"><div class="stat-label">Awaiting Secretary Approval</div><div class="stat-value"><?= count($report['approvals']) ?></div></div>
    <div class="stat-card review-awaiting"><div class="stat-label">Overdue (over <?= REPORT_OVERDUE_DAYS ?> days)</div><div class="stat-value"><?= (int) $af['overdue'] ?></div></div>
    <div class="stat-card review-awaiting"><div class="stat-label">Urgent (service within <?= REPORT_URGENT_DAYS ?> days)</div><div class="stat-value"><?= (int) $af['urgent'] ?></div></div>
    <div class="stat-card dark"><div class="stat-label">Needs Immediate Attention</div><div class="stat-value"><?= (int) $af['attention'] ?></div></div>
  </div>
  <?php if ($report['approvals']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Service</th><th>Applicant</th><th>Booked</th><th>Days Pending</th><th>Service Date</th><th>Flags</th><th class="report-col-action"></th></tr></thead>
        <tbody>
          <?php foreach ($report['approvals'] as $a): $r = $a['row']; ?>
            <tr>
              <td><?= e(reportReference($r)) ?></td>
              <td><?= e($r['service_name']) ?></td>
              <td><?= e(reportApplicant($r)) ?></td>
              <td><?= e(formatDate($r['created_at'])) ?></td>
              <td><?= (int) $a['days'] ?></td>
              <td><?= $r['appointment_date'] ? e(formatDate($r['appointment_date'])) : '<span class="text-muted">To be scheduled</span>' ?></td>
              <td>
                <?php if ($a['urgent']): ?><span class="badge badge-rejected">Urgent</span><?php endif; ?>
                <?php if ($a['overdue']): ?><span class="badge badge-pending">Overdue</span><?php endif; ?>
                <?php if ($a['review_status']): ?><?= $reviewBadge($a['review_status']) ?><?php endif; ?>
                <?php if (!$a['urgent'] && !$a['overdue'] && !$a['review_status']): ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td class="report-col-action"><?= $viewButton($r) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No appointments are awaiting Secretary approval in this period.</p>
  <?php endif; ?>
  <p class="text-muted report-note">Overdue = still pending more than <?= REPORT_OVERDUE_DAYS ?> days after booking. Urgent = still pending with a service date within the next <?= REPORT_URGENT_DAYS ?> days. Mass Intentions are not included (handled by the Cashier).</p>
</div>

<!-- 6. Cancellations and Rejections -->
<div class="card">
  <div class="card-header"><h3>6. Cancellations and Rejections</h3> <span class="report-basis">By booking date</span></div>
  <?php if ($report['closed']): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Service</th><th>Applicant</th><th>Last Updated</th><th>Reason</th><th>Final Status</th><th class="report-col-action"></th></tr></thead>
        <tbody>
          <?php foreach ($report['closed'] as $r):
            $reason = (int) $r['status_id'] === 3 ? $r['rejection_reason'] : $r['cancelled_reason']; ?>
            <tr>
              <td><?= e(reportReference($r)) ?></td>
              <td><?= e($r['service_name']) ?></td>
              <td><?= e(reportApplicant($r)) ?></td>
              <td><?= e(formatDate($r['updated_at'])) ?></td>
              <td><?= $reason ? e($reason) : '<span class="text-muted">No reason recorded</span>' ?></td>
              <td><?= $statusBadge($r) ?></td>
              <td class="report-col-action"><?= $viewButton($r) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted text-center mt-3">No cancelled or rejected appointments in this period.</p>
  <?php endif; ?>
  <p class="text-muted report-note">“Last Updated” is the most recent change to the appointment, not necessarily the exact cancellation or rejection date.</p>
</div>

<script>
(function () {
  var period = document.getElementById('reportPeriod');
  function syncPeriodFields() {
    document.querySelectorAll('[data-report-period]').forEach(function (field) {
      field.hidden = field.getAttribute('data-report-period').split(' ').indexOf(period.value) === -1;
    });
  }
  period.addEventListener('change', syncPeriodFields);
  syncPeriodFields();
})();
</script>

<?php include __DIR__ . '/../includes/detail-modal.php'; ?>
<?php include __DIR__ . '/../includes/secretary-modal-shells.php'; ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
