<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['chat_session_id'];
$userId = isLoggedIn() ? currentUser()['user_id'] : null;

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
// The chat widget answers instantly on the client from hydrated data
// (window.PARISH_DATA) and calls this endpoint in the background only to
// log the exchange. When it hands us the reply it already showed, log that
// verbatim instead of recomputing it, so the log matches what the visitor saw.
$clientReply = trim($input['reply'] ?? '');

function getSetting(string $key): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

$intents = [
    'mass_schedule' => [
        'keywords' => ['mass schedule', 'schedule of mass', 'what time is mass', 'mass time'],
        'respond' => function () {
            $val = getSetting('mass_schedule');
            return 'Here is our Mass Schedule: ' . ($val ?: 'Weekdays: 6:00 AM & 6:00 PM | Sunday: 6AM, 8AM, 10AM, 4PM, 6PM');
        },
    ],
    'office_hours' => [
        'keywords' => ['office hours', 'open', 'what time do you open', 'when are you open'],
        'respond' => function () {
            $val = getSetting('office_hours');
            return 'Our parish office hours are: ' . ($val ?: 'Mon-Sat: 8:00 AM - 5:00 PM');
        },
    ],
    'requirements' => [
        // baptism/binyag, confirmation/kumpil, matrimony/kasal, burial/libing, etc.
        'keywords' => ['requirement', 'requirements', 'what do i need', 'documents needed', 'binyag', 'kumpil', 'kasal', 'libing'],
        'respond' => function () {
            $rows = db()->query('SELECT service_name, requirements FROM services WHERE is_active=TRUE')->fetchAll();
            $lines = array_map(fn($r) => "• {$r['service_name']}: " . ($r['requirements'] ?: 'No specific requirements'), $rows);
            return "Here are the requirements per service:\n" . implode("\n", $lines);
        },
    ],
    'fees' => [
        'keywords' => ['fee', 'fees', 'price', 'cost', 'how much', 'magkano'],
        'respond' => function () {
            $rows = db()->query('SELECT service_name, fee FROM services WHERE is_active=TRUE ORDER BY category')->fetchAll();
            $lines = array_map(fn($r) => "• {$r['service_name']}: " . money((float)$r['fee']), $rows);
            return "Here are our current service fees:\n" . implode("\n", $lines);
        },
    ],
    'priests' => [
        'keywords' => ['priest', 'father', 'who are the priests', 'anointing', 'last rites'],
        'respond' => function () {
            $rows = db()->query("SELECT full_name, title FROM priests WHERE status='active'")->fetchAll();
            $lines = array_map(fn($r) => "• {$r['title']} {$r['full_name']}", $rows);
            return "Our parish priests:\n" . implode("\n", $lines);
        },
    ],
    'contact' => [
        'keywords' => ['contact', 'phone number', 'email address', 'get in touch'],
        'respond' => function () {
            $phone = getSetting('contact_number');
            $email = getSetting('contact_email');
            return 'You can reach us at ' . ($phone ?: '(02) 8123-4567') . ' or ' . ($email ?: 'parishoffice@parishhub.local');
        },
    ],
    'directions' => [
        'keywords' => ['direction', 'location', 'address', 'where are you located', 'how to get there'],
        'respond' => function () {
            $val = getSetting('parish_address');
            return 'We are located at: ' . ($val ?: '123 Sampaguita St., Quezon City, Metro Manila');
        },
    ],
    'booking' => [
        'keywords' => ['book', 'appointment', 'reserve', 'how to book'],
        'respond' => fn() => 'To book: log in to your account, go to "Book Appointment," choose a service, fill up the form, upload requirements, pick a schedule, and submit. Our secretary will review your request.',
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

$matched = matchIntent($message, $intents);
$intentKey = $matched ? $matched[0] : null;

if ($clientReply !== '') {
    // Client already answered instantly from hydrated PARISH_DATA — log that
    // exact text rather than recomputing (possibly divergent) copy here.
    $reply = $clientReply;
} elseif ($matched) {
    $reply = $matched[1]['respond']();
} else {
    $reply = "I'm not sure about that yet. You can ask me about: Mass Schedule, Office Hours, Requirements, Fees, Priests, Directions, Contact Information, or How to Book an Appointment. For anything else, please contact the parish office directly.";
}

try {
    db()->prepare("INSERT INTO chat_messages (user_id, session_id, sender, message, intent) VALUES (?, ?, 'user', ?, ?)")
        ->execute([$userId, $sessionId, $message, $intentKey]);
    db()->prepare("INSERT INTO chat_messages (user_id, session_id, sender, message, intent) VALUES (?, ?, 'bot', ?, ?)")
        ->execute([$userId, $sessionId, $reply, $intentKey]);
} catch (Throwable $e) {
    error_log('Chat log error: ' . $e->getMessage());
}

echo json_encode(['reply' => $reply]);
