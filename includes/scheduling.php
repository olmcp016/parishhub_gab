<?php
/**
 * PARISHHUB — Scheduling Rules Engine
 *
 * Encodes the parish's fixed scheduling policies so they are enforced
 * consistently everywhere a date/time is chosen or validated:
 *
 *   - Baptism/Wedding/Blessing/Confirmation : parishioner chooses Regular
 *       (an admin-configured fixed slot from service_schedules — see
 *       matchesRegularSlot()/regularSlotsForService()) or Special (a free
 *       date/time, still checked against Mass times, priest availability,
 *       and other bookings)
 *   - Funeral Mass    : earliest allowed date = date of death + 9 days
 *                       (the 9-day mourning period), fixed 1:00 PM
 *   - Mass Intention  : must land on an actual daily/Sunday Mass time slot
 *   - Staff day off   : every Monday from 12:00 PM onward, and all day Tuesday
 *                       — no appointments may be booked in this window
 *   - Priest availability : derived from priest status, ad-hoc
 *       priest_unavailability rows, and existing bookings — see
 *       priestIsAvailable()/availablePriestsFor()
 *
 * Unlike when this file was first written, most functions here now call
 * db() — always safe since this file is require_once'd after includes/auth.php
 * everywhere it's used.
 */

/** Day-of-week helper: 0=Sunday ... 6=Saturday, matching PHP's date('w') */
function dowOf(string $dateStr): int
{
    return (int) date('w', strtotime($dateStr));
}

/**
 * The Nth occurrence of a given weekday in the month of $dateStr.
 * Returns the 1-based occurrence number (1st, 2nd, 3rd, 4th...) that
 * $dateStr's day-of-month represents for its own weekday.
 */
function nthWeekdayOccurrence(string $dateStr): int
{
    $day = (int) date('j', strtotime($dateStr));
    return (int) floor(($day - 1) / 7) + 1;
}

/** True if $dateStr is a Saturday */
function isSaturday(string $dateStr): bool
{
    return dowOf($dateStr) === 6;
}

/** True if $dateStr is the 1st or 3rd Saturday of its month (Baptism rule) */
function isFirstOrThirdSaturday(string $dateStr): bool
{
    if (!isSaturday($dateStr)) return false;
    $n = nthWeekdayOccurrence($dateStr);
    return $n === 1 || $n === 3;
}

/** True if $dateStr is the 4th Saturday of its month (Wedding rule) */
function isFourthSaturday(string $dateStr): bool
{
    if (!isSaturday($dateStr)) return false;
    return nthWeekdayOccurrence($dateStr) === 4;
}

/**
 * True if the given date + time falls inside the staff's fixed day off:
 * Monday from 12:00 PM onward, and all of Tuesday.
 */
function isStaffDayOff(string $dateStr, ?string $timeStr = null): bool
{
    $dow = dowOf($dateStr);
    if ($dow === 2) return true; // Tuesday — entire day off
    if ($dow === 1 && $timeStr !== null && $timeStr >= '12:00') return true; // Monday afternoon
    return false;
}

/**
 * The valid Mass time slots for a given date, per the parish's daily
 * Mass schedule. Used to constrain Mass Intention bookings.
 */
function massTimesFor(string $dateStr): array
{
    $dow = dowOf($dateStr);
    if ($dow === 0) return ['06:30', '09:30', '16:30']; // Sunday: 1st, 2nd, 3rd Mass
    if ($dow === 3) return ['17:15'];                    // Wednesday: afternoon only
    return ['06:00'];                                    // Every other day: 6:00 AM
}

/**
 * The parish's official Mass Intention schedule — the ONLY times a Mass
 * Intention can be booked, on every date (H:i => label). Kept separate from
 * massTimesFor() above, which other services still use for their own
 * "occupied by a scheduled Mass" conflict rule.
 */
const MASS_INTENTION_TIMES = [
    '06:00' => '1st Mass',
    '09:00' => '2nd Mass',
    '16:00' => '3rd Mass',
];

/**
 * The Mass Intention times offered on a given date (H:i => label). The three
 * Masses run on SUNDAYS ONLY; Monday–Saturday there is a single 6:00 AM
 * Daily Mass. This is the one source of truth used by the booking form's
 * time list, the availability API, and the backend validation.
 */
function massIntentionTimesForDate(string $date): array
{
    $ts = strtotime($date);
    if ($ts !== false && (int) date('w', $ts) === 0) {
        return MASS_INTENTION_TIMES;
    }
    return ['06:00' => 'Daily Mass'];
}

/**
 * Whether a Mass Intention can be booked at this exact date + official Mass
 * time. Reuses the existing staff-day-off and blocked-calendar rules, plus:
 * dates/times already past, and another parish service already booked in
 * that exact slot (the church is in use).
 *
 * @return array{available: bool, reason: ?string}
 */
function massIntentionSlotAvailability(string $date, string $time): array
{
    $time5 = substr($time, 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        return ['available' => false, 'reason' => 'Please choose a valid date.'];
    }
    if (!isset(massIntentionTimesForDate($date)[$time5])) {
        $isSunday = (int) date('w', strtotime($date)) === 0;
        return ['available' => false, 'reason' => $isSunday
            ? 'On Sundays, Mass Intentions can only be offered at the 6:00 AM, 9:00 AM, or 4:00 PM Mass.'
            : 'From Monday to Saturday, Mass Intentions can only be offered at the 6:00 AM Daily Mass.'];
    }

    $today = date('Y-m-d');
    if ($date < $today || ($date === $today && $time5 <= date('H:i'))) {
        return ['available' => false, 'reason' => 'That Mass has already passed.'];
    }
    if (isStaffDayOff($date, $time5)) {
        return ['available' => false, 'reason' => 'The parish office is closed (Monday afternoons and all day Tuesday).'];
    }

    $stmt = db()->prepare('SELECT 1 FROM calendar WHERE calendar_date = ? AND is_blocked = 1');
    $stmt->execute([$date]);
    if ($stmt->fetchColumn()) {
        return ['available' => false, 'reason' => 'This date is not available (blocked on the parish calendar).'];
    }

    $stmt = db()->prepare(
        "SELECT 1 FROM appointments a JOIN services s ON a.service_id = s.service_id
         WHERE a.appointment_date = ? AND a.appointment_time = ?
           AND s.category NOT IN ('Mass Intention', 'Donation') AND a.status_id NOT IN (3, 7) LIMIT 1"
    );
    $stmt->execute([$date, $time5 . ':00']);
    if ($stmt->fetchColumn()) {
        return ['available' => false, 'reason' => 'Occupied — another parish service is scheduled at this time.'];
    }

    return ['available' => true, 'reason' => null];
}

/** The three official Mass Intention slots for a date, each with its availability, for the booking modal. */
function massIntentionSlotsFor(string $date): array
{
    $slots = [];
    foreach (massIntentionTimesForDate($date) as $time5 => $name) {
        $check = massIntentionSlotAvailability($date, $time5);
        $slots[] = [
            'time' => $time5,
            'label' => date('g:i A', strtotime($time5)) . ' — ' . $name,
            'available' => $check['available'],
            'reason' => $check['reason'],
        ];
    }
    return $slots;
}

/**
 * True if a non-cancelled/non-rejected appointment already occupies this
 * exact service+date+time. Closes a gap the priest-only conflict check
 * misses: two parishioners could otherwise both land on the same fixed
 * Regular slot (e.g. the same Baptism Saturday) if neither picks a priest.
 */
function serviceSlotIsBooked(int $serviceId, string $date, string $time, ?int $excludeAppointmentId = null): bool
{
    $time5 = substr($time, 0, 5);
    $sql = "SELECT COUNT(*) FROM appointments
            WHERE service_id = ? AND appointment_date = ? AND appointment_time = ?
              AND status_id NOT IN (3, 7)";
    $params = [$serviceId, $date, $time5];
    if ($excludeAppointmentId !== null) {
        $sql .= ' AND appointment_id != ?';
        $params[] = $excludeAppointmentId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Active service_schedules rows for a service, ordered for display
 * (by weekday then occurrence, "every week" rows first).
 */
function getServiceSchedules(int $serviceId): array
{
    $stmt = db()->prepare(
        "SELECT * FROM service_schedules WHERE service_id = ? AND is_active = TRUE
         ORDER BY weekday, occurrence NULLS FIRST, slot_time"
    );
    $stmt->execute([$serviceId]);
    return $stmt->fetchAll();
}

const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const OCCURRENCE_ORDINALS = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];

/**
 * Human-readable description of a service's configured Regular slots,
 * e.g. "1st and 3rd Saturday of the month at 9:00 AM" or, when a service
 * has multiple distinct rules, one sentence per rule joined together.
 */
function describeRegularSchedule(int $serviceId): string
{
    $rows = getServiceSchedules($serviceId);
    if (empty($rows)) {
        return 'No Regular schedule has been configured for this service yet — please choose Special.';
    }

    // Group by (weekday, time) so "1st and 3rd Saturday, 9:00 AM" reads as one sentence.
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['weekday'] . '|' . $r['slot_time'];
        $groups[$key]['weekday'] = (int) $r['weekday'];
        $groups[$key]['time'] = $r['slot_time'];
        $groups[$key]['occurrences'][] = $r['occurrence'] !== null ? (int) $r['occurrence'] : null;
    }

    $sentences = [];
    foreach ($groups as $g) {
        $weekdayName = WEEKDAY_NAMES[$g['weekday']];
        $timeLabel = date('g:i A', strtotime($g['time']));
        if (in_array(null, $g['occurrences'], true)) {
            $sentences[] = "Every $weekdayName at $timeLabel";
        } else {
            $ordinals = array_map(fn($o) => OCCURRENCE_ORDINALS[$o] ?? "{$o}th", $g['occurrences']);
            $list = count($ordinals) > 1
                ? implode(' and ', [implode(', ', array_slice($ordinals, 0, -1)), end($ordinals)])
                : $ordinals[0];
            $sentences[] = "$list $weekdayName of the month at $timeLabel";
        }
    }
    return implode('; ', $sentences) . '.';
}

/**
 * Checks a proposed Regular-mode date+time against a service's configured
 * service_schedules rows. Same {valid, message, forcedTime} shape as
 * validateBooking() so it can be spliced directly into that function.
 */
function matchesRegularSlot(int $serviceId, string $date, string $time): array
{
    $time5 = substr($time, 0, 5);
    $dow = dowOf($date);
    $occurrence = nthWeekdayOccurrence($date);

    $stmt = db()->prepare(
        "SELECT 1 FROM service_schedules
         WHERE service_id = ? AND is_active = TRUE AND weekday = ?
           AND (occurrence IS NULL OR occurrence = ?)
           AND slot_time = ?"
    );
    $stmt->execute([$serviceId, $dow, $occurrence, $time5 . ':00']);

    if ($stmt->fetchColumn()) {
        return ['valid' => true, 'message' => '', 'forcedTime' => $time5 . ':00'];
    }

    return [
        'valid' => false,
        'message' => 'That date/time doesn\'t match this service\'s fixed schedule. ' . describeRegularSchedule($serviceId),
        'forcedTime' => null,
    ];
}

/**
 * Expands a service's service_schedules rows into concrete upcoming
 * {date, time, label} options for the Regular-mode picker, skipping
 * calendar-blocked dates, the staff day-off, and slots already booked.
 */
function regularSlotsForService(int $serviceId, int $count = 8): array
{
    $rows = getServiceSchedules($serviceId);
    if (empty($rows)) {
        return [];
    }

    $blockedStmt = db()->query('SELECT calendar_date FROM calendar WHERE is_blocked = TRUE');
    $blockedDates = array_column($blockedStmt->fetchAll(), 'calendar_date');

    $slots = [];
    $cursor = new DateTime('today');
    $horizon = (clone $cursor)->modify('+180 days');

    while ($cursor <= $horizon && count($slots) < $count * 3) {
        $dateStr = $cursor->format('Y-m-d');
        $dow = (int) $cursor->format('w');
        $occurrence = nthWeekdayOccurrence($dateStr);

        foreach ($rows as $r) {
            if ((int) $r['weekday'] !== $dow) continue;
            if ($r['occurrence'] !== null && (int) $r['occurrence'] !== $occurrence) continue;

            $time5 = substr($r['slot_time'], 0, 5);
            if (in_array($dateStr, $blockedDates, true)) continue;
            if (isStaffDayOff($dateStr, $time5)) continue;
            if (in_array($time5, massTimesFor($dateStr), true)) continue;
            if (serviceSlotIsBooked($serviceId, $dateStr, $time5)) continue;

            $slots[] = [
                'date' => $dateStr,
                'time' => $time5,
                'label' => date('D, M j, Y', strtotime($dateStr)) . ' — ' . date('g:i A', strtotime($time5)),
            ];
        }
        $cursor->modify('+1 day');
    }

    return array_slice($slots, 0, $count);
}

/**
 * Priests do not have a recurring schedule table — availability is derived
 * from (a) their global status, (b) ad-hoc priest_unavailability rows staff
 * add directly, and (c) existing non-cancelled/non-rejected appointments.
 * This is the single source of truth, replacing the duplicated conflict
 * queries that used to live in book.php and secretary/appointment-detail.php.
 */
function priestIsAvailable(int $priestId, string $date, string $time, ?int $excludeAppointmentId = null): array
{
    $time5 = substr($time, 0, 5);

    $stmt = db()->prepare('SELECT full_name, title, status FROM priests WHERE priest_id = ?');
    $stmt->execute([$priestId]);
    $priest = $stmt->fetch();
    if (!$priest) {
        return ['available' => false, 'reason' => 'Priest not found.'];
    }
    if ($priest['status'] === 'inactive') {
        return ['available' => false, 'reason' => $priest['title'] . ' ' . $priest['full_name'] . ' is no longer active at this parish.'];
    }

    $stmt = db()->prepare(
        "SELECT reason, start_time, end_time FROM priest_unavailability
         WHERE priest_id = ? AND unavailable_date = ?
           AND (start_time IS NULL OR start_time <= ?) AND (end_time IS NULL OR end_time >= ?)"
    );
    $stmt->execute([$priestId, $date, $time5 . ':00', $time5 . ':00']);
    $blocked = $stmt->fetch();
    if ($blocked) {
        // Blank start AND end = the whole day; otherwise only that window.
        $window = ($blocked['start_time'] === null && $blocked['end_time'] === null)
            ? 'that day'
            : 'from ' . ($blocked['start_time'] ? date('g:i A', strtotime($blocked['start_time'])) : 'the start of the day')
              . ' to ' . ($blocked['end_time'] ? date('g:i A', strtotime($blocked['end_time'])) : 'the end of the day');
        return [
            'available' => false,
            'reason' => $priest['title'] . ' ' . $priest['full_name'] . ' is unavailable ' . $window . ($blocked['reason'] ? " ({$blocked['reason']})" : '') . '.',
            'note' => $blocked['reason'] ?: 'Not available',
        ];
    }

    $sql = "SELECT a.appointment_id, s.service_name FROM appointments a
            JOIN services s ON a.service_id = s.service_id
            WHERE a.priest_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
              AND a.status_id NOT IN (3, 7)";
    $params = [$priestId, $date, $time5];
    if ($excludeAppointmentId !== null) {
        $sql .= ' AND a.appointment_id != ?';
        $params[] = $excludeAppointmentId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch();
    if ($conflict) {
        return [
            'available' => false,
            'reason' => $priest['title'] . ' ' . $priest['full_name'] . " is already booked for \"{$conflict['service_name']}\" (#{$conflict['appointment_id']}) at that exact date and time.",
        ];
    }

    return ['available' => true, 'reason' => null];
}

/**
 * Every priest (active or on-leave — on-leave priests still show, disabled,
 * so the parishioner understands why) with an availability verdict for the
 * given date/time, for populating the booking modal's priest dropdown.
 */
function availablePriestsFor(string $date, string $time, ?int $excludeAppointmentId = null): array
{
    $priests = db()->query("SELECT priest_id, title, full_name FROM priests WHERE status != 'inactive' ORDER BY full_name")->fetchAll();
    $result = [];
    foreach ($priests as $p) {
        $check = priestIsAvailable((int) $p['priest_id'], $date, $time, $excludeAppointmentId);
        $result[] = [
            'priest_id' => (int) $p['priest_id'],
            'label' => $p['title'] . ' ' . $p['full_name'],
            'available' => $check['available'],
            'reason' => $check['reason'],
            'note' => $check['note'] ?? null,
        ];
    }
    return $result;
}

/**
 * Validates a proposed booking against the parish's scheduling rules.
 *
 * @param string      $category     Service category (Mass Intention, Wedding, Baptism, Funeral, Confirmation, Blessing, First Communion)
 * @param string      $date         Proposed appointment_date (Y-m-d)
 * @param string      $time         Proposed appointment_time (H:i or H:i:s)
 * @param string|null $dateOfDeath  Required only for Funeral Mass (Y-m-d)
 * @param string|null $scheduleType 'Regular' or 'Special' — only meaningful for
 *                                  Baptism/Wedding/Blessing/Confirmation. NULL
 *                                  falls back to each category's historical
 *                                  default (Regular for Baptism/Wedding, Special
 *                                  for Blessing/Confirmation) for legacy callers.
 * @param int|null    $serviceId    Required when $scheduleType === 'Regular', to
 *                                  look up that service's service_schedules rows.
 *
 * @return array{valid: bool, message: string, forcedTime: ?string}
 *         forcedTime, when present, is the time the system will actually save
 *         (overrides whatever the client submitted, for fixed-time categories).
 */
function validateBooking(
    string $category,
    string $date,
    string $time,
    ?string $dateOfDeath = null,
    ?string $scheduleType = null,
    ?int $serviceId = null
): array {
    $time5 = substr($time, 0, 5); // normalize to H:i for comparisons

    // Staff day-off applies to every category — the office is simply closed.
    if (isStaffDayOff($date, $time5)) {
        return [
            'valid' => false,
            'message' => 'The parish office is closed every Monday afternoon and all day Tuesday (staff day off). Please choose another date.',
            'forcedTime' => null,
        ];
    }

    switch ($category) {
        case 'Baptism':
        case 'Wedding':
        case 'Blessing':
        case 'Confirmation':
            // Legacy fallback for call sites that don't yet pass $scheduleType
            // (should be rare post-migration — existing rows were backfilled).
            $effectiveType = $scheduleType ?? (in_array($category, ['Baptism', 'Wedding'], true) ? 'Regular' : 'Special');

            if ($effectiveType === 'Regular') {
                if (!$serviceId) {
                    $result = ['valid' => false, 'message' => 'Missing service selection for Regular scheduling.', 'forcedTime' => null];
                    break;
                }
                $result = matchesRegularSlot($serviceId, $date, $time);
                break;
            }
            // Special — free date/time, still subject to the staff day-off and
            // Mass-conflict checks that apply below regardless of category.
            $result = ['valid' => true, 'message' => '', 'forcedTime' => null];
            break;

        case 'Funeral':
            if (!$dateOfDeath) {
                $result = [
                    'valid' => false,
                    'message' => 'Please provide the date of death so we can schedule the funeral Mass after the 9-day mourning period.',
                    'forcedTime' => null,
                ];
                break;
            }
            $earliest = date('Y-m-d', strtotime($dateOfDeath . ' +9 days'));
            if ($date < $earliest) {
                $result = [
                    'valid' => false,
                    'message' => "Funeral Masses take place after the 9-day mourning period. The earliest available date based on the date of death is $earliest, at 1:00 PM.",
                    'forcedTime' => null,
                ];
                break;
            }
            $result = ['valid' => true, 'message' => '', 'forcedTime' => '13:00:00'];
            break;

        case 'Mass Intention':
            // Only the parish's three official Mass times are bookable. Mass
            // Intentions ARE the Mass — they never conflict with each other,
            // so return directly, skipping the "occupied by a Mass" check below.
            $slot = massIntentionSlotAvailability($date, $time5);
            return ['valid' => $slot['available'], 'message' => (string) $slot['reason'], 'forcedTime' => null];

        case 'First Communion':
        default:
            // No specific fixed rule beyond the staff day-off, already checked above.
            $result = ['valid' => true, 'message' => '', 'forcedTime' => null];
            break;
    }

    // Every other service must not land on a time already occupied by a
    // scheduled Mass — the priest and church are already committed then.
    if ($result['valid']) {
        $effectiveTime = substr($result['forcedTime'] ?? $time, 0, 5);
        if (in_array($effectiveTime, massTimesFor($date), true)) {
            return [
                'valid' => false,
                'message' => 'That time is occupied by a scheduled Mass. Please choose another time outside Mass hours.',
                'forcedTime' => null,
            ];
        }
    }

    return $result;
}

/**
 * Human-readable scheduling policy text for a given category, shown to
 * parishioners on the booking form so expectations are clear up front.
 *
 * @param int|null $serviceId Needed for Baptism/Wedding/Blessing/Confirmation
 *                            to describe that specific service's configured
 *                            Regular slots. NULL looks up the first active
 *                            service in that category (keeps older callers,
 *                            e.g. about.php, working unchanged).
 */
function schedulingPolicyText(string $category, ?int $serviceId = null): string
{
    switch ($category) {
        case 'Baptism':
        case 'Wedding':
        case 'Blessing':
        case 'Confirmation':
            if ($serviceId === null) {
                $stmt = db()->prepare('SELECT service_id FROM services WHERE category = ? AND is_active = TRUE LIMIT 1');
                $stmt->execute([$category]);
                $serviceId = (int) $stmt->fetchColumn() ?: null;
            }
            $regularText = $serviceId ? describeRegularSchedule($serviceId) : 'No Regular schedule has been configured yet.';
            return "Choose Regular for the parish's fixed schedule ($regularText) or Special to request a custom date and time — just not during a scheduled Mass. Either way, availability is checked automatically.";
        case 'Funeral':
            return 'Funeral Masses are held after the 9-day mourning period from the date of death, fixed at 1:00 PM.';
        case 'Mass Intention':
            return 'Mass Intentions are offered on Sundays at the 1st Mass (6:00 AM), 2nd Mass (9:00 AM), or 3rd Mass (4:00 PM), and Monday to Saturday at the 6:00 AM Daily Mass — the available times appear after you pick your date. There is no fixed fee, but an offering greater than ₱0 must be paid to submit your intention. Once our Cashier confirms your payment, it is approved.';
        case 'First Communion':
            return 'Propose a preferred date and time below — just not during a scheduled Mass.';
        default:
            return '';
    }
}
