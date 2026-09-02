/**
 * PARISHHUB — Client-side scheduling helpers.
 * Mirrors the rules in includes/scheduling.php so the booking UI can give
 * instant feedback (disabling ineligible calendar dates, populating valid
 * Mass times, etc.) without a round trip to the server. The server always
 * re-validates independently via validateBooking() before saving anything.
 */

function isSaturdayJS(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.getDay() === 6;
}

function isTuesdayJS(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.getDay() === 2;
}

function nthWeekdayOccurrenceJS(dateStr) {
  var day = parseInt(dateStr.split('-')[2], 10);
  return Math.floor((day - 1) / 7) + 1;
}

function isFirstOrThirdSaturdayJS(dateStr) {
  return isSaturdayJS(dateStr) && [1, 3].indexOf(nthWeekdayOccurrenceJS(dateStr)) !== -1;
}

function isFourthSaturdayJS(dateStr) {
  return isSaturdayJS(dateStr) && nthWeekdayOccurrenceJS(dateStr) === 4;
}

/** Valid Mass time slots (24h "HH:MM") for a given date, per the daily Mass schedule. */
function massTimesForJS(dateStr) {
  var dow = new Date(dateStr + 'T00:00:00').getDay();
  if (dow === 0) return ['06:00', '09:00', '16:30']; // Sunday: 3 Masses
  if (dow === 3) return ['17:15'];                    // Wednesday: afternoon only
  return ['06:00'];                                    // every other day
}

/** Adds N days to a "YYYY-MM-DD" date string, returning a new "YYYY-MM-DD" string. */
function addDaysJS(dateStr, days) {
  var d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

/** Formats "HH:MM" (24h) as a friendly "H:MM AM/PM" label. */
function formatTimeLabel(t) {
  var parts = t.split(':');
  var h = parseInt(parts[0], 10);
  var m = parts[1];
  var ampm = h >= 12 ? 'PM' : 'AM';
  var h12 = h % 12;
  if (h12 === 0) h12 = 12;
  return h12 + ':' + m + ' ' + ampm;
}
