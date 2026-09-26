/**
 * PARISHHUB — shared inline form validation (see includes/functions.php's
 * server-side counterpart: keepOldInput()/oldInput()). Highlights the
 * specific invalid field in red with a message directly under it, instead
 * of a generic top-of-page banner, and blocks submission until fixed.
 * Reused by registration, login, and (later) guest booking forms.
 */

function fieldGroup(input) {
  return input.closest('.form-group') || input.parentElement;
}

function showFieldError(input, message) {
  var group = fieldGroup(input);
  group.classList.add('has-error');
  var msg = group.querySelector('.field-error-msg');
  if (!msg) {
    msg = document.createElement('span');
    msg.className = 'field-error-msg';
    group.appendChild(msg);
  }
  msg.textContent = message;
}

function clearFieldError(input) {
  var group = fieldGroup(input);
  group.classList.remove('has-error');
  var msg = group.querySelector('.field-error-msg');
  if (msg) msg.remove();
}

/**
 * Checks each [selector, humanLabel] pair against `form`; empty ones get a
 * red "Please enter/select <label>." message. Returns the first invalid
 * input (for focusing), or null if everything required is filled in.
 */
function validateRequiredFields(form, fields) {
  var firstInvalid = null;
  fields.forEach(function (f) {
    var input = form.querySelector(f[0]);
    if (!input) return;
    var isEmpty = input.type === 'checkbox' ? !input.checked : !input.value || !input.value.trim();
    if (isEmpty) {
      var verb = (input.tagName === 'SELECT' || input.type === 'radio' || input.type === 'checkbox') ? 'select' : 'enter';
      showFieldError(input, 'Please ' + verb + ' ' + f[1] + '.');
      if (!firstInvalid) firstInvalid = input;
    } else {
      clearFieldError(input);
    }
  });
  return firstInvalid;
}

/** Clears an individual field's error as soon as the person starts fixing it. */
function clearFieldErrorOnInput(form, selectors) {
  selectors.forEach(function (sel) {
    var input = form.querySelector(sel);
    if (!input) return;
    input.addEventListener('input', function () { clearFieldError(input); });
    input.addEventListener('change', function () { clearFieldError(input); });
  });
}
