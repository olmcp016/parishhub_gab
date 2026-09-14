<?php
/**
 * PARISHHUB — PayMongo webhook receiver.
 *
 * Public, no login (PayMongo calls this directly). If a webhook secret is
 * configured (Settings > Developers > Webhooks in the PayMongo dashboard,
 * pointing here), the signature is verified before anything is trusted.
 * If no webhook is configured, this endpoint simply sits unused — the
 * donation flow already reconciles payment status itself when the donor
 * returns to My Donations, so a webhook is a reliability improvement
 * (catches the case where a donor's browser never makes it back), not a
 * hard requirement for the feature to work.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$webhookSecret = getenv('PAYMONGO_WEBHOOK_SECRET');

if ($webhookSecret && !str_contains($webhookSecret, 'xxxxxxxxxxxx')) {
    $signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
    if (!paymongoVerifyWebhookSignature($rawBody, $signatureHeader, $webhookSecret)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($rawBody, true);
$eventType = $event['data']['attributes']['type'] ?? '';
$eventData = $event['data']['attributes']['data'] ?? null;

// The checkout session id is what we stored in transactions.gateway_transaction_id.
$sessionId = null;
if ($eventType === 'checkout_session.payment.paid') {
    $sessionId = $eventData['id'] ?? null;
} elseif (str_starts_with($eventType, 'payment_intent.') || str_starts_with($eventType, 'payment.')) {
    // Payment intent events reference the session indirectly via metadata
    // in some PayMongo API versions — fall back to looking it up by the
    // payment_intent's own id if that's what we stored, otherwise skip.
    $sessionId = $eventData['attributes']['payment_intent_id'] ?? ($eventData['id'] ?? null);
}

if ($sessionId) {
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id
         WHERE t.gateway = 'paymongo' AND t.gateway_transaction_id = ? LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $paymentRow = $stmt->fetch();

    if ($paymentRow && $paymentRow['payment_status'] === 'pending') {
        $session = paymongoGetCheckoutSession($sessionId);
        if ($session['ok']) {
            $localStatus = paymongoStatusToLocal($session['status']);
            db()->prepare("UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ?")
                ->execute([$session['status'], json_encode($session['raw']), $paymentRow['payment_id']]);

            if ($localStatus === 'verified') {
                $result = verifyPaymentAndIssueReceipt((int) $paymentRow['payment_id'], null, $sessionId);
                if (!$result['ok']) {
                    error_log("PayMongo webhook: payment {$paymentRow['payment_id']} confirmed but verifyPaymentAndIssueReceipt failed: {$result['message']}");
                }
            } elseif (in_array($localStatus, ['failed', 'cancelled'], true)) {
                markPaymentUnsuccessful((int) $paymentRow['payment_id'], $localStatus);
            }
        }
    }
}

echo json_encode(['received' => true]);
