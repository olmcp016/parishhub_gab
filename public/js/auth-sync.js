/**
 * PARISHHUB — Multi-tab auth sync.
 *
 * The PHP session cookie (path=/) is already shared by every tab in this
 * browser, so a sibling tab reloading always sees the correct, up-to-date
 * session — it just doesn't know *when* to reload. This script's only job
 * is to tell it when: it broadcasts this tab's auth snapshot through
 * localStorage on every load, and reloads this tab the moment a sibling
 * tab's snapshot no longer matches its own (another login, logout, or a
 * different account/role signing in). The server-side guestOnly() /
 * requireLogin() / requireRole() checks that already run on every page
 * then take it from there — no role→URL mapping is duplicated here.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'parishhub_auth_state';
  var state = window.PARISHHUB_AUTH || { loggedIn: false, userId: null, role: null };
  var baseUrl = window.PARISHHUB_BASE_URL || '/';
  var reloading = false;

  function readBroadcast() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function writeBroadcast() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        loggedIn: state.loggedIn,
        userId: state.userId,
        role: state.role,
        timestamp: Date.now(),
      }));
    } catch (e) {
      // Private browsing / storage disabled / quota exceeded — sync is
      // simply unavailable for this tab, nothing else to do about it.
    }
  }

  function statesDiffer(remote) {
    if (!remote) return false;
    return remote.loggedIn !== state.loggedIn
      || remote.userId !== state.userId
      || remote.role !== state.role;
  }

  // The current session cookie is already shared with this tab, so a plain
  // reload is enough to land it on whatever this now-stale tab should show.
  function reloadOnceStale() {
    if (reloading) return;
    reloading = true;
    window.location.reload();
  }

  // Announce this tab's own state so sibling tabs pick it up immediately.
  writeBroadcast();

  window.addEventListener('storage', function (e) {
    if (e.key !== STORAGE_KEY || !e.newValue) return;
    var remote;
    try {
      remote = JSON.parse(e.newValue);
    } catch (err) {
      return;
    }
    if (statesDiffer(remote)) reloadOnceStale();
  });

  // Catches two things a storage event can miss: a session that simply timed
  // out while this tab sat idle, and a sibling-tab change that happened
  // while this tab was backgrounded/bfcached and never dispatched the event.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible' || reloading) return;

    var remote = readBroadcast();
    if (statesDiffer(remote)) {
      reloadOnceStale();
      return;
    }

    fetch(baseUrl + 'auth/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (server) {
        if (statesDiffer(server)) reloadOnceStale();
      })
      .catch(function () {
        // Network hiccup — next focus/storage event will retry.
      });
  });
})();
