/**
 * PARISHHUB — shared inline form validation (see includes/functions.php's
 * server-side counterpart: keepOldInput()/oldInput()).
 *
 * Every invalid field gets a red border (.field-invalid) and its message
 * directly below it (.field-error-msg), instead of a browser popup or a single
 * banner at the top of the form. The browser's own validation is switched off
 * (novalidate), and the messages come from the field's native constraints
 * (required, pattern, min/max, email/date/time types), so they read the same
 * everywhere.
 *
 * Usage on a form:
 *   attachInlineValidation(form, { beforeCheck: function (form) { ... } });
 *
 * Per-field rules come from attributes, so most forms need nothing more:
 *   data-label="Groom Date of Birth"  name shown in messages (else the <label> text)
 *   data-past                         date must be before today
 *   data-min-age="18" data-age-on="2026-11-14" data-age-label="the wedding date"
 *                                     person must be at least N years old on that date
 * Phone numbers (type="tel") must be 11 digits starting with 09.
 * Anything else (e.g. a Mass time slot) is set with setCustomValidity() inside
 * beforeCheck, and shows the same inline message.
 */

var fieldErrorMessages = new Map(); // input -> its .field-error-msg element

function fieldGroup(input) {
  return input.closest('.form-group') || input.parentElement;
}

function todayISO() {
  return isoFromDate(new Date());
}

function isoFromDate(d) {
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function addYearsISO(iso, years) {
  var d = new Date(iso + 'T00:00:00');
  d.setFullYear(d.getFullYear() + years);
  return isoFromDate(d);
}

function ageInYears(birthISO, refISO) {
  var b = new Date(birthISO + 'T00:00:00');
  var r = new Date(refISO + 'T00:00:00');
  var years = r.getFullYear() - b.getFullYear();
  var beforeBirthday = r.getMonth() < b.getMonth() || (r.getMonth() === b.getMonth() && r.getDate() < b.getDate());
  return beforeBirthday ? years - 1 : years;
}

/** Human name for a field, used in messages: data-label, else its <label> text. */
function fieldLabel(input) {
  if (input.getAttribute('data-label')) return input.getAttribute('data-label');
  var label = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
  if (!label) label = fieldGroup(input).querySelector('label');
  var text = label ? label.textContent : (input.name || 'this field');
  return text.replace(/\(optional\)/gi, '').replace(/\*/g, '').replace(/\s+/g, ' ').trim() || 'this field';
}

/** Where the message goes: directly under the field (under the last option for radios/checkboxes). */
function messageAnchor(input) {
  if (input.type === 'radio' || input.type === 'checkbox') {
    var form = input.form || document;
    var sameGroup = Array.prototype.filter.call(form.querySelectorAll('input[type="' + input.type + '"]'), function (other) {
      return other.name === input.name;
    });
    var last = sameGroup[sameGroup.length - 1] || input;
    return last.closest('label') || last;
  }
  return input;
}

function showFieldError(input, message) {
  input.classList.add('field-invalid');
  input.setAttribute('aria-invalid', 'true');
  var msg = fieldErrorMessages.get(input);
  if (!msg) {
    msg = document.createElement('span');
    msg.className = 'field-error-msg';
    messageAnchor(input).insertAdjacentElement('afterend', msg);
    fieldErrorMessages.set(input, msg);
  }
  msg.textContent = message;
  fieldGroup(input).classList.add('has-error');
}

function clearFieldError(input) {
  input.classList.remove('field-invalid');
  input.removeAttribute('aria-invalid');
  var msg = fieldErrorMessages.get(input);
  if (msg) {
    msg.remove();
    fieldErrorMessages.delete(input);
  }
  var group = fieldGroup(input);
  if (!group.querySelector('.field-invalid')) group.classList.remove('has-error');
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

/** Date rules from data-past / data-min-age; returns '' when the date is fine. */
function dateRuleMessage(input, label) {
  if (input.type !== 'date' || !input.value) return '';
  if (input.hasAttribute('data-past') && input.value >= todayISO()) {
    return label + ' must be a date in the past.';
  }
  var minAge = Number(input.getAttribute('data-min-age'));
  var ageOn = input.getAttribute('data-age-on');
  if (minAge && ageOn && ageInYears(input.value, ageOn) < minAge) {
    return label + ' must be at least ' + minAge + ' years old on ' + (input.getAttribute('data-age-label') || 'the reference date') + '.';
  }
  return '';
}

/** Keeps the date pickers from offering dates the rules above would reject. */
function syncDateLimits(form) {
  form.querySelectorAll('input[type="date"][data-past], input[type="date"][data-min-age]').forEach(function (input) {
    var cap = null;
    if (input.hasAttribute('data-past')) {
      var yesterday = new Date();
      yesterday.setDate(yesterday.getDate() - 1);
      cap = isoFromDate(yesterday);
    }
    var minAge = Number(input.getAttribute('data-min-age'));
    var ageOn = input.getAttribute('data-age-on');
    if (minAge && ageOn) {
      var limit = addYearsISO(ageOn, -minAge);
      if (!cap || limit < cap) cap = limit;
    }
    if (cap) input.max = cap;
  });
}

/** The message for one field, or '' if it is fine. */
function inlineErrorFor(input, options) {
  if (input.disabled || input.willValidate === false) return '';
  if (!fieldGroup(input).getClientRects().length) return ''; // inside a hidden section
  var v = input.validity;
  if (v.customError) return input.validationMessage;

  var label = fieldLabel(input);
  var isChoice = input.tagName === 'SELECT' || input.type === 'radio' || input.type === 'checkbox';
  if (v.valueMissing && options.requireFilled !== false) {
    if (input.type === 'file') return 'Please upload ' + label + '.';
    return (isChoice ? 'Please select ' : 'Please enter ') + label + '.';
  }
  if (v.typeMismatch) {
    return input.type === 'email' ? 'Please enter a valid email address.' : 'Please enter a valid ' + label + '.';
  }
  if (input.type === 'tel' && input.value && !/^09\d{9}$/.test(input.value)) {
    return label + ' must be exactly 11 digits starting with 09 (e.g. 09XXXXXXXXX).';
  }
  var rule = dateRuleMessage(input, label);
  if (rule) return rule;
  if (v.patternMismatch) return input.title || label + ' is not in the correct format.';
  if (v.rangeUnderflow) return input.type === 'date' ? label + ' must be on or after ' + input.min + '.' : label + ' must be at least ' + input.min + '.';
  if (v.rangeOverflow) return input.type === 'date' ? label + ' must be on or before ' + input.max + '.' : label + ' must be at most ' + input.max + '.';
  if (v.stepMismatch) return label + ' has an invalid value.';
  if (v.tooLong) return label + ' is too long.';
  if (v.badInput) return 'Please enter a valid ' + label + '.';
  return '';
}

/** Radio groups are validated and messaged once, on their first option. */
function inlineTarget(input) {
  if (input.type !== 'radio') return input;
  var form = input.form || document;
  var first = Array.prototype.find.call(form.querySelectorAll('input[type="radio"]'), function (other) {
    return other.name === input.name;
  });
  return first || input;
}

/** Shows or clears one field's inline error. Returns the field if it is invalid. */
function checkInlineField(input, options) {
  var target = inlineTarget(input);
  var message = inlineErrorFor(target, options);
  if (message) {
    showFieldError(target, message);
    return target;
  }
  clearFieldError(target);
  return null;
}

/** Checks every field in the form. Returns the first invalid field (for focusing), or null. */
function validateInlineForm(form, options) {
  var first = null;
  var seenRadioGroups = {};
  Array.prototype.forEach.call(form.elements, function (el) {
    if (!el.name && !el.id) return;
    if (['hidden', 'submit', 'button', 'reset', 'image', 'fieldset'].indexOf(el.type) !== -1) return;
    if (el.type === 'radio') {
      if (seenRadioGroups[el.name]) return;
      seenRadioGroups[el.name] = true;
    }
    var bad = checkInlineField(el, options);
    if (bad && !first) first = bad;
  });
  return first;
}

function isValidatedField(el) {
  return el.matches && el.matches('input, select, textarea') && ['hidden', 'submit', 'button', 'reset', 'image'].indexOf(el.type) === -1;
}

/**
 * Wires inline validation onto a form:
 *  - turns off the browser's own popups (novalidate)
 *  - on change, validates that field; while typing, only clears an error that is already shown
 *  - on submit, validates everything and blocks the submit if anything is wrong
 *
 * Save-draft buttons marked formnovalidate only check format and age rules, not
 * whether every required field is filled in yet.
 *
 * options.beforeCheck(form) runs before each check; use it to set custom
 * messages with setCustomValidity() for rules that depend on other fields.
 */
function attachInlineValidation(form, options) {
  options = options || {};
  form.setAttribute('novalidate', '');

  function runBeforeCheck() {
    if (options.beforeCheck) options.beforeCheck(form);
    syncDateLimits(form);
  }

  runBeforeCheck();

  form.addEventListener('change', function (event) {
    if (!isValidatedField(event.target)) return;
    runBeforeCheck();
    checkInlineField(event.target, {});
  });

  form.addEventListener('input', function (event) {
    if (!isValidatedField(event.target)) return;
    if (!event.target.classList.contains('field-invalid') && event.target.type !== 'radio') return;
    runBeforeCheck();
    checkInlineField(event.target, {});
  });

  // Capture phase on the form runs before the page's own submit handler, so an invalid submit never reaches it.
  form.addEventListener('submit', function (event) {
    var requireFilled = !(event.submitter && event.submitter.hasAttribute('formnovalidate'));
    runBeforeCheck();
    var first = validateInlineForm(form, { requireFilled: requireFilled });
    if (!first) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    first.focus();
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, true);
}
