// PARISHHUB client-side behavior
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  }

  // Auto-hide flash alerts
  document.querySelectorAll('.alert').forEach((el) => {
    setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 4500);
  });

  // Chatbot widget
  const fab = document.getElementById('chatFab');
  const win = document.getElementById('chatWindow');
  const closeBtn = document.getElementById('chatClose');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const body = document.getElementById('chatBody');
  const base = window.PARISHHUB_BASE_URL || '/';

  if (fab && win) {
    fab.addEventListener('click', () => win.classList.toggle('open'));
    if (closeBtn) closeBtn.addEventListener('click', () => win.classList.remove('open'));

    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        appendMessage(text, 'user');
        input.value = '';

        try {
          const res = await fetch(base.replace(/\/$/, '') + '/chatbot.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text }),
          });
          const data = await res.json();
          appendMessage(data.reply, 'bot');
        } catch (err) {
          appendMessage('Sorry, something went wrong. Please try again.', 'bot');
        }
      });
    }
  }

  function appendMessage(text, sender) {
    const div = document.createElement('div');
    div.className = `chat-msg ${sender}`;
    div.textContent = text;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }
});
