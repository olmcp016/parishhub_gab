/**
 * PARISHHUB — Secretary "View/Review" pop-up modal behavior.
 *
 * Used by secretary/parishioners.php, secretary/mass-intentions.php, and
 * secretary/appointments.php: clicking a View/Review link no longer
 * navigates to a separate page — it fetches that same page's content as
 * a fragment (the page detects this via the X-Requested-With header, see
 * isDetailModalRequest() in includes/functions.php) and shows it inside
 * the shared #detailModal dialog. The link's real href still works as a
 * plain-navigation fallback if JS fails.
 *
 * Any form submitted from inside the modal (approve/reject/assign
 * priest/reschedule/etc.) posts via AJAX to that same page, which
 * responds with JSON instead of redirecting — same validation and
 * business logic either way, only the response transport differs. The
 * modal content is then refreshed in place to show the new status. The
 * underlying list page is only reloaded (once, on close) if something
 * actually changed, so opening a modal just to look never causes a
 * reload, and search/filter/pagination state survives because reloading
 * re-requests the exact same URL.
 */
(function () {
  var modal, modalBody, modalTitle;
  var dirty = false;

  function refs() {
    modal = document.getElementById('detailModal');
    modalBody = document.getElementById('detailModalBody');
    modalTitle = document.getElementById('detailModalTitle');
    return !!(modal && modalBody);
  }

  function showLoading() {
    modalBody.innerHTML = '<p class="text-muted" style="padding:30px; text-align:center;">Loading…</p>';
  }

  function showLoadError() {
    modalBody.innerHTML = '<p class="text-muted" style="padding:30px; text-align:center;">Could not load details. Please try again.</p>';
  }

  function fetchFragment(url) {
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (res) { return res.text(); });
  }

  function openDetailModal(url, title) {
    if (!refs()) return;
    if (modalTitle) modalTitle.textContent = title || 'Details';
    modal.dataset.currentUrl = url;
    showLoading();
    modal.showModal();
    fetchFragment(url).then(function (html) { modalBody.innerHTML = html; }).catch(showLoadError);
  }

  function closeDetailModal() {
    if (!refs() || !modal.open) return;
    modal.close();
    if (dirty) {
      dirty = false;
      window.location.reload();
    }
  }

  function prependBanner(success, message) {
    var banner = document.createElement('div');
    banner.className = 'alert' + (success ? ' alert-success' : '');
    if (!success) {
      banner.style.background = 'var(--danger-bg)';
      banner.style.color = 'var(--danger)';
      banner.style.border = '1px solid #f5c2c2';
    }
    banner.style.marginBottom = '16px';
    banner.textContent = message;
    modalBody.insertBefore(banner, modalBody.firstChild);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!refs()) return;

    document.addEventListener('click', function (e) {
      var viewLink = e.target.closest('.js-view-modal');
      if (viewLink) {
        e.preventDefault();
        openDetailModal(viewLink.dataset.url || viewLink.href, viewLink.dataset.title || '');
        return;
      }
      if (e.target.closest('.detail-modal-close')) {
        closeDetailModal();
        return;
      }
      // A direct hit on the <dialog> element itself (not a descendant) is
      // a click on its own backdrop/padding area, i.e. "outside" the card.
      if (e.target === modal) {
        closeDetailModal();
      }
    });

    // Esc key closes natively via the dialog's "cancel" event — still
    // needs the same dirty-reload follow-up as any other close path.
    modal.addEventListener('cancel', function () {
      setTimeout(function () {
        if (dirty) { dirty = false; window.location.reload(); }
      }, 0);
    });

    modal.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return; // an inline onsubmit (e.g. a confirm() the user cancelled) already handled this
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      // Opt-out for a form that needs a REAL top-level navigation — e.g. an
      // online payment form whose action can redirect to an external
      // payment page. Letting it submit normally works fine even while the
      // dialog is open: a genuine navigation just leaves the whole page,
      // dialog included, exactly as if the modal had never been there.
      if (form.dataset.plainSubmit) return;
      e.preventDefault();

      var submitButtons = form.querySelectorAll('button[type="submit"]');
      submitButtons.forEach(function (btn) { btn.disabled = true; });

      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.redirect) {
            // The record this modal was showing no longer exists in the
            // underlying list the way it did (e.g. it was cancelled) —
            // there's nothing left to refresh in place, so just go there.
            window.location.href = data.redirect;
            return;
          }
          if (data.success) dirty = true;
          return fetchFragment(modal.dataset.currentUrl).then(function (html) {
            modalBody.innerHTML = html;
            if (data.message) prependBanner(!!data.success, data.message);
          });
        })
        .catch(function () {
          submitButtons.forEach(function (btn) { btn.disabled = false; });
          alert('Something went wrong submitting that. Please try again.');
        });
    });
  });

  window.closeDetailModal = closeDetailModal;
})();
