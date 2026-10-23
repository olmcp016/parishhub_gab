<?php
/**
 * PARISHHUB — Pre-Cana emails to the guest / registered parishioner.
 *
 * Sent through the existing Brevo integration and email_logs de-duplication
 * (includes/email-log.php). Each email's key names the exact event, so
 * repeating the same action sends nothing new, while a genuinely different
 * schedule or a new rejected submission sends a new email:
 *
 *   precana_schedule:{appointment}:confirmed:{date}:{time}
 *   precana_schedule:{appointment}:proposed:{date}:{time}
 *   precana_certificate_revision:{appointment}:{rejected document}
 *
 * Called only after the Secretary's change is saved: a failed email never
 * undoes it. No documents or document links are included.
 */
require_once __DIR__ . '/precana.php';
require_once __DIR__ . '/requirements-review.php'; // applicant lookup, guest links, sendLoggedEmail()

/** The applicant's email, name and booking link (guests: signed link to their own booking). */
function precanaEmailRecipient(int $appointmentId): ?array
{
    $a = requirementsReviewAppointment($appointmentId);
    if (!$a) return null;
    $isGuest = requirementsReviewIsGuest($a);
    $email = trim((string) ($isGuest ? $a['guest_email'] : $a['user_email']));
    $link = $isGuest
        ? (trim((string) $a['guest_reference']) !== '' ? guestAccessLink($appointmentId, (string) $a['guest_reference']) : absoluteUrl('status.php'))
        : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId);
    return [
        'appointment' => $a,
        'is_guest' => $isGuest,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        'name' => $isGuest ? (string) $a['guest_name'] : trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')),
        'link' => $link,
        'reference' => requirementsReviewReference($a),
    ];
}

function precanaEmailHowToCheck(bool $isGuest): string
{
    return $isGuest
        ? 'Open your booking with the button below (the link is valid for 7 days; afterwards use your booking reference and email on the Check Your Status page).'
        : 'Log in to ParishHub and open this appointment under My Appointments, or use the button below.';
}

/**
 * Special Pre-Cana schedule confirmed by, or proposed by, the Parish Office.
 * A proposed schedule is never described as confirmed.
 *
 * @return string sent | failed | duplicate | no_address
 */
function sendPrecanaScheduleEmail(int $appointmentId, string $event, ?int $actorUserId): string
{
    try {
        $p = precanaForAppointment($appointmentId);
        $r = precanaEmailRecipient($appointmentId);
        if (!$p || !$r || !in_array($event, ['confirmed', 'proposed'], true)) return 'failed';
        if (!$r['email']) return 'no_address';
        $h = static fn($v): string => htmlspecialchars((string) $v);
        $d = precanaDisplay($p);
        $confirmed = $event === 'confirmed';
        $title = $confirmed ? 'Your Pre-Cana Seminar Schedule Is Confirmed' : 'A New Pre-Cana Seminar Schedule Was Proposed';
        $intro = $confirmed
            ? 'The Parish Office has <strong>confirmed</strong> the schedule of your ' . $h($d['type_label']) . '.'
            : 'The Parish Office has <strong>proposed</strong> a new schedule for your ' . $h($d['type_label']) . '. <strong>This schedule is not yet confirmed.</strong> If it does not suit you, please contact the Parish Office. You will receive another email once it is confirmed.';
        $body = '<p>Dear ' . $h($r['name'] ?: 'Parishioner') . ',</p>'
            . "<p>$intro</p>"
            . '<ul style="padding-left:20px;margin:0 0 16px;">'
            . '<li><strong>Booking Reference:</strong> ' . $h($r['reference']) . '</li>'
            . '<li><strong>Pre-Cana Seminar Type:</strong> ' . $h($d['type_label']) . '</li>'
            . '<li><strong>' . ($confirmed ? 'Confirmed' : 'Proposed') . ' Date:</strong> ' . $h($d['date']) . '</li>'
            . '<li><strong>' . ($confirmed ? 'Confirmed' : 'Proposed') . ' Time:</strong> ' . $h($d['time']) . '</li>'
            . '<li><strong>Schedule Status:</strong> ' . ($confirmed ? 'Confirmed by the Parish Office' : 'Proposed by the Parish Office — awaiting confirmation') . '</li>'
            . '</ul>'
            . '<p>This is your Pre-Cana seminar schedule only; your Wedding Ceremony schedule is unchanged.</p>'
            . '<p>' . precanaEmailHowToCheck($r['is_guest']) . '</p>'
            . ($confirmed ? '<p>After you attend the seminar, please upload your ' . $h(PRECANA_CERTIFICATE_LABEL) . ' through ParishHub; it is needed before your Wedding is confirmed.</p>' : '')
            . '<p>Our Lady of Mt. Carmel Parish</p>';
        $key = 'precana_schedule:' . $appointmentId . ':' . $event . ':' . $p['precana_date'] . ':' . substr((string) $p['precana_time'], 0, 5);
        return sendLoggedEmail('precana_schedule', $key, $appointmentId, $r['reference'], $r['email'], $r['name'] ?: null,
            'ParishHub – ' . $title, emailTemplate($title, $body, $r['link'], 'View My Booking'), $actorUserId);
    } catch (Throwable $e) {
        error_log("Pre-Cana schedule email for appointment #$appointmentId failed: " . $e->getMessage());
        return 'failed';
    }
}

/**
 * The Secretary rejected the Pre-Cana Seminar Certificate after approval.
 * (While the appointment is still pending, the rejection is included in the
 * regular consolidated revision-request email instead.)
 *
 * @return string sent | failed | duplicate | no_address
 */
function sendPrecanaCertificateRevisionEmail(int $appointmentId, int $documentId, ?string $reason, ?int $actorUserId): string
{
    try {
        $r = precanaEmailRecipient($appointmentId);
        if (!$r) return 'failed';
        if (!$r['email']) return 'no_address';
        $h = static fn($v): string => htmlspecialchars((string) $v);
        $title = 'Your Pre-Cana Seminar Certificate Needs Revision';
        $how = $r['is_guest']
            ? 'Open your booking with the button below, find <strong>' . $h(PRECANA_CERTIFICATE_LABEL) . '</strong>, and choose <strong>Upload Replacement</strong> to upload a corrected copy (PDF, JPG or PNG).'
            : 'Log in to ParishHub, open this appointment, and under <strong>' . $h(PRECANA_CERTIFICATE_LABEL) . '</strong> choose <strong>Upload Replacement</strong> to upload a corrected copy (PDF, JPG or PNG).';
        $body = '<p>Dear ' . $h($r['name'] ?: 'Parishioner') . ',</p>'
            . '<p>The Parish Office reviewed the ' . $h(PRECANA_CERTIFICATE_LABEL) . ' you submitted and it needs revision.</p>'
            . '<ul style="padding-left:20px;margin:0 0 16px;">'
            . '<li><strong>Booking Reference:</strong> ' . $h($r['reference']) . '</li>'
            . '<li><strong>Certificate Status:</strong> Needs Revision</li>'
            . (trim((string) $reason) !== '' ? '<li><strong>Reason:</strong> ' . $h($reason) . '</li>' : '')
            . '</ul>'
            . "<p>$how</p>"
            . '<p>The approved certificate is needed before your Wedding is confirmed. ' . ($r['is_guest'] ? 'The link is valid for 7 days; afterwards use your booking reference and email on the Check Your Status page.' : '') . '</p>'
            . '<p>Our Lady of Mt. Carmel Parish</p>';
        return sendLoggedEmail('precana_certificate_revision', "precana_certificate_revision:$appointmentId:$documentId", $appointmentId, $r['reference'],
            $r['email'], $r['name'] ?: null, 'ParishHub – ' . $title, emailTemplate($title, $body, $r['link'], 'Open My Booking'), $actorUserId);
    } catch (Throwable $e) {
        error_log("Pre-Cana certificate email for appointment #$appointmentId failed: " . $e->getMessage());
        return 'failed';
    }
}

/** Sentence for the Secretary's success message. */
function precanaEmailNotice(string $result): string
{
    return match ($result) {
        'sent' => 'The applicant was emailed (accepted by the email service for delivery).',
        'duplicate' => 'This exact email was already sent earlier, so it was not sent again.',
        'no_address' => 'No email was sent: there is no valid email address on this booking.',
        default => 'The email could not be sent' . (function_exists('emailDeliveryConfigured') && !emailDeliveryConfigured() ? ' because email is not configured on this server' : '') . '; the failure was logged. Please contact the applicant directly.',
    };
}
