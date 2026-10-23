<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

/* ------------------------------------------------------------------
 * Helpers (local to this page)
 * ------------------------------------------------------------------ */

/** Returns a valid Y-m-d string, or null if the input is not a real date. */
function reportValidDate(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

/** Parish name from settings when the helper exists, otherwise the default. */
function reportParishName(): string
{
    $default = 'Our Lady of Mt. Carmel Parish';
    return function_exists('setting') ? (string) setting('parish_name', $default) : $default;
}

/** Reference number shown on the ledger, e.g. APT-000123. */
function reportReference(int $appointmentId): string
{
    return 'APT-' . str_pad((string) $appointmentId, 6, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

$categories = db()->query(
    "SELECT DISTINCT category FROM services WHERE category IS NOT NULL AND category <> '' ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

$fromDate = reportValidDate($_GET['from_date'] ?? null);
$toDate   = reportValidDate($_GET['to_date'] ?? null);
$category = trim((string) ($_GET['category'] ?? ''));
if ($category !== '' && !in_array($category, $categories, true)) {
    $category = '';
}

$generated = isset($_GET['generate']);
$filterError = '';

if ($generated) {
    $fromDate = $fromDate ?? $defaultFrom;
    $toDate   = $toDate ?? $defaultTo;
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
        $filterError = 'Ang "Date From" ay mas huli sa "Date To", kaya awtomatikong pinalitan ang dalawa.';
    }
}

$formFrom = $fromDate ?? $defaultFrom;
$formTo   = $toDate ?? $defaultTo;

/* ------------------------------------------------------------------
 * Report data
 * Rejected/Cancelled bookings are excluded: they were never billed.
 * Paid amounts = sum of verified payments per appointment.
 * ------------------------------------------------------------------ */

$rows = [];
$categoryTotals = [];
$grand = [
    'bookings'  => 0,
    'billed'    => 0.0,
    'collected' => 0.0,
    'balance'   => 0.0,
];

if ($generated) {
    $sql = "
        SELECT a.appointment_id, a.appointment_date, a.appointment_time,
               a.guest_name, a.outside_priest_name,
               s.service_name, s.category, COALESCE(s.fee, 0) AS fee,
               st.status_name,
               pr.title AS priest_title, pr.full_name AS priest_name,
               u.firstname, u.lastname,
               COALESCE(pay.paid, 0) AS paid
        FROM appointments a
        JOIN services s            ON s.service_id = a.service_id
        JOIN appointment_status st ON st.status_id = a.status_id
        LEFT JOIN priests pr       ON pr.priest_id = a.priest_id
        LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
        LEFT JOIN users u          ON u.user_id = par.user_id
        LEFT JOIN (
            SELECT appointment_id, SUM(amount) AS paid
            FROM payments
            WHERE payment_status = 'verified'
            GROUP BY appointment_id
        ) pay ON pay.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ?
          AND st.status_name NOT IN ('Rejected', 'Cancelled')
    ";
    $params = [$fromDate, $toDate];
    if ($category !== '') {
        $sql .= ' AND s.category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.appointment_id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $r) {
        $fee  = (float) $r['fee'];
        $paid = (float) $r['paid'];

        if ($fee > 0) {
            $payStatus = $paid >= $fee ? 'Paid' : 'Pending';
            $balance   = max($fee - $paid, 0.0);
        } else {
            // Free / voluntary-offering services: Paid if an offering was verified, otherwise Waived.
            $payStatus = $paid > 0 ? 'Paid' : 'Waived';
            $balance   = 0.0;
        }

        $client = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
        if ($client === '') {
            $client = trim((string) $r['guest_name']) ?: '—';
        }

        $priest = trim(($r['priest_title'] ?? '') . ' ' . ($r['priest_name'] ?? ''));
        if ($priest === '') {
            $priest = trim((string) $r['outside_priest_name']) ?: '—';
        }

        $rows[] = [
            'date'       => $r['appointment_date'],
            'time'       => $r['appointment_time'],
            'reference'  => reportReference((int) $r['appointment_id']),
            'service'    => $r['service_name'],
            'category'   => $r['category'],
            'client'     => $client,
            'priest'     => $priest,
            'status'     => $r['status_name'],
            'fee'        => $fee,
            'paid'       => $paid,
            'balance'    => $balance,
            'payStatus'  => $payStatus,
        ];

        $cat = $r['category'] ?: 'Uncategorized';
        if (!isset($categoryTotals[$cat])) {
            $categoryTotals[$cat] = ['bookings' => 0, 'billed' => 0.0, 'collected' => 0.0, 'balance' => 0.0];
        }
        $categoryTotals[$cat]['bookings']++;
        $categoryTotals[$cat]['billed']    += $fee;
        $categoryTotals[$cat]['collected'] += $paid;
        $categoryTotals[$cat]['balance']   += $balance;

        $grand['bookings']++;
        $grand['billed']    += $fee;
        $grand['collected'] += $paid;
        $grand['balance']   += $balance;
    }
    ksort($categoryTotals);
}

$me = currentUser() ?? [];
$preparedBy = trim(($me['firstname'] ?? '') . ' ' . ($me['lastname'] ?? ''));
if ($preparedBy === '') {
    $preparedBy = 'Parish Secretary';
}
$generatedOn = date('F j, Y, g:i A');
$periodLabel = $fromDate && $toDate
    ? 'For the period of ' . formatDate($fromDate) . ' to ' . formatDate($toDate)
    : '';
$scopeLabel = $category !== '' ? $category : 'All Services';

$active = 'reports';
$pageTitle = 'Sales & Services Report';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<style>
  /* ---------- Screen styles ---------- */
  .rpt-filter { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; align-items: end; }
  .rpt-filter .field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: .4px; }
  .rpt-filter .field input,
  .rpt-filter .field select { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; background: #fff; }
  .rpt-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-top: 16px; }
  .rpt-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin: 20px 0; }
  .rpt-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; }
  .rpt-card .lbl { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
  .rpt-card .val { font-size: 24px; font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; font-family: 'Courier New', monospace, sans-serif; }
  .rpt-card.pending .val { color: #b45309; }
  .rpt-card.paid .val { color: #15803d; }
  .rpt-note { font-size: 13px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; }

  /* ---------- Report sheet (screen + print) ---------- */
  .rpt-sheet { background: #fff; color: #111; padding: 28px 30px; border: 1px solid #e2e8f0; border-radius: 10px; }
  .rpt-letterhead { display: flex; align-items: center; gap: 18px; padding-bottom: 14px; border-bottom: 3px double #333; }
  .rpt-letterhead img { width: 68px; height: 68px; object-fit: contain; flex-shrink: 0; }
  .rpt-letterhead .parish { font-family: 'Cormorant Garamond', Georgia, 'Times New Roman', serif; font-size: 24px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; margin: 0; }
  .rpt-letterhead .diocese { font-size: 12.5px; margin: 2px 0 0; color: #333; }
  .rpt-title { text-align: center; margin: 18px 0 4px; font-size: 16px; font-weight: 700; letter-spacing: .6px; }
  .rpt-meta { text-align: center; font-size: 12.5px; color: #333; margin: 2px 0; }
  .rpt-meta-line { display: flex; justify-content: space-between; font-size: 12px; color: #444; margin-top: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; }

  .rpt-sheet table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .rpt-sheet th, .rpt-sheet td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; text-align: left; }
  .rpt-sheet th { background: #f1f5f9; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .3px; color: #111; }
  .rpt-sheet td.num, .rpt-sheet th.num { text-align: right; font-variant-numeric: tabular-nums; font-family: 'Courier New', monospace, sans-serif; white-space: nowrap; }
  .rpt-sheet thead { display: table-header-group; }
  .rpt-sheet tr { page-break-inside: avoid; }
  .rpt-sheet .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; margin: 22px 0 8px; }
  .rpt-sheet tfoot td { font-weight: 700; border-top: 1px solid #000; border-bottom: 3px double #000; background: #fff; }
  .rpt-sheet .muted { color: #555; font-size: 11.5px; }
  .rpt-empty { text-align: center; padding: 22px; color: #555; font-style: italic; }

  .rpt-signatories { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 46px; page-break-inside: avoid; }
  .rpt-sig { text-align: center; font-size: 12.5px; }
  .rpt-sig .line { border-bottom: 1px solid #000; height: 34px; margin-bottom: 4px; }
  .rpt-sig .name { font-weight: 700; text-transform: uppercase; }
  .rpt-sig .role { color: #333; }
  .rpt-sig .label { font-size: 11px; color: #555; margin-bottom: 26px; text-align: left; }

  .rpt-sheet .badge { font-size: 11px; }

  /* ---------- Print ---------- */
  @media print {
    @page { size: A4 landscape; margin: 12mm; }
    html, body { background: #fff !important; }
    .sidebar, .topbar, .no-print, .flash, .flash-message, .alert,
    nav, header, footer { display: none !important; }
    .app-shell, .main-content, .page-body, .main, .content {
      margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: none !important;
      background: #fff !important; box-shadow: none !important;
    }
    .rpt-sheet { border: none; border-radius: 0; padding: 0; }
    .rpt-cards { gap: 10px; margin: 14px 0; }
    .rpt-card { border: 1px solid #000; }
    .rpt-card .val { font-size: 16px; }
    .rpt-sheet tr, .rpt-sheet td, .rpt-sheet th { page-break-inside: avoid; }
    .rpt-sheet thead { display: table-header-group; }
    .rpt-sheet tfoot { display: table-row-group; }
    .rpt-signatories { margin-top: 36px; }
    .rpt-sheet .section-title { break-after: avoid; }
  }
</style>

<?php if ($filterError !== ''): ?>
  <div class="rpt-note no-print"><?= e($filterError) ?></div>
<?php endif; ?>

<!-- ============ FILTER CARD ============ -->
<div class="card no-print">
  <div class="card-header">
    <h3>Sales &amp; Services Report</h3>
  </div>
  <form method="GET" id="reportForm" class="rpt-filter" autocomplete="off">
    <div class="field">
      <label for="preset">Quick Range</label>
      <select id="preset">
        <option value="custom">Custom</option>
        <option value="today">Today</option>
        <option value="week">This Week</option>
        <option value="month">This Month</option>
        <option value="year">This Year</option>
      </select>
    </div>
    <div class="field">
      <label for="from_date">Date From</label>
      <input type="date" id="from_date" name="from_date" value="<?= e($formFrom) ?>" required>
    </div>
    <div class="field">
      <label for="to_date">Date To</label>
      <input type="date" id="to_date" name="to_date" value="<?= e($formTo) ?>" required>
    </div>
    <div class="field">
      <label for="category">Service / Category</label>
      <select id="category" name="category">
        <option value="">All Services</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="generate" value="1">
    <div class="field">
      <button type="submit" class="btn btn-primary" style="width:100%;">Generate Report</button>
    </div>
  </form>

  <?php if ($generated): ?>
    <div class="rpt-actions">
      <span class="text-muted" style="font-size:13px;">
        Showing: <strong><?= e($scopeLabel) ?></strong> &middot; <?= e(formatDate($fromDate)) ?> to <?= e(formatDate($toDate)) ?>
      </span>
      <button type="button" class="btn btn-success" onclick="window.print()">🖨️ Print Report</button>
    </div>
  <?php endif; ?>
</div>

<?php if ($generated): ?>

<!-- ============ SCREEN SUMMARY CARDS ============ -->
<div class="rpt-cards no-print">
  <div class="rpt-card">
    <div class="lbl">Total Appointments / Bookings</div>
    <div class="val"><?= (int) $grand['bookings'] ?></div>
  </div>
  <div class="rpt-card">
    <div class="lbl">Total Billed / Collectible</div>
    <div class="val"><?= e(money($grand['billed'])) ?></div>
  </div>
  <div class="rpt-card paid">
    <div class="lbl">Verified Collections (Paid)</div>
    <div class="val"><?= e(money($grand['collected'])) ?></div>
  </div>
  <div class="rpt-card pending">
    <div class="lbl">Pending / Uncollected Balance</div>
    <div class="val"><?= e(money($grand['balance'])) ?></div>
  </div>
</div>

<!-- ============ REPORT SHEET (screen preview + printed statement) ============ -->
<div class="rpt-sheet">

  <div class="rpt-letterhead">
    <?php if (hasParishLogo()): ?>
      <img src="<?= e(url('public/img/logo.png')) ?>" alt="Parish Logo">
    <?php endif; ?>
    <div>
      <p class="parish"><?= e(strtoupper(reportParishName())) ?></p>
      <p class="diocese">Roman Catholic Diocese &nbsp;|&nbsp; Parish Office of Administration &amp; Finance</p>
    </div>
  </div>

  <h2 class="rpt-title">STATEMENT OF PARISH SERVICES &amp; REVENUE COLLECTIONS</h2>
  <p class="rpt-meta"><?= e($periodLabel) ?></p>
  <p class="rpt-meta">Service Scope: <?= e($scopeLabel) ?></p>
  <div class="rpt-meta-line">
    <span>Generated on: <?= e($generatedOn) ?> by <?= e($preparedBy) ?></span>
    <span>Total Entries: <?= (int) $grand['bookings'] ?></span>
  </div>

  <!-- Category breakdown -->
  <div class="section-title">I. Summary by Service Category</div>
  <table>
    <thead>
      <tr>
        <th>Category</th>
        <th class="num">No. of Bookings</th>
        <th class="num">Total Billed (₱)</th>
        <th class="num">Collected (₱)</th>
        <th class="num">Balance (₱)</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($categoryTotals)): ?>
        <tr><td colspan="5" class="rpt-empty">Walang bookings sa napiling petsa / kategorya.</td></tr>
      <?php endif; ?>
      <?php foreach ($categoryTotals as $catName => $t): ?>
        <tr>
          <td><?= e($catName) ?></td>
          <td class="num"><?= (int) $t['bookings'] ?></td>
          <td class="num"><?= e(number_format($t['billed'], 2)) ?></td>
          <td class="num"><?= e(number_format($t['collected'], 2)) ?></td>
          <td class="num"><?= e(number_format($t['balance'], 2)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if (!empty($categoryTotals)): ?>
    <tfoot>
      <tr>
        <td>Total</td>
        <td class="num"><?= (int) $grand['bookings'] ?></td>
        <td class="num"><?= e(number_format($grand['billed'], 2)) ?></td>
        <td class="num"><?= e(number_format($grand['collected'], 2)) ?></td>
        <td class="num"><?= e(number_format($grand['balance'], 2)) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- Transactions ledger -->
  <div class="section-title">II. Sales &amp; Services Journal (Transactions Ledger)</div>
  <div class="table-wrap" style="overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th style="width:11%;">Date &amp; Time</th>
        <th style="width:9%;">Reference #</th>
        <th style="width:16%;">Service &amp; Category</th>
        <th style="width:14%;">Client / Parishioner / Guest</th>
        <th style="width:14%;">Assigned / Visiting Priest</th>
        <th style="width:9%;">Status</th>
        <th class="num" style="width:10%;">Amount / Fee (₱)</th>
        <th style="width:9%;">Payment Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="rpt-empty">Walang bookings sa napiling petsa / kategorya.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <?= e(formatDate($row['date'])) ?><br>
            <span class="muted"><?= e($row['time'] ? date('g:i A', strtotime($row['time'])) : '—') ?></span>
          </td>
          <td><?= e($row['reference']) ?></td>
          <td>
            <?= e($row['service']) ?><br>
            <span class="muted"><?= e($row['category'] ?: '—') ?></span>
          </td>
          <td><?= e($row['client']) ?></td>
          <td><?= e($row['priest']) ?></td>
          <td><span class="badge badge-<?= e(badgeClass($row['status'])) ?>"><?= e($row['status']) ?></span></td>
          <td class="num"><?= e(number_format($row['fee'], 2)) ?></td>
          <td><?= e($row['payStatus']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if (!empty($rows)): ?>
    <tfoot>
      <tr>
        <td colspan="6">GRAND TOTAL (<?= (int) $grand['bookings'] ?> entries)</td>
        <td class="num"><?= e(number_format($grand['billed'], 2)) ?></td>
        <td class="num">Paid: <?= e(number_format($grand['collected'], 2)) ?></td>
      </tr>
      <tr>
        <td colspan="6">Pending / Uncollected Balance</td>
        <td class="num"><?= e(number_format($grand['balance'], 2)) ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>

  <!-- Signatories -->
  <div class="rpt-signatories">
    <div class="rpt-sig">
      <div class="label">Prepared by:</div>
      <div class="line"></div>
      <div class="name"><?= e($preparedBy) ?></div>
      <div class="role">Parish Secretary</div>
    </div>
    <div class="rpt-sig">
      <div class="label">Reviewed &amp; Verified by:</div>
      <div class="line"></div>
      <div class="name">&nbsp;</div>
      <div class="role">Parish Bookkeeper / Treasurer</div>
    </div>
    <div class="rpt-sig">
      <div class="label">Approved &amp; Noted by:</div>
      <div class="line"></div>
      <div class="name">&nbsp;</div>
      <div class="role">Parish Priest / Administrator</div>
    </div>
  </div>

</div>

<?php else: ?>
  <div class="card no-print" style="margin-top:20px;">
    <p class="text-muted text-center mt-3">Piliin ang petsa at kategorya, saka pindutin ang <strong>Generate Report</strong>.</p>
  </div>
<?php endif; ?>

<script>
  // Quick Range presets: fill the date inputs; any manual date change switches to "Custom".
  (function () {
    var preset = document.getElementById('preset');
    var from   = document.getElementById('from_date');
    var to     = document.getElementById('to_date');

    function iso(d) {
      var m = String(d.getMonth() + 1).padStart(2, '0');
      var day = String(d.getDate()).padStart(2, '0');
      return d.getFullYear() + '-' + m + '-' + day;
    }

    function apply(name) {
      var now = new Date();
      var start = new Date(now);
      var end = new Date(now);
      switch (name) {
        case 'today':
          break;
        case 'week':
          var dow = (now.getDay() + 6) % 7; // Monday = start of week
          start.setDate(now.getDate() - dow);
          end = new Date(start);
          end.setDate(start.getDate() + 6);
          break;
        case 'month':
          start = new Date(now.getFullYear(), now.getMonth(), 1);
          end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
          break;
        case 'year':
          start = new Date(now.getFullYear(), 0, 1);
          end = new Date(now.getFullYear(), 11, 31);
          break;
        default:
          return;
      }
      from.value = iso(start);
      to.value = iso(end);
    }

    preset.addEventListener('change', function () { apply(preset.value); });
    from.addEventListener('change', function () { preset.value = 'custom'; });
    to.addEventListener('change', function () { preset.value = 'custom'; });
  })();
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
