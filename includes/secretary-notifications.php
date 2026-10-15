<?php
/**
 * PARISHHUB — website notifications for the Secretary (no email).
 *
 * Stored in the existing `notifications` table, one row per active Secretary
 * account. notifications.appointment_id (database/migration_notifications_v2.sql)
 * lets the notification bell (includes/topbar.php, secretary/notifications-feed.php)
 * open the appointment; without that column the rows are still created and
 * open the appointments list instead.
 */
require_once __DIR__ . '/functions.php';

function notificationsHaveAppointmentLink(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $available = (bool) db()->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'notifications' AND column_name = 'appointment_id'")->fetchColumn();
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/** Creates one website notification per active Secretary. Never throws. @return int rows created */
function notifySecretaries(string $title, string $message, ?int $appointmentId): int
{
    try {
        $secretaries = db()->query(
            "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'Secretary' AND u.status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $withLink = $appointmentId !== null && notificationsHaveAppointmentLink();
        $insert = db()->prepare($withLink
            ? "INSERT INTO notifications (user_id, type, category, title, message, appointment_id) VALUES (?, 'website', 'appointment', ?, ?, ?)"
            : "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)");
        foreach ($secretaries as $secretaryId) {
            $insert->execute($withLink ? [(int) $secretaryId, $title, $message, $appointmentId] : [(int) $secretaryId, $title, $message]);
        }
        return count($secretaries);
    } catch (Throwable $e) {
        error_log("Secretary notification \"$title\" failed: " . $e->getMessage());
        return 0;
    }
}

/** Booking details used in Secretary notification messages. */
function secretaryNotificationBooking(int $appointmentId): ?array
{
    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.guest_name, a.guest_reference, s.service_name, s.category, u.firstname, u.lastname
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = p.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $isGuest = trim((string) ($row['guest_name'] ?? '')) !== '';
    return [
        'reference' => (string) ($row['guest_reference'] ?: 'Appointment #' . $appointmentId),
        'service' => (string) $row['service_name'],
        'category' => (string) $row['category'],
        'who' => $isGuest ? 'Guest' : 'Parishioner',
        'name' => $isGuest ? (string) $row['guest_name'] : trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
    ];
}

/**
 * "New Booking Received" — call once, after the booking is committed.
 * Mass Intentions and Donations are the Cashier's and are skipped.
 */
function notifySecretariesNewBooking(int $appointmentId): void
{
    try {
        $b = secretaryNotificationBooking($appointmentId);
        if (!$b || in_array($b['category'], ['Mass Intention', 'Donation'], true)) return;
        notifySecretaries(
            'New Booking Received',
            "A {$b['who']} ({$b['name']}) has submitted a {$b['service']} booking. Reference: {$b['reference']}.",
            $appointmentId
        );
    } catch (Throwable $e) {
        error_log("New booking notification for appointment #$appointmentId failed: " . $e->getMessage());
    }
}

/** "Updated Requirements Submitted" — after the applicant submits corrected requirements. */
function notifySecretariesUpdatedRequirements(int $appointmentId): void
{
    try {
        $b = secretaryNotificationBooking($appointmentId);
        if (!$b) return;
        notifySecretaries(
            'Updated Requirements Submitted',
            "Booking {$b['reference']} ({$b['service']}, {$b['who']}: {$b['name']}) has submitted corrected requirements and is ready for re-review.",
            $appointmentId
        );
    } catch (Throwable $e) {
        error_log("Updated requirements notification for appointment #$appointmentId failed: " . $e->getMessage());
    }
}

/** Unread count + latest notifications for the bell. */
function secretaryNotificationFeed(int $userId, int $limit = 10): array
{
    $count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE');
    $count->execute([$userId]);
    $link = notificationsHaveAppointmentLink();
    $stmt = db()->prepare('SELECT notification_id, title, message, is_read, created_at' . ($link ? ', appointment_id' : '') . ' FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = [];
    foreach ($stmt->fetchAll() as $n) {
        $appointmentId = $link && !empty($n['appointment_id']) ? (int) $n['appointment_id'] : notificationAppointmentFromText((string) $n['message']);
        $id = (int) $n['notification_id'];
        $items[] = [
            'id' => $id,
            'title' => (string) $n['title'],
            'message' => (string) $n['message'],
            'time' => formatDateTime($n['created_at']),
            'is_read' => in_array($n['is_read'], [true, 1, '1', 't', 'true'], true),
            // The appointment page marks the notification read once it has loaded (?notification=).
            'url' => $appointmentId
                ? url('secretary/appointment-detail.php?id=' . $appointmentId . '&notification=' . $id) . '#requirements-review'
                : url('secretary/appointments.php'),
        ];
    }
    return ['unread' => (int) $count->fetchColumn(), 'items' => $items];
}

/**
 * Appointment a notification is about, from its text, for rows without
 * notifications.appointment_id (written before migration_notifications_v2.sql):
 * "Reference: PH-…" / "Booking PH-…", or "appointment #123".
 */
function notificationAppointmentFromText(string $message): ?int
{
    static $byReference = [];
    if (preg_match('/\b(?:Reference:|Booking)\s+([A-Z]{2,}-[A-Z0-9]+)/', $message, $m)) {
        $reference = $m[1];
        if (!array_key_exists($reference, $byReference)) {
            $stmt = db()->prepare('SELECT appointment_id FROM appointments WHERE guest_reference = ?');
            $stmt->execute([$reference]);
            $byReference[$reference] = ($found = $stmt->fetchColumn()) ? (int) $found : null;
        }
        if ($byReference[$reference]) return $byReference[$reference];
    }
    if (preg_match('/\bappointment\s+#(\d+)/i', $message, $m)) return (int) $m[1];
    return null;
}

/** Marks one of this Secretary's notifications read — called by the appointment page after it loaded. */
function markSecretaryNotificationRead(int $notificationId, int $userId): void
{
    try {
        db()->prepare('UPDATE notifications SET is_read = TRUE WHERE notification_id = ? AND user_id = ?')->execute([$notificationId, $userId]);
    } catch (Throwable $e) {
        error_log('markSecretaryNotificationRead: ' . $e->getMessage());
    }
}
