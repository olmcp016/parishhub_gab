<?php
/**
 * PARISHHUB — Secretary Reports (operational monitoring; no financial data).
 *
 * Every section is computed from ONE filtered set of appointment rows, so the
 * summary totals always equal the detailed records below them:
 *
 *   - "Bookings" = appointments BOOKED (created_at) in the selected period,
 *     narrowed by service / status / booking reference. Feeds the Appointment
 *     Summary, booking trend, Services Summary, Requirements Monitoring,
 *     Pending & Overdue, Awaiting Final Schedule and Cancellations & Rejections.
 *   - "Service Schedule" = appointments whose SERVICE DATE (appointment_date)
 *     falls in the period, with the same service / status / reference filters.
 *
 * Donations are stored as appointments but are not parish-service bookings:
 * always excluded. Mass Intentions are counted in the summaries, but not in
 * approval, requirements or overdue monitoring (handled by the Cashier).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/scheduling.php';
require_once __DIR__ . '/wedding-draft.php';
require_once __DIR__ . '/baptism-draft.php';
require_once __DIR__ . '/funeral-draft.php';
require_once __DIR__ . '/appointment-workflow.php'; // also loads FPDF (vendor/autoload.php)
require_once __DIR__ . '/requirements-review.php';

const REPORT_PERIODS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom Date Range'];
const REPORT_OVERDUE_DAYS = 3;   // pending approval longer than this after booking
const REPORT_URGENT_DAYS = 7;    // pending with a service date within this many days
const REPORT_ACTIVE_STATUS_IDS = [1, 2, 4, 5]; // Pending, Approved, Payment Verified, Confirmed
const REPORT_SECRETARY_EXCLUDED = ['Mass Intention']; // not approved / reviewed by the Secretary

function reportParseDate(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

/** Normalised filters from the query string (invalid values fall back to defaults). */
function reportFilters(array $query, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $period = array_key_exists($query['period'] ?? '', REPORT_PERIODS) ? (string) $query['period'] : 'monthly';
    $anchor = reportParseDate($query['date'] ?? null) ?? $today;

    switch ($period) {
        case 'daily':
            $start = $end = $anchor;
            $label = $anchor->format('F j, Y');
            break;
        case 'weekly': // Monday to Sunday
            $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
            $end = $start->modify('+6 days');
            $label = 'Week of ' . $start->format('M j') . ' – ' . $end->format('M j, Y');
            break;
        case 'yearly':
            $start = $anchor->setDate((int) $anchor->format('Y'), 1, 1);
            $end = $anchor->setDate((int) $anchor->format('Y'), 12, 31);
            $label = $anchor->format('Y');
            break;
        case 'custom':
            $start = reportParseDate($query['from'] ?? null) ?? $today->modify('first day of this month');
            $end = reportParseDate($query['to'] ?? null) ?? $today;
            if ($start > $end) [$start, $end] = [$end, $start];
            $label = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
            break;
        default: // monthly
            $start = $anchor->modify('first day of this month');
            $end = $anchor->modify('last day of this month');
            $label = $anchor->format('F Y');
    }

    $statusId = (int) ($query['status'] ?? 0);
    return [
        'period' => $period,
        'date' => $anchor->format('Y-m-d'),
        'from' => $start->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
        'label' => $label,
        'service_id' => max(0, (int) ($query['service'] ?? 0)),
        'status_id' => $statusId >= 1 && $statusId <= 7 ? $statusId : 0,
        'ref' => mb_substr(trim((string) ($query['ref'] ?? '')), 0, 40),
        'today' => $today->format('Y-m-d'),
    ];
}

/** Query-string parameters that reproduce these filters (links, print, PDF). */
function reportQuery(array $f, array $extra = []): array
{
    $q = ['period' => $f['period']];
    if ($f['period'] === 'custom') { $q['from'] = $f['from']; $q['to'] = $f['to']; } else { $q['date'] = $f['date']; }
    if ($f['service_id']) $q['service'] = $f['service_id'];
    if ($f['status_id']) $q['status'] = $f['status_id'];
    if ($f['ref'] !== '') $q['ref'] = $f['ref'];
    return $q + $extra;
}

/** Shared SELECT + service / status / reference filters. */
function reportBaseSql(array $f, array &$params): string
{
    $sql = "SELECT a.*, s.service_name, s.category, s.requirements, st.status_name, u.firstname, u.lastname, p.full_name AS priest_name
            FROM appointments a
            JOIN services s ON s.service_id = a.service_id
            JOIN appointment_status st ON st.status_id = a.status_id
            LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
            LEFT JOIN users u ON u.user_id = par.user_id
            LEFT JOIN priests p ON p.priest_id = a.priest_id
            WHERE s.category <> 'Donation'";
    if ($f['service_id']) { $sql .= ' AND a.service_id = ?'; $params[] = $f['service_id']; }
    if ($f['status_id']) { $sql .= ' AND a.status_id = ?'; $params[] = $f['status_id']; }
    if ($f['ref'] !== '') {
        $like = strtoupper(likeSafe(ltrim($f['ref'], '#')));
        if (preg_match('/^#?(\d+)$/', $f['ref'], $m)) {
            $sql .= " AND (a.appointment_id = ? OR UPPER(COALESCE(a.guest_reference, '')) LIKE ? ESCAPE '\\')";
            $params[] = (int) $m[1];
        } else {
            $sql .= " AND UPPER(COALESCE(a.guest_reference, '')) LIKE ? ESCAPE '\\'";
        }
        $params[] = $like;
    }
    return $sql;
}

/** Appointments booked (created_at) within the period, one row per appointment. */
function reportBookings(array $f): array
{
    $params = [];
    $tz = new DateTimeZone(date_default_timezone_get());
    $sql = reportBaseSql($f, $params) . ' AND a.created_at >= ? AND a.created_at < ? ORDER BY a.created_at, a.appointment_id';
    $params[] = (new DateTimeImmutable($f['from'] . ' 00:00:00', $tz))->format('Y-m-d H:i:sP');
    $params[] = (new DateTimeImmutable($f['to'] . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d H:i:sP');
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) $rows[(int) $row['appointment_id']] = $row; // never count one appointment twice
    return array_values($rows);
}

/** Appointments whose service date falls within the period (not rejected / cancelled). */
function reportScheduled(array $f): array
{
    $params = [];
    $sql = reportBaseSql($f, $params) . ' AND a.appointment_date >= ? AND a.appointment_date <= ? AND a.status_id NOT IN (3, 7)
            ORDER BY a.appointment_date, a.appointment_time, a.appointment_id';
    $params[] = $f['from'];
    $params[] = $f['to'];
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) $rows[(int) $row['appointment_id']] = $row;
    return array_values($rows);
}

function reportApplicant(array $row): string
{
    $guest = trim((string) ($row['guest_name'] ?? ''));
    return $guest !== '' ? $guest . ' (guest)' : trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
}

function reportReference(array $row): string
{
    return (string) (($row['guest_reference'] ?? '') ?: 'Appointment #' . (int) $row['appointment_id']);
}

function reportLocalTime(?string $timestamp): ?DateTimeImmutable
{
    if (!$timestamp) return null;
    try {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        return null;
    }
}

function reportTime(?string $time): string
{
    return $time ? date('g:i A', strtotime($time)) : '';
}

/** Outstanding (missing / rejected / not yet generated) requirements, from the same check the Approve button uses. */
function reportOutstandingRequirements(array $row): array
{
    try {
        $reasons = appointmentApprovalEligibility($row)['blocking_reasons'];
    } catch (Throwable $e) {
        error_log('Report requirement check failed for appointment #' . $row['appointment_id'] . ': ' . $e->getMessage());
        return ['Could not be checked — open the appointment.'];
    }
    // Priest / PSS steps and items merely awaiting review are not missing requirements.
    return array_values(array_filter($reasons, static fn(string $r): bool => !preg_match('/priest|PSS classification|still pending review|must be approved/i', $r)));
}

/** Everything the page and the PDF show, from the same filtered rows. */
function reportData(array $f): array
{
    $bookings = reportBookings($f);
    $today = new DateTimeImmutable($f['today']);
    $urgentUntil = $today->modify('+' . REPORT_URGENT_DAYS . ' days');
    $reviewAvailable = requirementsReviewAvailable();

    // 1. Appointment Summary (all seven statuses, even at zero)
    $statusCounts = [];
    foreach (db()->query('SELECT status_id, status_name FROM appointment_status ORDER BY status_id')->fetchAll() as $s) {
        $statusCounts[(int) $s['status_id']] = ['name' => $s['status_name'], 'count' => 0];
    }
    // Booking trend: per day, or per month for a year / a long custom range.
    $byMonth = $f['period'] === 'yearly' || ($f['period'] === 'custom' && (new DateTimeImmutable($f['from']))->diff(new DateTimeImmutable($f['to']))->days > 62);
    $trend = [];
    $services = [];
    $massIntentions = 0;
    foreach ($bookings as $row) {
        $statusId = (int) $row['status_id'];
        if (!isset($statusCounts[$statusId])) $statusCounts[$statusId] = ['name' => $row['status_name'], 'count' => 0];
        $statusCounts[$statusId]['count']++;
        if ($row['category'] === 'Mass Intention') $massIntentions++;

        $booked = reportLocalTime($row['created_at']);
        $key = $booked ? $booked->format($byMonth ? 'Y-m' : 'Y-m-d') : 'unknown';
        $trend[$key] ??= ['label' => $booked ? $booked->format($byMonth ? 'F Y' : 'D, M j, Y') : 'Unknown date', 'count' => 0];
        $trend[$key]['count']++;

        // 2. Services Summary
        $sid = (int) $row['service_id'];
        $services[$sid] ??= ['service' => $row['service_name'], 'category' => $row['category'], 'total' => 0, 'completed' => 0, 'upcoming' => 0, 'cancelled' => 0];
        $services[$sid]['total']++;
        if ($statusId === 6) $services[$sid]['completed']++;
        if ($statusId === 7) $services[$sid]['cancelled']++;
        if (in_array($statusId, REPORT_ACTIVE_STATUS_IDS, true) && !empty($row['appointment_date']) && $row['appointment_date'] >= $f['today']) $services[$sid]['upcoming']++;
    }
    ksort($trend);
    uasort($services, static fn(array $a, array $b): int => [$b['total'], $a['service']] <=> [$a['total'], $b['service']]);

    // 3 + 5. Pending approval, requirements (Secretary-handled services only)
    $pending = array_values(array_filter($bookings, static fn(array $r): bool => (int) $r['status_id'] === 1 && !in_array($r['category'], REPORT_SECRETARY_EXCLUDED, true)));
    $pendingDocs = [];
    if ($pending) {
        $ids = array_map(static fn(array $r): int => (int) $r['appointment_id'], $pending);
        $stmt = db()->prepare(
            'SELECT appointment_id, COUNT(*) AS total FROM uploaded_documents
             WHERE superseded_by IS NULL AND (review_status = \'pending\' OR (review_status IS NULL AND verified = FALSE))
               AND appointment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             GROUP BY appointment_id'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $d) $pendingDocs[(int) $d['appointment_id']] = (int) $d['total'];
    }
    $requirements = [];
    $req = ['pending_docs' => 0, 'pending_doc_items' => 0, 'awaiting_revision' => $reviewAvailable ? 0 : null, 'ready_for_rereview' => $reviewAvailable ? 0 : null, 'incomplete' => 0];
    $approvals = [];
    $flags = ['overdue' => 0, 'urgent' => 0, 'attention' => 0];
    foreach ($pending as $row) {
        $id = (int) $row['appointment_id'];
        $docs = $pendingDocs[$id] ?? 0;
        $reviewStatus = $reviewAvailable ? ($row['requirements_review_status'] ?? null) : null;
        $outstanding = reportOutstandingRequirements($row);
        if ($docs) { $req['pending_docs']++; $req['pending_doc_items'] += $docs; }
        if ($reviewStatus === 'awaiting_revision') $req['awaiting_revision']++;
        if ($reviewStatus === 'ready_for_rereview') $req['ready_for_rereview']++;
        if ($outstanding) $req['incomplete']++;
        if ($docs || $reviewStatus || $outstanding) {
            $requirements[] = ['row' => $row, 'pending_docs' => $docs, 'review_status' => $reviewStatus, 'outstanding' => $outstanding];
        }

        // Calendar days since the booking date (Manila), so "Days Pending" and "Overdue" always agree.
        $booked = reportLocalTime($row['created_at']);
        $days = $booked ? max(0, (int) $booked->setTime(0, 0)->diff($today)->format('%r%a')) : 0;
        $overdue = $days > REPORT_OVERDUE_DAYS;
        $serviceDate = !empty($row['appointment_date']) ? new DateTimeImmutable(substr((string) $row['appointment_date'], 0, 10)) : null;
        $urgent = $serviceDate && $serviceDate >= $today && $serviceDate <= $urgentUntil;
        $flags['overdue'] += (int) $overdue;
        $flags['urgent'] += (int) $urgent;
        $flags['attention'] += (int) ($overdue || $urgent);
        $approvals[] = ['row' => $row, 'days' => $days, 'overdue' => $overdue, 'urgent' => $urgent, 'review_status' => $reviewStatus];
    }
    // Urgent first, then overdue, then oldest booking.
    usort($approvals, static fn(array $a, array $b): int => [(int) !$a['urgent'], (int) !$a['overdue'], -$a['days']] <=> [(int) !$b['urgent'], (int) !$b['overdue'], -$b['days']]);

    // 4. Awaiting Final Schedule (still going ahead, no date or time yet)
    $awaitingSchedule = array_values(array_filter($bookings, static fn(array $r): bool =>
        in_array((int) $r['status_id'], REPORT_ACTIVE_STATUS_IDS, true) && (empty($r['appointment_date']) || empty($r['appointment_time']))));

    // 6. Cancellations and rejections (date = updated_at, shown as "Last Updated")
    $closed = array_values(array_filter($bookings, static fn(array $r): bool => in_array((int) $r['status_id'], [3, 7], true)));
    usort($closed, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

    return [
        'total' => count($bookings),
        'mass_intentions' => $massIntentions,
        'statuses' => $statusCounts,
        'trend' => array_values($trend),
        'trend_by_month' => $byMonth,
        'services' => array_values($services),
        'requirements' => $requirements,
        'requirement_counts' => $req,
        'review_available' => $reviewAvailable,
        'approvals' => $approvals,
        'approval_flags' => $flags,
        'schedule' => reportScheduled($f),
        'awaiting_schedule' => $awaitingSchedule,
        'closed' => $closed,
    ];
}

function reportReviewLabel(?string $status): string
{
    return ['awaiting_revision' => 'Awaiting Revision', 'ready_for_rereview' => 'Ready for Re-Review'][$status] ?? 'Pending Review';
}

/** Human-readable list of the active filters (print header, PDF). */
function reportFilterSummary(array $f, array $serviceNames, array $statusNames): string
{
    $parts = [REPORT_PERIODS[$f['period']] . ': ' . $f['label']];
    $parts[] = 'Service: ' . ($f['service_id'] ? ($serviceNames[$f['service_id']] ?? 'Unknown') : 'All services');
    $parts[] = 'Status: ' . ($f['status_id'] ? ($statusNames[$f['status_id']] ?? 'Unknown') : 'All statuses');
    if ($f['ref'] !== '') $parts[] = 'Reference: ' . $f['ref'];
    return implode('  ·  ', $parts);
}

/* ------------------------------------------------------------------ PDF */

if (class_exists('FPDF') && !class_exists(ParishReportPdf::class)) {
    class ParishReportPdf extends FPDF
    {
        public string $generatedAt = '';

        function Header(): void
        {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(122, 92, 30);
            $this->Cell(0, 6, reportPdfText('Our Lady of Mt. Carmel Parish — ParishHub Secretary Report'), 0, 1, 'L');
            $this->SetDrawColor(201, 168, 76);
            $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
            $this->Ln(3);
        }

        function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('Helvetica', '', 8);
            $this->SetTextColor(120, 106, 84);
            $this->Cell(0, 6, reportPdfText('Generated ' . $this->generatedAt . '   ·   Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
        }
    }
}

/** FPDF core fonts are Windows-1252. */
function reportPdfText(?string $text): string
{
    $text = str_replace(['—', '–', '·', '…', '’', '‘', '“', '”', '₱'], ['-', '-', '-', '...', "'", "'", '"', '"', 'PHP '], (string) $text);
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
    return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
}

function reportPdfFit(FPDF $pdf, string $text, float $width): string
{
    $text = reportPdfText($text);
    if ($pdf->GetStringWidth($text) <= $width - 2) return $text;
    while ($text !== '' && $pdf->GetStringWidth($text . '...') > $width - 2) $text = substr($text, 0, -1);
    return $text . '...';
}

function reportPdfHeading(FPDF $pdf, string $title, ?string $note = null): void
{
    if ($pdf->GetY() > $pdf->GetPageHeight() - 45) $pdf->AddPage();
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(59, 47, 30);
    $pdf->Cell(0, 7, reportPdfText($title), 0, 1);
    if ($note) {
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(120, 106, 84);
        $pdf->MultiCell(0, 4.5, reportPdfText($note));
    }
    $pdf->Ln(1);
}

/** A table that repeats its header row after a page break; long cells are shortened. */
function reportPdfTable(FPDF $pdf, array $headers, array $widths, array $rows, string $emptyMessage): void
{
    $drawHeader = static function () use ($pdf, $headers, $widths): void {
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetFillColor(240, 233, 214);
        $pdf->SetTextColor(59, 47, 30);
        $pdf->SetDrawColor(226, 216, 196);
        foreach ($headers as $i => $h) $pdf->Cell($widths[$i], 7, reportPdfText($h), 1, 0, 'L', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(58, 46, 34);
    };
    $drawHeader();
    if (!$rows) {
        $pdf->SetFont('Helvetica', 'I', 8.5);
        $pdf->Cell(array_sum($widths), 7, reportPdfText($emptyMessage), 1, 1, 'C');
        return;
    }
    foreach ($rows as $row) {
        if ($pdf->GetY() + 6.5 > $pdf->GetPageHeight() - 16) { $pdf->AddPage(); $drawHeader(); }
        foreach ($row as $i => $cell) $pdf->Cell($widths[$i], 6.5, reportPdfFit($pdf, (string) $cell, $widths[$i]), 1, 0, is_int($cell) ? 'R' : 'L');
        $pdf->Ln();
    }
}

/** The full report as PDF bytes (same data as the page). */
function reportPdf(array $f, array $d, string $filterSummary): string
{
    if (!class_exists(ParishReportPdf::class)) throw new RuntimeException('The PDF library is not installed.');
    $pdf = new ParishReportPdf('L', 'mm', 'A4');
    $pdf->generatedAt = date('M j, Y g:i A');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(59, 47, 30);
    $pdf->Cell(0, 9, 'Secretary Reports', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(90, 74, 54);
    $pdf->MultiCell(0, 5, reportPdfText($filterSummary));
    $pdf->MultiCell(0, 5, reportPdfText('Bookings are counted by booking date; the Service Schedule uses the scheduled service date. Donations are excluded.'));

    $statusRows = [];
    foreach ($d['statuses'] as $s) $statusRows[] = [$s['name'], $s['count']];
    $statusRows[] = ['Total appointments', $d['total']];
    reportPdfHeading($pdf, '1. Appointment Summary (by booking date)', $d['mass_intentions'] ? "Includes {$d['mass_intentions']} Mass Intention(s)." : null);
    reportPdfTable($pdf, ['Status', 'Appointments'], [90, 40], $statusRows, 'No appointments were booked in this period.');
    reportPdfHeading($pdf, 'Bookings by ' . ($d['trend_by_month'] ? 'Month' : 'Day') . ' (booking date)');
    reportPdfTable($pdf, [$d['trend_by_month'] ? 'Month' : 'Booking Date', 'Bookings'], [90, 40], array_map(static fn(array $t): array => [$t['label'], $t['count']], $d['trend']), 'No bookings in this period.');

    reportPdfHeading($pdf, '2. Services Summary (by booking date)');
    $serviceRows = array_map(static fn(array $s): array => [$s['service'], $s['category'], $s['total'], $s['completed'], $s['upcoming'], $s['cancelled']], $d['services']);
    if ($serviceRows) $serviceRows[] = ['Total', '', $d['total'], array_sum(array_column($d['services'], 'completed')), array_sum(array_column($d['services'], 'upcoming')), array_sum(array_column($d['services'], 'cancelled'))];
    reportPdfTable($pdf, ['Service', 'Category', 'Total Bookings', 'Completed', 'Upcoming', 'Cancelled'], [80, 50, 35, 35, 35, 35], $serviceRows, 'No service bookings in this period.');

    $rc = $d['requirement_counts'];
    reportPdfHeading($pdf, '3. Requirements Monitoring (by booking date)', sprintf('Pending document reviews: %d appointment(s), %d item(s)  ·  Awaiting Revision: %s  ·  Ready for Re-Review: %s  ·  Incomplete requirements: %d. Mass Intentions excluded.',
        $rc['pending_docs'], $rc['pending_doc_items'], $rc['awaiting_revision'] ?? 'n/a', $rc['ready_for_rereview'] ?? 'n/a', $rc['incomplete']));
    reportPdfTable($pdf, ['Reference', 'Service', 'Applicant', 'Pending Docs', 'Review Status', 'Outstanding Requirements'], [32, 38, 45, 22, 32, 108],
        array_map(static fn(array $r): array => [reportReference($r['row']), $r['row']['service_name'], reportApplicant($r['row']), $r['pending_docs'], reportReviewLabel($r['review_status']), implode(' ', $r['outstanding']) ?: '—'], $d['requirements']),
        'No pending requirements to monitor.');

    reportPdfHeading($pdf, '4. Scheduling Report — Service Schedule (by service date)', 'Appointments whose scheduled service date falls in the selected period (rejected and cancelled excluded).');
    reportPdfTable($pdf, ['Service Date', 'Time', 'Reference', 'Service', 'Applicant', 'Location', 'Priest', 'Status'], [26, 18, 28, 36, 42, 50, 40, 37],
        array_map(static fn(array $r): array => [formatDate($r['appointment_date']), reportTime($r['appointment_time']) ?: 'TBA', reportReference($r), $r['service_name'], reportApplicant($r), $r['location_address'] ?: '—', $r['priest_name'] ?: '—', $r['status_name']], $d['schedule']),
        'No services are scheduled in this period.');
    reportPdfHeading($pdf, 'Awaiting Final Schedule (by booking date)', 'Active bookings in this period without a confirmed service date or time.');
    reportPdfTable($pdf, ['Reference', 'Service', 'Applicant', 'Booked', 'Date', 'Status'], [35, 50, 60, 35, 40, 57],
        array_map(static fn(array $r): array => [reportReference($r), $r['service_name'], reportApplicant($r), formatDate($r['created_at']), $r['appointment_date'] ? formatDate($r['appointment_date']) : 'To be scheduled', $r['status_name']], $d['awaiting_schedule']),
        'Every active booking in this period has a final schedule.');

    $af = $d['approval_flags'];
    reportPdfHeading($pdf, '5. Pending and Overdue (by booking date)', sprintf('Awaiting approval: %d  ·  Overdue (pending more than %d days after booking): %d  ·  Urgent (service within %d days): %d  ·  Needs immediate attention: %d. Mass Intentions excluded.',
        count($d['approvals']), REPORT_OVERDUE_DAYS, $af['overdue'], REPORT_URGENT_DAYS, $af['urgent'], $af['attention']));
    reportPdfTable($pdf, ['Reference', 'Service', 'Applicant', 'Booked', 'Days Pending', 'Service Date', 'Flags'], [32, 42, 50, 30, 25, 33, 65],
        array_map(static fn(array $a): array => [reportReference($a['row']), $a['row']['service_name'], reportApplicant($a['row']), formatDate($a['row']['created_at']), $a['days'],
            $a['row']['appointment_date'] ? formatDate($a['row']['appointment_date']) : 'To be scheduled',
            implode(', ', array_filter([$a['urgent'] ? 'URGENT' : '', $a['overdue'] ? 'Overdue' : '', $a['review_status'] ? reportReviewLabel($a['review_status']) : ''])) ?: '—'], $d['approvals']),
        'No appointments are awaiting Secretary approval.');

    reportPdfHeading($pdf, '6. Cancellations and Rejections (by booking date)', '"Last Updated" is the most recent change to the appointment, not necessarily the exact cancellation or rejection date.');
    reportPdfTable($pdf, ['Reference', 'Service', 'Applicant', 'Last Updated', 'Reason', 'Final Status'], [32, 40, 48, 30, 100, 27],
        array_map(static fn(array $r): array => [reportReference($r), $r['service_name'], reportApplicant($r), formatDate($r['updated_at']),
            (int) $r['status_id'] === 3 ? ($r['rejection_reason'] ?: 'No reason recorded') : ($r['cancelled_reason'] ?: 'No reason recorded'), $r['status_name']], $d['closed']),
        'No cancelled or rejected appointments in this period.');

    return $pdf->Output('S');
}
