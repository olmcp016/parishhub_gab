<?php
/* Chatbot is available everywhere, logged in or not — chatbot.php already
   supports an anonymous session (user_id = null) via $_SESSION['chat_session_id'].

   window.PARISH_DATA is hydrated once per page load, straight from the live
   Supabase database, so the assistant on the client can answer instantly
   (fees, requirements, hours, schedule, priests) without waiting on a
   network round-trip for every message. chatbot.js still calls chatbot.php
   in the background purely to log the exchange. */
require_once __DIR__ . '/functions.php';

$__chatSettings = [];
$__chatServiceRows = [];
$__chatPriestRows = [];
try {
    foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
        $__chatSettings[$row['setting_key']] = $row['setting_value'];
    }
    $__chatServiceRows = db()->query(
        'SELECT service_name, category, fee, requirements FROM services WHERE is_active=TRUE ORDER BY category, service_name'
    )->fetchAll();
    $__chatPriestRows = db()->query(
        "SELECT full_name, title, specialization FROM priests WHERE status='active'"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Chatbot hydration error: ' . $e->getMessage());
}

$__chatData = [
    'parish' => [
        'name'    => $__chatSettings['parish_name'] ?? 'the parish',
        'address' => $__chatSettings['parish_address'] ?? null,
        'phone'   => $__chatSettings['contact_number'] ?? null,
        'email'   => $__chatSettings['contact_email'] ?? null,
    ],
    'office_hours'  => $__chatSettings['office_hours'] ?? null,
    'mass_schedule' => $__chatSettings['mass_schedule'] ?? null,
    'services' => array_map(static fn($s) => [
        'name'         => $s['service_name'],
        'category'     => $s['category'],
        'fee'          => (float) $s['fee'],
        'requirements' => $s['requirements'],
    ], $__chatServiceRows),
    'priests' => array_map(static fn($p) => [
        'name'           => trim(($p['title'] ?: 'Rev. Fr.') . ' ' . $p['full_name']),
        'specialization' => $p['specialization'],
    ], $__chatPriestRows),
];
?>
<!-- ===================== Chatbot ===================== -->
<button class="chat-fab" id="chatFab" aria-label="Open parish assistant chat" aria-expanded="false">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
</button>

<div class="chat-panel" id="chatPanel" role="dialog" aria-label="Parish assistant chat">
  <div class="chat-head">
    <div class="avatar">⛪</div>
    <div>
      <strong>Parish Assistant</strong>
      <span>Usually replies instantly</span>
    </div>
    <button class="chat-close" id="chatClose" type="button" aria-label="Close chat">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="chat-body" id="chatBody"></div>
  <div class="suggested" id="chatSuggested"></div>
  <form class="chat-input" id="chatForm">
    <input type="text" id="chatInput" placeholder="Ask about requirements, fees, hours…" autocomplete="off">
    <button type="submit" aria-label="Send message">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2 15 22l-4-9-9-4 20-7z"/></svg>
    </button>
  </form>
</div>

<script>
  window.PARISHHUB_BASE_URL = "<?= url('') ?>";
  window.PARISH_DATA = <?php echo json_encode($__chatData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?= url('public/js/app.js') ?>"></script>
<script src="<?= url('public/js/chatbot.js') ?>"></script>
</body>
</html>
