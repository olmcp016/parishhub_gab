<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/funeral-draft.php';

header('Content-Type: application/json');

$action = (string) ($_GET['action'] ?? 'seed');
if ($action === 'cleanup') {
    foreach ($_SESSION['funeral_booking_drafts'] ?? [] as $draft) {
        if (is_array($draft)) funeralDraftDeleteAbandonedArtifacts($draft);
    }
    unset($_SESSION['funeral_booking_drafts'], $_SESSION['funeral_draft_tokens'], $_SESSION['user']);
    echo json_encode(['cleaned' => true]);
    exit;
}

$mode = (string) ($_GET['mode'] ?? 'guest');
$service = db()->query("SELECT service_id FROM services WHERE category = 'Funeral' ORDER BY service_id LIMIT 1")->fetch();
if (!$service) throw new RuntimeException('Funeral service is unavailable.');

$isGuest = $mode !== 'parishioner';
$parishionerId = null;
if ($isGuest) {
    unset($_SESSION['user']);
} else {
    $owner = db()->query("SELECT u.user_id, u.firstname, u.lastname, u.email, u.role_id, r.role_name, p.parishioner_id FROM users u JOIN roles r ON r.role_id = u.role_id JOIN parishioners p ON p.user_id = u.user_id WHERE r.role_name = 'Parishioner' ORDER BY u.user_id LIMIT 1")->fetch();
    if (!$owner) throw new RuntimeException('Parishioner test owner is unavailable.');
    $parishionerId = (int) $owner['parishioner_id'];
    $_SESSION['user'] = [
        'user_id' => (int) $owner['user_id'],
        'firstname' => (string) $owner['firstname'],
        'lastname' => (string) $owner['lastname'],
        'email' => (string) $owner['email'],
        'role_id' => (int) $owner['role_id'],
        'role_name' => (string) $owner['role_name'],
    ];
}

$draftId = time() . random_int(1000, 9999);
$rawToken = $isGuest ? bin2hex(random_bytes(32)) : null;
$draft = [
    'id' => $draftId,
    'expires_at' => time() + 3600,
    'guest_token' => $rawToken ? hash('sha256', $rawToken) : null,
    'is_guest' => $isGuest,
    'parishioner_id' => $parishionerId,
    'guest_name' => $isGuest ? 'Runtime Test Guest' : null,
    'guest_email' => $isGuest ? 'runtime-test@example.test' : null,
    'guest_phone' => $isGuest ? '09170000000' : null,
    'guest_reference' => $isGuest ? generateGuestReference() : null,
    'service_id' => (int) $service['service_id'],
    'priest_id' => null,
    'date' => null,
    'finalTime' => null,
    'pssClaim' => 'non_pss',
    'pssClassification' => 'pending_verification',
    'remarks' => 'Automated Funeral draft runtime test',
    'contactPhone' => '09170000000',
    'locationAddress' => 'Runtime test address',
    'dateOfDeath' => '2026-10-01',
    'requirementsSnapshot' => json_encode(['Death Certificate', 'Katin-awan sa Paglubong']),
    'uploaded_keys' => [],
    'katin_awan_payload' => null,
    'generated_document' => null,
];

$_SESSION['funeral_booking_drafts'][$draftId] = $draft;
if ($rawToken) $_SESSION['funeral_draft_tokens'][$draftId] = $rawToken;

echo json_encode(['draft_id' => $draftId, 'csrf_token' => csrfToken(), 'mode' => $mode]);
