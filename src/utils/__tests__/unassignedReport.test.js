/**
 * Step 3.5: the unassigned report is formatted from server-provided departures.
 */
import { describe, it, expect } from 'vitest';
import { buildUnassignedReportText, getLocation } from '../unassignedReport';

const now = new Date(2026, 8, 18, 21, 5); // 18 Sep 2026, 21:05 local

describe('getLocation', () => {
  it('maps titles to the short location guides know', () => {
    expect(getLocation('Uffizi & Accademia Walking Tour with Gelato')).toBe('Uffizi + Accademia');
    expect(getLocation('Uffizi Gallery Small Group Guided Tour')).toBe('Uffizi');
    expect(getLocation('David and Accademia Gallery VIP Tour')).toBe('Accademia');
    expect(getLocation('Florence Walking Tour')).toBe('Florence');
    expect(getLocation(null)).toBe('Florence');
  });
});

describe('buildUnassignedReportText', () => {
  it('one line per DEPARTURE, grouped by date and sorted by time', () => {
    const text = buildUnassignedReportText(
      [
        { tour_unit: 't9', date: '2026-09-20', time: '14:15', title: 'Uffizi & Accademia Walking Tour', bookings: 1, pax: 2 },
        { tour_unit: 'g12', date: '2026-09-19', time: '10:30', title: 'Uffizi Gallery Guided Tour', bookings: 4, pax: 9 },
        { tour_unit: 't7', date: '2026-09-19', time: '08:30', title: 'Accademia Gallery Tour', bookings: 1, pax: 3 },
      ],
      { filterLabel: 'Upcoming', now }
    );
    const lines = text.split('\n');
    expect(lines[0]).toBe('UNASSIGNED TOURS REPORT');
    expect(lines[1]).toBe('Generated: 18 Sep 2026, 21:05');
    expect(lines[2]).toBe('Filter: Upcoming');
    expect(text).toContain('--- Saturday, 19 September 2026 ---\n\n  08:30  Accademia\n  10:30  Uffizi\n');
    expect(text).toContain('--- Sunday, 20 September 2026 ---\n\n  14:15  Uffizi + Accademia\n');
    expect(text.indexOf('19 September')).toBeLessThan(text.indexOf('20 September'));
    expect(lines[lines.length - 1]).toBe('Total: 3 unassigned tours');
  });

  it('a group of 4 bookings is ONE line, not four', () => {
    const text = buildUnassignedReportText(
      [{ tour_unit: 'g12', date: '2026-09-19', time: '10:30', title: 'Uffizi Gallery Guided Tour', bookings: 4, pax: 9 }],
      { filterLabel: 'Today', now }
    );
    expect(text.match(/10:30 {2}Uffizi/g)).toHaveLength(1);
    expect(text).toContain('Total: 1 unassigned tours');
  });

  it('empty result', () => {
    const text = buildUnassignedReportText([], { filterLabel: 'Today', now });
    expect(text).toContain('No unassigned tours found.');
    expect(text).toContain('Total: 0 unassigned tours');
  });

  it('does not shift the day (no UTC parsing of YYYY-MM-DD)', () => {
    const text = buildUnassignedReportText([{ date: '2026-03-01', time: '09:00', title: 'Uffizi' }], { filterLabel: 'x', now });
    expect(text).toContain('--- Sunday, 01 March 2026 ---');
  });
});
