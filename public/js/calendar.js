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
      extendedProps: { kind: 'event' }
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
    events: eventItems.concat(blockedItems),
    dayMaxEvents: 2,

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

  if (options.minDate) {
    calendarConfig.validRange = { start: options.minDate };
  }

  var calendar = new FullCalendar.Calendar(el, calendarConfig);
  calendar.render();
  return calendar;
}
