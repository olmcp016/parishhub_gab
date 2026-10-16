<?php
/**
 * PARISHHUB — Pre-Cana seminar for Wedding bookings.
 *
 *   Regular Pre-Cana — No Fees. Fixed parish session: every second Saturday of
 *                      the month at 8:00 AM. The couple picks one of the
 *                      calculated sessions; the date/time cannot be typed in.
 *   Special Pre-Cana — ₱2,500 (regardless of PSS). The couple proposes a date
 *                      and time; the Parish Office confirms it or proposes
 *                      another one. Never confirmed automatically.
 *
 * The seminar is separate from the Wedding Ceremony schedule (appointment_date/
 * appointment_time/schedule_type): it has its own columns (database/
 * migration_precana.sql) and its own Secretary actions.
 *
 * Pre-Cana Seminar Certificate: couples attending a parish seminar do not have
 * it yet, so it is not required to submit the booking. It stays a pending
 * requirement, can be uploaded after the seminar, and must be approved before
 * the Wedding is confirmed (ready for ceremony). Bookings without a seminar
 * type (made before this feature) keep the certificate as a booking requirement.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/scheduling.php';

const PRECANA_TYPES = ['Regular', 'Special'];
const PRECANA_SPECIAL_FEE = 2500.00;
const PRECANA_REGULAR_TIME = '08:00:00';
const PRECANA_REGULAR_OCCURRENCE = 2; // second Saturday of the month
const PRECANA_CERTIFICATE_LABEL = 'Pre-Cana Seminar Certificate';
// scheduled = Regular fixed session; pending_confirmation = the couple's preferred Special schedule;
// proposed = an alternative proposed by the Parish Office (not yet confirmed); confirmed = confirmed by the Parish Office.
const PRECANA_STATUSES = ['scheduled', 'pending_confirmation', 'proposed', 'confirmed'];

/** True once database/migration_precana.sql has been run (both tables). Until then Pre-Cana stays hidden. */
function precanaAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $stmt = db()->query("SELECT COUNT(DISTINCT table_name) FROM information_schema.columns WHERE column_name = 'precana_type' AND table_name IN ('appointments', 'appointment_drafts')");
        $available = (int) $stmt->fetchColumn() === 2;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function precanaFee(?string $type): float
{
    return $type === 'Special' ? PRECANA_SPECIAL_FEE : 0.0;
}

function precanaFeeLabel(?string $type): string
{
    return $type === 'Special' ? money(PRECANA_SPECIAL_FEE) : 'No Fees';
}

/** True when this booking chose a parish seminar, so its certificate comes later. */
function precanaCertificateDeferred(array $row): bool
{
    return in_array($row['precana_type'] ?? null, PRECANA_TYPES, true);
}

/**
 * True for a requirement label that is recognisably the Pre-Cana Seminar
 * Certificate, whatever its capitalisation, spacing or dash style
 * ("PRE-CANA  Seminar Certificate", "Pre – Cana seminar certificate",
 * "PreCana Certificate", "Certificate of Pre-Cana Seminar"). Anything else
 * — e.g. "Marriage License", "Pre-Nuptial Interview" — never matches.
 */
function precanaIsCertificateLabel(?string $label): bool
{
    $text = mb_strtolower(trim((string) $label));
    $text = (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}_]/u', '-', $text); // dash variants
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = (string) preg_replace('/\s*-\s*/', '-', $text);
    $text = (string) preg_replace('/\bpre[- ]?cana\b/', 'pre-cana', $text);
    return (bool) preg_match('/^(pre-cana( seminar)? certificate|certificate of (attendance (in|at) |completion of )?(the )?pre-cana( seminar)?)$/', $text);
}

/** Booking-time requirement list: without the certificate when it is deferred. */
function precanaBookingRequirements(array $requirements, array $row): array
{
    if (!precanaCertificateDeferred($row)) return array_values($requirements);
    return array_values(array_filter($requirements, static fn($label): bool => !precanaIsCertificateLabel((string) $label)));
}

/** True when a service's comma-separated requirements list a recognisable Pre-Cana certificate. */
function precanaCatalogHasCertificate(?string $requirements): bool
{
    foreach (parseRequirementsList($requirements) as $label) {
        if (precanaIsCertificateLabel($label)) return true;
    }
    return false;
}

/**
 * Upcoming Regular sessions (second Saturday, 8:00 AM), skipping past sessions
 * and parish-blocked dates. With $weddingDate, only sessions before that day.
 *
 * @return array<int, array{date: string, time: string, label: string}>
 */
function precanaRegularSessions(?string $weddingDate = null, int $months = 12, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $blocked = [];
    try {
        $blocked = array_column(db()->query('SELECT calendar_date FROM calendar WHERE is_blocked = TRUE')->fetchAll(), 'calendar_date');
    } catch (Throwable $e) {
        error_log('Pre-Cana blocked-date lookup failed: ' . $e->getMessage());
    }
    $sessions = [];
    $month = $now->modify('first day of this month')->setTime(0, 0);
    for ($i = 0; $i <= $months; $i++, $month = $month->modify('first day of next month')) {
        $first = $month->format('Y-m-01');
        $offset = (6 - (int) date('w', strtotime($first)) + 7) % 7; // days to the first Saturday
        $date = date('Y-m-d', strtotime("$first +" . ($offset + 7 * (PRECANA_REGULAR_OCCURRENCE - 1)) . ' days'));
        if (new DateTimeImmutable($date . ' ' . PRECANA_REGULAR_TIME) <= $now) continue;
        if (in_array($date, $blocked, true)) continue;
        if ($weddingDate !== null && $date >= $weddingDate) break;
        $sessions[] = ['date' => $date, 'time' => PRECANA_REGULAR_TIME, 'label' => date('l, F j, Y', strtotime($date)) . ' — 8:00 AM'];
    }
    return $sessions;
}

/**
 * Validates the couple's choice against the Wedding Ceremony date.
 *
 * @return array{valid: bool, message: string, type: ?string, date: ?string, time: ?string, status: ?string}
 */
function precanaValidate(?string $type, ?string $regularDate, ?string $specialDate, ?string $specialTime, ?string $weddingDate, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $fail = static fn(string $message): array => ['valid' => false, 'message' => $message, 'type' => null, 'date' => null, 'time' => null, 'status' => null];
    if (!in_array($type, PRECANA_TYPES, true)) return $fail('Please choose a Pre-Cana Seminar Type (Regular or Special).');
    if (!$weddingDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weddingDate)) return $fail('Please choose the Wedding Ceremony date first, then the Pre-Cana seminar.');

    if ($type === 'Regular') {
        $sessions = precanaRegularSessions($weddingDate, 24, $now);
        if (!$sessions) {
            return $fail('No Regular Pre-Cana seminar (second Saturday, 8:00 AM) is available before your wedding date. Please contact the Parish Office or choose Special Pre-Cana.');
        }
        if (!in_array((string) $regularDate, array_column($sessions, 'date'), true)) {
            return $fail('Please choose one of the listed Regular Pre-Cana sessions before your wedding date.');
        }
        return ['valid' => true, 'message' => '', 'type' => 'Regular', 'date' => $regularDate, 'time' => PRECANA_REGULAR_TIME, 'status' => 'scheduled'];
    }

    // Special: preferred date/time, subject to Parish Office confirmation.
    $date = trim((string) $specialDate);
    $time = substr(trim((string) $specialTime), 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        return $fail('Please choose a valid preferred Pre-Cana seminar date.');
    }
    if (!preg_match('/^([01]\d|2[0-3]):(00|30)$/', $time)) return $fail('Please choose a preferred Pre-Cana seminar time in 30-minute intervals (for example 9:00 or 9:30).');
    if (new DateTimeImmutable("$date $time:00") <= $now) return $fail('The preferred Pre-Cana seminar date and time must still be ahead.');
    if ($date >= $weddingDate) return $fail('The Pre-Cana seminar must take place before the Wedding Ceremony date.');
    if (isStaffDayOff($date, $time)) return $fail('The parish office is closed every Monday afternoon and all day Tuesday (staff day off). Please choose another Pre-Cana seminar schedule.');
    return ['valid' => true, 'message' => '', 'type' => 'Special', 'date' => $date, 'time' => $time . ':00', 'status' => 'pending_confirmation'];
}

/** The Pre-Cana columns of one appointment, or null (older booking / migration not run). */
function precanaForAppointment(int $appointmentId): ?array
{
    if (!precanaAvailable()) return null;
    $stmt = db()->prepare('SELECT precana_type, precana_date, precana_time, precana_status FROM appointments WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    return $row && in_array($row['precana_type'], PRECANA_TYPES, true) ? $row : null;
}

/** Display values for any row carrying the precana_* columns. */
function precanaDisplay(array $row): ?array
{
    $type = $row['precana_type'] ?? null;
    if (!in_array($type, PRECANA_TYPES, true)) return null;
    $status = (string) ($row['precana_status'] ?? '');
    return [
        'type' => $type,
        'type_label' => $type . ' Pre-Cana Seminar',
        'fee_label' => precanaFeeLabel($type),
        'date' => !empty($row['precana_date']) ? date('l, F j, Y', strtotime((string) $row['precana_date'])) : 'To be scheduled',
        'time' => !empty($row['precana_time']) ? date('g:i A', strtotime((string) $row['precana_time'])) : '—',
        'status' => $status,
        // Guest / parishioner wording. A proposed schedule is never called confirmed,
        // nor the couple's own preference.
        'status_label' => match ($status) {
            'scheduled' => 'Your Pre-Cana seminar is scheduled at the fixed parish session.',
            'proposed' => 'The Parish Office has proposed a new Pre-Cana schedule. This schedule is not yet confirmed.',
            'confirmed' => 'Your Pre-Cana seminar schedule is confirmed.',
            default => 'Your preferred Pre-Cana schedule is awaiting Parish Office confirmation.',
        },
        'status_short' => match ($status) {
            'scheduled' => 'Scheduled',
            'proposed' => 'Proposed — Not Yet Confirmed',
            'confirmed' => 'Confirmed',
            default => 'Awaiting Confirmation',
        },
        'status_badge' => match ($status) { 'scheduled', 'confirmed' => 'approved', 'proposed' => 'regular', default => 'pending' },
        'schedule_heading' => match (true) {
            $type !== 'Special' => 'Schedule',
            $status === 'proposed' => 'Office-Proposed Schedule',
            $status === 'confirmed' => 'Confirmed Schedule',
            default => 'Preferred Schedule',
        },
    ];
}

/** Latest submission of the certificate (any recognisable label): missing | pending | rejected | approved. */
function precanaCertificateStatus(int $appointmentId): array
{
    $stmt = db()->prepare('SELECT document_id, file_name, requirement_label, review_status, verified, rejection_reason FROM uploaded_documents WHERE appointment_id = ? AND superseded_by IS NULL ORDER BY document_id DESC');
    $stmt->execute([$appointmentId]);
    $doc = null;
    foreach ($stmt->fetchAll() as $candidate) {
        if (precanaIsCertificateLabel($candidate['requirement_label'] ?? '')) { $doc = $candidate; break; }
    }
    if (!$doc) return ['status' => 'missing', 'document' => null];
    $status = (string) ($doc['review_status'] ?? '');
    if ($status === '') $status = in_array($doc['verified'], [true, 1, '1', 't', 'true'], true) ? 'approved' : 'pending';
    return ['status' => in_array($status, ['rejected', 'needs_revision'], true) ? 'rejected' : $status, 'document' => $doc];
}

/** Certificate Review status: [label, badge class]. "Not Submitted Yet" is grey, never the gold "Pending Review". */
function precanaCertificateStatusLabel(string $status): array
{
    return match ($status) {
        'approved' => ['Approved', 'approved'],
        'pending' => ['Pending Review', 'pending'],
        'rejected' => ['Needs Revision', 'rejected'],
        default => ['Not Submitted Yet', 'cancelled'],
    };
}

/** Seminar Schedule status for the Secretary: [label, badge class]. Never about the certificate. */
function precanaScheduleStatusLabel(array $row): array
{
    return match ((string) ($row['precana_status'] ?? '')) {
        'scheduled' => ['Regular Fixed Schedule', 'approved'],
        'proposed' => ['Office-Proposed Schedule — Not Yet Confirmed', 'regular'],
        'confirmed' => ['Confirmed Schedule', 'approved'],
        default => ['Preferred Schedule — Awaiting Confirmation', 'pending'],
    };
}

/**
 * What still prevents a Wedding from being confirmed (ready for ceremony):
 * an unconfirmed Special schedule, and the certificate not yet approved.
 * Bookings without a parish seminar have no Pre-Cana conditions here.
 */
function precanaCeremonyBlockers(array $appointment): array
{
    if (($appointment['category'] ?? '') !== 'Wedding' || !precanaCertificateDeferred($appointment)) return [];
    $blockers = [];
    if (($appointment['precana_type'] ?? '') === 'Special' && ($appointment['precana_status'] ?? '') !== 'confirmed') {
        $blockers[] = 'Seminar Schedule: confirm the Special Pre-Cana schedule first (currently ' . precanaScheduleStatusLabel($appointment)[0] . ').';
    }
    $certificate = precanaCertificateStatus((int) $appointment['appointment_id']);
    if ($certificate['status'] !== 'approved') {
        $blockers[] = 'Certificate Review: the Pre-Cana Seminar Certificate must be approved (currently '
            . precanaCertificateStatusLabel($certificate['status'])[0] . ').';
    }
    return $blockers;
}

/** True when the certificate can be uploaded / reviewed now (any active stage before confirmation). */
function precanaCertificateOpen(array $appointment): bool
{
    return precanaCertificateDeferred($appointment) && in_array((int) ($appointment['status_id'] ?? 0), [1, 2, 4], true);
}

/** Pre-Cana block for detail pages (applicant and Secretary views). */
function renderPrecanaSummary(array $row): void
{
    $p = precanaDisplay($row);
    if (!$p) {
        if (($row['category'] ?? '') === 'Wedding') echo '<p><strong>Pre-Cana Seminar:</strong> <span class="text-muted">Not recorded (booked before Pre-Cana seminar options)</span></p>';
        return;
    }
    ?>
    <div class="precana-summary">
      <h4 style="margin-top:0;">Pre-Cana Seminar</h4>
      <p><strong>Seminar Type:</strong> <?= e($p['type_label']) ?></p>
      <p><strong><?= e($p['schedule_heading']) ?>:</strong> <?= e($p['date']) ?></p>
      <p><strong>Time:</strong> <?= e($p['time']) ?></p>
      <p><strong>Seminar Fee:</strong> <?= e($p['fee_label']) ?></p>
      <p><strong>Schedule Status:</strong> <span class="badge badge-<?= e($p['status_badge']) ?>"><?= e($p['status_short']) ?></span></p>
      <p class="precana-status-note"><?= e($p['status_label']) ?></p>
    </div>
    <?php
}
