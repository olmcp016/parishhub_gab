<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/requirements-review.php';

/**
 * "Submit Updated Requirements" for a GUEST booking (status.php). Same rule as
 * the guest document replacement (document-upload.php): only the booking this
 * browser verified on the status page — by reference + email/phone, or by the
 * signed link from the revision email — within the last 15 minutes.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('status.php'));
verifyCsrf();

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$guest = $_SESSION['guest_status_verification'] ?? null;
$verified = is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();

$stmt = db()->prepare('SELECT guest_reference FROM appointments WHERE appointment_id = ? AND guest_name IS NOT NULL');
$stmt->execute([$appointmentId]);
$reference = (string) ($stmt->fetchColumn() ?: '');

if (!$verified || $reference === '' || !requirementsReviewAvailable()) {
    flash('error', 'Please look up your booking again (reference code and the email or phone you used), then submit your updated requirements.');
    redirect(url('status.php'));
}

$result = submitUpdatedRequirements($appointmentId, null);
flash($result['ok'] ? 'success' : 'error', $result['message']);
// Back to this booking without asking for the contact again: a fresh signed link.
redirect(guestAccessLink($appointmentId, $reference));
