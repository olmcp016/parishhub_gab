<?php
/**
 * PARISHHUB — logged, de-duplicated sending of booking emails.
 *
 * Used for the emails sent to guests / parishioners (the Secretary gets
 * website notifications instead, see includes/secretary-notifications.php).
 * sendLoggedEmail() records every intended email in email_logs
 * (database/migration_notifications_v2.sql) under a unique dedupe key:
 *   - already sent with that key  -> not sent again (no duplicates)
 *   - earlier attempt failed      -> tried again, same row updated
 * Each send retries once on a temporary failure (network, Brevo 429/5xx).
 * "sent" means Brevo accepted the message — nothing less.
 *
 * Without the email_logs table it still sends (and logs to activity_logs),
 * it just cannot de-duplicate across requests.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

/** True when Brevo is configured on this server (values are never exposed). */
function emailDeliveryConfigured(): bool
{
    $key = (string) getenv('BREVO_API_KEY');
    return $key !== '' && !str_contains($key, 'xxxxxxxxxxxx') && (string) getenv('BREVO_SENDER_EMAIL') !== '';
}

function emailLogAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $available = (bool) db()->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'email_logs'")->fetchColumn();
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/** a***@example.com — for logs and screens. */
function maskEmail(string $email): string
{
    return (string) preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email);
}

/** One delivery attempt, retrying once on a temporary failure. @return array{ok:bool,error:?string} */
function deliverEmailOnce(string $toEmail, ?string $toName, string $subject, string $html): array
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
    return ['ok' => (bool) $result['ok'], 'error' => $result['ok'] ? null : (string) ($result['error'] ?? 'unknown error')];
}

/**
 * @return string 'sent' | 'failed' | 'duplicate'
 */
function sendLoggedEmail(string $type, string $dedupeKey, ?int $appointmentId, ?string $reference, string $toEmail, ?string $toName, string $subject, string $html, ?int $actorUserId = null): string
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        logActivity($actorUserId, "Email FAILED ($type) for appointment #$appointmentId: invalid recipient address", 'Email');
        return 'failed';
    }

    $logId = null;
    if (emailLogAvailable()) {
        try {
            $existing = db()->prepare('SELECT email_log_id, status FROM email_logs WHERE dedupe_key = ?');
            $existing->execute([$dedupeKey]);
            $row = $existing->fetch();
            if ($row && $row['status'] === 'sent') return 'duplicate';
            if ($row) {
                $logId = (int) $row['email_log_id'];
            } else {
                $insert = db()->prepare(
                    "INSERT INTO email_logs (dedupe_key, notification_type, appointment_id, booking_reference, recipient, subject, body_html, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending') ON CONFLICT (dedupe_key) DO NOTHING RETURNING email_log_id"
                );
                $insert->execute([$dedupeKey, $type, $appointmentId, $reference, $toEmail, $subject, $html]);
                $logId = (int) ($insert->fetchColumn() ?: 0);
                if (!$logId) return 'duplicate'; // another request claimed this exact email first
            }
            // Claim the row so two concurrent requests cannot both send it.
            $claim = db()->prepare("UPDATE email_logs SET attempts = attempts + 1, last_attempt_at = CURRENT_TIMESTAMP WHERE email_log_id = ? AND status <> 'sent'");
            $claim->execute([$logId]);
            if ($claim->rowCount() !== 1) return 'duplicate';
        } catch (Throwable $e) {
            error_log('email_logs unavailable, sending without de-duplication: ' . $e->getMessage());
            $logId = null;
        }
    }

    $result = deliverEmailOnce($toEmail, $toName, $subject, $html);
    if ($logId) {
        try {
            db()->prepare($result['ok']
                ? "UPDATE email_logs SET status = 'sent', sent_at = CURRENT_TIMESTAMP, failure_reason = NULL WHERE email_log_id = ?"
                : "UPDATE email_logs SET status = 'failed', failure_reason = ? WHERE email_log_id = ?")
                ->execute($result['ok'] ? [$logId] : [mb_substr((string) $result['error'], 0, 1000), $logId]);
        } catch (Throwable $e) {
            error_log('email_logs update failed: ' . $e->getMessage());
        }
    }
    logActivity($actorUserId, ($result['ok'] ? 'Email sent' : 'Email FAILED') . " ($type) for appointment #$appointmentId to " . maskEmail($toEmail) . ($result['ok'] ? '' : ' — ' . $result['error']), 'Email');
    return $result['ok'] ? 'sent' : 'failed';
}

/** Re-sends one failed logged email exactly as it was composed. @return string sent|failed|duplicate */
function retryLoggedEmail(int $emailLogId, ?int $actorUserId): string
{
    $stmt = db()->prepare('SELECT * FROM email_logs WHERE email_log_id = ?');
    $stmt->execute([$emailLogId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] === 'sent') return 'duplicate';
    return sendLoggedEmail($row['notification_type'], $row['dedupe_key'], $row['appointment_id'] !== null ? (int) $row['appointment_id'] : null,
        $row['booking_reference'], $row['recipient'], null, $row['subject'], $row['body_html'], $actorUserId);
}

/** Logged emails for one appointment, newest first. */
function emailLogsForAppointment(int $appointmentId): array
{
    if (!emailLogAvailable()) return [];
    $stmt = db()->prepare('SELECT email_log_id, notification_type, recipient, status, attempts, failure_reason, last_attempt_at, sent_at FROM email_logs WHERE appointment_id = ? ORDER BY email_log_id DESC');
    $stmt->execute([$appointmentId]);
    return $stmt->fetchAll();
}
