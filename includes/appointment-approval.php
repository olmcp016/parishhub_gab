<?php
/**
 * PARISHHUB — Appointment Lifecycle Email & Notification Management.
 *
 * Handles transactional emails and in-app notifications throughout the appointment lifecycle:
 *   1. Booking Received: Sent immediately on submission (guests and parishioners).
 *   2. Update (Needs Revision): Handled via sendRevisionRequest() in requirements-review.php.
 *   3. Update (Reschedule): Sent when the secretary updates the schedule.
 *   4. Update (Rejection): Sent when an appointment is not approved.
 *   5. Approved (Awaiting Payment): Sent on approval when payment is required, including payment deadline + Pay Online CTA.
 *   6. Confirmed (Paid / ₱0): Sent when confirmed by secretary or when ₱0 / verified paid.
 *   7. Past-Date Notice: Sent when scheduled date passes without payment (kept active for reschedule; NOT cancelled).
 *
 * Never throws: failed deliveries are logged to email_logs / activity_logs / error_log
 * and reported to the staff without rolling back core database operations.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/service-fees.php';
require_once __DIR__ . '/requirements-review.php'; // guestAccessLink, requirementsReviewReference, email + notification helpers

/**
 * Checks whether the appointments table has the payment_deadline column.
 */
function paymentDeadlineColumnAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $stmt = db()->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'appointments' AND column_name = 'payment_deadline'");
        $available = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/** Everything the approval/lifecycle emails and notifications need. */
function approvedAppointmentDetails(int $appointmentId): ?array
{
    $hasDeadlineCol = paymentDeadlineColumnAvailable();
    $deadlineField = $hasDeadlineCol ? 'a.payment_deadline,' : '';

    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.status_id, a.appointment_date, a.appointment_time, a.location_address,
                a.guest_name, a.guest_email, a.guest_reference, a.schedule_type, a.pss_classification,
                a.sponsor_count, a.wedding_sponsor_count, a.fee_snapshot, a.approved_at, $deadlineField
                a.priest_id, pr.full_name AS priest_name,
                s.service_name, s.category, s.fee,
                u.user_id, u.email AS user_email, u.firstname, u.lastname
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN priests pr ON pr.priest_id = a.priest_id
         LEFT JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = p.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    return $stmt->fetch() ?: null;
}

/**
 * Calculates the payment deadline for an approved appointment.
 * Policy: 24 hours after approval, but never after the scheduled service date and time.
 * If ₱0 / no payment required or already confirmed/completed, returns null.
 */
function appointmentPaymentDeadline(array $a): ?DateTimeImmutable
{
    if (in_array((int) ($a['status_id'] ?? 0), [3, 5, 6, 7], true)) {
        return null;
    }
    if (appointmentNoPaymentRequired($a)) {
        return null;
    }
    if (!empty($a['payment_deadline'])) {
        try {
            return new DateTimeImmutable((string) $a['payment_deadline']);
        } catch (Throwable $e) {
            // fallback to calculation below
        }
    }
    $approvedTs = !empty($a['approved_at']) ? strtotime((string) $a['approved_at']) : time();
    $deadlineTs = $approvedTs + (24 * 3600); // 24 hours after approval

    if (!empty($a['appointment_date'])) {
        $timeStr = !empty($a['appointment_time']) ? (string) $a['appointment_time'] : '23:59:59';
        $serviceTs = strtotime($a['appointment_date'] . ' ' . $timeStr);
        if ($serviceTs && $serviceTs < $deadlineTs) {
            $deadlineTs = $serviceTs;
        }
    }
    return (new DateTimeImmutable())->setTimestamp($deadlineTs);
}

/**
 * Amount due, derived the same way parishioner/pay.php and guest-pay.php do
 * (read-only here: nothing is written). Null when it cannot be determined yet.
 */
function approvedAppointmentAmountDue(array $a): ?float
{
    $variableCategory = in_array($a['category'], ['Baptism', 'Wedding', 'Funeral', 'Wake'], true);
    if (!$variableCategory || $a['pss_classification'] === null) return (float) $a['fee'];
    $snapshot = $a['fee_snapshot'] ? json_decode((string) $a['fee_snapshot'], true) : null;
    if (is_array($snapshot) && isset($snapshot['total'])) return (float) $snapshot['total'];
    if (!in_array($a['pss_classification'], ['pss', 'non_pss'], true)) return null;
    $sponsors = $a['category'] === 'Wedding' ? (int) $a['wedding_sponsor_count'] : (int) $a['sponsor_count'];
    $precanaType = $a['category'] === 'Wedding' ? (precanaForAppointment((int) $a['appointment_id'])['precana_type'] ?? null) : null;
    $calculation = calculateServiceFee($a['category'], $a['schedule_type'], $a['pss_classification'], $sponsors, $precanaType);
    return $calculation ? (float) $calculation['total'] : null;
}

/** Payment line for the email — states only what is actually true. */
function approvedAppointmentPaymentHtml(array $a, bool $isGuest): string
{
    if ((int) $a['status_id'] === 5) {
        return '<strong>Payment:</strong> No payment is required for this appointment (' . NO_PAYMENT_REQUIRED_LABEL . '). Your appointment is confirmed.';
    }
    $latest = db()->prepare('SELECT payment_status FROM payments WHERE appointment_id = ? ORDER BY payment_id DESC LIMIT 1');
    $latest->execute([(int) $a['appointment_id']]);
    $paymentStatus = $latest->fetchColumn();
    if ($paymentStatus === 'verified') return '<strong>Payment:</strong> Your payment has been verified. Please wait for the Parish Office to confirm your appointment.';
    if ($paymentStatus === 'pending') return '<strong>Payment:</strong> Your payment has been submitted and is awaiting verification by the parish cashier.';

    $amount = approvedAppointmentAmountDue($a);
    if (feeTotalNeedsNoPayment($a['category'], $amount)) {
        return '<strong>Payment:</strong> No payment is required for this appointment (' . NO_PAYMENT_REQUIRED_LABEL . '). The Parish Office will confirm your appointment.';
    }
    $where = $isGuest
        ? 'online through your ParishHub booking page (button below) or in person at the parish office'
        : 'by logging in to ParishHub and opening this appointment, or at the parish office';

    $deadline = appointmentPaymentDeadline($a);
    $deadlineText = $deadline ? ' Payment deadline: <strong>' . htmlspecialchars($deadline->format('l, F j, Y \a\t g:i A')) . '</strong>.' : '';

    return '<strong>Payment:</strong> Not yet paid'
        . ($amount !== null && $amount > 0 ? ' — Amount due: <strong>' . htmlspecialchars(money($amount)) . '</strong>' : '')
        . ". Please complete your payment $where.$deadlineText Your appointment will be confirmed once the payment is verified."
        . ($amount === null ? ' The Parish Office will confirm the amount due.' : '');
}

/**
 * The approval notification's type and text, from what the approval actually did:
 *   - Confirmed (verified ₱0)        -> Appointment Confirmed, no payment required;
 *   - Approved, payment required     -> Appointment Approved, proceed to payment;
 *   - Approved, ₱0 but held for Pre-Cana -> hidden type (nothing to pay, not confirmed yet).
 * "#id" is added when notifications cannot store the link (the bell reads it from the text).
 *
 * @return array{title:string, message:string}
 */
function appointmentApprovedNotification(array $a): array
{
    $ref = notificationsHaveAppointmentLink() ? '' : ' #' . (int) $a['appointment_id'];
    if ((int) $a['status_id'] === 5) {
        return ['title' => NOTIFY_APPOINTMENT_CONFIRMED, 'message' => "Your appointment$ref has been confirmed. No payment is required."];
    }
    if (appointmentNoPaymentRequired($a)) {
        return ['title' => NOTIFY_APPROVED_NO_PAYMENT, 'message' => "Your appointment$ref has been approved. No payment is required; it will be confirmed once the Pre-Cana requirements are complete."];
    }
    $deadline = appointmentPaymentDeadline($a);
    $deadlinePart = $deadline ? " Payment deadline is " . $deadline->format('M j, Y g:i A') . "." : "";
    return ['title' => NOTIFY_APPOINTMENT_APPROVED, 'message' => "Your appointment$ref has been approved. Please proceed to payment.$deadlinePart"];
}

/**
 * Website notification for a registered parishioner (guests have no account).
 * @return bool true when a notification the parishioner sees was created/updated
 */
function notifyParishionerAppointmentApproved(array $a): bool
{
    if (requirementsReviewIsGuest($a) || empty($a['user_id'])) return false;
    try {
        $userId = (int) $a['user_id'];
        $id = (int) $a['appointment_id'];
        $notice = appointmentApprovedNotification($a);
        $link = notificationsHaveAppointmentLink();
        $existing = db()->prepare('SELECT notification_id FROM notifications WHERE user_id = ? AND title IN (?, ?) AND message LIKE ? ORDER BY notification_id DESC LIMIT 1');
        $existing->execute([$userId, NOTIFY_APPOINTMENT_APPROVED, NOTIFY_APPOINTMENT_CONFIRMED, "Your appointment #$id %"]);
        $notificationId = $existing->fetchColumn();
        $hidden = $notice['title'] === NOTIFY_APPROVED_NO_PAYMENT;
        if ($notificationId) {
            db()->prepare($link
                ? 'UPDATE notifications SET title = ?, message = ?, appointment_id = ? WHERE notification_id = ?'
                : 'UPDATE notifications SET title = ?, message = ? WHERE notification_id = ?')
                ->execute($link ? [$notice['title'], $notice['message'], $id, (int) $notificationId] : [$notice['title'], $notice['message'], (int) $notificationId]);
        } elseif (!$hidden) {
            db()->prepare($link
                ? "INSERT INTO notifications (user_id, type, category, title, message, appointment_id) VALUES (?, 'website', 'appointment', ?, ?, ?)"
                : "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
                ->execute($link ? [$userId, $notice['title'], $notice['message'], $id] : [$userId, $notice['title'], $notice['message']]);
        }
        return !$hidden;
    } catch (Throwable $e) {
        error_log("Approval notification for appointment #{$a['appointment_id']} failed: " . $e->getMessage());
        return false;
    }
}

/** The approval email body (Awaiting Payment). */
function appointmentApprovedEmailHtml(array $a, bool $isGuest, string $name, string $link): string
{
    $h = static fn($v): string => htmlspecialchars((string) $v);
    $service = $h($a['service_name']);
    $hasDate = !empty($a['appointment_date']);
    $hasTime = !empty($a['appointment_time']);
    $tba = 'To be announced by the Parish Office';
    $rows = [
        'Booking Reference' => $h(requirementsReviewReference($a)),
        'Service' => $service,
        'Date' => $hasDate ? $h(date('F j, Y', strtotime((string) $a['appointment_date']))) : $tba,
        'Time' => $hasTime ? $h(date('g:i A', strtotime((string) $a['appointment_time']))) : $tba,
    ];
    if (!empty($a['priest_name'])) $rows['Assigned Priest'] = $h($a['priest_name']);
    if (trim((string) ($a['location_address'] ?? '')) !== '') $rows['Location'] = $h($a['location_address']);
    $rows['Status'] = (int) $a['status_id'] === 5 ? 'Approved (Confirmed)' : 'Approved (Awaiting Payment)';

    // Payment deadline
    $deadline = appointmentPaymentDeadline($a);
    if ($deadline && (int) $a['status_id'] === 2) {
        $rows['Payment Deadline'] = '<span style="color:#b45309;font-weight:700;">' . $h($deadline->format('l, F j, Y \a\t g:i A')) . '</span>';
    }

    // Wedding Pre-Cana seminar (its own schedule, separate from the ceremony above).
    $precana = $a['category'] === 'Wedding' ? precanaForAppointment((int) $a['appointment_id']) : null;
    if ($precana && ($p = precanaDisplay($precana))) {
        $rows['Pre-Cana Seminar'] = $h($p['type_label']) . ' — ' . $h($p['date']) . ', ' . $h($p['time']) . ' (' . $h($p['fee_label']) . ')';
        $rows['Pre-Cana Schedule Status'] = $h($p['status_label']);
    }
    $list = '';
    foreach ($rows as $label => $value) $list .= "<li><strong>$label:</strong> $value</li>";
    if ($precana) {
        $list .= '<li><strong>' . $h(PRECANA_CERTIFICATE_LABEL) . ':</strong> please submit it through ParishHub after you attend the seminar; it is needed before your Wedding is confirmed.</li>';
    }

    $deadlineNotice = '';
    if ($deadline && (int) $a['status_id'] === 2) {
        $deadlineNotice = '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;margin:16px 0;font-size:14px;color:#92400e;">'
            . '⏰ <strong>Payment Deadline: ' . $h($deadline->format('l, F j, Y \a\t g:i A')) . '</strong><br>'
            . 'Please settle your payment on or before this deadline to secure your scheduled slot. You can pay online via GCash, Maya, or Card using the button below, or visit the Parish Office.'
            . '</div>';
    }

    return '<p>Dear ' . $h($name) . ',</p>'
        . "<p>Good news! Your appointment request for <strong>$service</strong> has been approved by the Parish Office.</p>"
        . '<p><strong>Appointment Details:</strong></p>'
        . '<ul style="padding-left:20px;margin:0 0 16px;">' . $list . '</ul>'
        . ($hasDate && $hasTime ? '' : '<p>Your final schedule is still being arranged. The Parish Office will provide the confirmed date and time.</p>')
        . $deadlineNotice
        . '<p>' . approvedAppointmentPaymentHtml($a, $isGuest) . '</p>'
        . '<p>You may check your booking details and status through the ParishHub portal'
        . ($isGuest ? ' (the button below opens your booking for 7 days; afterwards use your booking reference and email on the Check Your Status page).' : '.') . '</p>'
        . '<p>Thank you for choosing ParishHub!</p>'
        . '<p>Our Lady of Mt. Carmel Parish</p>';
}

/**
 * Email + website notification after a committed approval.
 *
 * @return array{email:string, email_to:?string, notified:bool}  email: sent|failed|duplicate|no_address
 */
function notifyAppointmentApproved(int $appointmentId, ?int $actorUserId): array
{
    $result = ['email' => 'failed', 'email_to' => null, 'notified' => false];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a || !in_array((int) $a['status_id'], [2, 5], true)) return $result;

        // If the appointment was confirmed straight on approval (verified ₱0, etc.)
        if ((int) $a['status_id'] === 5) {
            $confResult = notifyAppointmentConfirmed($appointmentId, $actorUserId);
            $confResult['notified'] = notifyParishionerAppointmentApproved($a);
            return $confResult;
        }

        $isGuest = requirementsReviewIsGuest($a);
        $result['notified'] = notifyParishionerAppointmentApproved($a);

        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference']) . '#booking-payment'
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $btnLabel = 'Pay Online Now';
        $ref = requirementsReviewReference($a);
        $subject = "ParishHub – Your Appointment Has Been Approved (Payment Required) ($ref)";
        $html = emailTemplate('Your Appointment Has Been Approved', appointmentApprovedEmailHtml($a, $isGuest, $displayName, $link), $link, $btnLabel);

        $result['email'] = sendLoggedEmail(
            'appointment_approved',
            "appointment_approved:$appointmentId",
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            $actorUserId
        );
    } catch (Throwable $e) {
        error_log("Approval email for appointment #$appointmentId failed: " . $e->getMessage());
    }
    return $result;
}

/**
 * 1. "Booking Received" — Sent on submit to acknowledge the request.
 *
 * @return array{email:string, email_to:?string}
 */
function notifyBookingReceived(int $appointmentId): array
{
    $result = ['email' => 'failed', 'email_to' => null];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a) return $result;

        $isGuest = requirementsReviewIsGuest($a);
        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');

        $ref = requirementsReviewReference($a);
        $service = htmlspecialchars((string) $a['service_name']);
        $hasDate = !empty($a['appointment_date']);
        $hasTime = !empty($a['appointment_time']);
        $dateStr = $hasDate ? date('F j, Y', strtotime((string) $a['appointment_date'])) : 'To be arranged by Parish Office';
        $timeStr = $hasTime ? date('g:i A', strtotime((string) $a['appointment_time'])) : '';
        $scheduleDisplay = htmlspecialchars($dateStr . ($timeStr ? " at $timeStr" : ''));

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $body = '<p>Dear ' . htmlspecialchars($displayName) . ',</p>'
            . "<p>Thank you for submitting your booking request for <strong>$service</strong> with Our Lady of Mt. Carmel Parish.</p>"
            . '<div style="background:#fcf9f2; border:1px solid #e8dfc8; border-radius:8px; padding:16px 20px; margin:20px 0;">'
            . '<p style="margin:0 0 6px;"><strong>Booking Reference:</strong> ' . htmlspecialchars($ref) . '</p>'
            . "<p style=\"margin:0 0 6px;\"><strong>Service:</strong> $service</p>"
            . "<p style=\"margin:0 0 6px;\"><strong>Requested Schedule:</strong> $scheduleDisplay</p>"
            . '<p style="margin:0;"><strong>Status:</strong> <span style="color:#b45309; font-weight:600;">Pending Secretary Review</span></p>'
            . '</div>'
            . '<p><strong>What happens next?</strong></p>'
            . '<p>Our Parish Secretary will review your request and submitted documents. You will receive an email update once your booking has been reviewed, or if any requirements need revision.</p>'
            . '<p>You may check your request status anytime using the button below'
            . ($isGuest ? ' (or on the Check Status page using your reference code and email).' : '.') . '</p>'
            . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';

        $subject = "ParishHub – We Received Your Booking Request ($ref)";
        $html = emailTemplate('We Received Your Booking Request', $body, $link, 'Check Booking Status');

        $result['email'] = sendLoggedEmail(
            'booking_received',
            "booking_received:$appointmentId",
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            null
        );
    } catch (Throwable $e) {
        error_log("notifyBookingReceived failed for appointment #$appointmentId: " . $e->getMessage());
    }
    return $result;
}

/**
 * 2b. "Update: Reschedule" — Sent when an appointment's schedule is updated by the Secretary.
 *
 * @return array{email:string, email_to:?string}
 */
function notifyAppointmentRescheduled(int $appointmentId, ?string $newDate, ?string $newTime, ?int $actorUserId): array
{
    $result = ['email' => 'failed', 'email_to' => null];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a) return $result;

        $isGuest = requirementsReviewIsGuest($a);
        $ref = requirementsReviewReference($a);
        $service = htmlspecialchars((string) $a['service_name']);

        $dateVal = $newDate ?: ($a['appointment_date'] ?? null);
        $timeVal = $newTime ?: ($a['appointment_time'] ?? null);
        $dateStr = !empty($dateVal) ? date('F j, Y', strtotime((string) $dateVal)) : 'To be arranged';
        $timeStr = !empty($timeVal) ? date('g:i A', strtotime((string) $timeVal)) : '';
        $scheduleDisplay = htmlspecialchars($dateStr . ($timeStr ? " at $timeStr" : ''));
        $priestName = !empty($a['priest_name']) ? htmlspecialchars((string) $a['priest_name']) : 'Assigned by Parish Office';

        // In-app notification for registered parishioner
        if (!$isGuest && !empty($a['user_id'])) {
            $link = notificationsHaveAppointmentLink();
            $notifTitle = 'Schedule Updated';
            $notifMsg = "Your appointment for {$a['service_name']} ($ref) has been rescheduled to $scheduleDisplay.";
            db()->prepare($link
                ? "INSERT INTO notifications (user_id, type, category, title, message, appointment_id) VALUES (?, 'website', 'appointment', ?, ?, ?)"
                : "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
                ->execute($link ? [(int) $a['user_id'], $notifTitle, $notifMsg, $appointmentId] : [(int) $a['user_id'], $notifTitle, $notifMsg]);
        }

        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $body = '<p>Dear ' . htmlspecialchars($displayName) . ',</p>'
            . "<p>The schedule for your <strong>$service</strong> booking has been updated by the Parish Office.</p>"
            . '<div style="background:#fcf9f2; border:1px solid #e8dfc8; border-radius:8px; padding:16px 20px; margin:20px 0;">'
            . '<p style="margin:0 0 6px;"><strong>Booking Reference:</strong> ' . htmlspecialchars($ref) . '</p>'
            . "<p style=\"margin:0 0 6px;\"><strong>Service:</strong> $service</p>"
            . "<p style=\"margin:0 0 6px;\"><strong>New Scheduled Date & Time:</strong> <strong>$scheduleDisplay</strong></p>"
            . "<p style=\"margin:0;\"><strong>Priest:</strong> $priestName</p>"
            . '</div>'
            . '<p>Please take note of your updated schedule. If you have questions or any conflict with this time, please contact the Parish Office promptly.</p>'
            . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';

        $subject = "ParishHub – Schedule Update for Your Appointment ($ref)";
        $html = emailTemplate('Schedule Update', $body, $link, 'View Updated Booking');
        $dedupeKey = "appointment_rescheduled:$appointmentId:$dateVal:$timeVal";

        $result['email'] = sendLoggedEmail(
            'appointment_rescheduled',
            $dedupeKey,
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            $actorUserId
        );
    } catch (Throwable $e) {
        error_log("notifyAppointmentRescheduled failed for appointment #$appointmentId: " . $e->getMessage());
    }
    return $result;
}

/**
 * 2c. "Update: Rejection" — Sent when an appointment request cannot be approved.
 *
 * @return array{email:string, email_to:?string}
 */
function notifyAppointmentRejected(int $appointmentId, string $reason, ?int $actorUserId): array
{
    $result = ['email' => 'failed', 'email_to' => null];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a) return $result;

        $isGuest = requirementsReviewIsGuest($a);
        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');
        $ref = requirementsReviewReference($a);
        $service = htmlspecialchars((string) $a['service_name']);
        $reasonDisplay = htmlspecialchars($reason);

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $body = '<p>Dear ' . htmlspecialchars($displayName) . ',</p>'
            . "<p>We have reviewed your booking request for <strong>$service</strong>.</p>"
            . '<p>Unfortunately, your request could not be approved at this time.</p>'
            . '<div style="background:#fff3f3; border:1px solid #f5c2c2; border-radius:8px; padding:16px 20px; margin:20px 0;">'
            . '<p style="margin:0 0 6px;"><strong>Booking Reference:</strong> ' . htmlspecialchars($ref) . '</p>'
            . "<p style=\"margin:0 0 6px;\"><strong>Service:</strong> $service</p>"
            . "<p style=\"margin:0;\"><strong>Reason for Rejection:</strong> <span style=\"color:#b74040; font-weight:600;\">$reasonDisplay</span></p>"
            . '</div>'
            . '<p>If you have questions or would like to submit a new request with updated details, please contact the Parish Office or visit the ParishHub portal.</p>'
            . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';

        $subject = "ParishHub – Update on Your Appointment Request ($ref)";
        $html = emailTemplate('Appointment Not Approved', $body, $link, 'View Request Details');

        $result['email'] = sendLoggedEmail(
            'appointment_rejected',
            "appointment_rejected:$appointmentId",
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            $actorUserId
        );
    } catch (Throwable $e) {
        error_log("notifyAppointmentRejected failed for appointment #$appointmentId: " . $e->getMessage());
    }
    return $result;
}

/**
 * 3. "Confirmed (paid / ₱0)" — Sent when an appointment is confirmed by Secretary or when paid.
 *
 * @return array{email:string, email_to:?string}
 */
function notifyAppointmentConfirmed(int $appointmentId, ?int $actorUserId): array
{
    $result = ['email' => 'failed', 'email_to' => null];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a) return $result;

        $isGuest = requirementsReviewIsGuest($a);
        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');
        $ref = requirementsReviewReference($a);
        $service = htmlspecialchars((string) $a['service_name']);

        $dateStr = !empty($a['appointment_date']) ? date('F j, Y', strtotime((string) $a['appointment_date'])) : 'To be confirmed';
        $timeStr = !empty($a['appointment_time']) ? date('g:i A', strtotime((string) $a['appointment_time'])) : '';
        $scheduleDisplay = htmlspecialchars($dateStr . ($timeStr ? " at $timeStr" : ''));
        $priestName = !empty($a['priest_name']) ? htmlspecialchars((string) $a['priest_name']) : 'Assigned Priest';

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $locationHtml = !empty($a['location_address']) ? '<p style="margin:0 0 6px;"><strong>Location:</strong> ' . htmlspecialchars((string) $a['location_address']) . '</p>' : '';

        $body = '<p>Dear ' . htmlspecialchars($displayName) . ',</p>'
            . "<p>Good news! Your appointment for <strong>$service</strong> has been officially <strong>confirmed</strong>.</p>"
            . '<div style="background:#f4fbf7; border:1px solid #c3e6cb; border-radius:8px; padding:16px 20px; margin:20px 0;">'
            . '<p style="margin:0 0 6px;"><strong>Booking Reference:</strong> ' . htmlspecialchars($ref) . '</p>'
            . "<p style=\"margin:0 0 6px;\"><strong>Service:</strong> $service</p>"
            . "<p style=\"margin:0 0 6px;\"><strong>Confirmed Date & Time:</strong> <strong>$scheduleDisplay</strong></p>"
            . "<p style=\"margin:0 0 6px;\"><strong>Assigned Priest:</strong> $priestName</p>"
            . $locationHtml
            . '<p style="margin:0;"><strong>Status:</strong> <span style="color:#28a745; font-weight:600;">Confirmed</span></p>'
            . '</div>'
            . '<p>Please arrive at the parish church 15–30 minutes prior to your scheduled time. We look forward to celebrating this sacred service with you.</p>'
            . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';

        $subject = "ParishHub – Your Appointment Has Been Confirmed ($ref)";
        $html = emailTemplate('Your Appointment Is Confirmed', $body, $link, 'View Confirmed Booking');

        $result['email'] = sendLoggedEmail(
            'appointment_confirmed',
            "appointment_confirmed:$appointmentId",
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            $actorUserId
        );
    } catch (Throwable $e) {
        error_log("notifyAppointmentConfirmed failed for appointment #$appointmentId: " . $e->getMessage());
    }
    return $result;
}

/**
 * 7. "Notice: Scheduled Date Passed" — Sent when an approved appointment passes its date while unpaid.
 * Clarifies that the appointment is NOT cancelled and explains rescheduling.
 *
 * @return array{email:string, email_to:?string}
 */
function notifyPastDateReschedule(int $appointmentId): array
{
    $result = ['email' => 'failed', 'email_to' => null];
    try {
        $a = approvedAppointmentDetails($appointmentId);
        if (!$a) return $result;

        $isGuest = requirementsReviewIsGuest($a);
        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $displayName = $name !== '' ? $name : ($isGuest ? 'Guest' : 'Parishioner');
        $ref = requirementsReviewReference($a);
        $service = htmlspecialchars((string) $a['service_name']);
        $dateStr = !empty($a['appointment_date']) ? date('F j, Y', strtotime((string) $a['appointment_date'])) : 'your scheduled date';

        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));

        $body = '<p>Dear ' . htmlspecialchars($displayName) . ',</p>'
            . "<p>We are reaching out regarding your approved booking for <strong>$service</strong> ($ref).</p>"
            . '<div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:16px 20px; margin:20px 0;">'
            . '<p style="margin:0 0 6px;"><strong>Booking Reference:</strong> ' . htmlspecialchars($ref) . '</p>'
            . "<p style=\"margin:0 0 6px;\"><strong>Service:</strong> $service</p>"
            . "<p style=\"margin:0 0 6px;\"><strong>Scheduled Date:</strong> " . htmlspecialchars($dateStr) . "</p>"
            . '<p style="margin:0;"><strong>Status:</strong> <span style="color:#b45309; font-weight:600;">Needs Reschedule (Scheduled Date Passed)</span></p>'
            . '</div>'
            . '<p><strong>Your booking has NOT been cancelled.</strong></p>'
            . '<p>Because the scheduled date passed without payment, your appointment is preserved so you can reschedule it for a new date that works for you and the parish.</p>'
            . '<p>Please contact or visit the Parish Office to coordinate a new schedule with our Parish Secretary.</p>'
            . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';

        $subject = "ParishHub – Notice: Scheduled Date Passed for Booking $ref";
        $html = emailTemplate('Appointment Notice – Needs Reschedule', $body, $link, 'View Booking Details');

        $result['email'] = sendLoggedEmail(
            'past_date_reschedule',
            "past_date_reschedule:$appointmentId",
            $appointmentId,
            $ref,
            $toEmail,
            $displayName,
            $subject,
            $html,
            null
        );
    } catch (Throwable $e) {
        error_log("notifyPastDateReschedule failed for appointment #$appointmentId: " . $e->getMessage());
    }
    return $result;
}

/** Sentence appended to the Secretary's success message. */
function appointmentApprovedNoticeSummary(array $r): string
{
    $parts = [];
    if (($r['email'] ?? '') === 'sent' || ($r['email'] ?? '') === 'duplicate') {
        $parts[] = 'The notification email to ' . maskEmail((string) ($r['email_to'] ?? '')) . ' was accepted by the email service for delivery.';
    } elseif (($r['email'] ?? '') === 'no_address') {
        $parts[] = 'No email was sent: there is no valid email address on this booking.';
    } else {
        $parts[] = 'The notification email could not be sent' . (emailDeliveryConfigured() ? '' : ' because email is not configured on this server') . '; the attempt was logged.';
    }
    if (!empty($r['notified'])) $parts[] = 'The parishioner was also notified in ParishHub.';
    return implode(' ', $parts);
}
