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

/*
 * Parishioner notification types. The notifications table has no type column,
 * so the title is the type: create and filter only with these constants.
 */
const NOTIFY_APPOINTMENT_APPROVED = 'Appointment Approved';      // payment required
const NOTIFY_APPOINTMENT_CONFIRMED = 'Appointment Confirmed';
const NOTIFY_NEEDS_REVISION = 'Needs Revision';
const NOTIFY_NEEDS_REVISION_OLD = 'Requirement Needs Revision'; // earlier title, still shown
const NOTIFY_APPROVED_NO_PAYMENT = 'Appointment Approved (No Payment Due)'; // ₱0 Wedding held for Pre-Cana: hidden
const NOTIFY_PAYMENT_VERIFIED = 'Payment Verified';
const NOTIFY_PAYMENT_CONFIRMED = 'Payment Confirmed';
const NOTIFY_MASS_INTENTION_SUBMITTED = 'Mass Intention Submitted';
/** Routine Parishioner notifications: never shown in the bell, its count or View All (old rows stay in the table). */
const PARISHIONER_HIDDEN_NOTIFICATIONS = ['Appointment Submitted', 'Documents Updated', 'Appointment Completed', 'Pre-Cana Schedule Confirmed', NOTIFY_APPROVED_NO_PAYMENT];

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
            if ($appointmentId !== null) {
                $dupCheck = db()->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND title = ? AND message LIKE ? AND is_read = FALSE LIMIT 1");
                $dupCheck->execute([(int) $secretaryId, $title, "%#$appointmentId%"]);
                if ($dupCheck->fetchColumn()) continue;
            }
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

/**
 * The Parishioner's notifications that are shown, newest first — the one filter
 * behind the bell, its unread count (topbar + feed) and View All, so all three
 * always agree. Hidden rows are never deleted, only left out:
 *   - routine types (PARISHIONER_HIDDEN_NOTIFICATIONS);
 *   - Payment Verified for anything but a Donation (its "Ref:" matched to this
 *     parishioner's own payments; a row that cannot be matched stays shown);
 *   - a Mass Intention's Submitted / Payment Confirmed once it has its own
 *     Appointment Confirmed notification (that one says it all).
 * Each row gets 'link_appointment_id': the appointment it is about, if this
 * parishioner owns it (null otherwise).
 */
function parishionerVisibleNotifications(int $userId): array
{
    $link = notificationsHaveAppointmentLink();
    $stmt = db()->prepare('SELECT notification_id, title, message, is_read, created_at' . ($link ? ', appointment_id' : '') . ' FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return [];

    $own = db()->prepare(
        'SELECT a.appointment_id, s.category, pay.reference_number
         FROM appointments a
         JOIN parishioners p ON p.parishioner_id = a.parishioner_id
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN payments pay ON pay.appointment_id = a.appointment_id
         WHERE p.user_id = ?'
    );
    $own->execute([$userId]);
    $categoryOf = [];
    $categoryByReference = [];
    foreach ($own->fetchAll() as $o) {
        $categoryOf[(int) $o['appointment_id']] = (string) $o['category'];
        if (trim((string) $o['reference_number']) !== '') $categoryByReference[trim((string) $o['reference_number'])][] = (string) $o['category'];
    }

    $confirmed = [];
    foreach ($rows as &$n) {
        $appointmentId = $link && !empty($n['appointment_id']) ? (int) $n['appointment_id'] : notificationAppointmentFromText((string) $n['message']);
        $n['link_appointment_id'] = $appointmentId && isset($categoryOf[$appointmentId]) ? $appointmentId : null;
        // For sorting out duplicates only (never a link): "Your Mass Intention (#12) …".
        $n['about_appointment_id'] = $n['link_appointment_id']
            ?? (preg_match('/\(#(\d+)\)/', (string) $n['message'], $m) && isset($categoryOf[(int) $m[1]]) ? (int) $m[1] : null);
        if ($n['title'] === NOTIFY_APPOINTMENT_CONFIRMED && $n['about_appointment_id']) $confirmed[$n['about_appointment_id']] = true;
    }
    unset($n);

    return array_values(array_filter($rows, static function (array $n) use ($categoryOf, $categoryByReference, $confirmed): bool {
        $title = (string) $n['title'];
        if (in_array($title, PARISHIONER_HIDDEN_NOTIFICATIONS, true)) return false;
        $about = $n['about_appointment_id'];
        if ($title === NOTIFY_PAYMENT_VERIFIED) {
            $categories = $about ? [$categoryOf[$about]] : (preg_match('/\(Ref: ([^)]+)\)/', (string) $n['message'], $m) ? ($categoryByReference[trim($m[1])] ?? []) : []);
            return !$categories || in_array('Donation', $categories, true);
        }
        if ($title === NOTIFY_MASS_INTENTION_SUBMITTED || $title === NOTIFY_PAYMENT_CONFIRMED) {
            return !($about && ($categoryOf[$about] ?? '') === 'Mass Intention' && isset($confirmed[$about]));
        }
        return true;
    }));
}

/** Unread count of the notifications parishionerVisibleNotifications() shows. */
function parishionerUnreadNotificationCount(array $visible): int
{
    return count(array_filter($visible, static fn(array $n): bool => !notificationIsRead($n['is_read'])));
}

function notificationIsRead($value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

/**
 * Unread count + latest notifications for a Parishioner's bell (same shape as
 * secretaryNotificationFeed()). Only this user's rows, filtered by
 * parishionerVisibleNotifications() before the limit. An appointment
 * notification links to the parishioner's own appointment page (which marks it
 * read once the appointment has loaded); anything else — or an appointment this
 * parishioner does not own — links to the full Notifications page. Never to a
 * Secretary or Admin page.
 */
function parishionerNotificationFeed(int $userId, int $limit = 10): array
{
    $visible = parishionerVisibleNotifications($userId);
    $items = [];
    foreach (array_slice($visible, 0, $limit) as $n) {
        $id = (int) $n['notification_id'];
        $items[] = [
            'id' => $id,
            'title' => (string) $n['title'],
            'message' => (string) $n['message'],
            'time' => formatDateTime($n['created_at']),
            'is_read' => notificationIsRead($n['is_read']),
            'url' => $n['link_appointment_id']
                ? url('parishioner/appointment-detail.php?id=' . $n['link_appointment_id'] . '&notification=' . $id)
                : url('parishioner/notifications.php'),
        ];
    }
    return ['unread' => parishionerUnreadNotificationCount($visible), 'items' => $items];
}

/**
 * "Needs Revision" for a registered parishioner: one notification per open
 * request, not one per rejected item. An unread one for this appointment is
 * refreshed (new text, moved to the top) instead of adding another; with
 * $sameRequest (the earlier request is still unresolved) the latest one is
 * refreshed even if already read. Otherwise — a new revision cycle — a new row.
 * Never throws.
 */
function notifyParishionerNeedsRevision(int $userId, int $appointmentId, string $message, bool $sameRequest = false): void
{
    try {
        $link = notificationsHaveAppointmentLink();
        $stmt = db()->prepare('SELECT notification_id, message, is_read' . ($link ? ', appointment_id' : '') . ' FROM notifications WHERE user_id = ? AND title IN (?, ?) ORDER BY notification_id DESC');
        $stmt->execute([$userId, NOTIFY_NEEDS_REVISION, NOTIFY_NEEDS_REVISION_OLD]);
        $existingId = null;
        foreach ($stmt->fetchAll() as $n) {
            $about = $link && !empty($n['appointment_id']) ? (int) $n['appointment_id'] : notificationAppointmentFromText((string) $n['message']);
            if ($about !== $appointmentId) continue;
            if ($sameRequest || !notificationIsRead($n['is_read'])) $existingId = (int) $n['notification_id'];
            break; // only the latest one for this appointment counts
        }
        if ($existingId) {
            db()->prepare($link
                ? 'UPDATE notifications SET title = ?, message = ?, is_read = FALSE, created_at = CURRENT_TIMESTAMP, appointment_id = ? WHERE notification_id = ?'
                : 'UPDATE notifications SET title = ?, message = ?, is_read = FALSE, created_at = CURRENT_TIMESTAMP WHERE notification_id = ?')
                ->execute($link ? [NOTIFY_NEEDS_REVISION, $message, $appointmentId, $existingId] : [NOTIFY_NEEDS_REVISION, $message, $existingId]);
        } else {
            db()->prepare($link
                ? "INSERT INTO notifications (user_id, type, category, title, message, appointment_id) VALUES (?, 'website', 'appointment', ?, ?, ?)"
                : "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
                ->execute($link ? [$userId, NOTIFY_NEEDS_REVISION, $message, $appointmentId] : [$userId, NOTIFY_NEEDS_REVISION, $message]);
        }
    } catch (Throwable $e) {
        error_log("Needs Revision notification for appointment #$appointmentId failed: " . $e->getMessage());
    }
}

/** Marks one of this user's own notifications read (a forged id for anyone else's matches nothing). */
function markOwnNotificationRead(int $notificationId, int $userId): void
{
    try {
        db()->prepare('UPDATE notifications SET is_read = TRUE WHERE notification_id = ? AND user_id = ?')->execute([$notificationId, $userId]);
    } catch (Throwable $e) {
        error_log('markOwnNotificationRead: ' . $e->getMessage());
    }
}
