(function () {
  function setHidden(el, hidden) { el.hidden = hidden; }

  function initCard(card, root) {
    var input = card.querySelector('[data-supporting-upload-input]');
    var status = card.querySelector('[data-upload-status]');
    var progress = card.querySelector('[data-upload-progress]');
    var progressLabel = card.querySelector('[data-upload-progress-label]');
    var progressBar = card.querySelector('[data-upload-progress-bar]');
    var error = card.querySelector('[data-upload-error]');
    var pickerLabel = card.querySelector('[data-upload-picker-label]');
    if (!input || !status || !progress || !error) return;

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var oldStatus = status.innerHTML;
      setHidden(error, true);
      error.textContent = '';
      setHidden(progress, false);
      progressLabel.textContent = 'Uploading ' + file.name + '…';
      progressBar.value = 0;
      input.disabled = true;

      var body = new FormData();
      body.append('csrf_token', root.dataset.csrfToken || '');
      body.append('context', root.dataset.uploadContext || '');
      body.append('context_id', root.dataset.contextId || '');
      body.append('requirement_label', card.dataset.requirementLabel || '');
      if (card.dataset.documentId) body.append('document_id', card.dataset.documentId);
      body.append('document', file);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', root.dataset.uploadUrl, true);
      xhr.responseType = 'json';
      xhr.upload.addEventListener('progress', function (event) {
        if (!event.lengthComputable) return;
        progressBar.value = Math.round((event.loaded / event.total) * 100);
        progressLabel.textContent = 'Uploading ' + file.name + '… ' + progressBar.value + '%';
      });
      xhr.onload = function () {
        var result = xhr.response || {};
        if (xhr.status >= 200 && xhr.status < 300 && result.success) {
          status.innerHTML = '<span class="supporting-document-success">Pending Review</span>'
            + '<span class="supporting-document-filename" data-upload-filename></span>'
            + (result.view_url ? ' <a class="btn btn-outline btn-sm" data-upload-view target="_blank" rel="noopener">View</a>' : '');
          status.querySelector('[data-upload-filename]').textContent = result.file_name || file.name;
          if (result.view_url) status.querySelector('[data-upload-view]').href = result.view_url;
          if (result.document_id) card.dataset.documentId = String(result.document_id);
          if (pickerLabel) pickerLabel.textContent = 'Choose Replacement';
          setHidden(progress, true);
          progressBar.value = 0;
        } else {
          status.innerHTML = oldStatus;
          error.textContent = '✕ Upload failed. ' + (result.message || 'Please try again.');
          setHidden(error, false);
          setHidden(progress, true);
        }
        input.disabled = false;
        input.value = '';
      };
      xhr.onerror = function () {
        status.innerHTML = oldStatus;
        error.textContent = '✕ Upload failed. Please check your connection and try again.';
        setHidden(error, false);
        setHidden(progress, true);
        input.disabled = false;
        input.value = '';
      };
      xhr.send(body);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-supporting-upload-root]').forEach(function (root) {
      root.querySelectorAll('[data-supporting-document-card]').forEach(function (card) { initCard(card, root); });
    });
  });
}());
