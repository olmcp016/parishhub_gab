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
 *
 * Payment safety (audit B-07): a checkout session never expires on its own,
 * so ParishHub never treats one as dead until PayMongo confirms it cannot be
 * paid, never opens a second checkout while an earlier one may still take
 * money, and never ignores a payment PayMongo reports as paid.
 */

const PAYMONGO_API_BASE = 'https://api.paymongo.com/v1';

/**
 * Optional time limit (Unix time) for PayMongo calls in this request, so a
 * best-effort step such as paymongoCloseOtherCheckouts() cannot hold up a
 * webhook reply. Pass a time to set it, true to clear it; returns the current one.
 */
function paymongoDeadline(float|bool|null $set = null): ?float
{
    static $deadline = null;
    if ($set === true) $deadline = null;
    elseif (is_float($set)) $deadline = $set;
    return $deadline;
}

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
    $timeout = 20;
    if (($deadline = paymongoDeadline()) !== null) {
        $left = $deadline - microtime(true);
        if ($left < 1) {
            return ['ok' => false, 'status' => 0, 'body' => ['error' => 'Time allowed for PayMongo calls has run out.']];
        }
        $timeout = (int) min(20, ceil($left));
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
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
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
 * centavos here, as PayMongo's API requires). $metadata (string values only,
 * e.g. payment_id / appointment_id) comes back with the session and is only
 * ever a backup way to find the payment — never proof of payment.
 *
 * @return array{ok: bool, session_id: ?string, checkout_url: ?string, error: ?string, raw: array}
 */
function paymongoCreateCheckoutSession(float $amount, string $description, string $successUrl, string $cancelUrl, ?string $donorEmail = null, array $metadata = []): array
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
        // Shown on the payer's bank/card statement line (PayMongo requires
        // 5–22 characters). This does NOT change the "Justine Abella" name
        // shown on PayMongo's own checkout page — that comes from the
        // account's Business Profile, which only PayMongo's dashboard
        // controls, not the API.
        'statement_descriptor' => 'PARISHHUB',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];
    if ($donorEmail) {
        $attributes['billing'] = ['email' => $donorEmail];
    }
    if ($metadata) {
        $attributes['metadata'] = array_map('strval', $metadata);
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
 * What a checkout session means for the money:
 *  paid       — PayMongo took the payment (a session takes at most one)
 *  processing — a payment is under way and may still succeed: never cancel or replace it
 *  active     — open and payable: no attempt yet, a failed attempt (the payer may try
 *               again on the same page), or one waiting for the payer's e-wallet or
 *               3-D Secure step. It stays payable until expired.
 *  expired    — can no longer be paid
 *  unknown    — anything else, treated as possibly payable
 */
function paymongoCheckoutState(array $attrs): string
{
    $paymentStatuses = [];
    foreach ((is_array($attrs['payments'] ?? null) ? $attrs['payments'] : []) as $payment) {
        $paymentStatuses[] = (string) ($payment['attributes']['status'] ?? '');
    }
    $intent = (string) ($attrs['payment_intent']['attributes']['status'] ?? '');
    $session = (string) ($attrs['status'] ?? '');

    if (in_array('paid', $paymentStatuses, true) || $intent === 'succeeded') return 'paid';
    if (in_array('pending', $paymentStatuses, true) || $intent === 'processing') return 'processing';
    if ($session === 'expired' || $intent === 'cancelled') return 'expired';
    if ($session === 'active') return 'active';
    return 'unknown';
}

/** @return array{ok: bool, state: string, status: ?string, amount: ?float, checkout_url: ?string, metadata: array, raw: array} */
function paymongoSessionFromResponse(array $result): array
{
    if (!$result['ok'] || !is_array($result['body']['data']['attributes'] ?? null)) {
        return ['ok' => false, 'state' => 'unknown', 'status' => null, 'amount' => null, 'checkout_url' => null, 'metadata' => [], 'raw' => $result['body'] ?? []];
    }
    $attrs = $result['body']['data']['attributes'];
    $centavos = null;
    foreach ((is_array($attrs['line_items'] ?? null) ? $attrs['line_items'] : []) as $item) {
        $centavos = ($centavos ?? 0) + (int) ($item['amount'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
    }
    return [
        'ok' => true,
        'state' => paymongoCheckoutState($attrs),
        'status' => $attrs['payment_intent']['attributes']['status'] ?? ($attrs['status'] ?? null),
        'amount' => $centavos === null ? null : $centavos / 100,
        'checkout_url' => $attrs['checkout_url'] ?? null,
        'metadata' => is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [],
        'raw' => $result['body'],
    ];
}

/**
 * Retrieves a Checkout Session from PayMongo with the secret key. This answer
 * is the only thing ParishHub trusts about a checkout: a browser redirect or a
 * webhook body never marks anything paid on its own.
 */
function paymongoGetCheckoutSession(string $sessionId): array
{
    if ($sessionId === '') {
        return paymongoSessionFromResponse(['ok' => false, 'body' => []]);
    }
    return paymongoSessionFromResponse(paymongoRequest('GET', '/checkout_sessions/' . urlencode($sessionId)));
}

/**
 * Makes a checkout unpayable (POST /checkout_sessions/{id}/expire). PayMongo
 * refuses when the session is already expired, paid, or has a payment under
 * way; the session is then read back to learn which. ok=false means PayMongo
 * gave no clear answer, so nothing may be assumed.
 */
function paymongoExpireCheckoutSession(string $sessionId): array
{
    $expired = paymongoSessionFromResponse(paymongoRequest('POST', '/checkout_sessions/' . urlencode($sessionId) . '/expire'));
    if ($expired['ok'] && $expired['state'] === 'expired') {
        return $expired;
    }
    return paymongoGetCheckoutSession($sessionId);
}

/**
 * True for a credential that was never filled in: empty, or one of the
 * example config's placeholder values (a run of x's, or "..._replace_me").
 */
function paymongoIsPlaceholderSecret(string $value): bool
{
    $value = trim($value);
    return $value === '' || str_contains($value, 'xxxxxxxxxxxx') || stripos($value, 'replace_me') !== false;
}

/**
 * The PayMongo mode this site is configured for, taken from its own secret key
 * (server-side configuration, never anything in a request): 'test' for an
 * sk_test_ key, 'live' for an sk_live_ key, null when the key is missing, a
 * placeholder or not a PayMongo secret key.
 */
function paymongoKeyMode(): ?string
{
    $key = trim((string) getenv('PAYMONGO_SECRET_KEY'));
    if (paymongoIsPlaceholderSecret($key) || !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $key, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * The configured webhook signing secret, or null when it is missing, a
 * placeholder, or not a plausible secret (shorter than 16 characters, or
 * containing spaces or control characters) — the webhook then accepts nothing.
 */
function paymongoWebhookSecret(): ?string
{
    $secret = trim((string) getenv('PAYMONGO_WEBHOOK_SECRET'));
    if (paymongoIsPlaceholderSecret($secret) || !preg_match('/^[\x21-\x7E]{16,}$/', $secret)) {
        return null;
    }
    return $secret;
}

/**
 * Verifies a PayMongo webhook's "Paymongo-Signature" header,
 * "t=<timestamp>,te=<test-mode signature>,li=<live-mode signature>", where a
 * signature is the hex HMAC-SHA256 of "<timestamp>.<raw body>" with the
 * webhook secret. PayMongo sends BOTH fields and leaves the other mode's one
 * empty (confirmed with a real test-mode delivery, audit B-07 Probe G), so the
 * field is chosen by the site's configured mode ($mode, from
 * paymongoKeyMode()) — never by which one happens to be filled, and never
 * falling back to the other mode's field. A missing, empty, malformed,
 * repeated or wrong signature is rejected.
 */
function paymongoVerifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret, string $mode): bool
{
    if (($mode !== 'test' && $mode !== 'live') || paymongoIsPlaceholderSecret($webhookSecret)) {
        return false;
    }
    $parts = [];
    foreach (explode(',', $signatureHeader) as $pair) {
        $pieces = explode('=', $pair, 2);
        if (count($pieces) !== 2) return false;                     // every field is key=value
        $key = trim($pieces[0]);
        if ($key === '' || array_key_exists($key, $parts)) return false;   // no nameless or repeated fields
        $parts[$key] = trim($pieces[1]);
    }
    $timestamp = $parts['t'] ?? '';
    $signature = $parts[$mode === 'live' ? 'li' : 'te'] ?? '';
    if (!preg_match('/^\d{1,20}$/', $timestamp) || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret), $signature);
}

/**
 * True when a webhook event's livemode flag matches the configured mode, so a
 * test-mode event is never applied by a live site, nor a live one by a test site.
 */
function paymongoWebhookEventModeMatches(mixed $event, string $mode): bool
{
    $livemode = is_array($event) ? ($event['data']['attributes']['livemode'] ?? null) : null;
    return is_bool($livemode) && ($mode === 'test' || $mode === 'live') && $livemode === ($mode === 'live');
}

/** @return array{payment_id: int, appointment_id: int, payment_status: string, amount: string, gateway_transaction_id: ?string, gateway_status: ?string, raw_response: ?string}|null */
function paymongoPaymentRow(int $paymentId): ?array
{
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.appointment_id, p.payment_status, p.amount, t.gateway_transaction_id, t.status AS gateway_status, t.raw_response
         FROM payments p JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE p.payment_id = ? ORDER BY t.transaction_id DESC LIMIT 1"
    );
    $stmt->execute([$paymentId]);
    return $stmt->fetch() ?: null;
}

/**
 * PayMongo record statuses that need the Treasurer: a charge PayMongo took that
 * could not be applied to its booking, and a checkout PayMongo created that
 * ParishHub could not record or confirm expired (paymongoAbandonUnrecordedCheckout()).
 */
const PAYMONGO_RECONCILIATION_STATUSES = ['needs_reconciliation', 'unrecorded_checkout'];

/** True when a still-pending payment's PayMongo record needs the Treasurer (labels in lists and reports). */
function paymongoStatusNeedsReconciliation(?string $paymentStatus, ?string $gatewayStatus): bool
{
    return $paymentStatus === 'pending' && in_array($gatewayStatus, PAYMONGO_RECONCILIATION_STATUSES, true);
}

/** True when a PayMongo charge or checkout for this payment is waiting on the Treasurer. */
function paymongoNeedsReconciliation(int $paymentId): bool
{
    $row = paymongoPaymentRow($paymentId);
    return $row !== null && paymongoStatusNeedsReconciliation($row['payment_status'], $row['gateway_status']);
}

/** The id of a checkout PayMongo created but ParishHub could not record, if this payment has one. */
function paymongoUnrecordedSessionId(array $row): ?string
{
    if (($row['gateway_status'] ?? '') !== 'unrecorded_checkout' || !empty($row['gateway_transaction_id'])) return null;
    $id = json_decode((string) ($row['raw_response'] ?? ''), true)['unrecorded_session_id'] ?? null;
    return is_string($id) && preg_match('/^cs_[A-Za-z0-9]+$/', $id) ? $id : null;
}

/** Records an unrecorded checkout's id after all, so every normal path (re-check, expiry, webhook) can reach it. */
function paymongoAdoptUnrecorded(int $paymentId, string $sessionId): bool
{
    $stmt = db()->prepare(
        "UPDATE transactions SET gateway_transaction_id = ?, status = 'pending'
         WHERE payment_id = ? AND gateway = 'paymongo' AND gateway_transaction_id IS NULL AND status = 'unrecorded_checkout'"
    );
    $stmt->execute([$sessionId, $paymentId]);
    return $stmt->rowCount() === 1;
}

/**
 * Brings one PayMongo payment in line with what PayMongo says about its
 * checkout. The single place the return pages, cancel links, the retry check,
 * the webhook and the Treasurer's re-check all decide what a checkout means:
 *  - paid: verified through verifyPaymentAndIssueReceipt(), even if ParishHub
 *    had marked the payment cancelled or failed — a real charge is never
 *    ignored. When the booking cannot take it (already paid, cancelled, or the
 *    amount differs) the payment stays pending and is flagged
 *    "needs_reconciliation" for the Treasurer: no second receipt, nothing
 *    discarded, nothing refunded automatically.
 *  - expired: a still-pending payment becomes cancelled.
 *  - active / processing / unknown: nothing changes.
 * The request that verifies a payment then closes the appointment's other
 * checkouts (paymongoCloseOtherCheckouts()) unless $closeOthers is false —
 * as it is for those other checkouts themselves, so this never recurses.
 *
 * @return string verified | reconcile | cancelled | pending | refunded | error
 */
function paymongoApplyCheckout(int $paymentId, array $session, bool $closeOthers = true): string
{
    $payment = paymongoPaymentRow($paymentId);
    if (!$payment) return 'error';
    if ($payment['payment_status'] === 'verified') return 'verified';
    if ($payment['payment_status'] === 'refunded') return 'refunded';
    if (!$session['ok']) return 'error';

    $record = db()->prepare("UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ? AND gateway = 'paymongo'");
    $raw = json_encode($session['raw'] ?? []);

    if ($session['state'] === 'paid') {
        $amountMatches = $session['amount'] !== null && abs($session['amount'] - (float) $payment['amount']) < 0.005;
        // Back to pending so it is verified now, or stays in the Treasurer's queue if it cannot be.
        db()->prepare("UPDATE payments SET payment_status = 'pending' WHERE payment_id = ? AND payment_status IN ('cancelled', 'failed')")
            ->execute([$paymentId]);
        $reason = 'the amount PayMongo charged differs from this payment';
        if ($amountMatches) {
            $resolvedMethod = resolvePaymentMethodName('PayMongo (Online)', $raw);
            $methodMap = ['GCash' => 2, 'Maya' => 3, 'Credit/Debit Card' => 5];
            $result = verifyPaymentAndIssueReceipt($paymentId, null, (string) $payment['gateway_transaction_id']);
            if ($result['ok'] || (paymongoPaymentRow($paymentId)['payment_status'] ?? '') === 'verified') {
                // ok, or verified a moment ago by a concurrent request (webhook and return page together)
                $record->execute(['paid', $raw, $paymentId]);
                if (isset($methodMap[$resolvedMethod])) {
                    try {
                        db()->prepare('UPDATE payments SET method_id = ? WHERE payment_id = ?')->execute([$methodMap[$resolvedMethod], $paymentId]);
                    } catch (Throwable $e) {}
                }
                // Only the request that did the verification tidies up, after it committed.
                if ($result['ok'] && $closeOthers) {
                    paymongoCloseOtherCheckouts($paymentId);
                }
                return 'verified';
            }
            $reason = $result['message'];
        }
        $record->execute(['needs_reconciliation', $raw, $paymentId]);
        if ($payment['gateway_status'] !== 'needs_reconciliation') {   // reported once, not on every re-check
            error_log("PayMongo payment $paymentId was paid but needs reconciliation: $reason");
            // activity_logs.action holds 150 characters: the reason stays in the error log above.
            logActivity(null, "PayMongo payment #$paymentId was paid but could not be applied to its booking — needs Treasurer reconciliation", 'Payments');
        }
        return 'reconcile';
    }

    $record->execute([(string) ($session['status'] ?? $session['state']), $raw, $paymentId]);
    if ($session['state'] === 'expired') {
        markPaymentUnsuccessful($paymentId, 'cancelled');
        return (paymongoPaymentRow($paymentId)['payment_status'] ?? '') === 'pending' ? 'pending' : 'cancelled';
    }
    return $payment['payment_status'] === 'pending' ? 'pending' : (string) $payment['payment_status'];
}

/** At most this many other checkouts, and this many seconds of PayMongo calls, per tidy-up. */
const PAYMONGO_CLEANUP_MAX_CHECKOUTS = 3;
const PAYMONGO_CLEANUP_SECONDS = 8.0;

/**
 * After a PayMongo payment is verified, the appointment's other checkouts must
 * not take money too. Each still-open one is expired at PayMongo; one being
 * processed is left alone (PayMongo refuses to expire it anyway); one already
 * paid is applied, which flags it for reconciliation (the booking is paid now,
 * so it can never get a second receipt). Best effort and bounded: a few
 * checkouts and a few seconds of calls, after the verification has committed,
 * with no database transaction held. Whatever PayMongo cannot answer now is
 * left as it is for the return pages, the webhook or the Treasurer's re-check
 * — the verified payment itself is never touched.
 */
function paymongoCloseOtherCheckouts(int $paymentId): void
{
    try {
        $stmt = db()->prepare(
            "SELECT p.payment_id, t.gateway_transaction_id
             FROM payments p JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
             WHERE p.appointment_id = (SELECT appointment_id FROM payments WHERE payment_id = ?)
               AND p.payment_id <> ? AND p.payment_status IN ('pending', 'cancelled', 'failed')
               AND t.gateway_transaction_id IS NOT NULL AND t.status NOT IN ('needs_reconciliation')
             ORDER BY p.payment_id DESC LIMIT " . PAYMONGO_CLEANUP_MAX_CHECKOUTS
        );
        $stmt->execute([$paymentId, $paymentId]);
        $others = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('ParishHub: could not list other PayMongo checkouts for payment ' . $paymentId . ' (' . get_class($e) . ' ' . $e->getCode() . ')');
        return;
    }
    if (!$others) return;

    $ownDeadline = paymongoDeadline() === null;
    if ($ownDeadline) paymongoDeadline(microtime(true) + PAYMONGO_CLEANUP_SECONDS);
    try {
        foreach ($others as $other) {
            $sessionId = (string) $other['gateway_transaction_id'];
            $session = paymongoGetCheckoutSession($sessionId);
            if ($session['ok'] && in_array($session['state'], ['active', 'unknown'], true)) {
                $session = paymongoExpireCheckoutSession($sessionId);
            }
            if (!$session['ok']) {
                error_log("PayMongo: checkout $sessionId (payment {$other['payment_id']}) could not be checked after payment $paymentId was verified; left for a later re-check");
                continue;
            }
            paymongoApplyCheckout((int) $other['payment_id'], $session, false);
        }
    } catch (Throwable $e) {
        error_log('ParishHub: closing other PayMongo checkouts after payment ' . $paymentId . ' stopped (' . get_class($e) . ' ' . $e->getCode() . ')');
    } finally {
        if ($ownDeadline) paymongoDeadline(true);
    }
}

/**
 * Re-reads one PayMongo payment's checkout and applies it. A payment
 * ParishHub had already given up on (cancelled or failed) whose checkout is
 * still open is expired at PayMongo now, so it can never be paid unnoticed.
 *
 * @return string as paymongoApplyCheckout(), or 'not_paymongo'
 */
function paymongoSyncPayment(int $paymentId): string
{
    $payment = paymongoPaymentRow($paymentId);
    if (!$payment) return 'not_paymongo';
    if (in_array($payment['payment_status'], ['verified', 'refunded'], true)) return (string) $payment['payment_status'];
    $sessionId = (string) $payment['gateway_transaction_id'];
    if ($sessionId === '') return $payment['payment_status'] === 'pending' ? 'pending' : (string) $payment['payment_status'];

    $session = paymongoGetCheckoutSession($sessionId);
    if ($session['ok'] && in_array($payment['payment_status'], ['cancelled', 'failed'], true) && in_array($session['state'], ['active', 'unknown'], true)) {
        $session = paymongoExpireCheckoutSession($sessionId);
    }
    return paymongoApplyCheckout($paymentId, $session);
}

/** Re-checks every not-yet-verified PayMongo payment of an appointment (an older checkout may have been paid too). */
function paymongoSyncAppointment(int $appointmentId): void
{
    $stmt = db()->prepare(
        "SELECT DISTINCT p.payment_id FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE p.appointment_id = ? AND p.payment_status IN ('pending', 'cancelled', 'failed') AND t.gateway_transaction_id IS NOT NULL
         ORDER BY p.payment_id DESC LIMIT 5"
    );
    $stmt->execute([$appointmentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
        paymongoSyncPayment((int) $paymentId);
    }
}

/**
 * The payer came back through a checkout's cancel link. The checkout is
 * expired at PayMongo first; only a confirmed expiry cancels the payment. A
 * paid checkout is verified (or reconciled); one still under way, or one
 * PayMongo would not confirm, is left pending.
 */
function paymongoCancelPayment(int $paymentId): string
{
    $payment = paymongoPaymentRow($paymentId);
    if (!$payment) return 'not_paymongo';
    if ($payment['payment_status'] !== 'pending') return (string) $payment['payment_status'];
    $sessionId = (string) $payment['gateway_transaction_id'];
    if ($sessionId === '') {
        // Its checkout was never handed to the payer (see paymongoStartCheckout()).
        markPaymentUnsuccessful($paymentId, 'cancelled');
        return 'cancelled';
    }
    return paymongoApplyCheckout($paymentId, paymongoExpireCheckoutSession($sessionId));
}

/** Seconds of PayMongo calls an appointment cancellation may spend closing its checkouts (well inside the payment lease). */
const PAYMONGO_CANCEL_SECONDS = 45.0;

/**
 * Before an appointment is cancelled (audit B1): every PayMongo checkout of the
 * appointment that could still take money must first be confirmed unpayable.
 * Call while holding the appointment's payment lease, so no new checkout can be
 * opened meanwhile. A still-open checkout is expired at PayMongo; a checkout
 * found paid is applied through paymongoApplyCheckout() (verified, or flagged
 * for reconciliation) and the cancellation is refused; one being processed, or
 * one PayMongo will not confirm expired, refuses it too. Payment and
 * transaction records are only updated, never deleted.
 *
 * @return array{ok: bool, outcome: string, closed: int}
 *   outcome: clear (nothing can take money any more) | paid | reconcile | processing | unconfirmed;
 *   closed: how many open checkouts PayMongo confirmed expired just now
 */
function paymongoCloseCheckoutsForCancellation(int $appointmentId): array
{
    $verified = db()->prepare("SELECT 1 FROM payments WHERE appointment_id = ? AND payment_status = 'verified' LIMIT 1");
    $verified->execute([$appointmentId]);
    if ($verified->fetchColumn()) return ['ok' => false, 'outcome' => 'paid', 'closed' => 0];

    $stmt = db()->prepare(
        "SELECT DISTINCT p.payment_id FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE p.appointment_id = ? AND p.payment_status IN ('pending', 'cancelled', 'failed')
         ORDER BY p.payment_id DESC"
    );
    $stmt->execute([$appointmentId]);
    $paymentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $closed = 0;
    $ownDeadline = paymongoDeadline() === null;
    if ($ownDeadline) paymongoDeadline(microtime(true) + PAYMONGO_CANCEL_SECONDS);
    try {
        foreach ($paymentIds as $paymentId) {
            $row = paymongoPaymentRow($paymentId);
            if (!$row) continue;
            if ($row['payment_status'] === 'pending' && $row['gateway_status'] === 'needs_reconciliation') {
                return ['ok' => false, 'outcome' => 'reconcile', 'closed' => $closed];
            }
            $sessionId = (string) $row['gateway_transaction_id'];
            if ($sessionId === '') {
                $unrecorded = $row['payment_status'] === 'pending' ? paymongoUnrecordedSessionId($row) : null;
                if ($unrecorded === null) {
                    // No checkout id was ever stored, so no link was handed out. Under the
                    // lease no request is still creating one: close it as paymongoPrepareNewPayment() does.
                    if ($row['payment_status'] === 'pending') {
                        markPaymentUnsuccessful($paymentId, 'cancelled');
                        db()->prepare("UPDATE transactions SET status = 'create_abandoned' WHERE payment_id = ? AND gateway = 'paymongo' AND gateway_transaction_id IS NULL")
                            ->execute([$paymentId]);
                    }
                    continue;
                }
                // A checkout PayMongo created but ParishHub could not record: record it, then close it like any other.
                if (!paymongoAdoptUnrecorded($paymentId, $unrecorded)) {
                    return ['ok' => false, 'outcome' => 'unconfirmed', 'closed' => $closed];
                }
                $sessionId = $unrecorded;
            }

            $session = paymongoGetCheckoutSession($sessionId);
            $wasOpen = $session['ok'] && in_array($session['state'], ['active', 'unknown'], true);
            if ($wasOpen) {
                // PayMongo refuses to expire a session that was paid or started a payment in
                // the meantime; paymongoExpireCheckoutSession() then reads back which it was.
                $session = paymongoExpireCheckoutSession($sessionId);
            }
            if (!$session['ok']) {
                return ['ok' => false, 'outcome' => 'unconfirmed', 'closed' => $closed];
            }
            $applied = paymongoApplyCheckout($paymentId, $session);
            if ($session['state'] === 'paid') {
                return ['ok' => false, 'outcome' => $applied === 'verified' ? 'paid' : 'reconcile', 'closed' => $closed];
            }
            if ($session['state'] === 'processing') {
                return ['ok' => false, 'outcome' => 'processing', 'closed' => $closed];
            }
            if ($session['state'] !== 'expired') {
                return ['ok' => false, 'outcome' => 'unconfirmed', 'closed' => $closed];
            }
            if ($wasOpen) $closed++;
        }
    } finally {
        if ($ownDeadline) paymongoDeadline(true);
    }
    return ['ok' => true, 'outcome' => 'clear', 'closed' => $closed];
}

/** Seconds a payment start may hold its appointment: above four PayMongo calls at their 20-second limit. */
const PAYMENT_LEASE_SECONDS = 120;

/**
 * Claims the right to start a payment for one appointment, so simultaneous
 * clicks cannot each open a checkout. A lease row in auth_throttle, taken
 * atomically: no database transaction stays open while PayMongo is called, a
 * crashed request's lease simply runs out, and a second request is refused at
 * once instead of waiting.
 *
 * @return int|null the lease token, or null when another request holds the appointment
 */
function paymentLeaseAcquire(int $appointmentId): ?int
{
    $token = random_int(1, 2147483647);
    $stmt = db()->prepare(
        "INSERT INTO auth_throttle (throttle_key, failures, locked_until, updated_at)
         VALUES (?, ?, NOW() + make_interval(secs => ?), NOW())
         ON CONFLICT (throttle_key) DO UPDATE SET failures = EXCLUDED.failures, locked_until = EXCLUDED.locked_until, updated_at = NOW()
         WHERE auth_throttle.locked_until IS NULL OR auth_throttle.locked_until <= NOW()
         RETURNING failures"
    );
    $stmt->execute(['payment_lease:' . $appointmentId, $token, PAYMENT_LEASE_SECONDS]);
    return (int) $stmt->fetchColumn() === $token ? $token : null;
}

/** Releases a lease, only if it is still ours (an expired one may belong to another request now). */
function paymentLeaseRelease(int $appointmentId, int $token): void
{
    try {
        db()->prepare('DELETE FROM auth_throttle WHERE throttle_key = ? AND failures = ?')->execute(['payment_lease:' . $appointmentId, $token]);
    } catch (Throwable $e) {
        error_log('ParishHub: payment lease release failed (' . get_class($e) . ' ' . $e->getCode() . ')');
    }
}

/**
 * Before a new payment is recorded for an appointment: never a second checkout
 * while an earlier one may still take money. Call while holding the
 * appointment's lease.
 *
 * @return array{action: string, url?: string, type?: string, message?: string}
 *         action new: go ahead · reuse: send the payer back to url · stop: show message, change nothing more
 */
function paymongoPrepareNewPayment(int $appointmentId, float $amount, bool $online): array
{
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.method_id, p.payment_status, p.amount, t.gateway_transaction_id, t.status AS gateway_status
         FROM payments p LEFT JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE p.appointment_id = ? ORDER BY p.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $existing = $stmt->fetch();
    if (!$existing || in_array($existing['payment_status'], ['failed', 'cancelled'], true)) {
        return ['action' => 'new'];
    }
    $isOpenOnline = (int) $existing['method_id'] === 7 && $existing['payment_status'] === 'pending';
    if (!$isOpenOnline) {
        return ['action' => 'stop', 'type' => 'error', 'message' => 'A payment has already been submitted for this appointment.'];
    }
    $paymentId = (int) $existing['payment_id'];
    $unconfirmed = ['action' => 'stop', 'type' => 'error', 'message' => 'We could not confirm the status of your earlier online payment with PayMongo, so nothing was changed. Please try again in a few minutes.'];

    $sessionId = (string) $existing['gateway_transaction_id'];
    if ($sessionId === '') {
        $unrecorded = paymongoUnrecordedSessionId(paymongoPaymentRow($paymentId) ?? []);
        if ($unrecorded === null) {
            // A checkout whose id never came back (the request stopped before or
            // while PayMongo answered): nobody was given its link, so it is closed here.
            markPaymentUnsuccessful($paymentId, 'cancelled');
            db()->prepare("UPDATE transactions SET status = 'create_abandoned' WHERE payment_id = ? AND gateway = 'paymongo'")->execute([$paymentId]);
            return ['action' => 'new'];
        }
        // A checkout PayMongo created but ParishHub could not record or expire
        // (paymongoAbandonUnrecordedCheckout()) is taken up again, never left
        // open beside a new one.
        if (!paymongoAdoptUnrecorded($paymentId, $unrecorded)) return $unconfirmed;
        $sessionId = $unrecorded;
    }

    $session = paymongoGetCheckoutSession($sessionId);
    if (!$session['ok']) return $unconfirmed;
    $sameAmount = abs((float) $existing['amount'] - $amount) < 0.005 && $session['amount'] !== null && abs($session['amount'] - $amount) < 0.005;
    if ($online && $session['state'] === 'active' && $sameAmount && $session['checkout_url']) {
        return ['action' => 'reuse', 'url' => (string) $session['checkout_url']];
    }
    if (in_array($session['state'], ['active', 'unknown'], true)) {
        $session = paymongoExpireCheckoutSession($sessionId);
    }

    return match (paymongoApplyCheckout($paymentId, $session)) {
        'cancelled' => ['action' => 'new'],
        'verified' => ['action' => 'stop', 'type' => 'success', 'message' => 'Your online payment was already received — thank you!'],
        'reconcile' => ['action' => 'stop', 'type' => 'error', 'message' => 'Your earlier online payment was received by PayMongo, but it needs to be reviewed by the parish office before anything else is done. Please contact the parish office.'],
        'pending' => $session['ok'] && $session['state'] === 'processing'
            ? ['action' => 'stop', 'type' => 'error', 'message' => 'Your earlier online payment is still being processed by PayMongo. Please wait a few minutes, then check this page again before paying.']
            : $unconfirmed,
        default => $unconfirmed,
    };
}

/**
 * Records a new online payment and opens its checkout, without keeping a
 * database transaction open while PayMongo answers: the payment and an empty
 * PayMongo transaction are saved first, then the checkout is created (carrying
 * payment_id and appointment_id as metadata), then its session id is stored.
 * If the request stops in between, the checkout's link was never handed out;
 * the next attempt closes that payment (see paymongoPrepareNewPayment()), and
 * the webhook can still find it by its metadata.
 *
 * @return array{ok: bool, payment_id: int, checkout_url: ?string, error: ?string}
 */
function paymongoStartCheckout(int $appointmentId, float $amount, string $description, string $successUrl, string $cancelUrl, ?string $email): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date) VALUES (?, NULL, ?, 7, 'pending', NOW())")
            ->execute([$appointmentId, $amount]);
        $paymentId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO transactions (payment_id, gateway, gateway_transaction_id, status, raw_response) VALUES (?, 'paymongo', NULL, 'creating', NULL)")
            ->execute([$paymentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        return ['ok' => false, 'payment_id' => 0, 'checkout_url' => null, 'error' => null];
    }

    $checkout = paymongoCreateCheckoutSession($amount, $description, $successUrl, $cancelUrl, $email,
        ['payment_id' => $paymentId, 'appointment_id' => $appointmentId]);
    if (!$checkout['ok'] || !$checkout['session_id'] || !$checkout['checkout_url']) {
        // The link never reaches the payer; the payment is closed. If PayMongo did
        // create a session after all, its metadata still leads the webhook here.
        markPaymentUnsuccessful($paymentId, 'cancelled');
        $pdo->prepare("UPDATE transactions SET status = 'create_failed', raw_response = ? WHERE payment_id = ? AND gateway = 'paymongo'")
            ->execute([json_encode($checkout['raw'] ?? []), $paymentId]);
        error_log('PayMongo checkout session creation failed: ' . json_encode($checkout['raw'] ?? []));
        return ['ok' => false, 'payment_id' => $paymentId, 'checkout_url' => null, 'error' => $checkout['error'] ?: null];
    }
    // The link is handed out only once its session id is stored on this payment.
    try {
        $store = $pdo->prepare("UPDATE transactions SET gateway_transaction_id = ?, status = 'pending', raw_response = ? WHERE payment_id = ? AND gateway = 'paymongo' AND gateway_transaction_id IS NULL");
        $store->execute([$checkout['session_id'], json_encode($checkout['raw']), $paymentId]);
        $stored = $store->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('ParishHub: could not record PayMongo checkout for payment ' . $paymentId . ' (' . get_class($e) . ' ' . $e->getCode() . ')');
        $stored = false;
    }
    if (!$stored) {
        paymongoAbandonUnrecordedCheckout($paymentId, (string) $checkout['session_id']);
        return ['ok' => false, 'payment_id' => $paymentId, 'checkout_url' => null, 'error' => null];
    }
    return ['ok' => true, 'payment_id' => $paymentId, 'checkout_url' => $checkout['checkout_url'], 'error' => null];
}

/**
 * PayMongo created a checkout that ParishHub could not record. Its link is
 * never handed out. It is expired at PayMongo; once that is confirmed the
 * payment is closed. If it cannot be confirmed the checkout may still accept
 * money, so its id is kept — in the error log always, and on the payment's
 * PayMongo record (status "unrecorded_checkout", shown to the Treasurer as
 * Needs Reconciliation) when the database allows — and the next payment
 * attempt or the Treasurer's re-check takes it up again instead of a second
 * checkout being opened beside it.
 */
function paymongoAbandonUnrecordedCheckout(int $paymentId, string $sessionId): void
{
    $session = paymongoExpireCheckoutSession($sessionId);
    if ($session['ok'] && $session['state'] === 'expired') {
        error_log("PayMongo checkout $sessionId for payment $paymentId could not be recorded; it was expired at PayMongo");
        try {
            markPaymentUnsuccessful($paymentId, 'cancelled');
            db()->prepare("UPDATE transactions SET status = 'create_unrecorded', raw_response = ? WHERE payment_id = ? AND gateway = 'paymongo' AND gateway_transaction_id IS NULL")
                ->execute([json_encode(['unrecorded_session_id' => $sessionId, 'expired' => true]), $paymentId]);
        } catch (Throwable $e) {
            error_log('ParishHub: could not close payment ' . $paymentId . ' after its unrecorded checkout expired (' . get_class($e) . ' ' . $e->getCode() . ')');
        }
        return;
    }
    error_log("PayMongo checkout $sessionId for payment $paymentId could not be recorded and was not confirmed expired — needs Treasurer reconciliation");
    try {
        db()->prepare("UPDATE transactions SET status = 'unrecorded_checkout', raw_response = ? WHERE payment_id = ? AND gateway = 'paymongo' AND gateway_transaction_id IS NULL")
            ->execute([json_encode(['unrecorded_session_id' => $sessionId]), $paymentId]);
        logActivity(null, mb_substr("PayMongo checkout $sessionId (payment #$paymentId) could not be recorded or expired — needs Treasurer reconciliation", 0, 150), 'Payments');
    } catch (Throwable $e) {
        error_log('ParishHub: could not flag payment ' . $paymentId . ' for its unrecorded checkout (' . get_class($e) . ' ' . $e->getCode() . ')');
    }
}

/**
 * Staff-triggered manual reconciliation for a PayMongo payment. Online
 * verification normally happens one of two ways — the payer's browser landing
 * back on a return page, or PayMongo's webhook — and if BOTH miss (browser
 * never returns, e.g. after an e-wallet app-switch; webhook not configured for
 * this deployment) the payment would sit unresolved with nothing to recover
 * it. This re-checks the checkout session directly with PayMongo and applies
 * whatever it reports through paymongoApplyCheckout(). It also works on a
 * payment ParishHub marked cancelled or failed, so a charge made on an older
 * checkout can be found. Requires includes/functions.php to already be loaded.
 *
 * @return array{ok: bool, outcome: string, message: string} outcome: verified|reconcile|pending|cancelled|refunded|not_paymongo|error
 */
function paymongoRecheckPayment(int $paymentId): array
{
    $row = paymongoPaymentRow($paymentId);
    $unrecorded = $row ? paymongoUnrecordedSessionId($row) : null;
    if ($unrecorded !== null && $row['payment_status'] === 'pending') {
        // A checkout PayMongo created but ParishHub could not record: record it now, then check it.
        if (!paymongoAdoptUnrecorded($paymentId, $unrecorded)) {
            return ['ok' => false, 'outcome' => 'error', 'message' => 'Could not record this payment\'s PayMongo checkout right now. Please try again shortly.'];
        }
        $row['gateway_transaction_id'] = $unrecorded;
    }
    if (!$row || !$row['gateway_transaction_id']) {
        return ['ok' => false, 'outcome' => 'not_paymongo', 'message' => 'This payment was not made through PayMongo online checkout, so there is nothing to re-check with PayMongo.'];
    }
    if (in_array($row['payment_status'], ['verified', 'refunded'], true)) {
        return ['ok' => true, 'outcome' => (string) $row['payment_status'], 'message' => 'This payment is already ' . $row['payment_status'] . ' — nothing to recheck.'];
    }
    $outcome = paymongoSyncPayment($paymentId);
    return match ($outcome) {
        'verified' => ['ok' => true, 'outcome' => 'verified', 'message' => 'PayMongo confirms this payment was paid. It has been verified and a receipt issued.'],
        'reconcile' => ['ok' => false, 'outcome' => 'reconcile', 'message' => 'PayMongo confirms this payment was PAID, but it could not be applied to its booking (already paid, cancelled, or a different amount). It is flagged for reconciliation: review the booking, and if this charge should not stand, refund it in the PayMongo dashboard.'],
        'cancelled' => ['ok' => true, 'outcome' => 'cancelled', 'message' => 'PayMongo confirms this checkout has expired and can no longer be paid. The payment is marked cancelled.'],
        'pending' => ['ok' => true, 'outcome' => 'pending', 'message' => "PayMongo still shows this payment as open or being processed. Nothing has changed yet — try again later."],
        default => ['ok' => false, 'outcome' => 'error', 'message' => 'Could not reach PayMongo to check this payment right now. Please try again shortly.'],
    };
}

/**
 * A donor came back through a donation checkout's cancel_url. Only the browser
 * session that started that checkout (donate.php sets the marker) may cancel
 * it, and only once PayMongo confirms the checkout can no longer be paid. If
 * PayMongo reports the session as paid, the payment is verified instead.
 * Returns ['status' => ..., 'message' => ...] for the donor, or null when
 * there is nothing to report (unknown or someone else's).
 */
function handleCancelledDonationCheckout(int $appointmentId): ?array
{
    if ($appointmentId < 1 || empty($_SESSION['donation_checkout'][$appointmentId])) return null;
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status
         FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         JOIN appointments a ON a.appointment_id = p.appointment_id
         JOIN services s ON s.service_id = a.service_id AND s.category = 'Donation'
         WHERE p.appointment_id = ?
         ORDER BY p.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $payment = $stmt->fetch();
    if (!$payment) return null;

    $outcome = $payment['payment_status'] === 'pending' ? paymongoCancelPayment((int) $payment['payment_id']) : (string) $payment['payment_status'];
    if ($outcome === 'verified') {
        unset($_SESSION['donation_checkout'][$appointmentId]);
        return ['status' => 'verified', 'message' => 'Thank you! Your donation has been received and verified — a receipt has been issued.'];
    }
    if ($outcome === 'reconcile') {
        unset($_SESSION['donation_checkout'][$appointmentId]);
        return ['status' => 'pending', 'message' => 'Your payment was received by PayMongo. The parish office will review it shortly; contact us if you have questions.'];
    }
    if ($outcome === 'pending' || $outcome === 'error') {
        return ['status' => 'pending', 'message' => 'Your payment is still being processed by PayMongo, so it could not be cancelled. If it completes, it will show as received.'];
    }
    unset($_SESSION['donation_checkout'][$appointmentId]);
    return ['status' => 'cancelled', 'message' => 'You cancelled the donation payment before it completed. No charge was made — you can try again anytime.'];
}

/**
 * A donor came back through a donation checkout's success_url. The redirect is
 * NOT proof of payment: the checkout session is always re-checked with PayMongo
 * (secret key) before payment_status changes. Idempotent: every step only acts
 * on a payment that is not yet verified, so a repeat visit is harmless.
 *
 * Only the browser that started this checkout (its per-donation marker) or the
 * signed-in parishioner who owns it learns how it went; anyone else (an edited
 * link, a lost session) gets a neutral note, though the payment is still
 * reconciled. A verified, authorized return sets the one-time Thank You
 * (rememberDonationThankYou()) and clears the marker.
 *
 * @return array{status: string, message: string, thank_you: bool}|null  null when
 *         the id is not an online Donation payment
 */
function handleDonationCheckoutReturn(int $appointmentId): ?array
{
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status, p.method_id
         FROM payments p
         JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         JOIN appointments a ON a.appointment_id = p.appointment_id
         JOIN services s ON s.service_id = a.service_id AND s.category = 'Donation'
         WHERE p.appointment_id = ?
         ORDER BY p.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $payment = $stmt->fetch();
    if (!$payment) return null;
    $authorized = canViewGuestReference($appointmentId); // checked before the marker is cleared

    $outcome = $payment['payment_status'] === 'verified' ? 'verified' : paymongoSyncPayment((int) $payment['payment_id']);
    $status = 'pending';
    $message = "We're still waiting for PayMongo to confirm this payment. If you completed it, it will update automatically shortly.";
    if ($outcome === 'verified') {
        $status = 'verified';
        $message = $payment['payment_status'] === 'verified'
            ? 'Thank you! Your donation has been received and verified.'
            : 'Thank you! Your donation has been received and verified — a receipt has been issued.';
    } elseif ($outcome === 'reconcile') {
        // PayMongo took the money but our bookkeeping step could not: never tell
        // the donor it failed — they were charged. The Treasurer reconciles it.
        $message = "Your payment was received by PayMongo. We're finishing up on our end; contact us if it doesn't show as verified within a day.";
    } elseif ($outcome === 'cancelled') {
        $status = 'cancelled';
        $message = 'The payment was cancelled. No charge was made — you can try again anytime.';
    }

    if (!$authorized) {
        return ['status' => 'neutral', 'message' => 'If you completed a donation, it is being confirmed. Use the reference code from your confirmation on the Check Status page to see its status.', 'thank_you' => false];
    }
    if ($status === 'verified' && (int) $payment['method_id'] === 7) {
        unset($_SESSION['donation_checkout'][$appointmentId]);
        rememberDonationThankYou($appointmentId);
        return ['status' => 'verified', 'message' => $message, 'thank_you' => true];
    }
    return ['status' => $status, 'message' => $message, 'thank_you' => false];
}

/** One-time note for the Services page after a donation return that shows no Thank You (pending, failed, ...). */
function rememberDonationReturnNotice(string $status, string $message): void
{
    $_SESSION['donation_return_notice'] = ['status' => $status, 'message' => $message];
}

function pullDonationReturnNotice(): ?array
{
    $notice = $_SESSION['donation_return_notice'] ?? null;
    unset($_SESSION['donation_return_notice']);
    return is_array($notice) ? $notice : null;
}

/**
 * Handles a donation return/cancel on the URL it arrived at, then sends the
 * donor to the clean Services page (the only donation page): the Thank You or a
 * one-time note is shown there, and a refresh repeats nothing.
 */
function finishDonationReturn(int $appointmentId, bool $cancelled): void
{
    if ($cancelled) {
        $result = handleCancelledDonationCheckout($appointmentId);
        if ($result && $result['status'] === 'verified') {
            rememberDonationThankYou($appointmentId); // paid after all
        } elseif ($result) {
            if ($result['status'] === 'cancelled') flash('error', $result['message']);
            else rememberDonationReturnNotice($result['status'], $result['message']);
        }
    } else {
        $result = handleDonationCheckoutReturn($appointmentId);
        if ($result && !$result['thank_you']) rememberDonationReturnNotice($result['status'], $result['message']);
    }
    redirect(url('parishioner/services.php'));
}
