<?php
/**
 * PARISHHUB — Shared helper functions
 */

function logActivity(?int $userId, string $action, string $module = 'General'): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, action, module, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $module,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }
}

/** YEARWEEK() returns a YYYYWW integer (e.g. 202637) — make it readable for report tables. */
function formatReportPeriod(string $period, $value): string
{
    $value = (string) $value;
    if ($period === 'weekly' && strlen($value) >= 5) {
        return 'Week ' . substr($value, -2) . ', ' . substr($value, 0, 4);
    }
    return $value;
}

/** Phrases a Mass Intention as a natural read-aloud line for the printed Mass list. */
function massIntentionReadingLine(string $type, string $offerer, string $for): string
{
    $for = trim($for) ?: 'the intention submitted';
    $offerer = trim($offerer) ?: 'the parish community';
    $line = match ($type) {
        'Living' => "For the health and well-being of {$for}",
        'Dead' => "For the eternal repose of the soul of {$for}",
        'Thanksgiving' => "In thanksgiving for the blessings received by {$for}",
        'Healing' => "For the healing and recovery of {$for}",
        'Birthday' => "For the birthday blessing of {$for}",
        default => "For the intention of {$for}",
    };
    return "{$line}, requested by {$offerer}.";
}

function badgeClass(string $statusName): string
{
    return strtolower(str_replace(' ', '-', $statusName));
}

/**
 * Display label for a role name. The "Treasurer" role is now branded as
 * "Cashier" everywhere a human sees it, without touching the underlying
 * role_name value (used throughout for requireRole()/login/URLs) — safer
 * than renaming a live-authenticated role across a deployed site.
 */
function roleLabel(string $roleName): string
{
    return $roleName === 'Treasurer' ? 'Cashier' : $roleName;
}

function money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

/** Services with no fixed fee (e.g. Mass Intentions) are a voluntary offering, not ₱0.00. */
function feeLabel(float $fee): string
{
    return $fee > 0 ? money($fee) : 'Voluntary Offering';
}

function formatDate(?string $date): string
{
    if (!$date) return '—';
    return date('M j, Y', strtotime($date));
}

function formatDateTime(?string $dt): string
{
    if (!$dt) return '—';
    return date('M j, Y g:i A', strtotime($dt));
}

/** Simple CSRF token helper */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Session expired or invalid request token. Please go back and try again.');
    }
}

/**
 * Builds the correct, web-accessible URL for an uploaded document.
 * Files are physically stored under public/uploads/, but older rows may
 * have been saved with just "uploads/<name>" (missing the "public/"
 * prefix) — this normalizes either form so links always resolve.
 */
function documentUrl(string $filePath): string
{
    $filePath = ltrim($filePath, '/');
    if (!str_starts_with($filePath, 'public/')) {
        $filePath = 'public/' . $filePath;
    }
    return url($filePath);
}

/** True if the filename's extension is a browser-viewable image type. */
function isImageFile(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/** The current calendar week's Monday and Sunday dates (Y-m-d), Monday-start. */
function currentWeekBounds(): array
{
    $dow = (int) date('N'); // 1 (Mon) .. 7 (Sun)
    $monday = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    return ['monday' => $monday, 'sunday' => $sunday];
}

/** The title of the current week's auto-generated donor announcement, and a helper to recognize it. */
function weeklyDonorAnnouncementTitle(): string
{
    $week = currentWeekBounds();
    $mondayTs = strtotime($week['monday']);
    $sundayTs = strtotime($week['sunday']);
    $from = date('M j', $mondayTs);
    $to = date('M', $mondayTs) === date('M', $sundayTs) ? date('j, Y', $sundayTs) : date('M j, Y', $sundayTs);
    return "Thank You, This Week's Donors! ({$from}–{$to})";
}

function isWeeklyDonorAnnouncementTitle(string $title): bool
{
    return str_starts_with($title, "Thank You, This Week's Donors!");
}

/** Every Donation payment verified during the current (Monday-start) week, most recent first. */
function getCurrentWeekDonors(): array
{
    $week = currentWeekBounds();
    $stmt = db()->prepare(
        "SELECT d.donor_name, d.purpose, p.amount, p.verified_at
         FROM payments p
         JOIN appointments a ON p.appointment_id = a.appointment_id
         JOIN services s ON a.service_id = s.service_id
         JOIN donations d ON d.appointment_id = a.appointment_id
         WHERE s.category = 'Donation' AND p.payment_status = 'verified'
           AND DATE(p.verified_at) BETWEEN ? AND ?
         ORDER BY p.verified_at DESC"
    );
    $stmt->execute([$week['monday'], $week['sunday']]);
    return $stmt->fetchAll();
}

/**
 * Creates or refreshes this week's "Thank You, This Week's Donors!"
 * announcement from every Donation payment verified since Monday (online
 * or staff-recorded). Called right after a donation payment is verified —
 * no cron needed, it's event-driven and idempotent. The title carries the
 * week's date range, so a new Monday naturally starts a fresh title/card
 * instead of appending to last week's.
 *
 * Deliberately a compact count + total, not a per-donor itemized list —
 * a growing list inside one announcement card doesn't scale (a busy week
 * would read like a mall receipt). The full itemized list is available
 * on click (see getCurrentWeekDonors()) via a modal on the announcements
 * page, and has a permanent home in the Donations tab / My Donations page.
 */
function syncWeeklyDonationAnnouncement(int $postedByUserId): void
{
    try {
        $donors = getCurrentWeekDonors();
        if (empty($donors)) {
            return;
        }

        $title = weeklyDonorAnnouncementTitle();
        $count = count($donors);
        $total = array_sum(array_map(fn($d) => (float) $d['amount'], $donors));
        $giftWord = $count === 1 ? 'gift was' : 'gifts were';
        $content = "🤲 $count $giftWord received this week, totaling " . money($total)
            . ". Thank you to everyone who gave! Click to see the full list.";

        $stmt = db()->prepare('SELECT announcement_id FROM announcements WHERE title = ?');
        $stmt->execute([$title]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            db()->prepare('UPDATE announcements SET content = ? WHERE announcement_id = ?')
                ->execute([$content, $existingId]);
        } else {
            db()->prepare(
                "INSERT INTO announcements (title, content, posted_by, is_pinned, status) VALUES (?, ?, ?, FALSE, 'published')"
            )->execute([$title, $content, $postedByUserId]);
        }
    } catch (Throwable $e) {
        error_log('Weekly donation announcement sync error: ' . $e->getMessage());
    }
}
