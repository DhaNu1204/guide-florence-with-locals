import { describe, it, expect } from 'vitest';
import { parseToursParams, parsePnlParams, parseYmdStrict } from '../deepLinks';

describe('parseYmdStrict', () => {
  it('accepts real dates only', () => {
    expect(parseYmdStrict('2026-09-29')).not.toBeNull();
    expect(parseYmdStrict('2026-02-30')).toBeNull();
    expect(parseYmdStrict('29/09/2026')).toBeNull();
    expect(parseYmdStrict(null)).toBeNull();
  });
});

describe('parseToursParams (step 7.4)', () => {
  it('reads every supported key', () => {
    expect(parseToursParams('?start=2026-09-29&end=2026-10-04&unassigned=1&guide_id=14&language=English')).toEqual({
      start: '2026-09-29', end: '2026-10-04', guideId: '14', language: 'English', unassigned: true,
    });
  });
  it('gives the defaults with no parameters (page unchanged)', () => {
    expect(parseToursParams('')).toEqual({ start: null, end: null, guideId: null, language: null, unassigned: false });
  });
  it('ignores invalid values', () => {
    expect(parseToursParams('?start=2026-10-04&end=2026-09-29')).toMatchObject({ start: null, end: null }); // reversed
    expect(parseToursParams('?start=2026-09-29')).toMatchObject({ start: null, end: null }); // half a range
    expect(parseToursParams('?guide_id=abc&language=<b>&unassigned=yes')).toMatchObject({ guideId: null, language: null, unassigned: false });
    expect(parseToursParams('?guide_id=0')).toMatchObject({ guideId: null });
  });
});

describe('parsePnlParams (step 7.4)', () => {
  it('?date= -> day view', () => {
    expect(parsePnlParams('?date=2026-09-29')).toEqual({ view: 'day', date: '2026-09-29' });
  });
  it('a whole calendar month -> month view', () => {
    expect(parsePnlParams('?start=2026-08-01&end=2026-08-31')).toEqual({ view: 'month', month: '2026-08' });
  });
  it('a Monday-Sunday week -> week view', () => {
    expect(parsePnlParams('?start=2026-09-21&end=2026-09-27')).toEqual({ view: 'week', weekStart: '2026-09-21' });
  });
  it('any other range (up to 93 days) -> range view', () => {
    expect(parsePnlParams('?start=2026-09-01&end=2026-09-29')).toEqual({ view: 'range', start: '2026-09-01', end: '2026-09-29' });
  });
  it('invalid or too long -> null (page opens on today)', () => {
    expect(parsePnlParams('')).toBeNull();
    expect(parsePnlParams('?date=tomorrow')).toBeNull();
    expect(parsePnlParams('?start=2026-01-01&end=2026-12-31')).toBeNull();
  });
});
