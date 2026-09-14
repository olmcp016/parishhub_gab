<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
$identity = requireParishionerOrGuest();

/**
 * The donation form now lives in a modal on My Donations (parishioner/donations.php),
 * submitted via fetch() (hidden "ajax=1" field), same pattern as book.php. GET
 * requests just redirect there — no standalone donate page anymore. Also
 * reachable by a logged-out guest (see requireParishionerOrGuest()) — the
 * existing donor_name/donor_email fields double as the guest's identity,
 * same info a donation would collect anyway.
 */
$isAjax = ($_POST['ajax'] ?? '') === '1';

function donateRespondError(bool $isAjax, string $message): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    flash('error', $message);
    redirect(url('parishioner/donations.php'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('parishioner/donations.php'));
}

verifyCsrf();

$userId = $identity['user_id'];
$parishionerId = $identity['parishioner_id'];
$isGuest = $identity['is_guest'];

$donationSetting = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn();
if ($donationSetting === '0') {
    donateRespondError($isAjax, 'Online donations are currently unavailable. Please check back later.');
}

$stmt = db()->prepare("SELECT service_id FROM services WHERE category = 'Donation' AND is_active = TRUE LIMIT 1");
$stmt->execute();
$donationServiceId = $stmt->fetchColumn();
if (!$donationServiceId) {
    donateRespondError($isAjax, 'Online donations are currently unavailable. Please check back later.');
}

$purposes = ['General Donation', 'Church Maintenance', 'Charity', 'Mass / Parish Activities'];
$manualMethods = [1 => 'Cash', 2 => 'GCash', 3 => 'Maya', 4 => 'Bank Transfer', 6 => 'PayPal'];
const PAYMONGO_METHOD_ID = 7;

$donorName = trim($_POST['donor_name'] ?? '') ?: null;
$donorEmail = trim($_POST['donor_email'] ?? '') ?: null;
$guestReference = $isGuest ? generateGuestReference() : null;
$amount = (float) ($_POST['amount'] ?? 0);
$purpose = in_array($_POST['purpose'] ?? '', $purposes, true) ? $_POST['purpose'] : $purposes[0];
$message = trim($_POST['message'] ?? '') ?: null;
$projectId = (int) ($_POST['project_id'] ?? 0) ?: null;
$payOnline = ($_POST['pay_online'] ?? '') === '1';
$methodId = $payOnline ? PAYMONGO_METHOD_ID : (int) ($_POST['method_id'] ?? 0);

if ($amount <= 0) {
    donateRespondError($isAjax, 'Please enter a valid donation amount.');
}
if (!$payOnline && !isset($manualMethods[$methodId])) {
    donateRespondError($isAjax, 'Please choose a payment method.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Donations are approved and payable immediately — no secretary
    // review, no priest, no scheduled time. appointment_date/time just
    // record when the donation was made.
    $stmt = $pdo->prepare(
        "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, approved_at, guest_name, guest_email, guest_reference)
         VALUES (?, ?, NULL, CURRENT_DATE, CURRENT_TIME, 2, NOW(), ?, ?, ?)"
    );
    $stmt->execute([$parishionerId, $donationServiceId, $isGuest ? $donorName : null, $isGuest ? $donorEmail : null, $guestReference]);
    $appointmentId = $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO donations (appointment_id, donor_name, donor_email, purpose, message, project_id) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$appointmentId, $donorName, $donorEmail, $purpose, $message, $projectId]);

    $stmt = $pdo->prepare(
        "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
         VALUES (?, NULL, ?, ?, 'pending', NOW())"
    );
    $stmt->execute([$appointmentId, $amount, $methodId]);
    $paymentId = $pdo->lastInsertId();

    if ($payOnline) {
        // Real-time PayMongo checkout — the browser gets redirected to
        // PayMongo's own hosted payment page (required by the provider for
        // card/e-wallet entry; card numbers never touch our server). The
        // donor lands back on My Donations, which reconciles the result.
        $successUrl = absoluteUrl('parishioner/donations.php') . '?paymongo_return=1&appointment_id=' . $appointmentId;
        $cancelUrl = absoluteUrl('parishioner/donations.php') . '?paymongo_cancelled=1&appointment_id=' . $appointmentId;
        $checkout = paymongoCreateCheckoutSession($amount, 'Donation — ' . $purpose, $successUrl, $cancelUrl, $donorEmail);

        if (!$checkout['ok']) {
            $pdo->rollBack();
            error_log('PayMongo checkout session creation failed: ' . json_encode($checkout['raw']));
            donateRespondError($isAjax, 'Could not start the online payment right now (' . $checkout['error'] . '). Please try again or choose "Pay Later" instead.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO transactions (payment_id, gateway, gateway_transaction_id, status, raw_response) VALUES (?, 'paymongo', ?, 'pending', ?)"
        );
        $stmt->execute([$paymentId, $checkout['session_id'], json_encode($checkout['raw'])]);

        $pdo->commit();
        logActivity($userId, "Started an online donation (#$appointmentId) via PayMongo" . ($isGuest ? ' (guest)' : ''), 'Donations');

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => $checkout['checkout_url']]);
            exit;
        }
        redirect($checkout['checkout_url']);
    }

    if (!$isGuest) {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Thank You for Your Donation', ?)"
        );
        $stmt->execute([$userId, "Thank you for your generous donation (#$appointmentId). It will be verified by our cashier shortly."]);
    }

    $pdo->commit();
    logActivity($userId, "Submitted a donation (#$appointmentId)" . ($isGuest ? ' (guest)' : ''), 'Donations');

    $successMessage = 'Thank you for your donation! It will be verified by our cashier shortly.';
    if ($isGuest) {
        $successMessage .= " Your reference code is $guestReference — save it to check your donation's status anytime.";
    }
    $detailUrl = $isGuest ? url('status.php?ref=' . urlencode($guestReference)) : url('parishioner/appointment-detail.php?id=' . $appointmentId);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $successMessage,
            'detail_url' => $detailUrl,
            'guest_reference' => $guestReference,
        ]);
        exit;
    }
    flash('success', $successMessage);
    redirect($detailUrl);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    donateRespondError($isAjax, 'Failed to submit your donation. Please try again.');
}
