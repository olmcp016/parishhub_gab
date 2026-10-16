<?php
/**
 * PARISHHUB — Funeral Mass (burial) schedule.
 *
 * Burial and Funeral Mass are ONE schedule:
 *   preferred_burial_date / preferred_burial_time  the family's request (kept for reference)
 *   appointment_date / appointment_time            the final schedule, set only when the
 *                                                  Secretary confirms or reschedules it
 * Both use the same rules (validateBooking('Funeral'): after the date of death,
 * not in the past, outside the staff day-off, 30-minute steps) plus the usual
 * same-service conflict check. The preferred fields stay hidden until
 * database/migration_funeral_preferred_burial.sql has been run.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/scheduling.php';

function funeralPreferredScheduleAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $available = (int) db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'appointments' AND column_name IN ('preferred_burial_date', 'preferred_burial_time')")->fetchColumn() === 2;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * The family's preferred burial / Funeral Mass schedule, checked with the same
 * rules the Secretary uses. Both values are required.
 *
 * @return array{valid: bool, message: string, date: ?string, time: ?string}
 */
function funeralPreferredScheduleCheck(?string $dateOfDeath, ?string $date, ?string $time, ?int $serviceId = null, ?int $excludeAppointmentId = null): array
{
    $date = trim((string) $date);
    $time = substr(trim((string) $time), 0, 5);
    $fail = static fn(string $message): array => ['valid' => false, 'message' => $message, 'date' => null, 'time' => null];
    if ($date === '' || $time === '') return $fail('Please choose your preferred burial / Funeral Mass date and time.');
    $check = validateBooking('Funeral', $date, $time, $dateOfDeath, null, $serviceId);
    if (!$check['valid']) return $fail($check['message']);
    if ($serviceId && serviceSlotIsBooked($serviceId, $date, $time, $excludeAppointmentId)) {
        return $fail('That burial / Funeral Mass date and time is already taken. Please choose another time.');
    }
    return ['valid' => true, 'message' => '', 'date' => $date, 'time' => $time . ':00'];
}

/** Display values: preferred (or "Not provided") and confirmed (or null). */
function funeralScheduleDisplay(array $row): array
{
    $hasPreferred = !empty($row['preferred_burial_date']);
    $hasConfirmed = !empty($row['appointment_date']) && !empty($row['appointment_time']);
    return [
        'preferred_date' => $hasPreferred ? date('l, F j, Y', strtotime((string) $row['preferred_burial_date'])) : 'Not provided',
        'preferred_time' => !empty($row['preferred_burial_time']) ? date('g:i A', strtotime((string) $row['preferred_burial_time'])) : 'Not provided',
        'has_preferred' => $hasPreferred && !empty($row['preferred_burial_time']),
        'confirmed' => $hasConfirmed ? date('l, F j, Y', strtotime((string) $row['appointment_date'])) . ' at ' . date('g:i A', strtotime((string) $row['appointment_time'])) : null,
    ];
}

/**
 * When the confirmed schedule differs from the burial date/time saved in the
 * Katin-awan sa Paglubong form, the warning to show (never auto-corrected).
 */
function funeralKatinAwanScheduleMismatch(array $appointment, ?array $formData): ?string
{
    if (!$formData || empty($appointment['appointment_date']) || empty($appointment['appointment_time'])) return null;
    $formDate = trim((string) ($formData['kanus_a_ilubong'] ?? ''));
    $formTime = substr(trim((string) ($formData['oras_sa_lubong'] ?? '')), 0, 5);
    $confirmedDate = substr((string) $appointment['appointment_date'], 0, 10);
    $confirmedTime = substr((string) $appointment['appointment_time'], 0, 5);
    if ($formDate === $confirmedDate && $formTime === $confirmedTime) return null;
    $fmt = static fn(string $d, string $t): string => ($d !== '' ? date('F j, Y', strtotime($d)) : 'no date') . ' at ' . ($t !== '' ? date('g:i A', strtotime($t)) : 'no time');
    return 'The confirmed burial / Funeral Mass schedule is ' . $fmt($confirmedDate, $confirmedTime)
        . ', but the Katin-awan sa Paglubong form says ' . $fmt($formDate, $formTime) . '.';
}

/**
 * The family's mismatch notice, by what they can do now (their saved answers
 * are never changed for them):
 *   requested  the Parish Office asked for an update: correct and resubmit
 *   editable   the form is still open for editing: correct it
 *   wait       the form is approved / under review: wait for the Parish Office
 */
function renderFuneralKatinAwanMismatchAlert(string $mismatch, string $mode, array $appointment, string $style = ''): void
{
    $confirmed = date('F j, Y', strtotime((string) $appointment['appointment_date'])) . ' at ' . date('g:i A', strtotime((string) $appointment['appointment_time']));
    if ($mode === 'requested') {
        $title = 'Update requested:';
        $next = 'The Parish Office asked you to correct the Katin-awan sa Paglubong form. Open the form, change KANUS-A ILUBONG and ORAS to ' . $confirmed
            . ', generate the form again, and follow the instructions shown to resubmit it for review.';
    } elseif ($mode === 'editable') {
        $title = 'Schedule changed:';
        $next = 'Please open the form, change KANUS-A ILUBONG and ORAS to ' . $confirmed . ', and generate it again.';
    } else {
        $title = 'Schedule changed:';
        $next = 'You do not need to do anything yet. The form cannot be edited for now; if it needs correcting, the Parish Office will request an update, and you can then edit and resubmit it. Your saved form has not been changed.';
    }
    ?>
    <div class="alert" style="background:var(--warning-bg); color:var(--brown-mid); border:1px solid var(--cream-dark);<?= $style ?>"><strong><?= e($title) ?></strong> <?= e($mismatch) ?> <?= e($next) ?></div>
    <?php
}

/**
 * Email the family that the Katin-awan form needs its burial date/time updated
 * (used after approval; pending appointments use the regular revision-request
 * email). Brevo + email_logs, one email per request. Never throws.
 *
 * @return string sent | failed | duplicate | no_address
 */
function sendFuneralFormUpdateEmail(int $appointmentId, string $reason, ?int $actorUserId): string
{
    try {
        require_once __DIR__ . '/precana-notifications.php'; // precanaEmailRecipient(): applicant email, name, booking link
        $r = precanaEmailRecipient($appointmentId);
        if (!$r) return 'failed';
        if (!$r['email']) return 'no_address';
        $h = static fn($v): string => htmlspecialchars((string) $v);
        $title = 'Please Update Your Katin-awan sa Paglubong Form';
        $how = $r['is_guest']
            ? 'Open your booking with the button below (the link is valid for 7 days; afterwards use your booking reference and email on the Check Your Status page), open the Katin-awan sa Paglubong form, update the burial date and time, and generate it again.'
            : 'Log in to ParishHub, open this appointment, open the Katin-awan sa Paglubong form, update the burial date and time, and generate it again.';
        $body = '<p>Dear ' . $h($r['name'] ?: 'Parishioner') . ',</p>'
            . '<p>The burial / Funeral Mass schedule for your booking has been confirmed, and the Katin-awan sa Paglubong form needs to match it.</p>'
            . '<ul style="padding-left:20px;margin:0 0 16px;">'
            . '<li><strong>Booking Reference:</strong> ' . $h($r['reference']) . '</li>'
            . '<li><strong>Form Status:</strong> Needs Revision</li>'
            . '<li><strong>Reason:</strong> ' . $h($reason) . '</li>'
            . '</ul>'
            . "<p>$how</p>"
            . '<p>Our Lady of Mt. Carmel Parish</p>';
        return sendLoggedEmail('funeral_form_update', 'funeral_form_update:' . $appointmentId . ':' . sha1($reason), $appointmentId, $r['reference'],
            $r['email'], $r['name'] ?: null, 'ParishHub – ' . $title, emailTemplate($title, $body, $r['link'], 'Open My Booking'), $actorUserId);
    } catch (Throwable $e) {
        error_log("Funeral form update email for appointment #$appointmentId failed: " . $e->getMessage());
        return 'failed';
    }
}

function funeralFormUpdateEmailNotice(string $result): string
{
    require_once __DIR__ . '/precana-notifications.php';
    return precanaEmailNotice($result);
}

/** Preferred + confirmed schedule lines for the family's pages and the Secretary. */
function renderFuneralScheduleSummary(array $row): void
{
    $s = funeralScheduleDisplay($row);
    ?>
    <div class="funeral-schedule-summary">
      <p><strong>Preferred Burial / Funeral Mass Date:</strong> <?= e($s['preferred_date']) ?></p>
      <p><strong>Preferred Burial / Funeral Mass Time:</strong> <?= e($s['preferred_time']) ?></p>
      <?php if ($s['confirmed']): ?>
        <p><strong>Confirmed Burial / Funeral Mass Schedule:</strong> <span class="badge badge-approved"><?= e($s['confirmed']) ?></span></p>
      <?php else: ?>
        <p><strong>Confirmed Burial / Funeral Mass Schedule:</strong> <span class="badge badge-pending">Not yet confirmed</span>
          <?php if ($s['has_preferred']): ?><br><span class="text-muted" style="font-size:13px;">Preferred Schedule — Awaiting Parish Office Confirmation</span><?php endif; ?></p>
      <?php endif; ?>
    </div>
    <?php
}
