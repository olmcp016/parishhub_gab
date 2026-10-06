<?php
/* Menu-driven Parish Assistant — public pages only.
   Self-guards: renders nothing if a user is already logged in. */
if (function_exists('isLoggedIn') && isLoggedIn()) return;

$__chatSettings = [];
$__chatServices = [];
try {
    foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) {
        $__chatSettings[$r['setting_key']] = $r['setting_value'];
    }
    $__chatServices = db()->query(
        'SELECT service_name, category, fee, requirements FROM services WHERE is_active=TRUE ORDER BY category, service_name'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Public chatbot data error: ' . $e->getMessage());
}

$__menuData = [
    'parish_name'   => $__chatSettings['parish_name']   ?? 'the parish',
    'office_hours'  => $__chatSettings['office_hours']  ?? null,
    'mass_schedule' => $__chatSettings['mass_schedule'] ?? null,
    'address'       => $__chatSettings['parish_address'] ?? null,
    'phone'         => $__chatSettings['contact_number'] ?? null,
    'email'         => $__chatSettings['contact_email']  ?? null,
    'services'      => array_map(static fn($s) => [
        'name'         => $s['service_name'],
        'category'     => $s['category'],
        'fee'          => (float) $s['fee'],
        'requirements' => $s['requirements'] ?? '',
    ], $__chatServices),
];
?>

<!-- ===== Public Menu-Driven Chatbot ===== -->
<button class="pchat-fab" id="pchatFab" aria-label="Open parish assistant" aria-expanded="false">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
  </svg>
</button>

<div class="pchat-panel" id="pchatPanel" role="dialog" aria-label="Parish assistant">
  <div class="pchat-head">
    <div class="pchat-avatar">⛪</div>
    <div>
      <strong><?= htmlspecialchars($__menuData['parish_name'], ENT_QUOTES) ?></strong>
      <span>Tap a topic to get started</span>
    </div>
    <button class="pchat-close" id="pchatClose" type="button" aria-label="Close chat">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="pchat-body" id="pchatBody"></div>
</div>

<script>
(function () {
  const data = <?php echo json_encode($__menuData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const fab    = document.getElementById('pchatFab');
  const panel  = document.getElementById('pchatPanel');
  const close  = document.getElementById('pchatClose');
  const body   = document.getElementById('pchatBody');
  if (!fab || !panel || !body) return;

  let opened = false;

  /* Unique categories from services table */
  const categories = [...new Set((data.services || []).map(function(s){ return s.category; }))];

  /* ── Helpers ────────────────────────────────────────────── */
  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function peso(n) {
    return '₱' + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
  function render(html) {
    body.innerHTML = html;
    body.scrollTop = 0;
  }
  function backBtn(label, action) {
    return '<button class="pchat-btn pchat-back" data-action="' + esc(action) + '">' + label + '</button>';
  }
  function mainMenuBtns() {
    return '<div class="pchat-menu">'
      + '<button class="pchat-btn" data-action="mass">📅&nbsp; Mass Schedules</button>'
      + '<button class="pchat-btn" data-action="hours">🏢&nbsp; Office Hours</button>'
      + '<button class="pchat-btn" data-action="location">📍&nbsp; Our Location</button>'
      + '<button class="pchat-btn" data-action="fees">⛪&nbsp; Services &amp; Fees</button>'
      + '<button class="pchat-btn" data-action="requirements">📋&nbsp; Requirements</button>'
      + '<button class="pchat-btn" data-action="contact">📞&nbsp; Contact Us</button>'
      + '<button class="pchat-btn" data-action="booking">🗓️&nbsp; How to Book</button>'
      + '</div>';
  }

  /* ── Screens ────────────────────────────────────────────── */
  function showMain() {
    render('<div class="pchat-greeting">👋 Hi! I\'m your Parish Assistant.<br>What would you like to know?</div>'
      + mainMenuBtns());
  }

  function showMass() {
    var val = data.mass_schedule || 'Please contact the parish office for the current Mass schedule.';
    render(answerBlock('📅 Mass Schedules', '<p>' + esc(val) + '</p>')
      + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showHours() {
    var val = data.office_hours || 'Please contact the parish office for current office hours.';
    render(answerBlock('🏢 Office Hours', '<p>' + esc(val) + '</p>')
      + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showLocation() {
    var val = data.address || 'Please contact the parish office for our address.';
    render(answerBlock('📍 Our Location', '<p>' + esc(val) + '</p>')
      + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showContact() {
    var html = '';
    if (data.phone) html += '<p>📱 <strong>' + esc(data.phone) + '</strong></p>';
    if (data.email) html += '<p>✉️ <strong>' + esc(data.email) + '</strong></p>';
    if (!html) html = '<p>Please visit us at the parish office.</p>';
    render(answerBlock('📞 Contact Us', html)
      + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showFees() {
    if (!categories.length) {
      render(answerBlock('⛪ Services & Fees', '<p>No services are listed yet. Please contact the parish office.</p>')
        + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
      return;
    }
    var btns = categories.map(function(c) {
      return '<button class="pchat-btn" data-action="fees:' + esc(c) + '">' + esc(c) + '</button>';
    }).join('');
    render('<div class="pchat-prompt">⛪ Select a service category to see fees:</div>'
      + '<div class="pchat-menu">' + btns + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showFeesFor(category) {
    var svcs = (data.services || []).filter(function(s){ return s.category === category; });
    var rows = svcs.map(function(s) {
      return '<div class="pchat-row"><span>' + esc(s.name) + '</span>'
        + '<strong>' + (s.fee > 0 ? peso(s.fee) : 'Free') + '</strong></div>';
    }).join('');
    render(answerBlock('⛪ ' + esc(category) + ' — Fees', rows || '<p>No fee information on file.</p>')
      + '<div class="pchat-menu">'
      + backBtn('← Back to Services', 'fees')
      + backBtn('← Main Menu', 'main')
      + '</div>');
  }

  function showRequirements() {
    if (!categories.length) {
      render(answerBlock('📋 Requirements', '<p>No services are listed yet. Please contact the parish office.</p>')
        + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
      return;
    }
    var btns = categories.map(function(c) {
      return '<button class="pchat-btn" data-action="reqs:' + esc(c) + '">' + esc(c) + '</button>';
    }).join('');
    render('<div class="pchat-prompt">📋 Select a service to see requirements:</div>'
      + '<div class="pchat-menu">' + btns + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function showReqsFor(category) {
    var svcs = (data.services || []).filter(function(s){ return s.category === category; });
    var rows = svcs.map(function(s) {
      return '<div class="pchat-req">'
        + '<div class="pchat-req-name">' + esc(s.name) + '</div>'
        + '<div class="pchat-req-text">' + esc(s.requirements || 'No specific requirements on file. Please contact the office to confirm.') + '</div>'
        + '</div>';
    }).join('');
    render(answerBlock('📋 ' + esc(category) + ' — Requirements', rows || '<p>No requirements on file.</p>')
      + '<div class="pchat-menu">'
      + backBtn('← Back to Requirements', 'requirements')
      + backBtn('← Main Menu', 'main')
      + '</div>');
  }

  function showBooking() {
    var steps = ['Create an account or log in.',
      'Go to <strong>Book Appointment</strong>.',
      'Choose the sacrament or service you need.',
      'Fill out the form and upload the required documents.',
      'Pick your preferred date and time.',
      'Submit — the secretary will review and confirm your appointment.'];
    var html = '<ol class="pchat-steps">' + steps.map(function(s){ return '<li>' + s + '</li>'; }).join('') + '</ol>';
    render(answerBlock('🗓️ How to Book an Appointment', html)
      + '<div class="pchat-menu">' + backBtn('← Back to Menu', 'main') + '</div>');
  }

  function answerBlock(label, content) {
    return '<div class="pchat-answer"><div class="pchat-answer-label">' + label + '</div>' + content + '</div>';
  }

  /* ── Event delegation ───────────────────────────────────── */
  body.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;
    if      (action === 'main')         showMain();
    else if (action === 'mass')         showMass();
    else if (action === 'hours')        showHours();
    else if (action === 'location')     showLocation();
    else if (action === 'contact')      showContact();
    else if (action === 'fees')         showFees();
    else if (action === 'requirements') showRequirements();
    else if (action === 'booking')      showBooking();
    else if (action.indexOf('fees:') === 0) showFeesFor(action.slice(5));
    else if (action.indexOf('reqs:') === 0) showReqsFor(action.slice(5));
  });

  /* ── Open / close ───────────────────────────────────────── */
  function openChat() {
    panel.classList.add('open');
    fab.setAttribute('aria-expanded', 'true');
    if (!opened) { opened = true; showMain(); }
  }
  function closeChat() {
    panel.classList.remove('open');
    fab.setAttribute('aria-expanded', 'false');
  }

  fab.addEventListener('click', function () {
    panel.classList.contains('open') ? closeChat() : openChat();
  });
  if (close) close.addEventListener('click', closeChat);
})();
</script>
