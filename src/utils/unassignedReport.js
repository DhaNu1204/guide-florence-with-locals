/**
 * unassignedReport.js - step 3.5
 *
 * Builds the text of the "Unassigned tours" report from the departures the server returns
 * (GET tours.php?action=unassigned-report). The server decides WHAT is unassigned - one row per
 * departure (a group or a single tour) whose effective guide is missing; this file only formats.
 * The layout is the one guides already know: a date header, then "  HH:MM  Location" lines.
 */
import { format } from 'date-fns';

// Location from the tour title by keyword (unchanged from the old in-page report)
export const getLocation = (title) => {
  if (!title) return 'Florence';
  const t = title.toLowerCase();
  if (t.includes('uffizi') && t.includes('accademia')) return 'Uffizi + Accademia';
  if (t.includes('uffizi')) return 'Uffizi';
  if (t.includes('accademia')) return 'Accademia';
  if (t.includes('duomo') || t.includes('cathedral')) return 'Duomo';
  if (t.includes('pitti')) return 'Pitti';
  if (t.includes('boboli')) return 'Boboli';
  if (t.includes('palazzo vecchio')) return 'Palazzo Vecchio';
  if (t.includes('san lorenzo') || t.includes('medici chapel')) return 'San Lorenzo';
  if (t.includes('santa croce')) return 'Santa Croce';
  if (t.includes('ponte vecchio')) return 'Ponte Vecchio';
  if (t.includes('bargello')) return 'Bargello';
  if (t.includes('vasari')) return 'Vasari Corridor';
  return 'Florence';
};

// 'YYYY-MM-DD' -> local Date (never new Date('YYYY-MM-DD'), which is UTC and can shift the day)
const parseYmdLocal = (ymd) => {
  const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number);
  return new Date(y, (m || 1) - 1, d || 1);
};

/**
 * @param {Array<{date:string,time:string,title:string,language:string}>} departures  server rows, one per departure
 * @param {{filterLabel:string, now?:Date}} options
 * @returns {string} the report text
 */
export const buildUnassignedReportText = (departures, { filterLabel, now = new Date() } = {}) => {
  const rows = Array.isArray(departures) ? departures : [];
  const lines = [];
  lines.push('UNASSIGNED TOURS REPORT');
  lines.push(`Generated: ${format(now, 'dd MMM yyyy, HH:mm')}`);
  lines.push(`Filter: ${filterLabel || ''}`);
  lines.push('========================');
  lines.push('');

  const byDate = new Map();
  rows.forEach((d) => {
    const key = String(d.date).slice(0, 10);
    if (!byDate.has(key)) byDate.set(key, []);
    byDate.get(key).push({
      time: (d.time || '00:00').substring(0, 5),
      location: getLocation(d.title),
      // Step 6.1: the guide needs to know which language the departure is in. A mixed group
      // arrives as "English, Spanish"; a departure with no language reads "Unknown".
      language: d.language || 'Unknown',
    });
  });

  [...byDate.keys()].sort().forEach((date) => {
    lines.push(`--- ${format(parseYmdLocal(date), 'EEEE, dd MMMM yyyy')} ---`);
    lines.push('');
    byDate
      .get(date)
      .sort((a, b) => a.time.localeCompare(b.time))
      .forEach((entry) => lines.push(`  ${entry.time}  ${entry.location} (${entry.language})`));
    lines.push('');
  });

  if (rows.length === 0) {
    lines.push('No unassigned tours found.');
    lines.push('');
  }

  lines.push('========================');
  lines.push(`Total: ${rows.length} unassigned tours`);
  return lines.join('\n');
};
