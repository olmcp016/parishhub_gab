<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

/* ---------------------------------------------------------------
 * Local helpers
 * ------------------------------------------------------------- */

/** Valid Y-m-d or null. */
function trptValidDate(?string $v): ?string
{
    if (!$v) return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Parish name — uses global setting() when available. */
function trptParishName(): string
{
    $default = 'Our Lady of Mt. Carmel Parish';
    return function_exists('setting') ? (string) setting('parish_name', $default) : $default;
}

/** True when the method is cash-in-hand (not a digital gateway). */
function trptIsCash(string $methodName): bool
{
    return strtolower(trim($methodName)) === 'cash';
}

/* ---------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------- */

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

// Available payment methods from DB
$allMethods = [];
try {
    $allMethods = db()->query(
        "SELECT method_id, method_name FROM payment_methods ORDER BY method_id"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Failed to fetch payment methods: ' . $e->getMessage());
}

$fromDate   = trptValidDate($_GET['from_date'] ?? null);
$toDate     = trptValidDate($_GET['to_date']   ?? null);
$methodId   = isset($_GET['method_id']) && ctype_digit((string)$_GET['method_id'])
                ? (int) $_GET['method_id'] : 0;
$statusFilter = 'all';
$generated  = isset($_GET['generate']);

if ($generated) {
    $fromDate = $fromDate ?? $defaultFrom;
    $toDate   = $toDate   ?? $defaultTo;
    // Auto-swap if inverted
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
}

$formFrom = $fromDate ?? $defaultFrom;
$formTo   = $toDate   ?? $defaultTo;

/* ---------------------------------------------------------------
 * Report queries (only run after Generate is clicked)
 * ------------------------------------------------------------- */

$rows          = [];
$byMethod      = [];
$byCategory    = [];
$grand = ['count' => 0, 'total' => 0.0, 'cash' => 0.0, 'digital' => 0.0, 'pending' => 0.0];

$filterError   = '';

if ($generated) {
    try {
        /* ---- Build WHERE clauses ---- */
    $where  = ['p.payment_date::date BETWEEN ? AND ?'];
    $params = [$fromDate, $toDate];

    if ($statusFilter !== 'all') {
        $where[] = 'p.payment_status = ?';
        $params[] = $statusFilter;
    }
    if ($methodId > 0) {
        $where[] = 'p.method_id = ?';
        $params[] = $methodId;
    }

    $whereStr = implode(' AND ', $where);

    /* ---- Summary pending (always show for the date range regardless of status filter) ---- */
    $pendingStmt = db()->prepare(
        "SELECT COALESCE(SUM(p.amount),0)
         FROM payments p
         WHERE p.payment_date::date BETWEEN ? AND ?
           AND p.payment_status = 'pending'"
    );
    $pendingStmt->execute([$fromDate, $toDate]);
    $grand['pending'] = (float) $pendingStmt->fetchColumn();

    /* ---- Main ledger ---- */
    $sql = "
        SELECT
            p.payment_id,
            p.payment_date,
            p.reference_number,
            p.amount,
            p.payment_status,
            pm.method_name,
            a.appointment_id,
            a.guest_name,
            a.guest_reference,
            s.service_name,
            s.category,
            -- Parishioner name
            TRIM(COALESCE(u.firstname,'') || ' ' || COALESCE(u.lastname,'')) AS par_name,
            -- Mass intention offerer (overrides parishioner for MI)
            mi.offerer_name,
            -- Donation donor name (overrides parishioner for donations)
            d.donor_name,
            d.purpose AS donation_purpose,
            -- Official receipt number
            orec.receipt_number,
            -- Verified by user
            TRIM(COALESCE(vu.firstname,'') || ' ' || COALESCE(vu.lastname,'')) AS verified_by_name
        FROM payments p
        JOIN payment_methods pm     ON pm.method_id = p.method_id
        JOIN appointments a         ON a.appointment_id = p.appointment_id
        JOIN services s             ON s.service_id = a.service_id
        LEFT JOIN parishioners par  ON par.parishioner_id = a.parishioner_id
        LEFT JOIN users u           ON u.user_id = par.user_id
        LEFT JOIN mass_intentions mi ON mi.appointment_id = a.appointment_id
        LEFT JOIN donations d        ON d.appointment_id = a.appointment_id
        LEFT JOIN official_receipts orec ON orec.payment_id = p.payment_id
        LEFT JOIN users vu           ON vu.user_id = p.verified_by
        WHERE $whereStr
        ORDER BY p.payment_date ASC, p.payment_id ASC
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $r) {
        $amt    = (float) $r['amount'];
        $method = (string) $r['method_name'];
        $isCash = trptIsCash($method);
        $cat    = (string) ($r['category'] ?: 'Uncategorized');

        // Resolve payor name
        $payor = trim((string) $r['par_name']);
        if ($cat === 'Mass Intention' && !empty($r['offerer_name'])) {
            $payor = (string) $r['offerer_name'];
        } elseif ($cat === 'Donation' && !empty($r['donor_name'])) {
            $payor = (string) $r['donor_name'];
        }
        if ($payor === '') {
            $payor = trim((string) ($r['guest_name'] ?? '')) ?: '—';
        }

        // Particulars
        $particulars = (string) $r['service_name'];
        if ($cat === 'Donation' && !empty($r['donation_purpose'])) {
            $particulars .= ' — ' . $r['donation_purpose'];
        }

        // Receipt / ref number
        $refDisplay = (string) ($r['receipt_number'] ?? $r['reference_number'] ?? '—');
        if ($refDisplay === '') $refDisplay = 'APT-' . str_pad((string)$r['appointment_id'], 6, '0', STR_PAD_LEFT);

        $rows[] = [
            'date'         => $r['payment_date'],
            'ref'          => $refDisplay,
            'gateway_ref'  => $r['reference_number'] ?? '',
            'payor'        => $payor,
            'particulars'  => $particulars,
            'category'     => $cat,
            'method'       => $method,
            'amount'       => $amt,
            'status'       => $r['payment_status'],
        ];

        // Only tally verified into totals
        if ($r['payment_status'] === 'verified') {
            $grand['count']++;
            $grand['total'] += $amt;
            if ($isCash) {
                $grand['cash'] += $amt;
            } else {
                $grand['digital'] += $amt;
            }

            // By method
            if (!isset($byMethod[$method])) {
                $byMethod[$method] = ['count' => 0, 'total' => 0.0, 'is_cash' => $isCash];
            }
            $byMethod[$method]['count']++;
            $byMethod[$method]['total'] += $amt;

            // By category
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['count' => 0, 'total' => 0.0];
            }
            $byCategory[$cat]['count']++;
            $byCategory[$cat]['total'] += $amt;
        }
    }

    arsort($byMethod);  // highest collection first
    arsort($byCategory);
    } catch (Throwable $e) {
        error_log('Treasurer report query error: ' . $e->getMessage());
        $filterError = 'Paumanhin, nagkaroon ng problema sa pagbuo ng ulat. Pakisubukang muli.';
    }
}

/* ---------------------------------------------------------------
 * Current user (for "Prepared by")
 * ------------------------------------------------------------- */
$me = currentUser() ?? [];
$preparedBy = trim(($me['firstname'] ?? '') . ' ' . ($me['lastname'] ?? ''));
if ($preparedBy === '') $preparedBy = 'Cashier';

$generatedOn = date('F j, Y, g:i A');
$periodLabel = ($fromDate && $toDate)
    ? 'Covering the Period of ' . formatDate($fromDate) . ' to ' . formatDate($toDate)
    : '';

$methodLabel = 'All Payment Methods';
if ($methodId > 0) {
    foreach ($allMethods as $m) {
        if ((int)$m['method_id'] === $methodId) {
            $methodLabel = (string) $m['method_name'];
            break;
        }
    }
}
$statusLabel = match($statusFilter) {
    'verified' => 'Verified / Collected',
    'pending'  => 'Pending',
    default    => 'All Statuses',
};

/* ---------------------------------------------------------------
 * Page setup
 * ------------------------------------------------------------- */
$active    = 'reports';
$pageTitle = 'Cash Receipts & Collection Report';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<style>
/* ── Screen layout ─────────────────────────────────────────── */
.trpt-filter { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:14px; align-items:end; }
.trpt-filter .fld label { display:block; font-size:12px; font-weight:600; margin-bottom:4px;
  color:var(--text-muted,#64748b); text-transform:uppercase; letter-spacing:.4px; }
.trpt-filter .fld input,
.trpt-filter .fld select { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px;
  font:inherit; background:#fff; box-sizing:border-box; }
.trpt-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center;
  justify-content:space-between; margin-top:16px; border-top:1px solid #e2e8f0; padding-top:12px; }

.trpt-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin:20px 0; }
.trpt-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; }
.trpt-card .lbl { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; font-weight:600; }
.trpt-card .val { font-size:22px; font-weight:700; margin-top:6px;
  font-variant-numeric:tabular-nums; font-family:'Courier New',monospace,sans-serif; }
.trpt-card.verified .val { color:#15803d; }
.trpt-card.cash     .val { color:#1d4ed8; }
.trpt-card.digital  .val { color:#7c3aed; }
.trpt-card.pending  .val { color:#b45309; }

.trpt-cols { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
@media(max-width:700px){ .trpt-cols { grid-template-columns:1fr; } }

/* ── Report sheet (screen + print) ────────────────────────── */
.trpt-sheet { background:#fff; color:#111; padding:28px 30px;
  border:1px solid #e2e8f0; border-radius:10px; }

.trpt-letterhead { display:flex; align-items:center; gap:18px;
  padding-bottom:14px; border-bottom:3px double #333; }
.trpt-letterhead img { width:68px; height:68px; object-fit:contain; flex-shrink:0; }
.trpt-letterhead .parish { font-family:'Cormorant Garamond',Georgia,'Times New Roman',serif;
  font-size:22px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; margin:0; }
.trpt-letterhead .sub { font-size:12px; margin:3px 0 0; color:#333; }

.trpt-doc-title { text-align:center; margin:18px 0 4px; font-size:15px; font-weight:700;
  letter-spacing:.6px; text-transform:uppercase; }
.trpt-meta { text-align:center; font-size:12.5px; color:#333; margin:2px 0; }
.trpt-meta-bar { display:flex; justify-content:space-between; font-size:12px; color:#444;
  margin-top:12px; border-bottom:1px solid #cbd5e1; padding-bottom:6px; }

.trpt-sheet table { width:100%; border-collapse:collapse; font-size:12.5px; }
.trpt-sheet th,
.trpt-sheet td { border:1px solid #cbd5e1; padding:6px 8px; vertical-align:top; text-align:left; }
.trpt-sheet th { background:#f1f5f9; font-weight:700; font-size:11.5px;
  text-transform:uppercase; letter-spacing:.3px; color:#111; }
.trpt-sheet td.num,
.trpt-sheet th.num { text-align:right; font-variant-numeric:tabular-nums;
  font-family:'Courier New',monospace,sans-serif; white-space:nowrap; }
.trpt-sheet td.ctr { text-align:center; }
.trpt-sheet thead { display:table-header-group; }
.trpt-sheet tr { page-break-inside:avoid; }
.trpt-sheet tfoot td { font-weight:700; background:#fff; }
.trpt-sheet tfoot tr:last-child td { border-top:1px solid #000; border-bottom:3px double #000; font-size:13.5px; }
.trpt-sheet tfoot tr:first-child td { border-top:1px solid #000; }

.trpt-section { font-size:13px; font-weight:700; text-transform:uppercase;
  letter-spacing:.4px; margin:22px 0 8px; }
.trpt-empty { text-align:center; padding:22px; color:#555; font-style:italic; }
.trpt-muted { color:#555; font-size:11.5px; }
.trpt-badge-verified { color:#15803d; font-weight:600; }
.trpt-badge-pending  { color:#b45309; font-weight:600; }
.trpt-badge-failed   { color:#dc2626; font-weight:600; }

/* Recapitulation box */
.trpt-recap { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px;
  padding:14px 18px; margin:18px 0 22px; }
.trpt-recap table { font-size:13px; }
.trpt-recap td { border:none; padding:3px 10px; }
.trpt-recap td.num { text-align:right; font-variant-numeric:tabular-nums;
  font-family:'Courier New',monospace,sans-serif; white-space:nowrap; width:150px; }
.trpt-recap .grand td { font-size:15px; font-weight:700;
  border-top:1px solid #000; border-bottom:3px double #000; }

/* Signatories */
.trpt-signatories { display:grid; grid-template-columns:repeat(2,1fr);
  gap:48px; max-width:760px; margin:46px auto 0; page-break-inside:avoid; }
.trpt-sig { text-align:center; font-size:12.5px; }
.trpt-sig .lbl2 { font-size:11px; color:#555; margin-bottom:26px; text-align:left; }
.trpt-sig .line { border-bottom:1px solid #000; height:34px; margin-bottom:4px; }
.trpt-sig .name { font-weight:700; text-transform:uppercase; }
.trpt-sig .role { color:#333; }

/* ── Print overrides ───────────────────────────────────────── */
@media print {
  @page { size:A4 landscape; margin:12mm; }
  html,body { background:#fff !important; }
  .sidebar,.topbar,.no-print,.flash,.flash-message,.alert,
  nav,header,footer,.btn { display:none !important; }
  .app-shell,.main-content,.page-body,.main,.content {
    margin:0 !important; padding:0 !important; width:100% !important;
    max-width:none !important; background:#fff !important; box-shadow:none !important;
  }
  .trpt-sheet { border:none; border-radius:0; padding:0; }
  .trpt-cards { gap:10px; margin:14px 0; }
  .trpt-card { border:1px solid #000; }
  .trpt-card .val { font-size:16px; }
  .trpt-cols { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .trpt-sheet tr,.trpt-sheet td,.trpt-sheet th { page-break-inside:avoid; }
  .trpt-sheet thead { display:table-header-group; }
  .trpt-sheet tfoot { display:table-row-group; }
  .trpt-recap { border:1px solid #000; }
  .trpt-signatories { margin-top:36px; }
  .trpt-section { break-after:avoid; }
}
</style>

<?php if (!empty($filterError)): ?>
  <div class="rpt-note no-print" style="margin-bottom:14px; padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; color:#92400e; font-size:13px;"><?= e($filterError) ?></div>
<?php endif; ?>

<!-- ╔══════════════════════════════════════════════════════════╗
     ║  FILTER CARD  (hidden on print)                        ║
     ╚══════════════════════════════════════════════════════════╝ -->
<div class="card no-print">
  <div class="card-header">
    <h3>Cash Receipts &amp; Collection Report</h3>
  </div>

  <form method="GET" id="trptForm" class="trpt-filter" autocomplete="off">
    <div class="fld">
      <label for="from_date">Date From</label>
      <input type="date" id="from_date" name="from_date" value="<?= e($formFrom) ?>" required>
    </div>
    <div class="fld">
      <label for="to_date">Date To</label>
      <input type="date" id="to_date" name="to_date" value="<?= e($formTo) ?>" required>
    </div>
    <div class="fld">
      <label for="method_id">Payment Method</label>
      <select id="method_id" name="method_id">
        <option value="0">All Methods</option>
        <?php foreach ($allMethods as $m): ?>
          <option value="<?= (int)$m['method_id'] ?>"
            <?= $methodId === (int)$m['method_id'] ? 'selected' : '' ?>>
            <?= e($m['method_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="generate" value="1">
    <div class="fld">
      <button type="submit" class="btn btn-primary" style="width:100%;">Generate Report</button>
    </div>
  </form>

  <?php if ($generated): ?>
    <div class="trpt-actions">
      <span style="font-size:13px; color:var(--text-muted,#64748b);">
        Showing: <strong><?= e($methodLabel) ?></strong> &middot;
        <?= e(formatDate($fromDate)) ?> &ndash; <?= e(formatDate($toDate)) ?>
      </span>
      <button type="button" class="btn btn-success" onclick="window.print()">
        🖨️ Print Financial Statement
      </button>
    </div>
  <?php endif; ?>
</div>

<?php if ($generated): ?>

<!-- ╔══════════════════════════════════════════════════════════╗
     ║  SCREEN SUMMARY CARDS  (hidden on print)               ║
     ╚══════════════════════════════════════════════════════════╝ -->
<div class="trpt-cards no-print">
  <div class="trpt-card verified">
    <div class="lbl">Total Verified Collections</div>
    <div class="val"><?= e(money($grand['total'])) ?></div>
    <div style="font-size:11px;color:#555;margin-top:4px;"><?= (int)$grand['count'] ?> verified transactions</div>
  </div>
  <div class="trpt-card cash">
    <div class="lbl">Cash on Hand</div>
    <div class="val"><?= e(money($grand['cash'])) ?></div>
  </div>
  <div class="trpt-card digital">
    <div class="lbl">Online / Digital Collections</div>
    <div class="val"><?= e(money($grand['digital'])) ?></div>
  </div>
  <div class="trpt-card pending">
    <div class="lbl">Pending Verification</div>
    <div class="val"><?= e(money($grand['pending'])) ?></div>
  </div>
</div>

<!-- ╔══════════════════════════════════════════════════════════╗
     ║  REPORT SHEET — visible on screen & printed            ║
     ╚══════════════════════════════════════════════════════════╝ -->
<div class="trpt-sheet">

  <!-- Letterhead -->
  <div class="trpt-letterhead">
    <?php if (hasParishLogo()): ?>
      <img src="<?= e(url('public/img/logo.png')) ?>" alt="Parish Logo">
    <?php endif; ?>
    <div>
      <p class="parish"><?= e(strtoupper(trptParishName())) ?></p>
      <p class="sub">Office of the Parish Treasurer &amp; Cashier &nbsp;|&nbsp; Financial Management System</p>
    </div>
  </div>

  <h2 class="trpt-doc-title">Official Statement of Cash Receipts &amp; Revenue Collections</h2>
  <p class="trpt-meta"><?= e($periodLabel) ?></p>
  <p class="trpt-meta">Payment Method: <?= e($methodLabel) ?> &nbsp;&middot;&nbsp; Status Filter: <?= e($statusLabel) ?></p>
  <div class="trpt-meta-bar">
    <span>Date &amp; Time Generated: <?= e($generatedOn) ?> &nbsp;|&nbsp; Prepared by: <?= e($preparedBy) ?></span>
    <span>Total Entries: <?= count($rows) ?></span>
  </div>

  <!-- ── Section I: Two-column breakdown ─────────────────────── -->
  <div class="trpt-cols" style="margin-top:20px;">

    <!-- By Payment Method -->
    <div>
      <div class="trpt-section">I. Collections by Payment Method</div>
      <table>
        <thead>
          <tr>
            <th>Payment Method</th>
            <th class="num">Transactions</th>
            <th class="num">Subtotal (₱)</th>
            <th class="num">% Share</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($byMethod)): ?>
            <tr><td colspan="4" class="trpt-empty">No verified transactions.</td></tr>
          <?php endif; ?>
          <?php foreach ($byMethod as $mName => $md): ?>
            <?php $pct = $grand['total'] > 0 ? round($md['total'] / $grand['total'] * 100, 1) : 0; ?>
            <tr>
              <td><?= e($mName) ?></td>
              <td class="num"><?= (int)$md['count'] ?></td>
              <td class="num"><?= e(number_format($md['total'], 2)) ?></td>
              <td class="num"><?= $pct ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (!empty($byMethod)): ?>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="num"><?= (int)$grand['count'] ?></td>
            <td class="num"><?= e(number_format($grand['total'], 2)) ?></td>
            <td class="num">100%</td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <!-- By Service / Revenue Source -->
    <div>
      <div class="trpt-section">II. Collections by Service / Revenue Source</div>
      <table>
        <thead>
          <tr>
            <th>Service / Category</th>
            <th class="num">Transactions</th>
            <th class="num">Amount Collected (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($byCategory)): ?>
            <tr><td colspan="3" class="trpt-empty">No verified transactions.</td></tr>
          <?php endif; ?>
          <?php foreach ($byCategory as $cName => $cd): ?>
            <tr>
              <td><?= e($cName) ?></td>
              <td class="num"><?= (int)$cd['count'] ?></td>
              <td class="num"><?= e(number_format($cd['total'], 2)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (!empty($byCategory)): ?>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="num"><?= (int)$grand['count'] ?></td>
            <td class="num"><?= e(number_format($grand['total'], 2)) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

  </div><!-- /.trpt-cols -->

  <!-- ── Section III: Recapitulation of Cash Inflow ──────────── -->
  <?php if (!empty($rows)): ?>
  <div class="trpt-section">III. Recapitulation of Cash Inflow</div>
  <div class="trpt-recap">
    <table>
      <tr>
        <td>Subtotal — Cash on Hand:</td>
        <td class="num"><?= e(number_format($grand['cash'], 2)) ?></td>
      </tr>
      <tr>
        <td>Subtotal — Bank / Digital Payments:</td>
        <td class="num"><?= e(number_format($grand['digital'], 2)) ?></td>
      </tr>
      <tr class="grand">
        <td>GRAND TOTAL — Verified Collections:</td>
        <td class="num"><?= e(number_format($grand['total'], 2)) ?></td>
      </tr>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Section IV: Detailed Cash Receipts Journal ──────────── -->
  <div class="trpt-section">IV. Detailed Cash Receipts Journal</div>
  <div style="overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th style="width:11%;">Date &amp; Time</th>
        <th style="width:10%;">Receipt / Payment Ref #</th>
        <th style="width:15%;">Payor</th>
        <th style="width:18%;">Particulars / Service</th>
        <th style="width:11%;">Payment Method</th>
        <th style="width:12%;">Gateway / Bank Ref #</th>
        <th class="num" style="width:10%;">Amount Collected (₱)</th>
        <th style="width:8%;">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="trpt-empty">No transactions for the selected filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <?= e(formatDate(substr((string)$row['date'], 0, 10))) ?><br>
            <span class="trpt-muted"><?= e($row['date'] ? date('g:i A', strtotime($row['date'])) : '—') ?></span>
          </td>
          <td><?= e($row['ref']) ?></td>
          <td><?= e($row['payor']) ?></td>
          <td>
            <?= e($row['particulars']) ?><br>
            <span class="trpt-muted"><?= e($row['category']) ?></span>
          </td>
          <td><?= e($row['method']) ?></td>
          <td class="trpt-muted"><?= e($row['gateway_ref'] ?: '—') ?></td>
          <td class="num"><?= e(number_format($row['amount'], 2)) ?></td>
          <td>
            <?php
              $sc = match($row['status']) {
                'verified' => 'trpt-badge-verified',
                'pending'  => 'trpt-badge-pending',
                default    => 'trpt-badge-failed',
              };
              $sl = ucfirst($row['status']);
            ?>
            <span class="<?= $sc ?>"><?= e($sl) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if (!empty($rows)): ?>
    <tfoot>
      <tr>
        <td colspan="6">Sub-Total — Verified Collections</td>
        <td class="num"><?= e(number_format($grand['total'], 2)) ?></td>
        <td></td>
      </tr>
      <tr>
        <td colspan="6">GRAND TOTAL (<?= (int)$grand['count'] ?> verified transactions)</td>
        <td class="num"><?= e(number_format($grand['total'], 2)) ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>

  <!-- ── Signatories ─────────────────────────────────────────── -->
  <div class="trpt-signatories">
    <div class="trpt-sig">
      <div class="lbl2">Prepared &amp; Remitted by:</div>
      <div class="line"></div>
      <div class="name"><?= e($preparedBy) ?></div>
      <div class="role">Cashier</div>
    </div>
    <div class="trpt-sig">
      <div class="lbl2">Approved &amp; Received by:</div>
      <div class="line"></div>
      <div class="name">&nbsp;</div>
      <div class="role">Parish Priest / Administrator</div>
    </div>
  </div>

</div><!-- /.trpt-sheet -->

<?php else: ?>

<div class="card no-print" style="margin-top:20px;">
  <p class="text-muted text-center" style="padding:24px 0;">
    Piliin ang petsa at mga filter, pagkatapos ay pindutin ang <strong>Generate Report</strong>.
  </p>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
