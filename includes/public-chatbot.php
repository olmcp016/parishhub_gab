<?php
/* Parish Virtual Assistant — a rule-based guided FAQ (click-only, no AI) for guests and
   logged-in Parishioners; never shown to staff. Facts come from Admin Settings and the same
   tables/helpers the Services page uses, built here into one JSON object; the conversation
   itself is public/js/parish-assistant.js. Nothing private is read: booking questions are
   answered with links to Check Status / My Appointments. Helpers are closures, so this
   template defines nothing global. */
$__pchatUser = function_exists('currentUser') ? currentUser() : null;
if ($__pchatUser && ($__pchatUser['role_name'] ?? '') !== 'Parishioner') return;

require_once __DIR__ . '/service-fees.php';        // fee rules + precana.php + scheduling.php + functions.php
require_once __DIR__ . '/document-validation.php'; // the upload rules every booking enforces (no other includes)

/** A contact number that looks like a placeholder (1234567, 0000000, too short) is not shown. */
$__pchatPhone = static function (?string $phone): ?string {
    $phone = trim((string) $phone);
    $digits = (string) preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 7 || preg_match('/1234567|7654321|(\d)\1{6}/', $digits)) return null;
    return $phone;
};

/** An email on a reserved/test domain (.local, .test, example.com …) is not shown. */
$__pchatEmail = static function (?string $email): ?string {
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $domain = strtolower(substr($email, strrpos($email, '@') + 1));
    $tld = substr($domain, strrpos($domain, '.') + 1);
    if (in_array($tld, ['local', 'localhost', 'test', 'example', 'invalid', 'internal'], true)
        || preg_match('/(^|\.)example\.(com|net|org)$/', $domain)) return null;
    return $email;
};

/**
 * Office hours from Settings, unless they contradict the office's day off that bookings
 * enforce (isStaffDayOff(): Monday afternoon and all of Tuesday) — e.g. "Mon-Sat",
 * "Weekdays" or "Tuesday" without saying when the office is closed.
 */
$__pchatHours = static function (?string $hours): ?string {
    $hours = trim((string) $hours);
    if ($hours === '') return null;
    $text = strtolower($hours);
    if (str_contains($text, 'closed')) return $hours;
    if (preg_match('/\b(tue|tues|tuesday)\b|\b(daily|everyday|every day|weekdays?)\b/', $text)) return null;
    $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    if (preg_match_all('/\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\.?\s*(?:-|–|—|to|through|thru|until)\s*(sun|mon|tue|wed|thu|fri|sat)/u', $text, $ranges, PREG_SET_ORDER)) {
        foreach ($ranges as $r) {
            $from = array_search($r[1], $days, true);
            $to = array_search($r[2], $days, true);
            if ($from <= $to ? ($from <= 2 && 2 <= $to) : (2 >= $from || 2 <= $to)) return null;
        }
    }
    return $hours;
};

/** HTML policy text (schedulingPolicyText) as plain lines; list items start with "• ". */
$__pchatPlain = static function (string $html): string {
    $text = str_replace(['<li>', '<br>', '<br/>', '<br />', '</ul>'], ["\n• ", "\n", "\n", "\n", "\n"], $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    return trim((string) preg_replace("/[ \t]*\n[ \t\n]*/", "\n", $text));
};

/** An amount as the Services page fee tables show it. */
$__pchatFee = static fn(float $amount): string => $amount > 0 ? money($amount) : 'No Fees';

$__pchatBuild = static function (bool $isParishioner) use ($__pchatPhone, $__pchatEmail, $__pchatHours, $__pchatPlain, $__pchatFee): array {
    $settings = [];
    try {
        foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
    } catch (Throwable $e) {
        error_log('Parish assistant settings: ' . $e->getMessage());
    }
    $services = [];
    try {
        // The Services page's own list: active, Donation excluded.
        $services = db()->query("SELECT service_id, service_name, category, fee, requirements FROM services WHERE is_active = TRUE AND category <> 'Donation' ORDER BY category, service_name")->fetchAll();
    } catch (Throwable $e) {
        error_log('Parish assistant services: ' . $e->getMessage());
    }
    $rules = [];
    try {
        foreach (db()->query('SELECT * FROM service_fee_rules')->fetchAll() as $r) $rules[$r['service_category']][$r['schedule_type']][$r['pss_classification']] = $r;
    } catch (Throwable $e) {
        // Pricing table not deployed: those fees are then "please contact the parish office".
    }
    $precana = precanaAvailable();
    $ruleFacts = static fn(array $r): array => [
        'base' => $__pchatFee((float) $r['base_fee']),
        'stipend' => $__pchatFee((float) $r['priest_stipend']),
        'included' => (int) $r['included_sponsors'],
        'additional' => $__pchatFee((float) $r['additional_sponsor_fee']),
    ];
    $totalFacts = static fn(array $r): array => [
        'base' => $__pchatFee((float) $r['base_fee']),
        'stipend' => $__pchatFee((float) $r['priest_stipend']),
        'total' => $__pchatFee((float) $r['base_fee'] + (float) $r['priest_stipend']),
    ];

    $list = [];
    foreach ($services as $s) {
        $category = (string) $s['category'];
        $id = (int) $s['service_id'];

        // Fees — what the Services page shows: service_fee_rules for Baptism, Wedding and
        // Funeral (never the old services.fee), the card's fee for everything else.
        if (in_array($category, ['Baptism', 'Wedding'], true)) {
            $schedules = [];
            foreach (['Regular', 'Special'] as $type) {
                $pss = $rules[$category][$type]['pss'] ?? null;
                $non = $rules[$category][$type]['non_pss'] ?? null;
                if ($pss && $non) $schedules[] = ['name' => $type, 'pss' => $ruleFacts($pss), 'non_pss' => $ruleFacts($non)];
            }
            $fee = $schedules ? ['kind' => 'rules', 'schedules' => $schedules] : ['kind' => 'unknown'];
        } elseif ($category === 'Funeral') {
            $pss = $rules['Funeral']['Any']['pss'] ?? null;
            $non = $rules['Funeral']['Any']['non_pss'] ?? null;
            $fee = $pss && $non ? ['kind' => 'funeral', 'pss' => $totalFacts($pss), 'non_pss' => $totalFacts($non)] : ['kind' => 'unknown'];
        } elseif ($category === 'Anointing') {
            $fee = ['kind' => 'free'];
        } elseif ($category === 'Mass Intention') {
            $fee = ['kind' => 'offering'];
        } else {
            $fee = (float) $s['fee'] > 0 ? ['kind' => 'fixed', 'amount' => money((float) $s['fee'])] : ['kind' => 'voluntary'];
        }

        // Requirements — the booking form's own lists (parishioner/services.php renderRequirementsPreview()).
        $documents = [];
        $afterSeminar = [];
        // parseRequirementsList() is what weddingRequiredDocumentsFromCatalog() returns for Weddings too
        // (that helper lives in wedding-draft.php, which public pages do not load).
        foreach (parseRequirementsList($s['requirements']) as $label) {
            if ($label === 'Katin-awan sa Paglubong') continue; // a form, filled in online
            if ($category === 'Wedding' && $precana && precanaIsCertificateLabel($label)) { $afterSeminar[] = $label; continue; }
            $documents[] = $label;
        }
        $forms = match ($category) {
            'Wedding' => ['Marriage Requirement and Application Form', 'Katin-awan sa Kasal / Cluster Clearance', 'Wedding Sponsor Clearance'],
            'Baptism' => ['Katin-awan sa Bunyag', 'Cluster Clearance for Baptism Sponsor'],
            'Funeral' => ['Katin-awan sa Paglubong'],
            default => [],
        };

        // Scheduling — the booking form's policy text, else the Services card's line.
        $policy = '';
        try {
            $policy = $__pchatPlain(schedulingPolicyText($category, $id));
        } catch (Throwable $e) {
            error_log('Parish assistant policy: ' . $e->getMessage());
        }
        if ($policy === '') {
            $policy = $category === 'Wake' ? 'Propose a preferred date and time; the Parish Office confirms it.' : 'The schedule is arranged with the Parish Office.';
        }
        $regular = null;
        if (in_array($category, ['Baptism', 'Wedding'], true)) {
            try {
                $description = describeRegularSchedule($id);
                if (!str_starts_with($description, 'No Regular schedule')) $regular = $description;
            } catch (Throwable $e) {
                error_log('Parish assistant schedule: ' . $e->getMessage());
            }
        }

        $list[] = [
            'id' => $id,
            'name' => (string) $s['service_name'],
            'category' => $category,
            'fee' => $fee,
            'documents' => $documents,
            'afterSeminar' => $afterSeminar,
            'forms' => $forms,
            'policy' => $policy,
            'regular' => $regular,
        ];
    }

    $parishName = trim((string) ($settings['parish_name'] ?? ''));
    return [
        'parishName' => $parishName !== '' ? $parishName : null,
        'isParishioner' => $isParishioner,
        'links' => [
            'book' => url('parishioner/services.php'),
            'status' => $isParishioner ? url('parishioner/appointments.php') : url('status.php'),
            'statusLabel' => $isParishioner ? 'My Appointments' : 'Check Status',
        ],
        'contact' => [
            'phone' => $__pchatPhone($settings['contact_number'] ?? null),
            'email' => $__pchatEmail($settings['contact_email'] ?? null),
            'address' => trim((string) ($settings['parish_address'] ?? '')) ?: null,
        ],
        'officeHours' => $__pchatHours($settings['office_hours'] ?? null),
        // The day off every booking form enforces (isStaffDayOff()); Mass Intentions are the exception.
        'officeClosed' => 'The parish office is closed every Monday afternoon and all day Tuesday (staff day off).',
        'massSchedule' => trim((string) ($settings['mass_schedule'] ?? '')) ?: null,
        'services' => $list,
        'precana' => $precana ? [
            'regular' => OCCURRENCE_ORDINALS[PRECANA_REGULAR_OCCURRENCE] . ' Saturday of the month at ' . date('g:i A', strtotime(PRECANA_REGULAR_TIME)),
            'specialFee' => money(PRECANA_SPECIAL_FEE),
            'certificate' => PRECANA_CERTIFICATE_LABEL,
        ] : null,
        // validateUploadedFile(): these types, this size, and photos in portrait orientation.
        'uploads' => [
            'types' => implode(', ', array_map(static fn(string $mime): string => ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'][$mime] ?? strtoupper($mime), ALLOWED_DOCUMENT_MIME_TYPES)),
            'maxSize' => (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) . ' MB',
        ],
        'noPaymentLabel' => NO_PAYMENT_REQUIRED_LABEL,
    ];
};

$__pchatData = $__pchatBuild($__pchatUser !== null);
$__pchatTitle = $__pchatData['parishName'] ?? 'Parish Virtual Assistant';
?>

<!-- ===== Parish Virtual Assistant (guided FAQ) ===== -->
<button class="pchat-fab" id="pchatFab" type="button" aria-label="Open the Parish Virtual Assistant" aria-expanded="false" aria-controls="pchatPanel">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
  </svg>
</button>

<section class="pchat-panel" id="pchatPanel" role="dialog" aria-modal="false" aria-labelledby="pchatTitle" hidden>
  <div class="pchat-head">
    <div class="pchat-avatar" aria-hidden="true">⛪</div>
    <div class="pchat-head-text">
      <strong id="pchatTitle"><?= htmlspecialchars($__pchatTitle, ENT_QUOTES) ?></strong>
      <span>Parish Virtual Assistant</span>
    </div>
    <button class="pchat-restart" id="pchatRestart" type="button">Start Over</button>
    <button class="pchat-close" id="pchatClose" type="button" aria-label="Close the assistant">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="pchat-body" id="pchatBody">
    <div class="pchat-log" id="pchatLog" role="log" aria-live="polite" aria-label="Conversation"></div>
    <div class="pchat-choices" id="pchatChoices" role="group" aria-label="Suggested questions"></div>
  </div>
  <nav class="pchat-actions" aria-label="Quick actions">
    <a class="pchat-action" href="<?= htmlspecialchars($__pchatData['links']['book'], ENT_QUOTES) ?>">Book a Service</a>
    <a class="pchat-action" href="<?= htmlspecialchars($__pchatData['links']['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($__pchatData['links']['statusLabel'], ENT_QUOTES) ?></a>
    <button class="pchat-action" id="pchatMenu" type="button">Main Menu</button>
  </nav>
</section>
<script type="application/json" id="pchatData"><?= json_encode($__pchatData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= url('public/js/parish-assistant.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/parish-assistant.js') ?>" defer></script>
