/**
 * PARISHHUB — notification bell (includes/topbar.php), for the Secretary and
 * the Parishioner. Polls the feed in data-feed-url (secretary/ or
 * parishioner/notifications-feed.php) every 30 seconds and when the panel
 * opens, so new notifications appear without a page refresh. Clicking a
 * notification opens its appointment, which then marks it read.
 */
(function () {
  var root = document.getElementById('notifBell');
  if (!root) return;
  var button = document.getElementById('notifBellButton');
  var panel = document.getElementById('notifBellPanel');
  var list = document.getElementById('notifBellList');
  var badge = document.getElementById('notifBellBadge');
  var feedUrl = root.dataset.feedUrl;
  var csrf = root.dataset.csrf;
  var POLL_MS = 30000;

  function render(data) {
    var unread = data.unread || 0;
    badge.textContent = unread > 99 ? '99+' : String(unread);
    badge.hidden = unread === 0;
    list.innerHTML = '';
    if (!data.items || !data.items.length) {
      var empty = document.createElement('p');
      empty.className = 'text-muted notif-bell-empty';
      empty.textContent = 'No notifications yet.';
      list.appendChild(empty);
      return;
    }
    data.items.forEach(function (item) {
      var link = document.createElement('a');
      link.className = 'notif-bell-item' + (item.is_read ? '' : ' is-unread');
      link.href = item.url;
      link.dataset.id = item.id;
      var title = document.createElement('strong'); title.textContent = item.title;
      var message = document.createElement('span'); message.className = 'notif-bell-message'; message.textContent = item.message;
      var time = document.createElement('span'); time.className = 'notif-bell-time'; time.textContent = item.time + (item.is_read ? '' : ' · Unread');
      link.appendChild(title); link.appendChild(message); link.appendChild(time);
      list.appendChild(link);
    });
  }

  function request(method, body) {
    var options = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) { body.append('csrf_token', csrf); options.body = body; }
    return fetch(feedUrl, options).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  function refresh() {
    return request('GET').then(render).catch(function () { /* keep the last state; try again next poll */ });
  }

  button.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = panel.hidden;
    panel.hidden = !open;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) refresh();
  });
  document.addEventListener('click', function (e) {
    if (!panel.hidden && !root.contains(e.target)) { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); button.focus(); }
  });

  document.getElementById('notifBellReadAll').addEventListener('click', function (e) {
    e.stopPropagation();
    var body = new FormData(); body.append('action', 'read_all');
    request('POST', body).then(render).catch(function () {});
  });

  // Clicking a notification just follows its link to the appointment; the
  // appointment page marks it read only after it has actually loaded
  // (secretary/appointment-detail.php?notification=…).

  setInterval(function () { if (!document.hidden) refresh(); }, POLL_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
