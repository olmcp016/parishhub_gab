/**
 * PARISHHUB — Parish Calendar (powered by FullCalendar.js)
 *
 * This is a thin wrapper around the real FullCalendar library
 * (https://fullcalendar.io) loaded from CDN in each page that needs it.
 * FullCalendar renders "today" using the browser's own local date/time
 * by default (no server timezone is involved), so the highlighted day,
 * month, and year are always correct for whoever is viewing the page.
 *
 * Usage (after the FullCalendar CDN <script> has loaded):
 *   renderParishCalendar('calendarElId', {
 *     events:  [{ date: '2026-08-15', title: 'Fiesta Mass' }, ...],
 *     blocked: [{ date: '2026-08-20', notes: 'Diocesan holiday' }, ...],
 *     minDate: '2026-08-02',                 // optional — disallow navigating/clicking before this date
 *     onDateClick: function (dateStr, info) { ... }  // info = { hasEvents, isBlocked }
 *   });
 * Returns the FullCalendar Calendar instance (or null if FullCalendar failed to load).
 */
function renderParishCalendar(elId, options) {
  options = options || {};
  var el = document.getElementById(elId);

  if (!el) return null;

  if (typeof FullCalendar === 'undefined') {
    el.innerHTML = '<p class="text-muted" style="padding:16px;">Calendar library failed to load. Check your internet connection.</p>';
    return null;
  }

  el.classList.add('pcal-fc-wrap');

  var eventItems = (options.events || []).map(function (ev) {
    return {
      title: ev.title,
      start: ev.date,
      allDay: true,
      backgroundColor: '#c99b2f',
      borderColor: '#a5791f',
      textColor: '#241611',
      extendedProps: { kind: 'event', eventId: ev.id || null, details: ev.details || null }
    };
  });

  var massItems = (options.massSchedule || []).map(function (m) {
    return {
      title: m.title,
      start: m.date,
      allDay: true,
      // Optional per-Mass colors (e.g. the priest calendar shows the viewer's own Masses in blue).
      backgroundColor: m.backgroundColor || '#2d7a46',
      borderColor: m.borderColor || '#1a5c33',
      textColor: '#ffffff',
      extendedProps: { kind: 'mass', cleanTitle: m.clean_title || m.title, time: m.time || '', details: m.details || null }
    };
  });

  // Optional: confirmed appointments (priest calendar). Same blue as the "Confirmed" badge.
  var appointmentItems = (options.appointments || []).map(function (a) {
    return {
      title: a.title,
      start: a.date,
      allDay: true,
      backgroundColor: '#2457a6',
      borderColor: '#1b4280',
      textColor: '#ffffff',
      extendedProps: { kind: 'appointment', time: a.time || '', details: a.details || null }
    };
  });

  var blockedItems = (options.blocked || []).map(function (b) {
    return {
      title: 'Unavailable' + (b.notes ? ': ' + b.notes : ''),
      start: b.date,
      allDay: true,
      backgroundColor: '#a3341f',
      borderColor: '#7a2618',
      textColor: '#ffffff',
      extendedProps: { kind: 'blocked', notes: b.notes || null }
    };
  });

  var blockedByDate = {};
  blockedItems.forEach(function (b) { blockedByDate[b.start] = b; });
  var eventsByDate = {};
  eventItems.forEach(function (e) {
    (eventsByDate[e.start] = eventsByDate[e.start] || []).push(e);
  });

  var calendarConfig = {
    initialView: 'dayGridMonth',
    height: 'auto',
    firstDay: 0,
    headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
    events: massItems.concat(appointmentItems).concat(eventItems).concat(blockedItems),
    dayMaxEvents: options.dayMaxEvents !== undefined ? options.dayMaxEvents : 2,

    // Fires after initial render and after every prev/next/today navigation
    // (the month can be 4-6 weeks tall) — lets a page keep something else
    // in sync with the calendar's actual rendered height.
    datesSet: function (info) {
      if (typeof options.datesSet === 'function') options.datesSet(info);
    },

    // FullCalendar already makes events focusable and opens them with Enter/Space through
    // eventClick below; this only announces the ones the page can open as buttons.
    eventDidMount: function (info) {
      var handlers = { event: options.onEventClick, mass: options.onMassClick, appointment: options.onAppointmentClick };
      if (typeof handlers[info.event.extendedProps.kind] === 'function') info.el.setAttribute('role', 'button');
    },

    eventClick: function (info) {
      var kind = info.event.extendedProps.kind;
      info.jsEvent.preventDefault();
      if (kind === 'event' && typeof options.onEventClick === 'function') {
        options.onEventClick(info.event);
      } else if (kind === 'mass' && typeof options.onMassClick === 'function') {
        options.onMassClick(info.event);
      } else if (kind === 'appointment' && typeof options.onAppointmentClick === 'function') {
        options.onAppointmentClick(info.event);
      }
    },

    dateClick: function (info) {
      var dateStr = info.dateStr; // YYYY-MM-DD, in the calendar's local rendering

      if (options.minDate && dateStr < options.minDate) return;
      if (typeof options.isDateDisabled === 'function' && options.isDateDisabled(dateStr)) return;

      var blockedInfo = blockedByDate[dateStr];

      if (typeof options.onDateClick === 'function') {
        options.onDateClick(dateStr, {
          isBlocked: !!blockedInfo,
          blockedInfo: blockedInfo ? blockedInfo.extendedProps : null,
          hasEvents: !!eventsByDate[dateStr],
          events: eventsByDate[dateStr] || []
        });
      }
    },

    // Visually dim/disable dates before minDate or excluded by isDateDisabled,
    // and mark today using the browser's own local date (FullCalendar's
    // default "today" behavior). extraDayClassNames adds non-blocking visual
    // markers (e.g. staff day-off shading) that don't prevent clicking.
    dayCellClassNames: function (arg) {
      var classes = [];
      var cellDate = arg.date.getFullYear() + '-' +
        String(arg.date.getMonth() + 1).padStart(2, '0') + '-' +
        String(arg.date.getDate()).padStart(2, '0');
      var disabled = (options.minDate && cellDate < options.minDate) ||
        (typeof options.isDateDisabled === 'function' && options.isDateDisabled(cellDate));
      if (disabled) {
        classes.push('pcal-fc-disabled');
      }
      if (typeof options.extraDayClassNames === 'function') {
        var extra = options.extraDayClassNames(cellDate) || [];
        classes = classes.concat(extra);
      }
      return classes;
    }
  };

  // Deliberately NOT using FullCalendar's own `validRange` here — it blanks
  // out the day number entirely for anything before it, rather than just
  // graying the cell like every other disabled date. minDate enforcement is
  // already fully handled above (dayCellClassNames greys it, dateClick
  // refuses it), so every disabled date — past or simply unavailable —
  // looks and behaves the same way: dimmed, but still showing its number.

  // Optional ordering within a day, e.g. 'time,title' to list entries by their "HH:MM" time.
  if (options.eventOrder) calendarConfig.eventOrder = options.eventOrder;

  var calendar = new FullCalendar.Calendar(el, calendarConfig);
  calendar.render();
  return calendar;
}
