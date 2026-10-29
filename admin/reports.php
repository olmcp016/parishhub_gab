<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

/* ---------------------------------------------------------------
 * Local helpers
 * ------------------------------------------------------------- */

/** Valid Y-m-d or null. */
function adminRptValidDate(?string $v): ?string
{
    if (!$v) return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Parish name — uses global setting() when available. */
function adminRptParishName(): string
{
    $default = 'Our Lady of Mt. Carmel Parish';
    return function_exists('setting') ? (string) setting('parish_name', $default) : $default;
}

/** True when the method is cash-in-hand (not a digital gateway). */
function adminRptIsCash(string $methodName): bool
{
    return strtolower(trim($methodName)) === 'cash';
}

function adminRptReference(int $appointmentId): string
{
    return 'APT-' . str_pad((string) $appointmentId, 6, '0', STR_PAD_LEFT);
}

/* ---------------------------------------------------------------
 * Filters & Report Type
 * ------------------------------------------------------------- */

$reportType = ($_GET['report_type'] ?? 'financial') === 'operations' ? 'operations' : 'financial';

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

$fromDate     = adminRptValidDate($_GET['from_date'] ?? null);
$toDate       = adminRptValidDate($_GET['to_date']   ?? null);
$methodId     = 0;
$statusFilter = 'all';
$generated    = isset($_GET['generate']);

if ($generated) {
    $fromDate = $fromDate ?? $defaultFrom;
    $toDate   = $toDate   ?? $defaultTo;
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
}

$formFrom = $fromDate ?? $defaultFrom;
$formTo   = $toDate   ?? $defaultTo;

/* ---------------------------------------------------------------
 * Financial Report Data (Cashier / Revenue Collections)
 * ------------------------------------------------------------- */
$finRows       = [];
$byMethod      = [];
$byCategory    = [];
$finGrand      = ['count' => 0, 'total' => 0.0, 'cash' => 0.0, 'digital' => 0.0, 'pending' => 0.0];
$filterError   = '';

/* ---------------------------------------------------------------
 * Operations Report Data (Secretary / Bookings & Billing)
 * ------------------------------------------------------------- */
$opsRows       = [];
$opsCategories = [];
$opsGrand      = ['bookings' => 0, 'billed' => 0.0, 'collected' => 0.0, 'balance' => 0.0];

if ($generated) {
    try {
        if ($reportType === 'financial') {
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

            // Summary pending
            $pendingStmt = db()->prepare(
                "SELECT COALESCE(SUM(p.amount),0)
                 FROM payments p
                 WHERE p.payment_date::date BETWEEN ? AND ?
                   AND p.payment_status = 'pending'"
            );
            $pendingStmt->execute([$fromDate, $toDate]);
            $finGrand['pending'] = (float) $pendingStmt->fetchColumn();

            // Main ledger with donations, projects, and gateway transactions joined
            $sql = "
                SELECT
                    p.payment_id,
                    p.payment_date,
                    p.reference_number,
                    p.amount,
                    p.payment_status,
                    pm.method_name,
                    t.raw_response AS gateway_raw_response,
                    t.gateway,
                    t.gateway_transaction_id,
                    a.appointment_id,
                    a.guest_name,
                    a.guest_reference,
                    s.service_name,
                    s.category,
                    TRIM(COALESCE(u.firstname,'') || ' ' || COALESCE(u.lastname,'')) AS par_name,
                    mi.offerer_name,
                    d.donor_name,
                    d.purpose AS donation_purpose,
                    d.project_id,
                    pr.project_name,
                    orec.receipt_number,
                    TRIM(COALESCE(vu.firstname,'') || ' ' || COALESCE(vu.lastname,'')) AS verified_by_name
                FROM payments p
                JOIN payment_methods pm          ON pm.method_id = p.method_id
                JOIN appointments a              ON a.appointment_id = p.appointment_id
                JOIN services s                  ON s.service_id = a.service_id
                LEFT JOIN parishioners par       ON par.parishioner_id = a.parishioner_id
                LEFT JOIN users u                ON u.user_id = par.user_id
                LEFT JOIN mass_intentions mi      ON mi.appointment_id = a.appointment_id
                LEFT JOIN donations d             ON d.appointment_id = a.appointment_id
                LEFT JOIN projects pr             ON pr.project_id = d.project_id
                LEFT JOIN official_receipts orec ON orec.payment_id = p.payment_id
                LEFT JOIN users vu               ON vu.user_id = p.verified_by
                LEFT JOIN (
                    SELECT payment_id, MAX(transaction_id) AS max_tx_id
                    FROM transactions
                    GROUP BY payment_id
                ) t_max ON t_max.payment_id = p.payment_id
                LEFT JOIN transactions t ON t.transaction_id = t_max.max_tx_id
                WHERE $whereStr
                ORDER BY p.payment_date ASC, p.payment_id ASC
            ";

            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $r) {
                $amt    = (float) $r['amount'];
                $method = resolvePaymentMethodName((string) $r['method_name'], $r['gateway_raw_response'] ?? null, $r['reference_number'] ?? null, $r['gateway_transaction_id'] ?? null);
                $isCash = adminRptIsCash($method);
                $rawCat = (string) ($r['category'] ?: 'Uncategorized');
                $cat    = $rawCat;

                // Separate General Parish Donation vs Support an Ongoing Project
                if ($rawCat === 'Donation') {
                    $isProject = !empty($r['project_id']) || !empty($r['project_name']) || (string)($r['donation_purpose'] ?? '') === 'Ongoing Project';
                    if ($isProject) {
                        $cat = 'Support an Ongoing Project';
                        $proj = !empty($r['project_name']) ? $r['project_name'] : 'Project';
                        $particulars = 'Support an Ongoing Project — ' . $proj;
                    } else {
                        $cat = 'General Parish Donation';
                        $purp = !empty($r['donation_purpose']) ? $r['donation_purpose'] : 'General';
                        $particulars = 'General Parish Donation — ' . $purp;
                    }
                } else {
                    $particulars = (string) $r['service_name'];
                }

                // Resolve payor name
                $payor = trim((string) $r['par_name']);
                if ($rawCat === 'Mass Intention' && !empty($r['offerer_name'])) {
                    $payor = (string) $r['offerer_name'];
                } elseif ($rawCat === 'Donation' && !empty($r['donor_name'])) {
                    $payor = (string) $r['donor_name'];
                }
                if ($payor === '') {
                    $payor = trim((string) ($r['guest_name'] ?? '')) ?: '—';
                }

                // Receipt / ref number
                $refDisplay = (string) ($r['receipt_number'] ?? $r['reference_number'] ?? '—');
                if ($refDisplay === '') $refDisplay = 'APT-' . str_pad((string)$r['appointment_id'], 6, '0', STR_PAD_LEFT);

                $finRows[] = [
                    'date'        => $r['payment_date'],
                    'ref'         => $refDisplay,
                    'gateway_ref' => $r['reference_number'] ?? '',
                    'payor'       => $payor,
                    'particulars' => $particulars,
                    'category'    => $cat,
                    'method'      => $method,
                    'amount'      => $amt,
                    'status'      => $r['payment_status'],
                ];

                if ($r['payment_status'] === 'verified') {
                    $finGrand['count']++;
                    $finGrand['total'] += $amt;
                    if ($isCash) {
                        $finGrand['cash'] += $amt;
                    } else {
                        $finGrand['digital'] += $amt;
                    }

                    if (!isset($byMethod[$method])) {
                        $byMethod[$method] = ['count' => 0, 'total' => 0.0, 'is_cash' => $isCash];
                    }
                    $byMethod[$method]['count']++;
                    $byMethod[$method]['total'] += $amt;

                    if (!isset($byCategory[$cat])) {
                        $byCategory[$cat] = ['count' => 0, 'total' => 0.0];
                    }
                    $byCategory[$cat]['count']++;
                    $byCategory[$cat]['total'] += $amt;
                }
            }

            arsort($byMethod);
            arsort($byCategory);

        } else {
            // Operations Report (Bookings & Billing)
            $outsideCol = (function_exists('outsidePriestAvailable') && outsidePriestAvailable())
                ? 'a.outside_priest_name'
                : 'NULL AS outside_priest_name';

            $sql = "
                SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                       a.guest_name, {$outsideCol},
                       s.service_name, s.category::text AS category, COALESCE(s.fee, 0) AS fee,
                       st.status_name,
                       pr.title AS priest_title, pr.full_name AS priest_name,
                       u.firstname, u.lastname,
                       COALESCE(pay.paid, 0) AS paid,
                       d.project_id, proj.project_name, d.purpose AS donation_purpose
                FROM appointments a
                JOIN services s            ON s.service_id = a.service_id
                JOIN appointment_status st ON st.status_id = a.status_id
                LEFT JOIN priests pr       ON pr.priest_id = a.priest_id
                LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
                LEFT JOIN users u          ON u.user_id = par.user_id
                LEFT JOIN donations d      ON d.appointment_id = a.appointment_id
                LEFT JOIN projects proj    ON proj.project_id = d.project_id
                LEFT JOIN (
                    SELECT appointment_id, SUM(amount) AS paid
                    FROM payments
                    WHERE payment_status = 'verified'
                    GROUP BY appointment_id
                ) pay ON pay.appointment_id = a.appointment_id
                WHERE a.appointment_date BETWEEN ? AND ?
                ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.appointment_id ASC
            ";

            $stmt = db()->prepare($sql);
            $stmt->execute([$fromDate, $toDate]);

            foreach ($stmt->fetchAll() as $r) {
                $fee  = (float) $r['fee'];
                $paid = (float) $r['paid'];

                if ($fee > 0) {
                    $payStatus = $paid >= $fee ? 'Paid' : 'Pending';
                    $balance   = max($fee - $paid, 0.0);
                } else {
                    $payStatus = $paid > 0 ? 'Paid' : 'Waived';
                    $balance   = 0.0;
                }

                $client = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
                if ($client === '') {
                    $client = trim((string) ($r['guest_name'] ?? '')) ?: '—';
                }

                $priest = trim(($r['priest_title'] ?? '') . ' ' . ($r['priest_name'] ?? ''));
                if ($priest === '') {
                    $priest = trim((string) ($r['outside_priest_name'] ?? '')) ?: '—';
                }

                $rawCat = (string) ($r['category'] ?: 'Uncategorized');
                $cat = $rawCat;
                $serviceTitle = (string) $r['service_name'];

                if ($rawCat === 'Donation') {
                    $isProject = !empty($r['project_id']) || !empty($r['project_name']) || (string)($r['donation_purpose'] ?? '') === 'Ongoing Project';
                    if ($isProject) {
                        $cat = 'Support an Ongoing Project';
                        $serviceTitle = 'Support an Ongoing Project — ' . ($r['project_name'] ?: 'Project');
                    } else {
                        $cat = 'General Parish Donation';
                        $serviceTitle = 'General Parish Donation' . (!empty($r['donation_purpose']) ? ' — ' . $r['donation_purpose'] : '');
                    }
                }

                $opsRows[] = [
                    'date'       => $r['appointment_date'],
                    'time'       => $r['appointment_time'],
                    'reference'  => adminRptReference((int) $r['appointment_id']),
                    'service'    => $serviceTitle,
                    'category'   => $cat,
                    'client'     => $client,
                    'priest'     => $priest,
                    'status'     => $r['status_name'],
                    'fee'        => $fee,
                    'paid'       => $paid,
                    'balance'    => $balance,
                    'payStatus'  => $payStatus,
                ];

                if (!isset($opsCategories[$cat])) {
                    $opsCategories[$cat] = ['bookings' => 0, 'billed' => 0.0, 'collected' => 0.0, 'balance' => 0.0];
                }
                $opsCategories[$cat]['bookings']++;
                $opsCategories[$cat]['billed']    += $fee;
                $opsCategories[$cat]['collected'] += $paid;
                $opsCategories[$cat]['balance']   += $balance;

                $opsGrand['bookings']++;
                $opsGrand['billed']    += $fee;
                $opsGrand['collected'] += $paid;
                $opsGrand['balance']   += $balance;
            }

            arsort($opsCategories);
        }
    } catch (Throwable $e) {
        error_log('Admin report query error: ' . $e->getMessage());
        $filterError = 'Error generating report: ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------
 * Metadata
 * ------------------------------------------------------------- */
$me = currentUser() ?? [];
$preparedBy = trim(($me['firstname'] ?? '') . ' ' . ($me['lastname'] ?? ''));
if ($preparedBy === '') $preparedBy = 'Administrator';

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

$active    = 'reports';
$pageTitle = 'Parish Administrative Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<style>
/* ── Screen layout ─────────────────────────────────────────── */
.rpt-nav { display:flex; gap:10px; margin-bottom:18px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; }
.rpt-nav a { padding:8px 16px; border-radius:6px; font-weight:600; font-size:13.5px; text-decoration:none; color:#475569; }
.rpt-nav a.active { background:var(--primary, #15803d); color:#fff; }
.rpt-nav a:not(.active):hover { background:#f1f5f9; color:#1e293b; }

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
  @page { size:A4 landscape; margin:12mm;
    @bottom-right { content:"Page " counter(page) " of " counter(pages); font:8pt Arial,sans-serif; color:#555; } }
  html,body { background:#fff !important; }
  .sidebar,.topbar,.no-print,.flash,.flash-message,.alert,
  nav,header,footer,.btn,.rpt-nav { display:none !important; }
  .app-shell,.main-content,.page-body,.main,.content {
    margin:0 !important; padding:0 !important; width:100% !important;
    max-width:none !important; background:#fff !important; box-shadow:none !important;
  }
  .trpt-sheet { border:none; border-radius:0; padding:0; }
  .trpt-cards { gap:10px; margin:14px 0; }
  /* Same pagination rules as treasurer/reports.php: totals print once at the
     end of a ledger instead of repeating at the foot of every page. */
  .trpt-sheet tr,.trpt-sheet td,.trpt-sheet th { page-break-inside:avoid; }
  .trpt-sheet thead { display:table-header-group; }
  .trpt-sheet tfoot { display:table-row-group; }
  .trpt-section { break-after:avoid; }
}
</style>

<!-- Report Type Tabs -->
<div class="rpt-nav no-print">
  <a href="<?= url('admin/reports.php?report_type=financial&from_date=' . urlencode($formFrom) . '&to_date=' . urlencode($formTo) . ($generated ? '&generate=1' : '')) ?>"
     class="<?= $reportType === 'financial' ? 'active' : '' ?>">
    💰 Cash Receipts &amp; Revenue Collections (Financial / Cashier)
  </a>
  <a href="<?= url('admin/reports.php?report_type=operations&from_date=' . urlencode($formFrom) . '&to_date=' . urlencode($formTo) . ($generated ? '&generate=1' : '')) ?>"
     class="<?= $reportType === 'operations' ? 'active' : '' ?>">
    📋 Bookings, Billing &amp; Service Collections (Secretariat)
  </a>
</div>

<!-- Filter card -->
<div class="card no-print">
  <form method="GET" action="<?= url('admin/reports.php') ?>">
    <input type="hidden" name="report_type" value="<?= e($reportType) ?>">
    <div class="trpt-filter">
      <div class="fld">
        <label for="f_from">Date From</label>
        <input type="date" id="f_from" name="from_date" value="<?= e($formFrom) ?>" required>
      </div>
      <div class="fld">
        <label for="f_to">Date To</label>
        <input type="date" id="f_to" name="to_date" value="<?= e($formTo) ?>" required>
      </div>

      <div class="fld">
        <button type="submit" name="generate" value="1" class="btn btn-primary btn-block">
          Generate Report
        </button>
      </div>
    </div>

    <?php if ($generated): ?>
    <div class="trpt-actions">
      <span style="font-size:13px; color:#64748b;">
        Showing report for <strong><?= e(formatDate($fromDate)) ?></strong> to <strong><?= e(formatDate($toDate)) ?></strong>
      </span>
      <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
        🖨️ Print / Export Official Statement
      </button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($filterError): ?>
  <div class="alert alert-danger no-print" style="margin-top:16px;"><?= e($filterError) ?></div>
<?php endif; ?>

<?php if (!$generated): ?>
  <div class="card no-print" style="margin-top:20px; text-align:center; padding:40px 20px; color:#64748b;">
    <div style="font-size:42px; margin-bottom:12px;">📊</div>
    <h3 style="margin:0 0 6px; color:#1e293b;">Parish Administration Reports</h3>
    <p style="margin:0; font-size:14px;">Select a date range and click <strong>Generate Report</strong> to view the official statement.</p>
  </div>
<?php else: ?>

  <?php if ($reportType === 'financial'): ?>
    <!-- ══════════════════════════════════════════════════════════
         FINANCIAL REPORT (Cashier / Revenue Collections)
         ══════════════════════════════════════════════════════════ -->
    <div class="trpt-cards no-print">
      <div class="trpt-card verified">
        <div class="lbl">Total Verified Collections</div>
        <div class="val"><?= e(money($finGrand['total'])) ?></div>
        <div style="font-size:11px;color:#555;margin-top:4px;"><?= (int)$finGrand['count'] ?> verified transactions</div>
      </div>
      <div class="trpt-card cash">
        <div class="lbl">Cash on Hand</div>
        <div class="val"><?= e(money($finGrand['cash'])) ?></div>
      </div>
      <div class="trpt-card digital">
        <div class="lbl">Online / Digital Collections</div>
        <div class="val"><?= e(money($finGrand['digital'])) ?></div>
      </div>
      <div class="trpt-card pending">
        <div class="lbl">Pending Verification</div>
        <div class="val"><?= e(money($finGrand['pending'])) ?></div>
      </div>
    </div>

    <div class="trpt-sheet">
      <!-- Letterhead -->
      <div class="trpt-letterhead">
        <?php if (hasParishLogo()): ?>
          <img src="<?= e(url('public/img/logo-transparent.png')) ?>" alt="Parish Logo">
        <?php endif; ?>
        <div>
          <p class="parish"><?= e(strtoupper(adminRptParishName())) ?></p>
          <p class="sub">Office of the Parish Administration &amp; Finance &nbsp;|&nbsp; Financial Management System</p>
        </div>
      </div>

      <h2 class="trpt-doc-title">Official Statement of Cash Receipts &amp; Revenue Collections</h2>
      <p class="trpt-meta"><?= e($periodLabel) ?></p>
      
      <div class="trpt-meta-bar">
        <span>Date &amp; Time Generated: <?= e($generatedOn) ?> &nbsp;|&nbsp; Prepared by: <?= e($preparedBy) ?></span>
        <span>Total Entries: <?= count($finRows) ?></span>
      </div>

      <!-- Section I & II -->
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
                <?php $pct = $finGrand['total'] > 0 ? round($md['total'] / $finGrand['total'] * 100, 1) : 0; ?>
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
                <td class="num"><?= (int)$finGrand['count'] ?></td>
                <td class="num"><?= e(number_format($finGrand['total'], 2)) ?></td>
                <td class="num">100%</td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>

        <!-- By Service / Revenue Source (separated General Parish Donation vs Support an Ongoing Project) -->
        <div>
          <div class="trpt-section">II. Collections by Service / Revenue Source</div>
          <table>
            <thead>
              <tr>
                <th>Service / Revenue Source</th>
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
                <td class="num"><?= (int)$finGrand['count'] ?></td>
                <td class="num"><?= e(number_format($finGrand['total'], 2)) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <!-- Section III: Recapitulation -->
      <?php if (!empty($finRows)): ?>
      <div class="trpt-section">III. Recapitulation of Cash Inflow</div>
      <div class="trpt-recap">
        <table>
          <tr>
            <td>Subtotal — Cash on Hand:</td>
            <td class="num"><?= e(number_format($finGrand['cash'], 2)) ?></td>
          </tr>
          <tr>
            <td>Subtotal — Bank / Digital Payments:</td>
            <td class="num"><?= e(number_format($finGrand['digital'], 2)) ?></td>
          </tr>
          <tr class="grand">
            <td>GRAND TOTAL — Verified Collections:</td>
            <td class="num"><?= e(number_format($finGrand['total'], 2)) ?></td>
          </tr>
        </table>
      </div>
      <?php endif; ?>

      <!-- Section IV: Detailed Ledger -->
      <div class="trpt-section">IV. Detailed Cash Receipts Journal</div>
      <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th style="width:11%;">Date &amp; Time</th>
            <th style="width:11%;">Receipt / Ref #</th>
            <th style="width:16%;">Payor / Donor</th>
            <th>Particulars &amp; Category</th>
            <th style="width:11%;">Method</th>
            <th style="width:12%;">Gateway / Bank Ref #</th>
            <th class="num" style="width:10%;">Amount (₱)</th>
            <th style="width:8%;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($finRows)): ?>
            <tr><td colspan="8" class="trpt-empty">No transactions for the selected filters.</td></tr>
          <?php endif; ?>
          <?php foreach ($finRows as $row): ?>
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
        <?php if (!empty($finRows)): ?>
        <tfoot>
          <tr>
            <td colspan="6">Sub-Total — Verified Collections</td>
            <td class="num"><?= e(number_format($finGrand['total'], 2)) ?></td>
            <td></td>
          </tr>
          <tr>
            <td colspan="6" style="color:#b45309;">Pending Verification</td>
            <td class="num" style="color:#b45309;"><?= e(number_format($finGrand['pending'], 2)) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
      </div>

      <!-- Signatories -->
      <div class="trpt-signatories">
        <div class="trpt-sig">
          <div class="lbl2">Prepared by:</div>
          <div class="line"></div>
          <div class="name"><?= e($preparedBy) ?></div>
          <div class="role">Parish Administrator</div>
        </div>
        <div class="trpt-sig">
          <div class="lbl2">Verified &amp; Received by:</div>
          <div class="line"></div>
          <div class="name">Parish Priest</div>
          <div class="role"><?= e(adminRptParishName()) ?></div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════
         OPERATIONS REPORT (Secretariat / Bookings & Billing)
         ══════════════════════════════════════════════════════════ -->
    <div class="trpt-cards no-print">
      <div class="trpt-card verified">
        <div class="lbl">Total Bookings</div>
        <div class="val"><?= (int)$opsGrand['bookings'] ?></div>
      </div>
      <div class="trpt-card cash">
        <div class="lbl">Total Billed Fees</div>
        <div class="val"><?= e(money($opsGrand['billed'])) ?></div>
      </div>
      <div class="trpt-card digital">
        <div class="lbl">Total Collected</div>
        <div class="val"><?= e(money($opsGrand['collected'])) ?></div>
      </div>
      <div class="trpt-card pending">
        <div class="lbl">Outstanding Balance</div>
        <div class="val"><?= e(money($opsGrand['balance'])) ?></div>
      </div>
    </div>

    <div class="trpt-sheet">
      <!-- Letterhead -->
      <div class="trpt-letterhead">
        <?php if (hasParishLogo()): ?>
          <img src="<?= e(url('public/img/logo-transparent.png')) ?>" alt="Parish Logo">
        <?php endif; ?>
        <div>
          <p class="parish"><?= e(strtoupper(adminRptParishName())) ?></p>
          <p class="sub">Office of the Parish Administration &amp; Secretariat &nbsp;|&nbsp; Parish Management System</p>
        </div>
      </div>

      <h2 class="trpt-doc-title">Official Statement of Bookings, Billing &amp; Collections</h2>
      <p class="trpt-meta"><?= e($periodLabel) ?></p>
      <div class="trpt-meta-bar">
        <span>Date &amp; Time Generated: <?= e($generatedOn) ?> &nbsp;|&nbsp; Prepared by: <?= e($preparedBy) ?></span>
        <span>Total Appointments: <?= count($opsRows) ?></span>
      </div>

      <!-- Section I: Summary by Category -->
      <div class="trpt-section" style="margin-top:20px;">I. Bookings &amp; Financial Summary by Category</div>
      <table>
        <thead>
          <tr>
            <th>Service / Category</th>
            <th class="num">Bookings</th>
            <th class="num">Total Billed (₱)</th>
            <th class="num">Collected (₱)</th>
            <th class="num">Balance (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($opsCategories)): ?>
            <tr><td colspan="5" class="trpt-empty">No bookings found for the selected period.</td></tr>
          <?php endif; ?>
          <?php foreach ($opsCategories as $catName => $cd): ?>
            <tr>
              <td><?= e($catName) ?></td>
              <td class="num"><?= (int)$cd['bookings'] ?></td>
              <td class="num"><?= e(number_format($cd['billed'], 2)) ?></td>
              <td class="num"><?= e(number_format($cd['collected'], 2)) ?></td>
              <td class="num"><?= e(number_format($cd['balance'], 2)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (!empty($opsCategories)): ?>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="num"><?= (int)$opsGrand['bookings'] ?></td>
            <td class="num"><?= e(number_format($opsGrand['billed'], 2)) ?></td>
            <td class="num"><?= e(number_format($opsGrand['collected'], 2)) ?></td>
            <td class="num"><?= e(number_format($opsGrand['balance'], 2)) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>

      <!-- Section II: Detailed Ledger -->
      <div class="trpt-section">II. Detailed Appointments &amp; Collections Ledger</div>
      <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th style="width:10%;">Date &amp; Time</th>
            <th style="width:10%;">Ref #</th>
            <th style="width:16%;">Service &amp; Category</th>
            <th style="width:16%;">Parishioner / Client</th>
            <th style="width:14%;">Priest Assigned</th>
            <th style="width:9%;">Status</th>
            <th class="num" style="width:8%;">Billed (₱)</th>
            <th class="num" style="width:8%;">Paid (₱)</th>
            <th class="num" style="width:8%;">Balance (₱)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($opsRows)): ?>
            <tr><td colspan="9" class="trpt-empty">No appointment records for this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($opsRows as $row): ?>
            <tr>
              <td>
                <?= e(formatDate($row['date'])) ?><br>
                <span class="trpt-muted"><?= e($row['time'] ? date('g:i A', strtotime($row['time'])) : '—') ?></span>
              </td>
              <td><?= e($row['reference']) ?></td>
              <td>
                <?= e($row['service']) ?><br>
                <span class="trpt-muted"><?= e($row['category']) ?></span>
              </td>
              <td><?= e($row['client']) ?></td>
              <td><?= e($row['priest']) ?></td>
              <td><?= e($row['status']) ?></td>
              <td class="num"><?= e(number_format($row['fee'], 2)) ?></td>
              <td class="num"><?= e(number_format($row['paid'], 2)) ?></td>
              <td class="num"><?= e(number_format($row['balance'], 2)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <?php if (!empty($opsRows)): ?>
        <tfoot>
          <tr>
            <td colspan="6">Grand Totals</td>
            <td class="num"><?= e(number_format($opsGrand['billed'], 2)) ?></td>
            <td class="num"><?= e(number_format($opsGrand['collected'], 2)) ?></td>
            <td class="num"><?= e(number_format($opsGrand['balance'], 2)) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
      </div>

      <!-- Signatories -->
      <div class="trpt-signatories">
        <div class="trpt-sig">
          <div class="lbl2">Prepared by:</div>
          <div class="line"></div>
          <div class="name"><?= e($preparedBy) ?></div>
          <div class="role">Parish Administrator</div>
        </div>
        <div class="trpt-sig">
          <div class="lbl2">Attested &amp; Verified by:</div>
          <div class="line"></div>
          <div class="name">Parish Priest</div>
          <div class="role"><?= e(adminRptParishName()) ?></div>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>



<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
