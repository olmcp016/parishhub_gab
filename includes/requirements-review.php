<?php
/**
 * PARISHHUB — requirements revision / re-review workflow (email based).
 *
 *   Secretary rejects items  ->  "Send Revision Request"  (one email to the
 *   parishioner/guest listing every rejected item)  ->  appointment is
 *   "Awaiting Revision"  ->  applicant replaces documents / regenerates forms
 *   ->  "Submit Updated Requirements"  (email to the Secretaries)  ->  "Ready
 *   for Re-Review"  ->  Secretary approves / rejects again.
 *
 * Stored state (database/migration_requirements_review.sql):
 *   appointments.requirements_review_status  NULL | 'awaiting_revision' | 'ready_for_rereview'
 *   appointments.revision_requested_at       when the last revision request was sent
 * "Pending Review" and "Approved" are derived from the requirement rows
 * themselves (uploaded_documents), so they never drift out of date.
 *
 * Status changes are committed BEFORE any email is sent: a failed email never
 * undoes or blocks the change, it is only logged (activity_logs + error_log)
 * and reported to the person who clicked the button.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const GUEST_ACCESS_LINK_TTL = 7 * 24 * 3600;

/** True once the migration has been run; until then every feature here stays hidden. */
function requirementsReviewAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $stmt = db()->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'appointments' AND column_name = 'requirements_review_status'");
        $available = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * The appointment's current requirements (supporting documents and generated
 * form PDFs): one active row per requirement.
 *
 * @return array<int, array{document_id:int, label:string, kind:string, review_status:string, rejection_reason:?string, reviewed_at:?string}>
 */
function requirementsReviewItems(int $appointmentId): array
{
    $stmt = db()->prepare(
        "SELECT document_id, requirement_label, file_name, document_source, review_status, verified, rejection_reason, reviewed_at
         FROM uploaded_documents
         WHERE appointment_id = ? AND superseded_by IS NULL
         ORDER BY document_id"
    );
    $stmt->execute([$appointmentId]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $status = (string) ($row['review_status'] ?? '');
        if ($status === '') $status = in_array($row['verified'], [true, 1, '1', 't', 'true'], true) ? 'approved' : 'pending';
        $label = trim((string) ($row['requirement_label'] ?: $row['file_name']));
        $items[] = [
            'document_id' => (int) $row['document_id'],
            'label' => preg_replace('/\.pdf$/i', '', $label),
            'kind' => ($row['document_source'] ?? '') === 'generated' ? 'Form' : 'Document',
            'review_status' => in_array($status, ['rejected', 'needs_revision'], true) ? 'rejected' : $status,
            'rejection_reason' => $row['rejection_reason'],
            'reviewed_at' => $row['reviewed_at'],
        ];
    }
    return $items;
}

/** @return array{rejected:int, pending:int, approved:int} */
function requirementsReviewCounts(array $items): array
{
    $counts = ['rejected' => 0, 'pending' => 0, 'approved' => 0];
    foreach ($items as $item) {
        $key = $item['review_status'] === 'rejected' ? 'rejected' : ($item['review_status'] === 'approved' ? 'approved' : 'pending');
        $counts[$key]++;
    }
    return $counts;
}

/**
 * Display state of the requirements review.
 *
 * @return array{code:string,label:string,badge:string}
 */
function requirementsReviewState(?string $stored, array $counts): array
{
    if ($stored === 'awaiting_revision') return ['code' => 'awaiting_revision', 'label' => 'Awaiting Revision', 'badge' => 'rejected'];
    if ($stored === 'ready_for_rereview') return ['code' => 'ready_for_rereview', 'label' => 'Ready for Re-Review', 'badge' => 'confirmed'];
    if ($counts['rejected'] === 0 && $counts['pending'] === 0 && $counts['approved'] > 0) return ['code' => 'approved', 'label' => 'Approved', 'badge' => 'approved'];
    return ['code' => 'pending_review', 'label' => 'Pending Review', 'badge' => 'pending'];
}

/**
 * Rejected items the applicant has not been told about yet: everything rejected
 * when no request is open, or items rejected after the last request was sent.
 */
function requirementsReviewUnsentRejections(array $appointment, array $items): array
{
    $requestedAt = $appointment['revision_requested_at'] ?? null;
    $isOpen = ($appointment['requirements_review_status'] ?? null) === 'awaiting_revision';
    return array_values(array_filter($items, static function (array $item) use ($requestedAt, $isOpen): bool {
        if ($item['review_status'] !== 'rejected') return false;
        if (!$isOpen || !$requestedAt) return true;
        return $item['reviewed_at'] !== null && strtotime((string) $item['reviewed_at']) > strtotime((string) $requestedAt);
    }));
}

/** Secret for signing guest links: GUEST_LINK_SECRET, else derived from the server-only DB/storage secrets. */
function guestAccessSecret(): string
{
    $configured = (string) getenv('GUEST_LINK_SECRET');
    if ($configured !== '') return $configured;
    return hash('sha256', 'parishhub-guest-link|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('SUPABASE_SECRET_KEY') ? SUPABASE_SECRET_KEY : ''));
}

function guestAccessSignature(int $appointmentId, string $reference, int $expires): string
{
    return hash_hmac('sha256', "appointment:$appointmentId|reference:" . strtoupper($reference) . "|expires:$expires", guestAccessSecret());
}

/** An expiring, booking-specific link to the guest status page (no reference+contact lookup needed). */
function guestAccessLink(int $appointmentId, string $reference): string
{
    $expires = time() + GUEST_ACCESS_LINK_TTL;
    return absoluteUrl('status.php') . '?' . http_build_query([
        'ref' => strtoupper($reference),
        'access' => $expires . '.' . guestAccessSignature($appointmentId, $reference, $expires),
    ]);
}

/** Validates a status.php "access" value for the appointment with that reference. */
function guestAccessTokenValid(int $appointmentId, string $reference, string $token): bool
{
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) return false;
    $expires = (int) $m[1];
    if ($expires < time() || $expires > time() + GUEST_ACCESS_LINK_TTL + 60) return false;
    return hash_equals(guestAccessSignature($appointmentId, $reference, $expires), $m[2]);
}

/**
 * Sends one workflow email, retrying once on a temporary failure, and logs the
 * outcome to activity_logs. Never throws.
 */
function sendRequirementsEmail(string $toEmail, ?string $toName, string $subject, string $html, int $appointmentId, ?int $actorUserId): bool
{
    $result = ['ok' => false, 'error' => 'not attempted'];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $result = sendEmail($toEmail, $toName, $subject, $html);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage(), 'retryable' => true];
        }
        if ($result['ok'] || empty($result['retryable'])) break;
        usleep(700000);
    }
    $masked = preg_replace('/(?<=.).(?=[^@]*@)/', '*', $toEmail);
    logActivity($actorUserId, ($result['ok'] ? 'Email sent' : 'Email FAILED') . " for appointment #$appointmentId: \"$subject\" to $masked" . ($result['ok'] ? '' : ' — ' . ($result['error'] ?? 'unknown error')), 'Email');
    return (bool) $result['ok'];
}

/** The appointment fields both emails need (service, reference, applicant email). */
function requirementsReviewAppointment(int $appointmentId, bool $lock = false): ?array
{
    $stmt = db()->prepare(
        'SELECT a.appointment_id, a.status_id, a.guest_name, a.guest_email, a.guest_reference, a.requirements_review_status, a.revision_requested_at,
                s.service_name, s.category, u.email AS user_email, u.firstname, u.lastname
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = p.user_id
         WHERE a.appointment_id = ?' . ($lock ? ' FOR UPDATE OF a' : '')
    );
    $stmt->execute([$appointmentId]);
    return $stmt->fetch() ?: null;
}

function requirementsReviewIsGuest(array $appointment): bool
{
    return trim((string) ($appointment['guest_name'] ?? '')) !== '';
}

function requirementsReviewReference(array $appointment): string
{
    return (string) ($appointment['guest_reference'] ?: 'Appointment #' . (int) $appointment['appointment_id']);
}

/**
 * "Send Revision Request": one consolidated email listing every rejected
 * requirement. Marks the appointment Awaiting Revision first.
 *
 * @return array{ok:bool, message:string}
 */
function sendRevisionRequest(int $appointmentId, int $secretaryUserId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $appointment = requirementsReviewAppointment($appointmentId, true);
        if (!$appointment || (int) $appointment['status_id'] !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Revision requests can only be sent while the appointment is pending.'];
        }
        $items = requirementsReviewItems($appointmentId);
        if (!requirementsReviewUnsentRejections($appointment, $items)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'There are no new rejected requirements to send. Reject the items that need correction first.'];
        }
        $pdo->prepare("UPDATE appointments SET requirements_review_status = 'awaiting_revision', revision_requested_at = CURRENT_TIMESTAMP WHERE appointment_id = ?")
            ->execute([$appointmentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Revision request failed for appointment #$appointmentId: " . $e->getMessage());
        return ['ok' => false, 'message' => 'The revision request could not be saved. Please try again.'];
    }
    logActivity($secretaryUserId, "Sent a revision request for appointment #$appointmentId", 'Appointments');

    $isGuest = requirementsReviewIsGuest($appointment);
    $toEmail = trim((string) ($isGuest ? $appointment['guest_email'] : $appointment['user_email']));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'message' => 'Revision request saved (Awaiting Revision), but there is no email address on this booking. Please contact the applicant by phone.'];
    }

    $rejected = array_values(array_filter($items, static fn(array $i): bool => $i['review_status'] === 'rejected'));
    $list = '';
    foreach ($rejected as $item) {
        $list .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e8dfc8;">'
            . '<strong>' . htmlspecialchars($item['kind']) . ':</strong> ' . htmlspecialchars($item['label']) . '<br>'
            . '<strong>Reason:</strong> ' . htmlspecialchars((string) ($item['rejection_reason'] ?: 'Please review and correct this requirement.'))
            . '</td></tr>';
    }
    $greeting = $isGuest ? 'Dear ' . htmlspecialchars((string) $appointment['guest_name']) . ',' : 'Dear Parishioner,';
    $link = $isGuest
        ? guestAccessLink($appointmentId, (string) $appointment['guest_reference'])
        : absoluteUrl('parishioner/appointment-detail.php?id=' . $appointmentId);
    $how = $isGuest
        ? 'Open your booking with the button below (the link is valid for 7 days; afterwards use your reference code and email on the Check Status page). Replace each document marked <strong>Needs Revision</strong>, edit and regenerate any rejected form, then click <strong>Submit Updated Requirements</strong>.'
        : 'Log in to ParishHub and open this appointment. Replace each document marked <strong>Needs Revision</strong>, edit and regenerate any rejected form, then click <strong>Submit Updated Requirements</strong>.';
    $body = "<p>$greeting</p>"
        . '<p>Your submitted requirements for your <strong>' . htmlspecialchars((string) $appointment['service_name']) . '</strong> booking need revision.</p>'
        . '<p><strong>Booking Reference:</strong> ' . htmlspecialchars(requirementsReviewReference($appointment)) . '</p>'
        . '<table width="100%" cellpadding="0" cellspacing="0">' . $list . '</table>'
        . "<p style=\"margin-top:18px;\">$how</p>"
        . '<p>Your booking stays active — no new booking is needed. Approved requirements are kept.</p>'
        . '<p>Thank you,<br>Our Lady of Mt. Carmel Parish</p>';
    $sent = sendRequirementsEmail($toEmail, $isGuest ? (string) $appointment['guest_name'] : trim(($appointment['firstname'] ?? '') . ' ' . ($appointment['lastname'] ?? '')),
        'ParishHub – Requirements Need Revision', emailTemplate('Requirements Need Revision', $body, $link, 'Open My Booking'), $appointmentId, $secretaryUserId);

    return $sent
        ? ['ok' => true, 'message' => 'Revision request sent. The applicant has been emailed and the appointment is now Awaiting Revision.']
        : ['ok' => true, 'message' => 'Revision request saved (Awaiting Revision), but the email could not be sent. Please contact the applicant directly.'];
}

/** Secretary email recipients: SECRETARY_NOTIFY_EMAIL (comma-separated) if set, else every active Secretary account. */
function requirementsReviewSecretaryEmails(): array
{
    $configured = array_filter(array_map('trim', explode(',', (string) getenv('SECRETARY_NOTIFY_EMAIL'))));
    if ($configured) return array_values(array_filter($configured, static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $stmt = db()->query("SELECT DISTINCT u.email FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Secretary' AND u.status = 'active' AND u.email IS NOT NULL AND u.email <> ''");
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}

/**
 * "Submit Updated Requirements" by the parishioner or guest. Every rejected
 * item must have been replaced/regenerated first.
 *
 * @return array{ok:bool, message:string}
 */
function submitUpdatedRequirements(int $appointmentId, ?int $actorUserId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $appointment = requirementsReviewAppointment($appointmentId, true);
        if (!$appointment || (int) $appointment['status_id'] !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Updated requirements can only be submitted while the appointment is pending.'];
        }
        if (($appointment['requirements_review_status'] ?? null) !== 'awaiting_revision') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => ($appointment['requirements_review_status'] ?? null) === 'ready_for_rereview'
                ? 'Your updated requirements were already submitted and are waiting for the Secretary.'
                : 'There is no open revision request for this booking.'];
        }
        $stillRejected = array_values(array_filter(requirementsReviewItems($appointmentId), static fn(array $i): bool => $i['review_status'] === 'rejected'));
        if ($stillRejected) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Please update these first: ' . implode(', ', array_map(static fn($i) => $i['label'], $stillRejected)) . '.'];
        }
        $pdo->prepare("UPDATE appointments SET requirements_review_status = 'ready_for_rereview' WHERE appointment_id = ? AND requirements_review_status = 'awaiting_revision'")
            ->execute([$appointmentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Submitting updated requirements failed for appointment #$appointmentId: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Your updated requirements could not be submitted. Please try again.'];
    }
    logActivity($actorUserId, "Submitted updated requirements for appointment #$appointmentId (Ready for Re-Review)", 'Appointments');

    $body = '<p>Dear Secretary,</p>'
        . '<p>A ' . (requirementsReviewIsGuest($appointment) ? 'Guest' : 'Parishioner') . ' has submitted corrected requirements.</p>'
        . '<p><strong>Service:</strong> ' . htmlspecialchars((string) $appointment['service_name']) . '<br>'
        . '<strong>Booking Reference:</strong> ' . htmlspecialchars(requirementsReviewReference($appointment)) . '<br>'
        . '<strong>Status:</strong> Ready for Re-Review</p>'
        . '<p>Please log in to ParishHub to review the updated documents and forms.</p>';
    $html = emailTemplate('Updated Requirements Ready for Re-Review', $body, absoluteUrl('secretary/appointment-detail.php?id=' . $appointmentId), 'Review Appointment');
    $sentAny = false;
    foreach (requirementsReviewSecretaryEmails() as $email) {
        $sentAny = sendRequirementsEmail($email, 'Parish Secretary', 'ParishHub – Updated Requirements Ready for Re-Review', $html, $appointmentId, $actorUserId) || $sentAny;
    }
    if (!$sentAny) error_log("No Secretary was emailed about re-review of appointment #$appointmentId.");
    return ['ok' => true, 'message' => 'Your updated requirements were submitted and are now Ready for Re-Review. The parish office will review them.'];
}

/**
 * After the Secretary approves/rejects one item: a re-review round is finished
 * once nothing is pending any more (it then reads Approved, or Pending Review
 * with new rejections waiting to be sent in another revision request).
 */
function requirementsReviewAfterSecretaryDecision(int $appointmentId): void
{
    if (!requirementsReviewAvailable()) return;
    try {
        $stmt = db()->prepare('SELECT requirements_review_status FROM appointments WHERE appointment_id = ?');
        $stmt->execute([$appointmentId]);
        if ($stmt->fetchColumn() !== 'ready_for_rereview') return;
        if (requirementsReviewCounts(requirementsReviewItems($appointmentId))['pending'] > 0) return;
        db()->prepare("UPDATE appointments SET requirements_review_status = NULL WHERE appointment_id = ? AND requirements_review_status = 'ready_for_rereview'")
            ->execute([$appointmentId]);
    } catch (Throwable $e) {
        error_log("Re-review status update failed for appointment #$appointmentId: " . $e->getMessage());
    }
}
