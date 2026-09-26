<?php
/**
 * PARISHHUB — outbound email via Brevo's transactional email HTTP API.
 *
 * The API key is read from the environment (config/config.local.php,
 * gitignored) and used ONLY here, server-side, via cURL — same pattern as
 * includes/paymongo.php. Used for the emails a GUEST needs (no account, so
 * no in-app notification is possible): appointment approved (with a link to
 * pay), rescheduled, etc. Logged-in parishioners keep using the existing
 * in-app `notifications` table — this is guest-only, additive.
 *
 * If no API key is configured, sendEmail() simply no-ops (logging why) —
 * the rest of the app must keep working with or without email configured.
 */

const BREVO_API_BASE = 'https://api.brevo.com/v3';

/**
 * Sends one HTML email via Brevo.
 *
 * @return array{ok: bool, error: ?string}
 */
function sendEmail(string $toEmail, ?string $toName, string $subject, string $html): array
{
    $apiKey = getenv('BREVO_API_KEY');
    $senderEmail = getenv('BREVO_SENDER_EMAIL');
    $senderName = getenv('BREVO_SENDER_NAME') ?: 'PARISHHUB';

    if (!$apiKey || !$senderEmail || str_contains($apiKey, 'xxxxxxxxxxxx')) {
        error_log("Email not sent (Brevo not configured): to=$toEmail subject=\"$subject\"");
        return ['ok' => false, 'error' => 'Email is not configured.'];
    }

    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $toEmail, 'name' => $toName ?: $toEmail]],
        'subject' => $subject,
        'htmlContent' => $html,
    ];

    $ch = curl_init(BREVO_API_BASE . '/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Brevo send failed (cURL): $curlError");
        return ['ok' => false, 'error' => 'Could not reach the email service.'];
    }

    $body = json_decode($raw, true) ?: [];
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['ok' => true, 'error' => null];
    }

    $message = $body['message'] ?? "Brevo returned HTTP $httpStatus";
    error_log("Brevo send failed: $message (to=$toEmail)");
    return ['ok' => false, 'error' => $message];
}

/**
 * Wraps a message in a minimal, parish-branded HTML shell so every email
 * looks consistent without hand-writing a full HTML document each time.
 */
function emailTemplate(string $title, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $cta = '';
    if ($ctaUrl && $ctaLabel) {
        $cta = '<p style="text-align:center; margin:28px 0;">'
            . '<a href="' . htmlspecialchars($ctaUrl) . '" style="background:#b8860b; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:600; display:inline-block;">'
            . htmlspecialchars($ctaLabel) . '</a></p>';
    }
    return '<!DOCTYPE html><html><body style="margin:0; padding:0; background:#f4ead8; font-family: Georgia, serif;">'
        . '<div style="max-width:520px; margin:0 auto; padding:32px 24px;">'
        . '<div style="background:#fff; border-radius:14px; padding:28px 26px; border:1px solid #e7dcc3;">'
        . '<h2 style="margin:0 0 16px; color:#3a2a1a;">' . htmlspecialchars($title) . '</h2>'
        . '<div style="font-size:15px; line-height:1.6; color:#4a3a28;">' . $bodyHtml . '</div>'
        . $cta
        . '</div>'
        . '<p style="text-align:center; color:#9a8b6f; font-size:12px; margin-top:18px;">PARISHHUB &middot; Parish Service Portal</p>'
        . '</div></body></html>';
}
