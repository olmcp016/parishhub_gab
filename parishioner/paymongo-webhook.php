<?php
/**
 * PARISHHUB — PayMongo webhook receiver.
 *
 * Public, no login (PayMongo calls this directly, once a webhook is set up in
 * the PayMongo dashboard under Developers > Webhooks, pointing here). Every
 * event must carry a valid signature for the site's configured mode, and its
 * livemode flag must match that mode; nothing unsigned is ever accepted
 * (audit B-07 W1/W2). Without a usable webhook secret and secret key the
 * endpoint refuses everything with 503 before touching any payment, so PayMongo
 * retries once it is configured. A webhook is a reliability improvement
 * (catches the case where a payer's browser never makes it back), not a hard
 * requirement: the return pages and the Treasurer's re-check reconcile
 * payments themselves.
 *
 * The event body is never trusted for a payment's outcome: the checkout
 * session is read back from PayMongo with the secret key and applied through
 * paymongoApplyCheckout() (audit B-07). That makes duplicate and
 * out-of-order deliveries harmless. Responses: 400 for a bad signature or an
 * event from the other mode; 2xx once an event is handled or can never be
 * handled (so PayMongo stops); 503/500 when the webhook is not configured or
 * PayMongo or the database failed for now, so PayMongo retries (it gives up
 * after 12 attempts).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';

header('Content-Type: application/json');

$respond = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

$mode = paymongoKeyMode();
$webhookSecret = paymongoWebhookSecret();
if ($mode === null || $webhookSecret === null) {
    error_log('PayMongo webhook refused: PAYMONGO_WEBHOOK_SECRET or PAYMONGO_SECRET_KEY is missing or a placeholder');
    $respond(503, ['error' => 'Webhook is not configured']);
}

$rawBody = (string) file_get_contents('php://input');
if (!paymongoVerifyWebhookSignature($rawBody, (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ''), $webhookSecret, $mode)) {
    $respond(400, ['error' => 'Invalid signature']);
}

$event = json_decode($rawBody, true);
if (!paymongoWebhookEventModeMatches($event, $mode)) {
    $respond(400, ['error' => 'Event mode does not match this site']);
}
$eventType = is_array($event) ? (string) ($event['data']['attributes']['type'] ?? '') : '';
$eventData = is_array($event) ? ($event['data']['attributes']['data'] ?? null) : null;

// Only a paid checkout session matters here (the checkout session id is what
// transactions.gateway_transaction_id holds). Anything else is acknowledged.
$sessionId = $eventType === 'checkout_session.payment.paid' && is_array($eventData) ? (string) ($eventData['id'] ?? '') : '';
if ($sessionId === '' || !preg_match('/^cs_[A-Za-z0-9]+$/', $sessionId)) {
    $respond(200, ['received' => true, 'ignored' => true]);
}

try {
    $session = paymongoGetCheckoutSession($sessionId);
    if (!$session['ok']) {
        $respond(503, ['error' => 'Could not confirm the checkout with PayMongo yet']);
    }

    $stmt = db()->prepare(
        "SELECT p.payment_id FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id
         WHERE t.gateway = 'paymongo' AND t.gateway_transaction_id = ? LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $paymentId = (int) $stmt->fetchColumn();

    if (!$paymentId) {
        // Backup match: the session's own metadata, as read back from PayMongo
        // (never the event body), for a payment whose session id was never
        // stored because its request stopped before PayMongo answered.
        $metaPayment = (int) ($session['metadata']['payment_id'] ?? 0);
        $metaAppointment = (int) ($session['metadata']['appointment_id'] ?? 0);
        if ($metaPayment > 0) {
            $attach = db()->prepare(
                "UPDATE transactions t SET gateway_transaction_id = ?
                 FROM payments p
                 WHERE t.payment_id = p.payment_id AND t.gateway = 'paymongo' AND t.gateway_transaction_id IS NULL
                   AND p.payment_id = ? AND p.appointment_id = ? AND p.method_id = 7"
            );
            $attach->execute([$sessionId, $metaPayment, $metaAppointment]);
            if ($attach->rowCount() === 1) $paymentId = $metaPayment;
        }
    }

    if (!$paymentId) {
        if ($session['state'] === 'paid') {
            // Money PayMongo took that matches no payment here: keep enough to reconcile by hand.
            error_log('PayMongo webhook: paid checkout ' . $sessionId . ' (PHP ' . number_format((float) $session['amount'], 2) . ') matches no ParishHub payment');
            logActivity(null, mb_substr('Paid PayMongo checkout ' . $sessionId . ' (PHP ' . number_format((float) $session['amount'], 2) . ') matches no ParishHub payment — needs reconciliation', 0, 150), 'Payments');
        }
        $respond(200, ['received' => true, 'matched' => false]);
    }

    $outcome = paymongoApplyCheckout($paymentId, $session);
    if ($outcome === 'error') {
        $respond(503, ['error' => 'Payment could not be processed yet']);
    }
    $respond(200, ['received' => true, 'outcome' => $outcome]);
} catch (Throwable $e) {
    error_log('PayMongo webhook processing failed (' . get_class($e) . ' ' . $e->getCode() . ')');
    $respond(500, ['error' => 'Processing failed']);
}
