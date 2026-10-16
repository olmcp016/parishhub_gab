<?php
/**
 * PARISHHUB — telling the applicant their appointment was approved.
 *
 * Called by secretary/appointment-detail.php ONLY after the approval
 * (Pending -> Approved, or Pending -> Confirmed for a no-fee PSS Funeral) has
 * been committed. Never throws: a failed email or notification never undoes
 * the approval, it is only logged (email_logs / activity_logs / error_log)
 * and reported to the Secretary.
 *
 *   - Email (guests and parishioners) through the existing Brevo + email_logs
 *     path; one per appointment (dedupe key appointment_approved:{id}).
 *   - Website notification for registered parishioners only. The database
 *     trigger trg_appointment_notify (migration_notification_trigger.sql) may
 *     already have inserted "Appointment Approved"/"Appointment Confirmed" for
 *     this status change; that row is updated instead of adding a second one.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/service-fees.php';
require_once __DIR__ . '/requirements-review.php'; // guestAccessLink, requirementsReviewReference, email + notification helpers

/** Everything the approval email and notification need. */
function approvedAppointmentDetails(int $appointmentId): ?array
{
    $stmt = db()->prepare(
        'SELECT a.appointment_id, a.status_id, a.appointment_date, a.appointment_time, a.location_address, a.guest_name, a.guest_email, a.guest_reference,
                a.schedule_type, a.pss_classification, a.sponsor_count, a.wedding_sponsor_count, a.fee_snapshot,
                s.service_name, s.category, s.fee, u.user_id, u.email AS user_email, u.firstname, u.lastname
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = p.user_id
         WHERE a.appointment_id = ?'
    );
    $stmt->execute([$appointmentId]);
    return $stmt->fetch() ?: null;
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
        return '<strong>Payment:</strong> No payment is required for this appointment (PSS Giver — No Fees). Your appointment is confirmed.';
    }
    $latest = db()->prepare('SELECT payment_status FROM payments WHERE appointment_id = ? ORDER BY payment_id DESC LIMIT 1');
    $latest->execute([(int) $a['appointment_id']]);
    $paymentStatus = $latest->fetchColumn();
    if ($paymentStatus === 'verified') return '<strong>Payment:</strong> Your payment has been verified. Please wait for the Parish Office to confirm your appointment.';
    if ($paymentStatus === 'pending') return '<strong>Payment:</strong> Your payment has been submitted and is awaiting verification by the parish cashier.';

    $amount = approvedAppointmentAmountDue($a);
    if ($amount !== null && $amount <= 0 && in_array($a['category'], ['Wedding', 'Funeral'], true)) {
        return '<strong>Payment:</strong> No payment is required for this appointment (No Fees). The Parish Office will confirm your appointment.';
    }
    $where = $isGuest
        ? 'through your ParishHub booking page (button below) or at the parish office'
        : 'by logging in to ParishHub and opening this appointment, or at the parish office';
    return '<strong>Payment:</strong> Not yet paid'
        . ($amount !== null && $amount > 0 ? ' — Amount due: <strong>' . htmlspecialchars(money($amount)) . '</strong>' : '')
        . ". Please complete your payment $where. Your appointment will be confirmed once the payment is verified."
        . ($amount === null ? ' The Parish Office will confirm the amount due.' : '');
}

/** "Your appointment for Baptism has been approved." (+ "#id" when notifications cannot store the link). */
function appointmentApprovedNotificationMessage(array $a): string
{
    return notificationsHaveAppointmentLink()
        ? "Your appointment for {$a['service_name']} has been approved."
        : "Your appointment #{$a['appointment_id']} for {$a['service_name']} has been approved.";
}

/** Website notification for a registered parishioner (guests have no account). @return bool created/updated */
function notifyParishionerAppointmentApproved(array $a): bool
{
    if (requirementsReviewIsGuest($a) || empty($a['user_id'])) return false;
    try {
        $userId = (int) $a['user_id'];
        $id = (int) $a['appointment_id'];
        $message = appointmentApprovedNotificationMessage($a);
        $link = notificationsHaveAppointmentLink();
        // Row the status trigger may have just inserted for this same change ("Your appointment #12 has been approved…").
        $existing = db()->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND title IN ('Appointment Approved', 'Appointment Confirmed') AND message LIKE ? ORDER BY notification_id DESC LIMIT 1");
        $existing->execute([$userId, "Your appointment #$id %"]);
        $notificationId = $existing->fetchColumn();
        if ($notificationId) {
            db()->prepare($link
                ? "UPDATE notifications SET title = 'Appointment Approved', message = ?, appointment_id = ? WHERE notification_id = ?"
                : "UPDATE notifications SET title = 'Appointment Approved', message = ? WHERE notification_id = ?")
                ->execute($link ? [$message, $id, (int) $notificationId] : [$message, (int) $notificationId]);
        } else {
            db()->prepare($link
                ? "INSERT INTO notifications (user_id, type, category, title, message, appointment_id) VALUES (?, 'website', 'appointment', 'Appointment Approved', ?, ?)"
                : "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Approved', ?)")
                ->execute($link ? [$userId, $message, $id] : [$userId, $message]);
        }
        return true;
    } catch (Throwable $e) {
        error_log("Approval notification for appointment #{$a['appointment_id']} failed: " . $e->getMessage());
        return false;
    }
}

/** The approval email body. */
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
    if (trim((string) $a['location_address']) !== '') $rows['Location'] = $h($a['location_address']);
    $rows['Status'] = (int) $a['status_id'] === 5 ? 'Approved (Confirmed)' : 'Approved';
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

    return '<p>Dear ' . $h($name) . ',</p>'
        . "<p>Good news! Your appointment request for <strong>$service</strong> has been approved by the Parish Office.</p>"
        . '<p><strong>Appointment Details:</strong></p>'
        . '<ul style="padding-left:20px;margin:0 0 16px;">' . $list . '</ul>'
        . ($hasDate && $hasTime ? '' : '<p>Your final schedule is still being arranged. The Parish Office will provide the confirmed date and time.</p>')
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
        $isGuest = requirementsReviewIsGuest($a);
        $result['notified'] = notifyParishionerAppointmentApproved($a);

        $toEmail = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['email'] = 'no_address';
            return $result;
        }
        $result['email_to'] = $toEmail;
        $name = $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $link = $isGuest && trim((string) $a['guest_reference']) !== ''
            ? guestAccessLink($appointmentId, (string) $a['guest_reference'])
            : ($isGuest ? absoluteUrl('status.php') : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId));
        $result['email'] = sendLoggedEmail('appointment_approved', "appointment_approved:$appointmentId", $appointmentId, requirementsReviewReference($a),
            $toEmail, $name !== '' ? $name : null, 'ParishHub – Your Appointment Has Been Approved',
            emailTemplate('Your Appointment Has Been Approved', appointmentApprovedEmailHtml($a, $isGuest, $name !== '' ? $name : 'Parishioner', $link), $link, 'View My Booking'),
            $actorUserId);
    } catch (Throwable $e) {
        error_log("Approval email for appointment #$appointmentId failed: " . $e->getMessage());
    }
    return $result;
}

/** Sentence appended to the Secretary's success message. */
function appointmentApprovedNoticeSummary(array $r): string
{
    $parts = [];
    if ($r['email'] === 'sent' || $r['email'] === 'duplicate') {
        $parts[] = 'The approval email to ' . maskEmail((string) $r['email_to']) . ' was accepted by the email service for delivery.';
    } elseif ($r['email'] === 'no_address') {
        $parts[] = 'No approval email was sent: there is no valid email address on this booking.';
    } else {
        $parts[] = 'The approval email could not be sent' . (emailDeliveryConfigured() ? '' : ' because email is not configured on this server') . '; the failure was logged. Please contact the applicant directly.';
    }
    if ($r['notified']) $parts[] = 'The parishioner was also notified in ParishHub.';
    return implode(' ', $parts);
}
