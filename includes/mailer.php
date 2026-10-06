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
 * Wraps a message in a formal, parish-branded HTML email shell.
 */
function emailTemplate(string $title, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $cta = '';
    if ($ctaUrl && $ctaLabel) {
        $cta = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:32px 0;">'
            . '<tr><td align="center">'
            . '<a href="' . htmlspecialchars($ctaUrl) . '" '
            . 'style="background:#7a5c1e; color:#fff; text-decoration:none; padding:14px 36px; '
            . 'border-radius:6px; font-family:Georgia,serif; font-size:15px; font-weight:600; '
            . 'letter-spacing:0.5px; display:inline-block;">'
            . htmlspecialchars($ctaLabel) . '</a>'
            . '</td></tr></table>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0e9d6;font-family:Georgia,\'Times New Roman\',serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0e9d6;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

      <!-- Header -->
      <tr><td align="center" style="background:#3b2f1e;border-radius:10px 10px 0 0;padding:28px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td width="70" align="center" valign="middle">
              <img src="https://parishhub-qqg2.onrender.com/public/img/logo.png" alt="Parish Logo" width="60" style="display:block; max-width:60px;">
            </td>
            <td align="left" valign="middle" style="padding-left:16px;">
              <p style="margin:0 0 4px;font-size:11px;letter-spacing:3px;color:#c9a84c;text-transform:uppercase;font-family:Georgia,serif;">Official Correspondence</p>
              <h1 style="margin:0;font-size:22px;color:#f5edda;font-family:Georgia,serif;letter-spacing:1px;">PARISHHUB</h1>
              <p style="margin:6px 0 0;font-size:12px;color:#b8a07a;font-family:Georgia,serif;">Our Lady of Mt. Carmel Parish Service Portal</p>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Divider -->
      <tr><td style="background:#c9a84c;height:3px;font-size:0;line-height:0;">&nbsp;</td></tr>

      <!-- Body -->
      <tr><td style="background:#fff;padding:36px 40px;border-left:1px solid #e2d8c4;border-right:1px solid #e2d8c4;">
        <h2 style="margin:0 0 20px;font-size:18px;color:#3b2f1e;font-family:Georgia,serif;border-bottom:1px solid #e8dfc8;padding-bottom:14px;">'
        . htmlspecialchars($title) . '</h2>
        <div style="font-size:15px;line-height:1.8;color:#3a2e22;">' . $bodyHtml . '</div>'
        . $cta
        . '<p style="margin:24px 0 0;font-size:13px;color:#7a6a54;border-top:1px solid #e8dfc8;padding-top:18px;">'
        . 'If you did not request this, please disregard this message. For concerns, contact the parish office directly.</p>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f7f2e8;border-radius:0 0 10px 10px;border:1px solid #e2d8c4;border-top:none;padding:18px 32px;text-align:center;">
        <p style="margin:0 0 4px;font-size:11px;color:#7a6a54;font-family:Georgia,serif;">
          &copy; ' . date('Y') . ' &nbsp;&middot;&nbsp; PARISHHUB &nbsp;&middot;&nbsp; Our Lady of Mt. Carmel Parish
        </p>
        <p style="margin:0;font-size:11px;color:#9a8b72;">This is an automated message. Please do not reply directly to this email.</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>';
}
