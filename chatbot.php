<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['chat_session_id'];
$user   = isLoggedIn() ? currentUser() : null;
$userId = $user ? (int) $user['user_id'] : null;

$input       = json_decode(file_get_contents('php://input'), true);
$message     = trim($input['message'] ?? '');
$clientReply = trim($input['reply'] ?? '');

if ($message === '') {
    echo json_encode(['reply' => '']);
    exit;
}

function getSetting(string $key): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

// ── Local intent handlers (fast-path, DB-hydrated) ────────────────────────────
// These mirror what chatbot.js answers client-side from PARISH_DATA. They act
// as a server-side safety net when the JS sends a message without a clientReply.

$intents = [
    'mass_schedule' => [
        'keywords' => ['mass schedule', 'schedule of mass', 'what time is mass', 'mass time'],
        'respond'  => function () {
            $val = getSetting('mass_schedule');
            return 'Here is our Mass Schedule: ' . ($val ?: 'Weekdays: 6:00 AM & 6:00 PM | Sunday: 6AM, 8AM, 10AM, 4PM, 6PM');
        },
    ],
    'office_hours' => [
        'keywords' => ['office hours', 'open', 'what time do you open', 'when are you open'],
        'respond'  => function () {
            $val = getSetting('office_hours');
            return 'Our parish office hours are: ' . ($val ?: 'Mon-Sat: 8:00 AM - 5:00 PM');
        },
    ],
    'requirements' => [
        'keywords' => ['requirement', 'requirements', 'what do i need', 'documents needed', 'binyag', 'kumpil', 'kasal', 'libing'],
        'respond'  => function () {
            $rows  = db()->query('SELECT service_name, requirements FROM services WHERE is_active=TRUE')->fetchAll();
            $lines = array_map(fn($r) => "• {$r['service_name']}: " . ($r['requirements'] ?: 'No specific requirements'), $rows);
            return "Here are the requirements per service:\n" . implode("\n", $lines);
        },
    ],
    'fees' => [
        'keywords' => ['fee', 'fees', 'price', 'cost', 'how much', 'magkano'],
        'respond'  => function () {
            $rows  = db()->query('SELECT service_name, fee FROM services WHERE is_active=TRUE ORDER BY category')->fetchAll();
            $lines = array_map(fn($r) => "• {$r['service_name']}: " . money((float) $r['fee']), $rows);
            return "Here are our current service fees:\n" . implode("\n", $lines);
        },
    ],
    'priests' => [
        'keywords' => ['priest', 'father', 'who are the priests', 'anointing', 'last rites'],
        'respond'  => function () {
            $rows  = db()->query("SELECT full_name, title FROM priests WHERE status='active'")->fetchAll();
            $lines = array_map(fn($r) => "• {$r['title']} {$r['full_name']}", $rows);
            return "Our parish priests:\n" . implode("\n", $lines);
        },
    ],
    'contact' => [
        'keywords' => ['contact', 'phone number', 'email address', 'get in touch'],
        'respond'  => function () {
            $phone = getSetting('contact_number');
            $email = getSetting('contact_email');
            return 'You can reach us at ' . ($phone ?: '(02) 8123-4567') . ' or ' . ($email ?: 'parishoffice@parishhub.local');
        },
    ],
    'directions' => [
        'keywords' => ['direction', 'location', 'address', 'where are you located', 'how to get there'],
        'respond'  => function () {
            $val = getSetting('parish_address');
            return 'We are located at: ' . ($val ?: '123 Sampaguita St., Quezon City, Metro Manila');
        },
    ],
    'booking' => [
        'keywords' => ['book', 'appointment', 'reserve', 'how to book'],
        'respond'  => fn() => 'To book: log in to your account, go to "Book Appointment," choose a service, fill up the form, upload requirements, pick a schedule, and submit. Our secretary will review your request.',
    ],
];

function matchIntent(string $text, array $intents): ?array
{
    $lower = mb_strtolower($text);
    foreach ($intents as $key => $intent) {
        foreach ($intent['keywords'] as $kw) {
            if (str_contains($lower, $kw)) {
                return [$key, $intent];
            }
        }
    }
    return null;
}

// ── Gemini integration ────────────────────────────────────────────────────────

function buildSystemPrompt(?array $user): string
{
    $settings = [];
    try {
        foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $serviceRows = db()->query(
            'SELECT service_name, category, fee, requirements FROM services WHERE is_active=TRUE ORDER BY category, service_name'
        )->fetchAll();
        $priestRows  = db()->query("SELECT full_name, title FROM priests WHERE status='active'")->fetchAll();
    } catch (Throwable $e) {
        error_log('Gemini prompt hydration error: ' . $e->getMessage());
        $serviceRows = [];
        $priestRows  = [];
    }

    $parishName = $settings['parish_name'] ?? 'our parish';
    $role       = $user ? ($user['role_name'] ?? 'Parishioner') : 'Guest';
    $firstName  = $user ? ($user['firstname'] ?? 'there') : 'there';

    $roleGuidance = match ($role) {
        'Secretary'          => 'They manage appointments on behalf of the parish. Help them with sacrament document requirements, scheduling rules, and appointment workflows.',
        'Treasurer', 'Admin' => 'They handle parish finances and/or system administration. Help them with payment verification, receipts, donation recording, fee queries, and any system question.',
        'Priest'             => 'They preside over parish sacraments. Help them understand scheduled appointments and sacrament requirements.',
        default              => 'Help them find information about services, fees, requirements, and how to book or track an appointment.',
    };

    $serviceLines = [];
    foreach ($serviceRows as $s) {
        $fee  = ((float) $s['fee']) > 0 ? '₱' . number_format((float) $s['fee'], 2) : 'Free';
        $req  = $s['requirements'] ? " Requirements: {$s['requirements']}." : '';
        $serviceLines[] = "- {$s['service_name']} ({$s['category']}): {$fee}.{$req}";
    }

    $priests = array_map(
        fn($p) => trim(($p['title'] ?: 'Rev. Fr.') . ' ' . $p['full_name']),
        $priestRows
    );

    $hours         = $settings['office_hours']   ?? 'Not specified';
    $massSchedule  = $settings['mass_schedule']  ?? 'Not specified';
    $address       = $settings['parish_address'] ?? 'Not listed';
    $phone         = $settings['contact_number'] ?? 'Not listed';
    $email         = $settings['contact_email']  ?? 'Not listed';
    $servicesBlock = $serviceLines ? implode("\n", $serviceLines) : 'No services listed.';
    $priestsBlock  = $priests      ? implode(', ', $priests)      : 'Not listed';

    return <<<PROMPT
You are the Parish Assistant for {$parishName}, a helpful and respectful AI chatbot embedded in the ParishHub parish management system.

Current user: {$role} — {$firstName}

Parish Information (answer ONLY from this data; do not fabricate):
  Office hours:   {$hours}
  Mass schedule:  {$massSchedule}
  Address:        {$address}
  Phone:          {$phone}
  Email:          {$email}
  Active priests: {$priestsBlock}

Services and fees:
{$servicesBlock}

Role guidance: {$roleGuidance}

Instructions:
- Be concise, warm, and respectful. Keep replies under 4 sentences unless listing items.
- Only answer questions about the parish, its services, sacraments, fees, requirements, schedules, or the ParishHub booking system.
- If the question is outside that scope, politely say you cannot help with that and suggest calling the parish office.
- Never fabricate fees, names, dates, or requirements not present in the data above.
- Recognize Filipino terms for sacraments: binyag=baptism, kumpil=confirmation, kasal=wedding, libing=burial/funeral.
PROMPT;
}

function callGemini(string $systemPrompt, array $history, string $userMessage): string
{
    $apiKey = GEMINI_API_KEY;
    if ($apiKey === '') {
        return "I'm sorry, the AI assistant is not configured yet. Please contact the parish office directly for assistance.";
    }

    $contents = [];
    foreach ($history as $msg) {
        $role       = $msg['sender'] === 'user' ? 'user' : 'model';
        $contents[] = ['role' => $role, 'parts' => [['text' => $msg['message']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => $contents,
        'generationConfig'   => ['temperature' => 0.7, 'maxOutputTokens' => 400],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . urlencode($apiKey);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $response === false) {
        error_log('Gemini cURL error: ' . $curlError);
        return "I'm having trouble connecting right now. Please try again in a moment, or contact the parish office directly.";
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('Gemini API error (HTTP ' . $httpCode . '): ' . substr((string) $response, 0, 500));
        // DEBUG — remove once the API key and model are confirmed working.
        return '[DEBUG] HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 600);
    }

    return trim($data['candidates'][0]['content']['parts'][0]['text']);
}

// ── Routing logic ─────────────────────────────────────────────────────────────

$matched   = matchIntent($message, $intents);
$intentKey = $matched ? $matched[0] : null;

if ($clientReply !== '') {
    // JS already answered locally — just log the exchange it pre-computed.
    $reply = $clientReply;
} elseif ($matched) {
    // Server-side intent match (safety net for when JS sends without a reply).
    $reply = $matched[1]['respond']();
} else {
    // No local match — forward to Gemini with conversation history.
    $history = [];
    try {
        $stmt = db()->prepare(
            "SELECT sender, message FROM chat_messages
             WHERE session_id = ? ORDER BY created_at DESC LIMIT 6"
        );
        $stmt->execute([$sessionId]);
        $history = array_reverse($stmt->fetchAll());
    } catch (Throwable $e) {
        error_log('Chat history fetch error: ' . $e->getMessage());
    }
    $reply = callGemini(buildSystemPrompt($user), $history, $message);
}

// ── Log the exchange ──────────────────────────────────────────────────────────

try {
    db()->prepare("INSERT INTO chat_messages (user_id, session_id, sender, message, intent) VALUES (?, ?, 'user', ?, ?)")
        ->execute([$userId, $sessionId, $message, $intentKey]);
    db()->prepare("INSERT INTO chat_messages (user_id, session_id, sender, message, intent) VALUES (?, ?, 'bot', ?, ?)")
        ->execute([$userId, $sessionId, $reply, $intentKey]);
} catch (Throwable $e) {
    error_log('Chat log error: ' . $e->getMessage());
}

echo json_encode(['reply' => $reply]);
