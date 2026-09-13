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
    if ($dow === 0) return ['06:30', '09:30', '16:30']; // Sunday: 1st, 2nd, 3rd Mass
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
                $result = [
                    'valid' => false,
                    'message' => 'Baptisms are only scheduled on the 1st and 3rd Saturday of the month, at 9:00 AM.',
                    'forcedTime' => null,
                ];
                break;
            }
            $result = ['valid' => true, 'message' => '', 'forcedTime' => '09:00:00'];
            break;

        case 'Wedding':
            if (!isFourthSaturday($date)) {
                $result = [
                    'valid' => false,
                    'message' => 'Weddings are only scheduled on the 4th Saturday of the month, at 8:00 AM.',
                    'forcedTime' => null,
                ];
                break;
            }
            $result = ['valid' => true, 'message' => '', 'forcedTime' => '08:00:00'];
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

        case 'Confirmation':
            // No fixed rule — schedule depends on the Bishop's availability.
            // The secretary/admin coordinates this manually; any date is accepted here.
            $result = ['valid' => true, 'message' => '', 'forcedTime' => null];
            break;

        case 'Blessing':
            // No fixed rule — arranged directly between parishioner and priest.
            $result = ['valid' => true, 'message' => '', 'forcedTime' => null];
            break;

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
            // Mass Intentions ARE the Mass — they never conflict with themselves,
            // so return directly, skipping the "occupied by a Mass" check below.
            return ['valid' => true, 'message' => '', 'forcedTime' => null];

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
 */
function schedulingPolicyText(string $category): string
{
    switch ($category) {
        case 'Baptism':
            return 'Baptisms are scheduled on the 1st and 3rd Saturday of the month, fixed at 9:00 AM.';
        case 'Wedding':
            return 'Weddings are scheduled on the 4th Saturday of the month, fixed at 8:00 AM.';
        case 'Confirmation':
            return 'Confirmation schedules depend on the Bishop\'s availability. The parish office will coordinate the exact date with you — just not during a scheduled Mass.';
        case 'Funeral':
            return 'Funeral Masses are held after the 9-day mourning period from the date of death, fixed at 1:00 PM.';
        case 'Blessing':
            return 'House Blessing schedules are arranged directly between you and the priest. Propose a preferred date and time below — just not during a scheduled Mass.';
        case 'Mass Intention':
            return 'Mass Intentions are offered during the daily 6:00 AM Mass (5:15 PM on Wednesdays; 6:30 AM, 9:30 AM, or 4:30 PM on Sundays). The time is assigned automatically based on your chosen date — no need to pick a time. Your request is approved instantly, with no documents required, and you can proceed straight to payment.';
        case 'First Communion':
            return 'Propose a preferred date and time below — just not during a scheduled Mass.';
        default:
            return '';
    }
}
