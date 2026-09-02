<?php
/**
 * PARISHHUB — Scheduling Rules Engine
 *
 * Encodes the parish's fixed scheduling policies so they are enforced
 * consistently everywhere a date/time is chosen or validated:
 *
 *   - Baptism         : 1st & 3rd Saturday of the month only, fixed 9:00 AM
 *   - Wedding         : 4th Saturday of the month only, fixed 8:00 AM
 *   - Confirmation    : no fixed rule — depends on the Bishop's availability
 *   - Funeral Mass    : earliest allowed date = date of death + 9 days
 *                       (the 9-day mourning period), fixed 1:00 PM
 *   - Blessing        : no fixed rule — arranged directly between the
 *                       parishioner and the priest
 *   - Mass Intention  : must land on an actual daily/Sunday Mass time slot
 *   - Staff day off   : every Monday from 12:00 PM onward, and all day Tuesday
 *                       — no appointments may be booked in this window
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
    if ($dow === 0) return ['06:00', '09:00', '16:30']; // Sunday: 3 Masses
    if ($dow === 3) return ['17:15'];                    // Wednesday: afternoon only
    return ['06:00'];                                    // Every other day: 6:00 AM
}

/**
 * Validates a proposed booking against the parish's scheduling rules.
 *
 * @param string      $category     Service category (Mass Intention, Wedding, Baptism, Funeral, Confirmation, Blessing, First Communion)
 * @param string      $date         Proposed appointment_date (Y-m-d)
 * @param string      $time         Proposed appointment_time (H:i or H:i:s)
 * @param string|null $dateOfDeath  Required only for Funeral Mass (Y-m-d)
 *
 * @return array{valid: bool, message: string, forcedTime: ?string}
 *         forcedTime, when present, is the time the system will actually save
 *         (overrides whatever the client submitted, for fixed-time categories).
 */
function validateBooking(string $category, string $date, string $time, ?string $dateOfDeath = null): array
{
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
            if (!isFirstOrThirdSaturday($date)) {
                return [
                    'valid' => false,
                    'message' => 'Baptisms are only scheduled on the 1st and 3rd Saturday of the month, at 9:00 AM.',
                    'forcedTime' => null,
                ];
            }
            return ['valid' => true, 'message' => '', 'forcedTime' => '09:00:00'];

        case 'Wedding':
            if (!isFourthSaturday($date)) {
                return [
                    'valid' => false,
                    'message' => 'Weddings are only scheduled on the 4th Saturday of the month, at 8:00 AM.',
                    'forcedTime' => null,
                ];
            }
            return ['valid' => true, 'message' => '', 'forcedTime' => '08:00:00'];

        case 'Funeral':
            if (!$dateOfDeath) {
                return [
                    'valid' => false,
                    'message' => 'Please provide the date of death so we can schedule the funeral Mass after the 9-day mourning period.',
                    'forcedTime' => null,
                ];
            }
            $earliest = date('Y-m-d', strtotime($dateOfDeath . ' +9 days'));
            if ($date < $earliest) {
                return [
                    'valid' => false,
                    'message' => "Funeral Masses take place after the 9-day mourning period. The earliest available date based on the date of death is $earliest, at 1:00 PM.",
                    'forcedTime' => null,
                ];
            }
            return ['valid' => true, 'message' => '', 'forcedTime' => '13:00:00'];

        case 'Confirmation':
            // No fixed rule — schedule depends on the Bishop's availability.
            // The secretary/admin coordinates this manually; any date is accepted here.
            return ['valid' => true, 'message' => '', 'forcedTime' => null];

        case 'Blessing':
            // No fixed rule — arranged directly between parishioner and priest.
            return ['valid' => true, 'message' => '', 'forcedTime' => null];

        case 'Mass Intention':
            $validTimes = massTimesFor($date);
            if (!in_array($time5, $validTimes, true)) {
                $list = implode(', ', array_map(fn($t) => date('g:i A', strtotime($t)), $validTimes));
                return [
                    'valid' => false,
                    'message' => "Mass Intentions must be offered during an actual Mass time. Available Mass time(s) for that date: $list.",
                    'forcedTime' => null,
                ];
            }
            return ['valid' => true, 'message' => '', 'forcedTime' => null];

        case 'First Communion':
        default:
            // No specific fixed rule beyond the staff day-off, already checked above.
            return ['valid' => true, 'message' => '', 'forcedTime' => null];
    }
}

/**
 * Human-readable scheduling policy text for a given category, shown to
 * parishioners on the booking form so expectations are clear up front.
 */
function schedulingPolicyText(string $category): string
{
    switch ($category) {
        case 'Baptism':
            return 'Baptisms are scheduled on the 1st and 3rd Saturday of the month, fixed at 9:00 AM.';
        case 'Wedding':
            return 'Weddings are scheduled on the 4th Saturday of the month, fixed at 8:00 AM.';
        case 'Confirmation':
            return 'Confirmation schedules depend on the Bishop\'s availability. The parish office will coordinate the exact date with you.';
        case 'Funeral':
            return 'Funeral Masses are held after the 9-day mourning period from the date of death, fixed at 1:00 PM.';
        case 'Blessing':
            return 'House Blessing schedules are arranged directly between you and the priest. Propose a preferred date and time below.';
        case 'Mass Intention':
            return 'Mass Intentions are offered during an actual Mass: daily at 6:00 AM (5:15 PM on Wednesdays), or on Sundays at 6:00 AM, 9:00 AM, or 4:30 PM.';
        default:
            return '';
    }
}
