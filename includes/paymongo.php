<?php
/**
 * PARISHHUB — PayMongo server-side integration.
 *
 * The secret key is read from the environment (config/config.local.php,
 * gitignored) and is used ONLY in this file, server-side, via cURL — it
 * never reaches the browser. The public key is safe to expose and is
 * handed to the frontend separately where needed.
 *
 * Uses PayMongo's Checkout Sessions API: we create a session server-side
 * and redirect the donor to PayMongo's own hosted payment page (supports
 * GCash, Maya, and card from one flow) — card numbers and e-wallet auth
 * never touch our server at all. This is the provider-required redirect
 * the feature spec explicitly allows ("unless a redirect is required by
 * the actual payment provider").
 */

const PAYMONGO_API_BASE = 'https://api.paymongo.com/v1';

/**
 * Low-level authenticated request to the PayMongo API.
 * @return array{ok: bool, status: int, body: array}
 */
function paymongoRequest(string $method, string $path, ?array $body = null): array
{
    $secretKey = getenv('PAYMONGO_SECRET_KEY');
    if (!$secretKey || str_contains($secretKey, 'xxxxxxxxxxxx')) {
        return ['ok' => false, 'status' => 0, 'body' => ['error' => 'PayMongo secret key is not configured.']];
    }

    $ch = curl_init(PAYMONGO_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $body]]));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'body' => ['error' => $error ?: 'Connection to PayMongo failed.']];
    }

    $decoded = json_decode($response, true) ?? ['raw' => $response];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $decoded];
}

/**
 * Creates a Checkout Session for a given amount, redirecting the donor to
 * PayMongo's hosted payment page. $amount is in PHP pesos (converted to
 * centavos here, as PayMongo's API requires).
 *
 * @return array{ok: bool, session_id: ?string, checkout_url: ?string, error: ?string, raw: array}
 */
function paymongoCreateCheckoutSession(float $amount, string $description, string $successUrl, string $cancelUrl, ?string $donorEmail = null): array
{
    $attributes = [
        'send_email_receipt' => false,
        'show_description' => true,
        'show_line_items' => true,
        'line_items' => [[
            'currency' => 'PHP',
            'amount' => (int) round($amount * 100),
            'name' => $description,
            'quantity' => 1,
        ]],
        'payment_method_types' => ['card', 'gcash', 'paymaya'],
        'description' => $description,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];
    if ($donorEmail) {
        $attributes['billing'] = ['email' => $donorEmail];
    }

    $result = paymongoRequest('POST', '/checkout_sessions', $attributes);

    if (!$result['ok']) {
        $message = $result['body']['errors'][0]['detail'] ?? ($result['body']['error'] ?? 'Could not start the PayMongo checkout.');
        return ['ok' => false, 'session_id' => null, 'checkout_url' => null, 'error' => $message, 'raw' => $result['body']];
    }

    $data = $result['body']['data'] ?? null;
    return [
        'ok' => true,
        'session_id' => $data['id'] ?? null,
        'checkout_url' => $data['attributes']['checkout_url'] ?? null,
        'error' => null,
        'raw' => $result['body'],
    ];
}

/**
 * Retrieves a Checkout Session's current status. PayMongo checkout
 * sessions carry a linked payment_intent whose status is the real source
 * of truth: 'succeeded' (paid), 'awaiting_payment_method'/'processing'
 * (still pending), or the session itself can be 'expired'.
 *
 * @return array{ok: bool, status: ?string, amount: ?float, raw: array}
 */
function paymongoGetCheckoutSession(string $sessionId): array
{
    $result = paymongoRequest('GET', '/checkout_sessions/' . urlencode($sessionId));
    if (!$result['ok']) {
        return ['ok' => false, 'status' => null, 'amount' => null, 'raw' => $result['body']];
    }

    $attrs = $result['body']['data']['attributes'] ?? [];
    $paymentIntent = $attrs['payment_intent'] ?? null;
    $piStatus = is_array($paymentIntent) ? ($paymentIntent['attributes']['status'] ?? null) : null;

    // A checkout session with a paid payment_intent is the success case.
    // If there's no payment_intent yet, the donor hasn't completed checkout.
    $status = $piStatus ?? ($attrs['status'] ?? 'unknown');
    $amount = isset($attrs['line_items'][0]['amount']) ? $attrs['line_items'][0]['amount'] / 100 : null;

    return ['ok' => true, 'status' => $status, 'amount' => $amount, 'raw' => $result['body']];
}

/**
 * Verifies a PayMongo webhook's "Paymongo-Signature" header, formatted as
 * "t=<timestamp>,te=<test-mode-signature>,li=<live-mode-signature>" — the
 * signature is HMAC-SHA256 of "<timestamp>.<raw body>" using the webhook
 * secret. Only needed if a webhook is actually configured in the PayMongo
 * dashboard (see paymongo-webhook.php) — verify this against PayMongo's
 * current documentation before depending on it, as their exact header
 * format may evolve.
 */
function paymongoVerifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
        if ($key !== null) {
            $parts[trim($key)] = trim((string) $value);
        }
    }
    $timestamp = $parts['t'] ?? null;
    $expectedSig = $parts['te'] ?? $parts['li'] ?? null;
    if (!$timestamp || !$expectedSig) {
        return false;
    }
    $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
    return hash_equals($computed, $expectedSig);
}

/** Maps a PayMongo payment_intent status to our payment_status_type enum. */
function paymongoStatusToLocal(string $paymongoStatus): string
{
    return match ($paymongoStatus) {
        'succeeded', 'paid' => 'verified',
        'awaiting_payment_method', 'awaiting_next_action', 'processing' => 'pending',
        'cancelled' => 'cancelled',
        default => 'failed',
    };
}
