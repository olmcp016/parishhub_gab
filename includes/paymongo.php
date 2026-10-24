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

/**
 * Staff-triggered manual reconciliation for a payment stuck 'pending' on a
 * PayMongo checkout. Online verification normally happens one of two ways —
 * the payer's browser landing back on guest-payment-return.php /
 * parishioner/payment-return.php, or PayMongo's webhook — and if BOTH miss
 * (browser never returns, e.g. after an e-wallet app-switch; webhook not
 * configured for this deployment) the payment sits pending forever with
 * nothing to recover it. This button is that recovery path: it re-checks the
 * checkout session directly with PayMongo and applies whatever it reports,
 * using the exact same verification/failure handling as the return pages and
 * the webhook — this is not a separate decision, just a manual trigger for
 * the same reconciliation. Requires includes/functions.php to already be
 * loaded (verifyPaymentAndIssueReceipt(), markPaymentUnsuccessful(),
 * resolvePaymentMethodName(), db()).
 *
 * @return array{ok: bool, outcome: string, message: string} outcome: verified|pending|failed|cancelled|not_paymongo|error
 */
function paymongoRecheckPayment(int $paymentId): array
{
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status, t.gateway_transaction_id
         FROM payments p
         LEFT JOIN transactions t ON t.payment_id = p.payment_id AND t.gateway = 'paymongo'
         WHERE p.payment_id = ?"
    );
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch();
    if (!$row || !$row['gateway_transaction_id']) {
        return ['ok' => false, 'outcome' => 'not_paymongo', 'message' => 'This payment was not made through PayMongo online checkout, so there is nothing to re-check with PayMongo.'];
    }
    if ($row['payment_status'] !== 'pending') {
        return ['ok' => true, 'outcome' => (string) $row['payment_status'], 'message' => 'This payment is already ' . $row['payment_status'] . ' — nothing to recheck.'];
    }

    $session = paymongoGetCheckoutSession($row['gateway_transaction_id']);
    if (!$session['ok']) {
        return ['ok' => false, 'outcome' => 'error', 'message' => 'Could not reach PayMongo to check this payment right now. Please try again shortly.'];
    }
    $localStatus = paymongoStatusToLocal((string) $session['status']);
    db()->prepare('UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ?')
        ->execute([$session['status'], json_encode($session['raw'] ?? []), $paymentId]);

    if ($localStatus === 'verified') {
        $resolvedMethod = resolvePaymentMethodName('PayMongo (Online)', json_encode($session['raw'] ?? []));
        $methodMap = ['GCash' => 2, 'Maya' => 3, 'Credit/Debit Card' => 5];
        if (isset($methodMap[$resolvedMethod])) {
            try {
                db()->prepare('UPDATE payments SET method_id = ? WHERE payment_id = ?')->execute([$methodMap[$resolvedMethod], $paymentId]);
            } catch (Throwable $e) {}
        }
        $result = verifyPaymentAndIssueReceipt($paymentId, null, $row['gateway_transaction_id']);
        if ($result['ok']) {
            return ['ok' => true, 'outcome' => 'verified', 'message' => 'PayMongo confirms this payment was paid. ' . $result['message']];
        }
        return ['ok' => false, 'outcome' => 'error', 'message' => 'PayMongo confirms this was paid, but the appointment could not be updated: ' . $result['message']];
    }
    if (in_array($localStatus, ['failed', 'cancelled'], true)) {
        markPaymentUnsuccessful($paymentId, $localStatus);
        return ['ok' => true, 'outcome' => $localStatus, 'message' => 'PayMongo reports this checkout was ' . $localStatus . '. The payment has been marked accordingly.'];
    }
    return ['ok' => true, 'outcome' => 'pending', 'message' => "PayMongo still shows this payment as pending/awaiting the payer's action. Nothing has changed yet — try again later."];
}

/**
 * A donor came back through a donation checkout's cancel_url. Only the browser
 * session that started that checkout (donate.php sets the marker) may cancel
 * it, and only a still-pending payment is touched. PayMongo stays the
 * authority: if it reports the session as paid, the payment is verified
 * instead of cancelled. Returns ['status' => ..., 'message' => ...] for the
 * donor, or null when there is nothing to report (unknown or someone else's).
 */
function handleCancelledDonationCheckout(int $appointmentId): ?array
{
    if ($appointmentId < 1 || empty($_SESSION['donation_checkout'][$appointmentId])) return null;
    $stmt = db()->prepare(
        "SELECT p.payment_id, p.payment_status, t.gateway_transaction_id
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

    if ($payment['payment_status'] === 'verified') {
        unset($_SESSION['donation_checkout'][$appointmentId]);
        return ['status' => 'verified', 'message' => 'Thank you! Your donation has been received and verified.'];
    }
    if ($payment['payment_status'] === 'pending') {
        $session = paymongoGetCheckoutSession((string) $payment['gateway_transaction_id']);
        if ($session['ok'] && paymongoStatusToLocal($session['status']) === 'verified') {
            // Paid after all (e.g. back button pressed after paying): never cancel a real payment.
            $result = verifyPaymentAndIssueReceipt((int) $payment['payment_id'], null, $payment['gateway_transaction_id']);
            unset($_SESSION['donation_checkout'][$appointmentId]);
            return $result['ok']
                ? ['status' => 'verified', 'message' => 'Thank you! Your donation has been received and verified — a receipt has been issued.']
                : ['status' => 'pending', 'message' => "Your payment was received by PayMongo. We're finishing up on our end — it will show as verified shortly."];
        }
        markPaymentUnsuccessful((int) $payment['payment_id'], 'cancelled');
        db()->prepare("UPDATE transactions SET status = 'cancelled' WHERE payment_id = ?")->execute([$payment['payment_id']]);
    }
    unset($_SESSION['donation_checkout'][$appointmentId]);
    return ['status' => 'cancelled', 'message' => 'You cancelled the donation payment before it completed. No charge was made — you can try again anytime.'];
}

/**
 * A donor came back through a donation checkout's success_url. The redirect is
 * NOT proof of payment: the checkout session is always re-checked with PayMongo
 * (secret key) before payment_status changes. Idempotent:
 * verifyPaymentAndIssueReceipt() / markPaymentUnsuccessful() only act on a
 * still-pending payment, so a repeat visit is harmless.
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
        "SELECT p.payment_id, p.payment_status, p.method_id, t.gateway_transaction_id
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

    if ($payment['payment_status'] === 'verified') {
        // Already reconciled (e.g. the webhook got there first, or a repeat visit).
        $status = 'verified';
        $message = 'Thank you! Your donation has been received and verified.';
    } else {
        $session = paymongoGetCheckoutSession((string) $payment['gateway_transaction_id']);
        $localStatus = $session['ok'] ? paymongoStatusToLocal($session['status']) : 'pending';
        db()->prepare("UPDATE transactions SET status = ?, raw_response = ? WHERE payment_id = ?")
            ->execute([$session['ok'] ? $session['status'] : 'unknown', json_encode($session['raw'] ?? []), $payment['payment_id']]);

        if ($localStatus === 'verified') {
            $resolvedMethod = resolvePaymentMethodName('PayMongo (Online)', json_encode($session['raw'] ?? []));
            $methodMap = ['GCash' => 2, 'Maya' => 3, 'Credit/Debit Card' => 5];
            if (isset($methodMap[$resolvedMethod])) {
                try {
                    db()->prepare('UPDATE payments SET method_id = ? WHERE payment_id = ?')->execute([$methodMap[$resolvedMethod], $payment['payment_id']]);
                } catch (Throwable $e) {}
            }
            $result = verifyPaymentAndIssueReceipt((int) $payment['payment_id'], null, $payment['gateway_transaction_id']);
            if ($result['ok']) {
                $status = 'verified';
                $message = 'Thank you! Your donation has been received and verified — a receipt has been issued.';
            } else {
                // PayMongo confirmed the payment but our bookkeeping step failed (e.g. a
                // race with the webhook): never tell the donor it failed — they were
                // charged. Left pending for staff to reconcile if needed.
                error_log("PayMongo payment {$payment['payment_id']} confirmed but verifyPaymentAndIssueReceipt failed: {$result['message']}");
                $status = 'pending';
                $message = "Your payment was received by PayMongo. We're finishing up on our end — this will show as verified shortly; contact us if it doesn't within a day.";
            }
        } elseif ($localStatus === 'failed' || $localStatus === 'cancelled') {
            markPaymentUnsuccessful((int) $payment['payment_id'], $localStatus);
            $status = $localStatus;
            $message = $localStatus === 'cancelled'
                ? 'The payment was cancelled. No charge was made — you can try again anytime.'
                : 'The payment did not go through. No charge was made — please try again or choose a different method.';
        } else {
            $status = 'pending';
            $message = "We're still waiting for PayMongo to confirm this payment. If you completed it, it will update automatically shortly.";
        }
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
