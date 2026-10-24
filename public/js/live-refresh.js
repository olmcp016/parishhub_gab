/**
 * PARISHHUB — Lightweight background auto-refresh for dashboard stat cards
 * and list tables (Secretary + Treasurer/Cashier modules).
 *
 * Any element marked `data-live-refresh-id="X"` is kept in sync with the
 * server: every POLL_INTERVAL_MS — only while the tab is visible, nothing on
 * the page is actively being typed into, and no detail modal is open — the
 * current page URL (filters, search terms and pagination included, exactly
 * as the address bar shows it) is re-fetched in the background. The matching
 * `data-live-refresh-id="X"` element from the freshly parsed response then
 * replaces this one's contents in place, but only if the HTML actually
 * changed. Everything else on the page — the filter form, scroll position,
 * focus — is left completely alone; nothing here ever triggers a real
 * navigation or full-page reload.
 */
(function () {
  var POLL_INTERVAL_MS = 12000;
  var timer = null;
  var fetchInFlight = false;

  function liveRefreshTargets() {
    return document.querySelectorAll('[data-live-refresh-id]');
  }

  /** True while the user is actively typing/selecting in a form field anywhere on the page. */
  function isTypingAnywhere() {
    var active = document.activeElement;
    if (!active) return false;
    var tag = active.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag === 'INPUT') {
      var type = (active.type || 'text').toLowerCase();
      return ['text', 'search', 'email', 'number', 'tel', 'date', 'password', 'url'].indexOf(type) !== -1;
    }
    return false;
  }

  /** The shared Secretary/Treasurer "View/Review" modal (public/js/detail-modal.js) is open. */
  function isDetailModalOpen() {
    var modal = document.getElementById('detailModal');
    return !!(modal && modal.open);
  }

  function shouldSkipTick() {
    return document.hidden || fetchInFlight || isDetailModalOpen() || isTypingAnywhere();
  }

  function tick() {
    if (shouldSkipTick()) return;
    var targets = liveRefreshTargets();
    if (!targets.length) return;

    fetchInFlight = true;
    fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        fetchInFlight = false;
        // Re-check: the fetch may have taken a moment, and the user may have
        // started typing or opened the review modal while it was in flight.
        if (shouldSkipTick()) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        liveRefreshTargets().forEach(function (el) {
          var id = el.getAttribute('data-live-refresh-id');
          var fresh = doc.querySelector('[data-live-refresh-id="' + id + '"]');
          if (!fresh) return;
          if (fresh.innerHTML !== el.innerHTML) el.innerHTML = fresh.innerHTML;
        });
      })
      .catch(function () {
        fetchInFlight = false;
        // Silent — the next tick simply tries again.
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!liveRefreshTargets().length) return;
    timer = setInterval(tick, POLL_INTERVAL_MS);
    // Coming back to this tab after being away shouldn't wait out a full
    // interval before showing current data.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick();
    });
  });
})();
