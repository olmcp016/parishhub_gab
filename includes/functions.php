<?php
/**
 * PARISHHUB — Shared helper functions
 */

/**
 * True when the current request came from the detail-modal JS (see
 * public/js/detail-modal.js) rather than a normal browser navigation —
 * used by pages that support both a full standalone page and an
 * in-modal fragment/AJAX-action mode (e.g. secretary/appointment-detail.php).
 */
function isDetailModalRequest(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

/**
 * Responds to a POST action either as JSON (when called via the detail
 * modal's AJAX form submit) or as the classic flash+redirect (when the
 * same form is submitted normally, e.g. JS disabled, or a direct link) —
 * same underlying action/validation either way, only the response
 * transport differs. Always ends the request.
 */
function respondAjaxOrRedirect(bool $isAjax, bool $success, string $message, string $redirectUrl): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }
    flash($success ? 'success' : 'error', $message);
    redirect($redirectUrl);
}

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

/**
 * Public-safe breakdown of a Mass Intention for the website's "Today's Mass
 * Intentions" display — no contact info, no internal status. Returns the
 * three pieces that must always be shown: the Intention Type, the message
 * body (the parishioner's own Prayer Message when they gave one, else a
 * short generated line from the intention type and who it's for), and the
 * name it's offered by.
 */
function publicMassIntentionParts(string $type, string $offerer, string $for, ?string $message): array
{
    $message = trim((string) $message);
    if ($message !== '') {
        $body = $message;
    } else {
        $for = trim($for) ?: 'the intention submitted';
        $body = match ($type) {
            'Living' => "For the health and well-being of {$for}.",
            'Dead' => "For the eternal repose of the soul of {$for}.",
            'Thanksgiving' => "In thanksgiving for the blessings received by {$for}.",
            'Healing' => "For the healing and recovery of {$for}.",
            'Birthday' => "For the birthday blessing of {$for}.",
            default => "For the intention of {$for}.",
        };
    }
    return [
        'type' => $type,
        'body' => $body,
        'name' => trim($offerer) ?: 'the parish community',
    ];
}

/**
 * Today's Mass Intentions that are eligible for public display — payment
 * must be Cashier-confirmed (appointments.status_id = 5), matching the
 * required flow: submit -> pay -> Cashier verifies -> Cashier confirms ->
 * public display. Compared against PHP's Asia/Manila "today" (see
 * config/config.php), not the database's own current date, consistent
 * with how the rest of the app computes "today".
 */
function getTodaysConfirmedMassIntentions(): array
{
    // Public display needs BOTH the Cashier's confirmation (status 5) AND a
    // real, verified payment of more than ₱0 on record — never one without
    // the other.
    $stmt = db()->prepare(
        "SELECT mi.intention_type, mi.offerer_name, mi.intention_for, mi.message, a.appointment_time
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id
         JOIN mass_intentions mi ON mi.appointment_id = a.appointment_id
         WHERE s.category = 'Mass Intention' AND a.status_id = 5 AND a.appointment_date = ?
           AND EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.appointment_id
                       AND p.payment_status = 'verified' AND p.amount > 0)
         ORDER BY a.appointment_time ASC"
    );
    $stmt->execute([date('Y-m-d')]);
    return $stmt->fetchAll();
}

/**
 * Clear, consistent Mass Intention status wording used across the system:
 * Payment Required -> Pending Cashier Verification -> Approved / Rejected.
 * Underlying appointment statuses are unchanged (2/4 = submitted with
 * payment, awaiting Cashier; 5 = Cashier approved; 3 = rejected). The
 * Secretary never sees payment-stage wording, only whether it's approved.
 *
 * @return array{0: string, 1: string} [label, badge class suffix]
 */
function massIntentionStatusDisplay(string $statusName, ?bool $hasPayment = true, bool $forSecretary = false): array
{
    return match ($statusName) {
        'Rejected' => ['Rejected', 'rejected'],
        'Cancelled' => ['Cancelled', 'cancelled'],
        'Completed' => ['Completed', 'completed'],
        'Confirmed' => ['Approved', 'approved'],
        default => $hasPayment === false
            ? ['Payment Required', 'pending']
            : [$forSecretary ? 'Pending Approval' : 'Pending Cashier Verification', 'pending'],
    };
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

/**
 * Splits a service's free-text comma-separated requirements column (e.g.
 * "Baptismal Certificate, Marriage License, CENOMAR") into a clean list of
 * individual requirement names, for per-requirement upload rows/status.
 */
function parseRequirementsList(?string $requirementsText): array
{
    if (!$requirementsText) {
        return [];
    }
    $items = array_map('trim', explode(',', $requirementsText));
    return array_values(array_filter($items, fn($item) => $item !== ''));
}

/**
 * Simple page-number pagination from $_GET['page']. Returns the page/offset/
 * limit to use in a LIMIT/OFFSET query plus the total page count, clamped to
 * a valid range so an out-of-range ?page= never errors or shows nothing.
 */
function paginate(int $totalRows, int $perPage = 10): array
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    return [
        'page' => $page,
        'totalPages' => $totalPages,
        'offset' => ($page - 1) * $perPage,
        'limit' => $perPage,
        'totalRows' => $totalRows,
        'perPage' => $perPage,
    ];
}

/**
 * Renders the one pagination bar used everywhere in PARISHHUB: a
 * "Showing X–Y of Z" summary plus Previous / page numbers (with an ellipsis
 * once there are too many to list) / Next. $pagination is a paginate()
 * result; $baseUrl is the current page's URL WITHOUT a `page` query param —
 * any other filters (search, status, date, ...) should already be included
 * in it so they carry over when a page link is followed.
 */
function renderPagination(array $pagination, string $baseUrl): string
{
    $page = $pagination['page'];
    $totalPages = $pagination['totalPages'];
    $totalRows = $pagination['totalRows'];
    $perPage = $pagination['perPage'];

    if ($totalRows <= 0) {
        return '';
    }

    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $link = fn(int $p): string => e($baseUrl . $sep . 'page=' . $p);

    $first = ($page - 1) * $perPage + 1;
    $last = min($page * $perPage, $totalRows);
    $summary = "Showing {$first}–{$last} of {$totalRows}";

    if ($totalPages <= 1) {
        return '<div class="pagination"><span class="pagination-summary">' . e($summary) . '</span></div>';
    }

    $prevTag = $page <= 1
        ? '<span class="pagination-btn is-disabled">&laquo; Previous</span>'
        : '<a href="' . $link($page - 1) . '" class="pagination-btn">&laquo; Previous</a>';
    $nextTag = $page >= $totalPages
        ? '<span class="pagination-btn is-disabled">Next &raquo;</span>'
        : '<a href="' . $link($page + 1) . '" class="pagination-btn">Next &raquo;</a>';

    // Page numbers: always the first and last page, plus one on each side of
    // the current page — everything else collapses into a single ellipsis so
    // a huge result set never prints dozens of page links.
    $numbers = [];
    for ($p = 1; $p <= $totalPages; $p++) {
        if ($p === 1 || $p === $totalPages || abs($p - $page) <= 1) {
            $numbers[] = $p;
        } elseif (end($numbers) !== '…') {
            $numbers[] = '…';
        }
    }
    $numberTags = '';
    foreach ($numbers as $n) {
        if ($n === '…') {
            $numberTags .= '<span class="pagination-ellipsis">…</span>';
        } elseif ($n === $page) {
            $numberTags .= '<span class="pagination-btn is-current" aria-current="page">' . $n . '</span>';
        } else {
            $numberTags .= '<a href="' . $link($n) . '" class="pagination-btn">' . $n . '</a>';
        }
    }

    return <<<HTML
    <div class="pagination">
      <span class="pagination-summary">{$summary}</span>
      <div class="pagination-controls">
        {$prevTag}
        {$numberTags}
        {$nextTag}
      </div>
    </div>
    HTML;
}

/** Public announcement badge choices (announcements.category; NULL is shown as "Announcement"). */
const ANNOUNCEMENT_CATEGORIES = ['Announcement', 'Parish News', 'Event', 'Project', 'Donation', 'Mass', 'Community', 'Important'];

/** A category from a form post, or NULL when blank/unknown (never trust the raw value). */
function normalizeAnnouncementCategory(?string $value): ?string
{
    return in_array($value, ANNOUNCEMENT_CATEGORIES, true) ? $value : null;
}

/** Short plain-text preview of an announcement for its card: whitespace collapsed, cut at a word boundary. */
function announcementExcerpt(string $content, int $limit = 110): string
{
    $flat = trim(preg_replace('/\s+/u', ' ', $content));
    if (mb_strlen($flat) <= $limit) {
        return $flat;
    }
    $cut = mb_substr($flat, 0, $limit);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }
    return rtrim($cut, " ,.;:-–—") . '…';
}

/**
 * Upcoming / Active / Expired status for an announcement, derived from its
 * start_date/end_date rather than stored — always correct, never stale.
 * An announcement with no dates set (legacy rows, or "no expiry") is always
 * Active, preserving today's behavior for anything not using the new
 * duration feature.
 */
function announcementStatus(?string $startDate, ?string $endDate): string
{
    $today = date('Y-m-d');
    if ($startDate && $startDate > $today) {
        return 'Upcoming';
    }
    if ($endDate && $endDate < $today) {
        return 'Expired';
    }
    return 'Active';
}

/**
 * Marks a pending payment verified, issues an official receipt, confirms
 * the appointment, notifies the parishioner, and (for donations) refreshes
 * the weekly donor announcement — the single canonical "a payment just
 * cleared" path, used by both the treasurer's manual verification action
 * and the automated PayMongo reconciliation, so an online payment flows
 * into the exact same transaction/reporting trail as a manually-verified one.
 *
 * @param int|null $verifiedByUserId NULL for a system/gateway-driven verification (no human staff involved).
 * @return array{ok: bool, message: string, receipt_number: ?string}
 */
function verifyPaymentAndIssueReceipt(int $paymentId, ?int $verifiedByUserId, string $referenceNumber = ''): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare(
            "UPDATE payments SET payment_status='verified', reference_number=?, verified_by=?, verified_at=NOW()
             WHERE payment_id=? AND payment_status='pending'"
        );
        $updateStmt->execute([$referenceNumber, $verifiedByUserId, $paymentId]);
        if ($updateStmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Payment already processed or not found.', 'receipt_number' => null];
        }

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE payment_id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        $pdo->prepare("UPDATE appointments SET status_id = 4 WHERE appointment_id = ?")->execute([$payment['appointment_id']]);

        $receiptNumber = 'OR-' . date('Y') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO official_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)")
            ->execute([$paymentId, $receiptNumber, $verifiedByUserId]);

        $stmt = $pdo->prepare('SELECT parishioner_id FROM appointments WHERE appointment_id = ?');
        $stmt->execute([$payment['appointment_id']]);
        $parId = $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT user_id FROM parishioners WHERE parishioner_id = ?');
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();

        $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'payment', 'Payment Verified', ?)")
            ->execute([$puid, "Your payment (Ref: {$payment['reference_number']}) has been verified. Official Receipt $receiptNumber issued."]);

        $stmt = $pdo->prepare(
            "SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$payment['appointment_id']]);
        $isDonation = $stmt->fetchColumn() === 'Donation';

        $pdo->commit();
        if ($isDonation) {
            syncWeeklyDonationAnnouncement($verifiedByUserId ?? $puid);
        }
        return ['ok' => true, 'message' => "Payment verified. Receipt $receiptNumber generated.", 'receipt_number' => $receiptNumber];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        return ['ok' => false, 'message' => 'Failed to verify payment.', 'receipt_number' => null];
    }
}

/** Marks a still-pending payment as failed or cancelled (e.g. a PayMongo checkout that didn't complete). No-op if already resolved. */
function markPaymentUnsuccessful(int $paymentId, string $status): void
{
    db()->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ? AND payment_status = 'pending'")
        ->execute([$status, $paymentId]);
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

/**
 * True only for THIS week's donor announcement (exact date range match),
 * as opposed to a prior week's leftover row. Only the current week's card
 * should be clickable — its donor-list modal is only ever built from
 * getCurrentWeekDonors(), so an older card would open an empty/wrong modal.
 */
function isCurrentWeeklyDonorAnnouncement(string $title): bool
{
    return $title === weeklyDonorAnnouncementTitle();
}

/** Every Donation payment verified during the current (Monday-start) week, most recent first. */
function getCurrentWeekDonors(): array
{
    $week = currentWeekBounds();
    // verified_at is stored as a UTC timestamptz, but "this week" is computed
    // in PHP's Asia/Manila clock (see config.php) — convert before taking the
    // date, or a payment verified after midnight Manila time but before the
    // UTC day rolls over would still look like it belongs to the prior week.
    $stmt = db()->prepare(
        "SELECT d.donor_name, d.purpose, p.amount, p.verified_at
         FROM payments p
         JOIN appointments a ON p.appointment_id = a.appointment_id
         JOIN services s ON a.service_id = s.service_id
         JOIN donations d ON d.appointment_id = a.appointment_id
         WHERE s.category = 'Donation' AND p.payment_status = 'verified'
           AND DATE(p.verified_at AT TIME ZONE 'Asia/Manila') BETWEEN ? AND ?
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
                "INSERT INTO announcements (title, content, posted_by, is_pinned, status, category) VALUES (?, ?, ?, FALSE, 'published', 'Donation')"
            )->execute([$title, $content, $postedByUserId]);
        }
    } catch (Throwable $e) {
        error_log('Weekly donation announcement sync error: ' . $e->getMessage());
    }
}
