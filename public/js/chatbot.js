// PARISHHUB — Parish Assistant chatbot widget
// Ported from parishhub_abella's chatbot logic/interaction model, restyled
// with the gold/brown theme and wired to PARISHHUB's own hydrated data.
(function () {
  const data = window.PARISH_DATA || {};
  const base = (window.PARISHHUB_BASE_URL || '/').replace(/\/$/, '');

  const fab = document.getElementById('chatFab');
  const panel = document.getElementById('chatPanel');
  const closeBtn = document.getElementById('chatClose');
  const body = document.getElementById('chatBody');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const suggestedWrap = document.getElementById('chatSuggested');

  if (!fab || !panel || !form || !input || !body) return;

  const suggestions = [
    'Requirements for baptism?',
    'How much is confirmation?',
    'What are your office hours?',
    'Documents needed for burial?',
    'How do I book an appointment?',
  ];

  // Sacrament keyword map — recognizes both English and Tagalog terms and
  // maps them to the `category` values stored on the services table.
  const SERVICE_KEYWORDS = {
    baptism: ['baptism', 'baptismal', 'binyag'],
    confirmation: ['confirmation', 'kumpil'],
    wedding: ['wedding', 'matrimony', 'marry', 'marriage', 'kasal'],
    funeral: ['burial', 'funeral', 'death', 'libing'],
    mass_intention: ['mass intention', 'intention'],
    anointing: ['anointing', 'last rites', 'sick call', 'sick'],
  };
  const CATEGORY_BY_KEY = {
    baptism: 'Baptism',
    confirmation: 'Confirmation',
    wedding: 'Wedding',
    funeral: 'Funeral',
    mass_intention: 'Mass Intention',
  };

  function findService(text) {
    for (const key in SERVICE_KEYWORDS) {
      if (SERVICE_KEYWORDS[key].some((w) => text.includes(w))) return key;
    }
    return null;
  }

  function servicesForKey(key) {
    const category = CATEGORY_BY_KEY[key];
    if (!category) return [];
    return (data.services || []).filter((s) => s.category === category);
  }

  function peso(amount) {
    const n = Number(amount) || 0;
    return '₱' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function addBubble(text, who) {
    const b = document.createElement('div');
    b.className = 'bubble ' + who;
    b.textContent = text;
    body.appendChild(b);
    body.scrollTop = body.scrollHeight;
    return b;
  }

  function showTyping() {
    const t = document.createElement('div');
    t.className = 'typing';
    t.id = 'typingIndicator';
    t.innerHTML = '<span></span><span></span><span></span>';
    body.appendChild(t);
    body.scrollTop = body.scrollHeight;
  }
  function hideTyping() {
    const t = document.getElementById('typingIndicator');
    if (t) t.remove();
  }

  function reply(raw) {
    const text = raw.toLowerCase();
    const p = data.parish || {};
    const services = data.services || [];

    // fee / pricing
    if (/\bfee|how much|\bcost|\bprice/.test(text)) {
      const key = findService(text);
      if (key === 'anointing') {
        return `The Anointing of the Sick / Last Rites is offered as a pastoral service, free of charge — please call the office right away at ${p.phone || 'the parish office'} for urgent sick calls.`;
      }
      const matches = servicesForKey(key);
      if (matches.length === 1) {
        const s = matches[0];
        return s.fee > 0
          ? `${s.name} has an estimated fee of ${peso(s.fee)}. This can vary slightly depending on schedule and requirements.`
          : `${s.name} is offered free of charge, though a love offering is always welcome.`;
      }
      if (matches.length > 1) {
        return `Here are the fees for ${matches[0].category}:\n` + matches.map((s) => `• ${s.name}: ${peso(s.fee)}`).join('\n');
      }
      if (!services.length) return "I don't have our fee list handy right now — please check the Services section or contact the office.";
      return 'Here are our current service fees:\n' + services.map((s) => `• ${s.name}: ${peso(s.fee)}`).join('\n');
    }

    // requirements / documents
    if (/requirement|document|\bneed\b/.test(text)) {
      const key = findService(text);
      if (key === 'anointing') {
        return 'For Anointing of the Sick / Last Rites, no paperwork is required beforehand — just call the parish office and a priest will be sent as soon as possible.';
      }
      const matches = servicesForKey(key);
      if (matches.length) {
        return matches
          .map((s) => `For ${s.name}, please prepare: ${s.requirements || 'no specific requirements on file — contact the office to confirm.'}`)
          .join('\n');
      }
      return "Requirements depend on the sacrament — try asking something like 'requirements for baptism' or check the Services section below for the full list per service.";
    }

    // office hours / location
    if (/office hour|what time do you open|when are you open|\blocated\b|\baddress\b|\bwhere are you\b/.test(text) || (/\bopen\b/.test(text) && !text.includes('mass'))) {
      const hours = data.office_hours ? `Our parish office hours are: ${data.office_hours}.` : '';
      const address = p.address ? ` We're located at ${p.address}.` : '';
      return (hours + address).trim() || 'Please contact the parish office for our current hours and location.';
    }

    // mass schedule
    if (/mass schedule|schedule of mass|what time is mass|mass time/.test(text)) {
      return data.mass_schedule
        ? `Here is our Mass Schedule: ${data.mass_schedule}.`
        : 'Please check the parish bulletin or contact the office for our current Mass schedule.';
    }

    // priest availability
    if (/\bfather\b|\bfr\.|priest available|who are the priests/.test(text)) {
      const priests = data.priests || [];
      if (priests.length) {
        return 'Our parish priests: ' + priests.map((pr) => `${pr.name}${pr.specialization ? ` (${pr.specialization})` : ''}`).join(', ') +
          `. They're generally available during office hours for consultations; for confessions or urgent sick calls, contact the office at ${p.phone || 'the parish office'}.`;
      }
      return `Our parish priests are generally available during office hours for consultations. For confessions or urgent sick calls, please contact the office directly at ${p.phone || 'the parish office'}.`;
    }

    // contact info
    if (/\bcontact\b|phone number|email address|get in touch/.test(text)) {
      const parts = [];
      if (p.phone) parts.push(`Phone: ${p.phone}`);
      if (p.email) parts.push(`Email: ${p.email}`);
      const addr = p.address ? `You can reach us at ${p.address}. ` : '';
      return (addr + parts.join(' · ')).trim() || 'Please check our About page for contact details.';
    }

    // booking instructions
    if (/\bbook\b|appointment|\breserve\b|schedule an/.test(text)) {
      return 'To book: log in to your account, go to "Book Appointment," choose a service, fill up the form, upload requirements, pick a schedule, and submit. Our secretary will review your request.';
    }

    return "I can help with sacrament requirements, fees, office hours, Mass schedule, priest availability, or booking instructions — try one of the suggestions below, or rephrase your question.";
  }

  function logExchange(message, replyText) {
    fetch(base + '/chatbot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: message, reply: replyText }),
    }).catch(() => {});
  }

  function respondTo(text) {
    addBubble(text, 'user');
    showTyping();
    const answer = reply(text);
    const delay = 400 + Math.random() * 400; // 400ms–800ms, feels conversational
    setTimeout(() => {
      hideTyping();
      addBubble(answer, 'bot');
      logExchange(text, answer);
    }, delay);
  }

  function renderSuggestions() {
    suggestedWrap.innerHTML = '';
    suggestions.forEach((q) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'chip';
      chip.textContent = q;
      chip.addEventListener('click', () => respondTo(q));
      suggestedWrap.appendChild(chip);
    });
  }

  let started = false;
  function openChat() {
    panel.classList.add('open');
    fab.setAttribute('aria-expanded', 'true');
    if (!started) {
      started = true;
      const p = data.parish || {};
      addBubble(`Peace be with you! I'm the parish assistant for ${p.name || 'the parish'}. Ask me about sacrament requirements, fees, schedules, or how to book an appointment.`, 'bot');
      renderSuggestions();
    }
    input.focus();
  }
  function closeChat() {
    panel.classList.remove('open');
    fab.setAttribute('aria-expanded', 'false');
  }

  fab.addEventListener('click', () => {
    panel.classList.contains('open') ? closeChat() : openChat();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeChat);

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const val = input.value.trim();
    if (!val) return;
    input.value = '';
    respondTo(val);
  });
})();
