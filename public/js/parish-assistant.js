/*
 * PARISHHUB — Parish Virtual Assistant: a rule-based guided FAQ (click-only, no AI).
 *
 * Welcome -> topic -> suggested question -> polite answer -> related questions,
 * with Back to topic, Main Menu and Start Over. The facts come from #pchatData,
 * built by includes/public-chatbot.php from Admin Settings and the same tables
 * and helpers the Services page uses; nothing private is ever read here.
 * Every message is built with textContent (never innerHTML), so nothing in the
 * data can turn into markup.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('pchatData');
  var fab = document.getElementById('pchatFab');
  var panel = document.getElementById('pchatPanel');
  if (!dataEl || !fab || !panel) return;
  var D;
  try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

  var body = document.getElementById('pchatBody');
  var log = document.getElementById('pchatLog');
  var choices = document.getElementById('pchatChoices');
  var closeBtn = document.getElementById('pchatClose');
  var restartBtn = document.getElementById('pchatRestart');
  var menuBtn = document.getElementById('pchatMenu');
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var services = D.services || [];
  var contact = D.contact || {};
  var links = D.links || {};
  var precana = D.precana;
  var parishName = D.parishName || 'our parish';
  var FALLBACK = "I'm sorry, but I don't have confirmed information about that yet. For accurate assistance, please contact our parish office.";
  var CLOSINGS = ['Is there anything else I can assist you with?', 'May I help you with anything else?', 'Please feel free to choose another question below.'];
  var closingIndex = 0;
  var state = { started: false, topic: null };

  /* ---------- Small helpers ---------- */
  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }
  function byCategory(category) { return services.filter(function (s) { return s.category === category; }); }
  function hasCategory(category) { return byCategory(category).length > 0; }
  function categories() {
    var seen = [];
    services.forEach(function (s) { if (seen.indexOf(s.category) === -1) seen.push(s.category); });
    return seen;
  }
  function closing() {
    var text = CLOSINGS[closingIndex % CLOSINGS.length];
    closingIndex++;
    return { closing: text };
  }
  /** Policy text from the booking form: "• " lines become a list. */
  function textBlocks(text) {
    var blocks = [];
    var items = null;
    String(text || '').split('\n').forEach(function (line) {
      line = line.trim();
      if (!line) return;
      if (line.indexOf('• ') === 0) {
        if (!items) { items = []; blocks.push({ list: items }); }
        items.push(line.slice(2));
      } else {
        items = null;
        blocks.push(line);
      }
    });
    return blocks;
  }
  function contactLines() {
    var lines = [];
    if (contact.phone) lines.push('Phone: ' + contact.phone);
    if (contact.email) lines.push('Email: ' + contact.email);
    if (contact.address) lines.push('Address: ' + contact.address);
    return lines;
  }
  /** The polite "not confirmed" answer, with whatever contact details are verified. */
  function fallback() {
    var blocks = [FALLBACK];
    var lines = contactLines();
    if (lines.length) blocks.push({ list: lines });
    return blocks;
  }
  function feeNotes() {
    return [
      { note: 'PSS Giver status is verified by our parish office. Choosing it on the booking form does not by itself make a booking free.' },
      { note: 'Please note that the final fee will be confirmed after verification by our parish office. If the verified total is ' + D.noPaymentLabel.split(' ')[0] + ', no payment is required.' }
    ];
  }
  var DATE_CATEGORIES = ['Baptism', 'Wedding', 'Blessing', 'Funeral', 'Anointing', 'Wake'];
  var STATUS_PLACE = D.isParishioner ? 'My Appointments' : 'Check Status';

  /* ---------- Rendering ---------- */
  function renderBlocks(container, blocks) {
    blocks.forEach(function (b) {
      if (typeof b === 'string') { container.appendChild(el('p', null, b)); return; }
      if (b.heading) container.appendChild(el('p', 'pchat-subhead', b.heading));
      if (b.list) {
        var list = el(b.ordered ? 'ol' : 'ul', 'pchat-list');
        b.list.forEach(function (item) { list.appendChild(el('li', null, item)); });
        container.appendChild(list);
      }
      if (b.table) {
        var wrap = el('div', 'pchat-table-wrap');
        var table = el('table', 'pchat-table');
        if (b.table.caption) table.appendChild(el('caption', null, b.table.caption));
        var head = el('thead');
        var headRow = el('tr');
        b.table.head.forEach(function (h, i) { var th = el('th', null, h); th.scope = 'col'; if (i === 0 && !h) th.setAttribute('aria-label', 'Item'); headRow.appendChild(th); });
        head.appendChild(headRow);
        table.appendChild(head);
        var tbody = el('tbody');
        b.table.rows.forEach(function (row) {
          var tr = el('tr');
          row.forEach(function (cell, i) {
            var c = el(i === 0 ? 'th' : 'td', null, cell);
            if (i === 0) c.scope = 'row';
            tr.appendChild(c);
          });
          tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);
      }
      if (b.note) container.appendChild(el('p', 'pchat-note', b.note));
      if (b.closing) container.appendChild(el('p', 'pchat-closing', b.closing));
    });
  }
  function addMessage(who, content) {
    var msg = el('div', 'pchat-msg pchat-msg--' + who);
    msg.appendChild(el('span', 'pchat-sr', who === 'user' ? 'You asked: ' : 'Assistant: '));
    if (who === 'user') msg.appendChild(document.createTextNode(content));
    else renderBlocks(msg, content);
    log.appendChild(msg);
    return msg;
  }
  /** Brings the latest question to the top of the chat area, so its answer reads from the start. */
  function scrollToMessage(msg) {
    var top = Math.max(body.scrollTop + msg.getBoundingClientRect().top - body.getBoundingClientRect().top - 8, 0);
    if (body.scrollTo) body.scrollTo({ top: top, behavior: reduceMotion ? 'auto' : 'smooth' });
    else body.scrollTop = top;
  }
  function setChoices(options, nav) {
    choices.textContent = '';
    if (options.length) {
      var list = el('div', 'pchat-options');
      options.forEach(function (o) {
        var b = el('button', 'pchat-choice' + (o.topic ? ' pchat-choice--topic' : ''), o.label);
        b.type = 'button';
        b.addEventListener('click', o.run);
        list.appendChild(b);
      });
      choices.appendChild(list);
    }
    if (nav && nav.length) {
      var bar = el('div', 'pchat-nav');
      nav.forEach(function (o) {
        var b = el('button', 'pchat-nav-btn', o.label);
        b.type = 'button';
        b.addEventListener('click', o.run);
        bar.appendChild(b);
      });
      choices.appendChild(bar);
    }
    if (isOpen()) {
      var first = choices.querySelector('button');
      if (first) first.focus({ preventScroll: true });
    }
  }

  /* ---------- Answers (facts from #pchatData only) ---------- */
  var Q = {
    'mass.schedule': {
      label: 'What is the parish Mass schedule?',
      answer: function () {
        if (!D.massSchedule) return fallback();
        return ['Certainly! Here is our current Mass schedule:', { list: D.massSchedule.split('|').map(function (p) { return p.trim(); }).filter(Boolean) }];
      },
      related: ['mass.intention', 'office.hours', 'location.where']
    },
    'mass.intention': {
      label: 'How do I request a Mass Intention?',
      available: function () { return hasCategory('Mass Intention'); },
      answer: function () {
        var s = byCategory('Mass Intention')[0];
        return ["I'd be happy to help. You may offer a Mass Intention through ParishHub."]
          .concat(textBlocks(s.policy))
          .concat(['To submit one, choose Book a Service below and select ' + s.name + '.']);
      },
      related: ['mass.schedule', 'fees.pay', 'status.check']
    },
    'office.hours': {
      label: 'When is the parish office open?',
      answer: function () {
        if (D.officeHours) return ['Our parish office hours are:', D.officeHours];
        return [FALLBACK, 'What I can confirm is this: ' + D.officeClosed].concat(contactLines().length ? [{ list: contactLines() }] : []);
      },
      related: ['office.closed', 'location.where', 'contact.how']
    },
    'office.closed': {
      label: 'Is the office closed on certain days?',
      answer: function () {
        return ['Yes. ' + D.officeClosed, 'Appointments cannot be scheduled during those times. Mass Intentions are the only exception, since the Masses still take place.'];
      },
      related: ['office.hours', 'book.steps']
    },
    'location.where': {
      label: 'Where is the parish located?',
      answer: function () {
        if (!contact.address) return fallback();
        return ['Our parish is located at:', contact.address];
      },
      related: ['location.visit', 'contact.how', 'mass.schedule']
    },
    'location.visit': {
      label: 'When can I visit the parish office?',
      answer: function () {
        var blocks = D.officeHours ? ['You are welcome to visit during our office hours:', D.officeHours] : [FALLBACK, 'What I can confirm is this: ' + D.officeClosed];
        if (contact.address) blocks.push({ note: 'Address: ' + contact.address });
        return blocks;
      },
      related: ['office.closed', 'contact.how']
    },
    'contact.how': {
      label: 'How can I contact the parish office?',
      answer: function () {
        var lines = contactLines();
        if (contact.phone || contact.email) return ['Certainly! You may reach our parish office through:', { list: lines }];
        return ["I'm sorry, but I don't have confirmed contact details for the parish office yet."]
          .concat(contact.address ? ['You are welcome to visit us in person at ' + contact.address + '.'] : [])
          .concat([D.officeClosed]);
      },
      related: ['office.hours', 'location.where']
    },
    'fees.services': {
      label: 'Which services can I request?',
      available: function () { return services.length > 0; },
      answer: function () {
        return ['Here are the parish services you can request through ParishHub:', { list: services.map(function (s) { return s.name; }) }];
      },
      related: ['fees.service', 'req.service', 'book.steps']
    },
    'fees.service': {
      label: 'How much does a service cost?',
      pickPrompt: "I'd be glad to help. Which service would you like to know about?",
      labelFor: function (s) { return 'What is the fee for ' + s.name + '?'; },
      answerFor: function (s) {
        var f = s.fee || { kind: 'unknown' };
        if (f.kind === 'rules') {
          var blocks = ['Certainly! Here are the current ' + s.name + ' fees.'];
          f.schedules.forEach(function (sc) {
            blocks.push({ table: {
              caption: sc.name + ' schedule',
              head: ['', 'PSS Giver', 'Non-PSS Giver'],
              rows: [
                ['Base Fee', sc.pss.base, sc.non_pss.base],
                ['Priest Stipend', sc.pss.stipend, sc.non_pss.stipend],
                ['Sponsors with no fees', 'Up to ' + sc.pss.included, 'Up to ' + sc.non_pss.included],
                ['Each additional sponsor', sc.pss.additional, sc.non_pss.additional]
              ]
            } });
          });
          if (s.category === 'Wedding' && precana) {
            blocks.push({ note: 'Pre-Cana seminar: Regular Pre-Cana has no fees; Special Pre-Cana is ' + precana.specialFee + ', added once whatever your PSS status.' });
          }
          return blocks.concat(feeNotes());
        }
        if (f.kind === 'funeral') {
          return ['Certainly! Here are the current ' + s.name + ' fees.', { table: {
            caption: s.name,
            head: ['', 'PSS Giver', 'Non-PSS Giver'],
            rows: [
              ['Service Fee', f.pss.base, f.non_pss.base],
              ['Priest Stipend', f.pss.stipend, f.non_pss.stipend],
              ['Total Payment', f.pss.total, f.non_pss.total]
            ]
          } }].concat(feeNotes());
        }
        if (f.kind === 'free') return [s.name + ' is free of charge. No payment is required.'];
        if (f.kind === 'offering') return ['There is no fixed fee for a ' + s.name.replace(/s$/, '') + ', but an offering greater than ₱0 is required when you submit it. Our Cashier then confirms your payment.'];
        if (f.kind === 'fixed') return ['The fee for ' + s.name + ' is ' + f.amount + '.', 'You will be asked to pay once the parish office approves your request.'];
        if (f.kind === 'voluntary') return ['On our Services page, ' + s.name + ' is listed as a voluntary offering. For guidance on the amount, please contact our parish office.'];
        return fallback();
      },
      related: function (s) {
        var r = [['req.service', s], ['book.service', s]];
        if (s.category === 'Wedding' && precana) r.push(['book.precana']);
        r.push(['fees.pay']);
        return r;
      }
    },
    'fees.pay': {
      label: 'When and how do I pay?',
      answer: function () {
        var items = [
          'Most services are paid after the parish office approves your request.',
          'You can pay in cash at the parish office or online through ParishHub, from ' + STATUS_PLACE + '.',
          'If an approved booking with a fee is still unpaid when its scheduled date passes, it is cancelled automatically.'
        ];
        if (hasCategory('Mass Intention')) items.splice(1, 0, 'Mass Intentions are paid when you submit them, and our Cashier confirms the payment.');
        return ["Here's how payment works:", { list: items }];
      },
      related: ['fees.zero', 'book.after', 'status.check']
    },
    'fees.zero': {
      label: 'When is no payment required?',
      available: function () { return services.length > 0; },
      answer: function () {
        var items = [];
        byCategory('Anointing').forEach(function (s) { items.push(s.name + ' is free of charge.'); });
        var verified = services.filter(function (s) { return s.fee && (s.fee.kind === 'rules' || s.fee.kind === 'funeral'); }).map(function (s) { return s.name; });
        if (verified.length) items.push(verified.join(', ') + ': when the parish office verifies a total of ' + D.noPaymentLabel + '. The booking then goes on to confirmation without a payment step.');
        if (!items.length) return fallback();
        return ['Certainly. No payment is required in these cases:', { list: items }, { note: 'Claiming PSS Giver status on the form does not by itself make a booking free; our parish office verifies it first.' }];
      },
      related: ['fees.service', 'fees.pay']
    },
    'req.service': {
      label: 'What documents do I need?',
      pickPrompt: 'Certainly! Which service are you preparing for?',
      labelFor: function (s) { return 'What are the requirements for ' + s.name + '?'; },
      answerFor: function (s) {
        var blocks = [];
        if (s.documents.length) {
          blocks.push('Here are the requirements for your selected service. Please prepare these documents before submitting your request.');
          blocks.push({ heading: 'Documents to upload', list: s.documents });
        }
        if (s.forms.length) blocks.push({ heading: 'Forms you will fill out online while booking', list: s.forms });
        if (s.afterSeminar.length) {
          blocks.push({ note: s.afterSeminar.join(', ') + ': not needed to submit your booking. Please upload it after you attend your Pre-Cana seminar; it must be approved before your Wedding is confirmed.' });
        }
        if (s.category === 'Blessing') blocks.push('The booking form asks for a contact number and the address of the place to be blessed.');
        if (!s.documents.length && !s.forms.length && s.category !== 'Blessing') {
          blocks.push('ParishHub does not list any documents to upload for ' + s.name + '. For any special instructions, please contact our parish office.');
        }
        if (s.documents.length) blocks.push({ note: 'Accepted files: ' + D.uploads.types + ', up to ' + D.uploads.maxSize + ' each.' });
        return blocks;
      },
      related: function (s) {
        var r = [['fees.service', s], ['book.service', s], ['req.uploads']];
        if (s.afterSeminar.length) r.push(['req.precana']);
        return r;
      }
    },
    'req.forms': {
      label: 'Are there forms to fill out online?',
      available: function () { return services.some(function (s) { return s.forms.length; }); },
      answer: function () {
        var items = [];
        services.forEach(function (s) { if (s.forms.length) items.push(s.name + ': ' + s.forms.join('; ')); });
        return ['Yes. For these services, you fill out the parish forms online while booking:', { list: items }];
      },
      related: ['req.service', 'req.uploads']
    },
    'req.precana': {
      label: 'When do I submit the Pre-Cana Seminar Certificate?',
      available: function () { return !!precana && services.some(function (s) { return s.afterSeminar.length; }); },
      answer: function () {
        return ['The ' + precana.certificate + ' is not needed to submit your Wedding booking.',
          'Please upload it after you attend your Pre-Cana seminar, from ' + STATUS_PLACE + '. It must be approved before your Wedding is confirmed.'];
      },
      related: ['book.precana', 'req.service']
    },
    'req.uploads': {
      label: 'What files can I upload?',
      answer: function () {
        return ['Certainly! When uploading documents:', { list: [
          'Accepted file types: ' + D.uploads.types,
          'Maximum size: ' + D.uploads.maxSize + ' per file',
          'Photos (JPG or PNG) must be in portrait orientation, taller than they are wide.'
        ] }];
      },
      related: ['req.service', 'req.revision']
    },
    'req.revision': {
      label: 'What if my documents need revision?',
      answer: function () {
        var items = [
          'You will receive an email listing each item that needs correction and the reason.',
          'Open your booking from ' + STATUS_PLACE + ', replace each item marked Needs Revision, then click Submit Updated Requirements.',
          'Your booking stays active, so there is no need to book again.'
        ];
        if (D.isParishioner) items.splice(1, 0, 'You will also see a Needs Revision notification in ParishHub.');
        return ['If our parish office finds something to correct, it will ask you to revise it:', { list: items }];
      },
      related: ['req.uploads', 'status.check']
    },
    'book.steps': {
      label: 'How do I book a parish service?',
      answer: function () {
        return ["Certainly! Here's how to book a parish service:", { ordered: true, list: [
          'Open Book a Service (the Services page).',
          'Choose the parish service you need.',
          'Select an available date and time when the service asks for one.',
          'Complete the booking form and the requirements.',
          'Submit your request.',
          'Save the booking reference code shown after you submit.',
          'Wait for our parish office to review your request.',
          'Complete any requested revisions, or your payment when one is required.',
          'Wait for your appointment to be confirmed.'
        ] }, { note: 'Guests may book without creating an account.' }];
      },
      related: ['book.service', 'book.guest', 'book.after']
    },
    'book.guest': {
      label: 'Can I book without an account?',
      answer: function () {
        if (D.isParishioner) return ['Yes, guests may book without an account. Since you are signed in, your bookings are saved to your account and listed in My Appointments.'];
        return ['Yes, you may book as a guest without creating an account.',
          'After you submit, please save your booking reference code. You will use it, together with the email address or phone number on your booking, to check its status on the Check Status page.'];
      },
      related: ['book.steps', 'status.check']
    },
    'book.service': {
      label: 'How is a specific service scheduled?',
      pickPrompt: 'Of course! Which service would you like to book?',
      labelFor: function (s) { return 'How do I book ' + s.name + '?'; },
      answerFor: function (s) {
        var blocks = ["Certainly! Here's how " + s.name + ' is scheduled:'].concat(textBlocks(s.policy));
        if (s.regular) blocks.push('Several families may book the same Regular date and time.');
        if (s.category === 'Funeral') blocks.push('The burial and Funeral Mass date must be after the date of death, and times are in 30-minute steps.');
        if (s.category === 'Blessing') blocks.push('The booking form asks for a contact number and the address of the place to be blessed.');
        if (s.category === 'Wedding' && precana) blocks.push('You will also choose a Pre-Cana seminar, which must take place before your wedding date.');
        if (DATE_CATEGORIES.indexOf(s.category) !== -1) blocks.push({ note: D.officeClosed + ' Appointments cannot be scheduled during those times.' });
        blocks.push('To begin, please choose Book a Service below.');
        return blocks;
      },
      related: function (s) {
        var r = [['req.service', s], ['fees.service', s]];
        if (s.category === 'Wedding' && precana) r.push(['book.precana']);
        r.push(['book.after']);
        return r;
      }
    },
    'book.schedules': {
      label: 'What are Regular and Special schedules?',
      available: function () { return services.some(function (s) { return s.regular; }); },
      answer: function () {
        var fixed = services.filter(function (s) { return s.regular; }).map(function (s) { return s.name + ': ' + s.regular; });
        return [byCategory('Baptism').concat(byCategory('Wedding')).map(function (s) { return s.name; }).join(' and ') + ' offer two kinds of schedules:', { list: [
          "Regular: the parish's fixed schedule. Several families may book the same Regular date and time.",
          'Special: you request your own date and time, subject to availability.'
        ] }, { heading: 'Current Regular schedules', list: fixed }, { note: 'Fees differ between Regular and Special schedules. You may check them under Services & Fees.' }];
      },
      related: ['fees.service', 'book.service']
    },
    'book.precana': {
      label: 'What are the Pre-Cana seminar options?',
      available: function () { return !!precana && hasCategory('Wedding'); },
      answer: function () {
        return ['Wedding couples choose one Pre-Cana seminar:', { list: [
          'Regular Pre-Cana (no fees): held on the ' + precana.regular + '. You pick one of the sessions before your wedding date.',
          'Special Pre-Cana (' + precana.specialFee + '): you propose a date and time, and our parish office confirms it or proposes another one.'
        ] }, 'The seminar must take place before your Wedding Ceremony date. The ' + precana.certificate + ' is uploaded after you attend.'];
      },
      related: ['req.precana', 'fees.service', 'book.steps']
    },
    'book.after': {
      label: 'What happens after I submit my request?',
      answer: function () {
        var items = [
          'Your request is Pending while our parish office reviews your details and requirements.',
          'If something needs correction, you will be asked to revise it.',
          'Once approved, please complete your payment if a fee is due. If your verified total is ' + D.noPaymentLabel + ', no payment is needed.',
          'After any required payment is verified, our parish office confirms your appointment.'
        ];
        if (hasCategory('Mass Intention')) items.push('Mass Intentions are paid when submitted and are confirmed once our Cashier verifies the payment.');
        return ["Here's what happens next:", { list: items }];
      },
      related: ['status.meaning', 'status.check', 'fees.pay']
    },
    'status.check': {
      label: 'How do I check my booking status?',
      answer: function () {
        if (D.isParishioner) return ["For your privacy, I can't look up bookings here.", 'Since you are signed in, please open My Appointments to see the status of each of your bookings.'];
        return ["For your privacy, I can't look up bookings here.", 'Please open Check Status and enter your booking reference code (for example PH-XXXXXXXX) together with the email address or phone number you used when booking.'];
      },
      related: ['status.meaning', 'status.reference']
    },
    'status.meaning': {
      label: 'What do the booking statuses mean?',
      answer: function () {
        return ['Certainly! Here is what each status means:', { list: [
          'Pending: our parish office is reviewing your request and requirements.',
          'Approved: your request was approved. Please complete your payment if a fee is due.',
          'Payment Verified: our Cashier has verified your payment; the parish office will confirm your appointment next.',
          'Confirmed: your appointment is confirmed.',
          'Completed: the service has taken place.',
          'Rejected: the request was not approved. The reason is shown on your booking.',
          'Cancelled: the booking was cancelled. The reason is shown on your booking.'
        ] }];
      },
      related: ['status.check', 'book.after']
    },
    'status.reference': {
      label: "I can't find my booking reference code.",
      answer: function () {
        if (D.isParishioner) return ['No problem. While you are signed in, you do not need a reference code: My Appointments lists all of your bookings.'];
        var blocks = ['Your reference code is shown on the Thank You message right after you submit, and it is included in emails from our parish office about your booking.',
          'If you still cannot find it, please contact our parish office.'];
        var lines = contactLines();
        if (lines.length) blocks.push({ list: lines });
        return blocks;
      },
      related: ['status.check', 'contact.how']
    }
  };

  var TOPICS = [
    { id: 'mass', label: 'Mass Schedules', intro: 'Certainly! Here are common questions about our Masses.', questions: ['mass.schedule', 'mass.intention'] },
    { id: 'office', label: 'Office Hours', intro: 'Of course! Here is what I can tell you about the parish office.', questions: ['office.hours', 'office.closed'] },
    { id: 'location', label: 'Our Location', intro: "I'd be happy to help you find us.", questions: ['location.where', 'location.visit'] },
    { id: 'fees', label: 'Services & Fees', intro: 'Certainly! Here are common questions about our services and fees.', questions: ['fees.services', 'fees.service', 'fees.pay', 'fees.zero'] },
    { id: 'requirements', label: 'Service Requirements', intro: 'Certainly! I can help you prepare your requirements.', questions: ['req.service', 'req.forms', 'req.precana', 'req.uploads', 'req.revision'] },
    { id: 'booking', label: 'How to Book', intro: "I'd be happy to guide you through booking.", questions: ['book.steps', 'book.guest', 'book.service', 'book.schedules', 'book.precana', 'book.after'] },
    { id: 'status', label: 'Check Booking Status', intro: 'Of course! Here is how you can follow your booking.', questions: ['status.check', 'status.meaning', 'status.reference'] },
    { id: 'contact', label: 'Contact Us', intro: 'Certainly! Here is how to reach us.', questions: ['contact.how', 'location.where'] }
  ];

  function available(id) {
    var q = Q[id];
    return !!q && (!q.available || q.available());
  }

  /* ---------- Conversation ---------- */
  function topicNav() {
    if (!state.topic) return [];
    var topic = state.topic;
    return [{ label: '← Back to ' + topic.label, run: function () { showTopic(topic, true); } }];
  }
  function topicOptions() {
    return TOPICS.map(function (t) { return { label: t.label, topic: true, run: function () { showTopic(t); } }; });
  }
  function showMainMenu(fromUser) {
    state.topic = null;
    var msg = null;
    if (fromUser) {
      msg = addMessage('user', 'Main Menu');
      addMessage('bot', ['Certainly! Here are the topics I can help you with.']);
    }
    setChoices(topicOptions(), []);
    if (msg) scrollToMessage(msg);
  }
  function showTopic(topic, back) {
    state.topic = topic;
    var msg = addMessage('user', back ? 'Back to ' + topic.label : topic.label);
    addMessage('bot', [back ? 'Of course! Here are the questions about ' + topic.label + ' again.' : topic.intro]);
    setChoices(topic.questions.filter(available).map(function (id) { return { label: Q[id].label, run: function () { ask(id); } }; }), []);
    scrollToMessage(msg);
  }
  function relatedOptions(q, service) {
    var list = typeof q.related === 'function' ? q.related(service) : (q.related || []).map(function (id) { return [id]; });
    return list.filter(function (r) { return available(r[0]); }).map(function (r) {
      var rq = Q[r[0]];
      var label = r[1] && rq.labelFor ? rq.labelFor(r[1]) : rq.label;
      return { label: label, run: function () { ask(r[0], r[1]); } };
    });
  }
  function ask(id, service) {
    var q = Q[id];
    var msg;
    if (q.pickPrompt && !service) {
      msg = addMessage('user', q.label);
      if (!services.length) {
        addMessage('bot', fallback().concat([closing()]));
        setChoices([], topicNav());
      } else {
        addMessage('bot', [q.pickPrompt]);
        setChoices(services.map(function (s) { return { label: s.name, run: function () { ask(id, s); } }; }), topicNav());
      }
      scrollToMessage(msg);
      return;
    }
    msg = addMessage('user', service ? q.labelFor(service) : q.label);
    addMessage('bot', (service ? q.answerFor(service) : q.answer()).concat([closing()]));
    setChoices(relatedOptions(q, service), topicNav());
    scrollToMessage(msg);
  }
  function start() {
    state.started = true;
    state.topic = null;
    closingIndex = 0;
    log.textContent = '';
    addMessage('bot', ['Good day! Welcome to ' + parishName + ". I'm your Parish Virtual Assistant. How may I assist you today?"]);
    setChoices(topicOptions(), []);
    body.scrollTop = 0;
  }

  /* ---------- Open / close ---------- */
  var hideTimer = null;
  function isOpen() { return panel.classList.contains('open'); }
  function openPanel() {
    clearTimeout(hideTimer);
    panel.hidden = false;
    // Next frame, so the opening transition runs from the hidden state.
    window.requestAnimationFrame(function () { panel.classList.add('open'); focusFirst(); });
    fab.setAttribute('aria-expanded', 'true');
    fab.setAttribute('aria-label', 'Close the Parish Virtual Assistant');
    if (!state.started) start();
  }
  function closePanel(returnFocus) {
    if (!isOpen()) return;
    panel.classList.remove('open');
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-label', 'Open the Parish Virtual Assistant');
    hideTimer = setTimeout(function () { if (!isOpen()) panel.hidden = true; }, reduceMotion ? 0 : 300);
    if (returnFocus) fab.focus();
  }
  function focusFirst() {
    var first = choices.querySelector('button') || closeBtn;
    if (first) first.focus({ preventScroll: true });
  }

  fab.addEventListener('click', function () { if (isOpen()) closePanel(true); else openPanel(); });
  if (closeBtn) closeBtn.addEventListener('click', function () { closePanel(true); });
  if (restartBtn) restartBtn.addEventListener('click', function () { start(); focusFirst(); });
  if (menuBtn) menuBtn.addEventListener('click', function () { showMainMenu(true); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen() && (panel.contains(document.activeElement) || document.activeElement === fab)) {
      e.preventDefault();
      closePanel(true);
    }
  });
  // Opening the mobile menu or the notification bell closes the assistant, so they never overlap.
  document.addEventListener('click', function (e) {
    if (isOpen() && e.target.closest && e.target.closest('#menuToggle, .public-nav-toggle, .notif-bell-button')) closePanel(false);
  });
  document.body.classList.add('pchat-on');
})();
