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
  var activeDetailRequest = 0;
  var detailAbortController = null;
  var activeScheduleRequest = 0;
  var scheduleAbortController = null;

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

  function fetchFragment(url, signal) {
    var options = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (signal) options.signal = signal;
    return fetch(url, options).then(function (res) { return res.text(); });
  }

  function closeChildModals() {
    activeScheduleRequest += 1;
    if (scheduleAbortController) scheduleAbortController.abort();
    scheduleAbortController = null;
    var scheduleModal = document.getElementById('priestScheduleModal');
    var rejectModal = document.getElementById('rejectApptModal');
    if (scheduleModal && scheduleModal.open) scheduleModal.close();
    if (rejectModal && rejectModal.open) rejectModal.close();
  }

  function openDetailModal(url, title) {
    if (!refs()) return;
    closeChildModals();
    activeDetailRequest += 1;
    var requestId = activeDetailRequest;
    if (detailAbortController) detailAbortController.abort();
    detailAbortController = window.AbortController ? new AbortController() : null;
    if (modalTitle) modalTitle.textContent = title || 'Details';
    modal.dataset.currentUrl = url;
    showLoading();
    if (!modal.open) modal.showModal();
    fetchFragment(url, detailAbortController && detailAbortController.signal)
      .then(function (html) {
        if (requestId !== activeDetailRequest || !modal.open) return;
        modalBody.innerHTML = html;
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        if (requestId === activeDetailRequest && modal.open) showLoadError();
      });
  }

  function closeDetailModal() {
    if (!refs()) return;
    activeDetailRequest += 1;
    if (detailAbortController) detailAbortController.abort();
    detailAbortController = null;
    closeChildModals();
    if (modal.open) modal.close();
    modalBody.replaceChildren();
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
    refs();

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
      var scheduleButton = e.target.closest('.js-view-priest-schedule');
      if (scheduleButton) {
        e.preventDefault();
        openPriestSchedule(scheduleButton);
        return;
      }
      if (e.target.closest('.js-load-more-priest-schedule')) {
        e.preventDefault();
        loadMorePriestSchedule();
        return;
      }
      if (e.target.closest('.js-close-priest-schedule')) {
        var scheduleModal = document.getElementById('priestScheduleModal');
        if (scheduleModal && scheduleModal.open) scheduleModal.close();
        return;
      }
      if (e.target.closest('#openRejectConfirmBtn')) {
        e.preventDefault();
        var rejectSelect = document.getElementById('rejectionReasonSelect');
        var customReason = document.getElementById('customRejectionReason');
        var rejectModal = document.getElementById('rejectApptModal');
        if (!rejectSelect || !rejectModal) return;
        if (!rejectSelect.value) { rejectSelect.setCustomValidity('Please select a reason for rejection.'); rejectSelect.reportValidity(); rejectSelect.setCustomValidity(''); return; }
        if (rejectSelect.value === 'Other' && !customReason.value.trim()) { customReason.setCustomValidity('Please specify the reason for rejection.'); customReason.reportValidity(); customReason.setCustomValidity(''); return; }
        document.getElementById('rejectConfirmReason').textContent = rejectSelect.value === 'Other' ? customReason.value.trim() : rejectSelect.value;
        rejectModal.showModal();
        return;
      }
      if (e.target.closest('#closeRejectApptModal') || e.target.closest('#cancelRejectApptModal')) {
        e.preventDefault();
        var confirmationModal = document.getElementById('rejectApptModal');
        if (confirmationModal && confirmationModal.open) confirmationModal.close();
        return;
      }
      if (e.target.closest('#confirmRejectApptBtn')) {
        e.preventDefault();
        var confirmation = document.getElementById('rejectApptModal');
        var appointmentRejectForm = document.getElementById('rejectForm');
        var reasonSelect = document.getElementById('rejectionReasonSelect');
        var customReasonField = document.getElementById('customRejectionReason');
        if (!confirmation || !appointmentRejectForm || !reasonSelect) return;
        if (!reasonSelect.value) {
          reasonSelect.setCustomValidity('Please select a reason for rejecting the appointment.');
          reasonSelect.reportValidity();
          reasonSelect.setCustomValidity('');
          return;
        }
        if (reasonSelect.value === 'Other' && (!customReasonField || !customReasonField.value.trim())) {
          if (customReasonField) {
            customReasonField.setCustomValidity('Please explain why the entire appointment cannot proceed.');
            customReasonField.reportValidity();
            customReasonField.setCustomValidity('');
          }
          return;
        }
        if (confirmation.open) confirmation.close();
        if (typeof appointmentRejectForm.requestSubmit === 'function') {
          appointmentRejectForm.requestSubmit();
        } else {
          appointmentRejectForm.submit();
        }
        return;
      }
      if (e.target.closest('#showAppointmentRejectionBtn')) {
        e.preventDefault();
        var showButton = e.target.closest('#showAppointmentRejectionBtn');
        var appointmentPanel = document.getElementById('appointmentRejectionPanel');
        var appointmentSelect = document.getElementById('rejectionReasonSelect');
        if (!appointmentPanel || !appointmentSelect) return;
        appointmentPanel.hidden = false;
        showButton.hidden = true;
        appointmentSelect.focus();
        return;
      }
      if (e.target.closest('#cancelAppointmentRejection')) {
        e.preventDefault();
        var cancelPanel = document.getElementById('appointmentRejectionPanel');
        var cancelForm = document.getElementById('rejectForm');
        var cancelShowButton = document.getElementById('showAppointmentRejectionBtn');
        if (!cancelPanel || !cancelForm) return;
        cancelForm.reset();
        cancelPanel.hidden = true;
        if (cancelShowButton) cancelShowButton.hidden = false;
        var cancelGroup = document.getElementById('customRejectionReasonGroup');
        var cancelField = document.getElementById('customRejectionReason');
        if (cancelGroup) cancelGroup.style.display = 'none';
        if (cancelField) cancelField.required = false;
        return;
      }
      var rejectionToggle = e.target.closest('.js-open-rejection');
      if (rejectionToggle) {
        e.preventDefault();
        var rejectionPanel = document.getElementById(rejectionToggle.dataset.target || '');
        if (!rejectionPanel) return;
        rejectionPanel.hidden = false;
        rejectionToggle.hidden = true;
        var rejectionField = rejectionPanel.querySelector('textarea');
        if (rejectionField) rejectionField.focus();
        return;
      }
      var rejectionCancel = e.target.closest('.js-cancel-rejection');
      if (rejectionCancel) {
        e.preventDefault();
        var formPanel = rejectionCancel.closest('.review-rejection-form');
        var panel = formPanel ? (formPanel.closest('.review-rejection-row') || formPanel) : null;
        if (!panel) return;
        panel.hidden = true;
        var rejectionRoot = modalBody || document;
        var matchingToggle = rejectionRoot.querySelector('.js-open-rejection[data-target="' + panel.id + '"]');
        if (!matchingToggle && formPanel) matchingToggle = rejectionRoot.querySelector('.js-open-rejection[data-target="' + formPanel.id + '"]');
        if (matchingToggle) matchingToggle.hidden = false;
        if (formPanel) formPanel.reset();
        return;
      }
      // A direct hit on the <dialog> element itself (not a descendant) is
      // a click on its own backdrop/padding area, i.e. "outside" the card.
      if (modal && e.target === modal) {
        closeDetailModal();
      }
    });

    document.addEventListener('change', function (e) {
      if (e.target.id !== 'rejectionReasonSelect') return;
      var group = document.getElementById('customRejectionReasonGroup');
      var field = document.getElementById('customRejectionReason');
      if (!group || !field) return;
      var isOther = e.target.value === 'Other';
      group.style.display = isOther ? '' : 'none';
      field.required = isOther;
      if (!isOther) field.value = '';
    });

    function openPriestSchedule(button) {
      var scheduleModal = document.getElementById('priestScheduleModal');
      var body = document.getElementById('priestScheduleModalBody');
      if (!scheduleModal || !body) return;
      var priestId = button.dataset.priestId;
      var priestName = button.dataset.priestName || 'Priest';
      var offset = 0;
      activeScheduleRequest += 1;
      var requestId = activeScheduleRequest;
      if (scheduleAbortController) scheduleAbortController.abort();
      scheduleAbortController = window.AbortController ? new AbortController() : null;
      var heading = scheduleModal.querySelector('h3');
      if (heading) heading.textContent = 'Priest Schedule';
      body.innerHTML = '<p><strong>' + escapeHtml(priestName) + '</strong></p><p class="text-muted">Loading schedule…</p>';
      body.removeAttribute('data-priest-id');
      body.removeAttribute('data-offset');
      body.removeAttribute('data-schedule-url');
      if (!scheduleModal.open) scheduleModal.showModal();

      function loadSchedule() {
        var options = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (scheduleAbortController) options.signal = scheduleAbortController.signal;
        fetch(button.dataset.scheduleUrl + '?priest_id=' + encodeURIComponent(priestId) + '&offset=' + offset, options)
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (requestId !== activeScheduleRequest || !scheduleModal.open) return;
            if (!data.success) throw new Error(data.message || 'Unable to load schedule.');
            var html = '<div class="priest-schedule-identity"><strong>' + escapeHtml(data.priest.full_name) + '</strong><span>' + escapeHtml(data.priest.title || 'Priest') + '</span></div><h4 class="priest-schedule-heading">Upcoming Appointments' + (data.appointments.length ? ' (' + data.appointments.length + ')' : '') + '</h4>';
            if (!data.appointments.length && offset === 0) html += '<div class="priest-schedule-empty"><strong>No upcoming appointments</strong><span>This priest currently has no upcoming appointments scheduled.</span></div>';
            html += renderScheduleAppointments(data.appointments);
            if (data.has_more) html += '<button type="button" class="btn btn-outline btn-block js-load-more-priest-schedule">Load More</button>';
            body.innerHTML = html;
            body.dataset.priestId = priestId;
            body.dataset.offset = String(offset + data.appointments.length);
            body.dataset.scheduleUrl = button.dataset.scheduleUrl;
          })
          .catch(function (error) {
            if (error && error.name === 'AbortError') return;
            if (requestId === activeScheduleRequest && scheduleModal.open) body.innerHTML = '<p class="text-muted">' + escapeHtml(error.message) + '</p>';
          });
      }
      loadSchedule();
    }

    function loadMorePriestSchedule() {
      var body = document.getElementById('priestScheduleModalBody');
      var scheduleModal = document.getElementById('priestScheduleModal');
      if (!body || !scheduleModal || !scheduleModal.open || !body.dataset.priestId || !body.dataset.scheduleUrl) return;
      var requestId = activeScheduleRequest;
      var oldButton = body.querySelector('.js-load-more-priest-schedule');
      if (oldButton) oldButton.disabled = true;
      var options = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
      if (scheduleAbortController) options.signal = scheduleAbortController.signal;
      fetch(body.dataset.scheduleUrl + '?priest_id=' + encodeURIComponent(body.dataset.priestId) + '&offset=' + encodeURIComponent(body.dataset.offset || '0'), options)
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (requestId !== activeScheduleRequest || !scheduleModal.open) return;
          if (!data.success) throw new Error(data.message || 'Unable to load more appointments.');
          if (oldButton) oldButton.remove();
          data.appointments.forEach(function (item) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = renderScheduleAppointments([item]);
            while (wrapper.firstChild) body.appendChild(wrapper.firstChild);
          });
          body.dataset.offset = String(Number(body.dataset.offset || '0') + data.appointments.length);
          if (data.has_more) { var more = document.createElement('button'); more.type = 'button'; more.className = 'btn btn-outline btn-block js-load-more-priest-schedule'; more.textContent = 'Load More'; body.appendChild(more); }
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') return;
          if (requestId !== activeScheduleRequest || !scheduleModal.open) return;
          if (oldButton) { oldButton.disabled = false; }
          var errorNote = document.createElement('p'); errorNote.className = 'text-muted'; errorNote.textContent = error.message; body.appendChild(errorNote);
        });
    }

    function escapeHtml(value) { return String(value).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]); }); }
    function renderScheduleAppointments(items) { return items.map(function (item) { return '<div class="priest-schedule-item"><div class="priest-schedule-date">' + formatScheduleDate(item.appointment_date) + '</div><div class="priest-schedule-time">' + formatScheduleTime(item.appointment_time) + '</div><div class="priest-schedule-service">' + escapeHtml(item.service_name) + '</div></div>'; }).join(''); }
    function formatScheduleDate(value) { var parts = String(value).split('-'); return parts.length === 3 ? new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).toLocaleDateString(undefined, { year:'numeric', month:'long', day:'numeric' }) : escapeHtml(value); }
    function formatScheduleTime(value) { var parts = String(value).split(':'); if (parts.length < 2) return escapeHtml(value); var h = Number(parts[0]); var m = parts[1]; return String((h + 11) % 12 + 1) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM'); }

    // Esc key closes natively via the dialog's "cancel" event — still
    // needs the same dirty-reload follow-up as any other close path.
    if (modal) {
      modal.addEventListener('cancel', function () {
        activeDetailRequest += 1;
        if (detailAbortController) detailAbortController.abort();
        detailAbortController = null;
        closeChildModals();
        modalBody.replaceChildren();
        setTimeout(function () {
          if (dirty) { dirty = false; window.location.reload(); }
        }, 0);
      });
    }

    if (!modal) return;
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
      if (form.dataset.submitting === '1') {
        e.preventDefault();
        return;
      }
      if (form.classList.contains('review-rejection-form')) {
        var reasonField = form.querySelector('textarea[name="document_rejection_reason"]');
        var reason = reasonField ? reasonField.value.trim() : '';
        if (!reason) {
          e.preventDefault();
          if (reasonField) {
            reasonField.setCustomValidity('Please provide a reason for rejection.');
            reasonField.reportValidity();
            reasonField.setCustomValidity('');
          }
          return;
        }
        reasonField.value = reason;
      }
      e.preventDefault();

      var submitButtons = form.querySelectorAll('button[type="submit"]');
      var submitRequestId = activeDetailRequest;
      var submitUrl = modal.dataset.currentUrl;
      form.dataset.submitting = '1';
      submitButtons.forEach(function (btn) {
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = 'Processing…';
      });

      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok) throw new Error(data.message || 'The request could not be completed.');
            return data;
          });
        })
        .then(function (data) {
          if (submitRequestId !== activeDetailRequest || !modal.open) return;
          if (data.redirect) {
            // The record this modal was showing no longer exists in the
            // underlying list the way it did (e.g. it was cancelled) —
            // there's nothing left to refresh in place, so just go there.
            window.location.href = data.redirect;
            return;
          }
          if (data.success) dirty = true;
          return fetchFragment(submitUrl, detailAbortController && detailAbortController.signal).then(function (html) {
            if (submitRequestId !== activeDetailRequest || !modal.open) return;
            modalBody.innerHTML = html;
            if (data.message) prependBanner(!!data.success, data.message);
          });
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') return;
          if (submitRequestId !== activeDetailRequest || !modal.open) return;
          form.dataset.submitting = '0';
          submitButtons.forEach(function (btn) {
            btn.disabled = false;
            if (btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
          });
          alert('Something went wrong submitting that. Please try again.');
        });
    });
  });

  window.closeDetailModal = closeDetailModal;
})();
