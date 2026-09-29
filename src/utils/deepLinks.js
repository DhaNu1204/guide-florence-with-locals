// Step 7.4: deep links into Tours and Daily P&L (used by the assistant's "Open in…" links).
// Every value is validated; anything invalid is ignored, so a page opened without (or with bad)
// parameters behaves exactly as before. Dates are built from their parts - never
// new Date('YYYY-MM-DD'), which is UTC and can shift the day.

const YMD = /^(\d{4})-(\d{2})-(\d{2})$/;

/** 'YYYY-MM-DD' -> local Date, or null when it is not a real calendar date. */
export const parseYmdStrict = (value) => {
  if (typeof value !== 'string') return null;
  const m = YMD.exec(value);
  if (!m) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);
  const dt = new Date(y, mo - 1, d);
  if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
  return dt;
};

export const ymdOf = (dt) =>
  `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;

/** Days from a to b inclusive (both local midnight Dates). */
const daysInclusive = (a, b) => Math.round((b - a) / 86400000) + 1;

const readParams = (search) => {
  try {
    return new URLSearchParams(search || '');
  } catch (_) {
    return new URLSearchParams('');
  }
};

/** A valid start..end pair (start <= end, at most maxDays long) or null. */
const readRange = (params, maxDays) => {
  const s = parseYmdStrict(params.get('start'));
  const e = parseYmdStrict(params.get('end'));
  if (!s || !e || s > e || daysInclusive(s, e) > maxDays) return null;
  return { start: ymdOf(s), end: ymdOf(e) };
};

/**
 * Tours: ?date= (already supported by the page) and, new, ?start=&end= (custom range),
 * ?unassigned=1 (only departures without a guide), ?guide_id=, ?language=.
 * Returns only the valid ones; missing/invalid -> null / false.
 */
export const parseToursParams = (search) => {
  const p = readParams(search);
  const range = readRange(p, 366);
  const gid = p.get('guide_id');
  const lang = p.get('language');
  return {
    start: range ? range.start : null,
    end: range ? range.end : null,
    guideId: gid && /^\d{1,9}$/.test(gid) && Number(gid) > 0 ? gid : null,
    language: lang && /^[A-Za-z][A-Za-z ]{1,29}$/.test(lang) ? lang : null,
    unassigned: p.get('unassigned') === '1',
  };
};

const mondayOfDate = (dt) => {
  const day = dt.getDay(); // 0 = Sunday
  const back = day === 0 ? 6 : day - 1;
  return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate() - back);
};

/**
 * Daily P&L: ?date=YYYY-MM-DD -> day view; ?start=&end= (at most 93 days, the endpoint's cap)
 * -> month view for a whole calendar month, week view for a Monday-Sunday week, otherwise a plain
 * range view. null when nothing valid is given (the page opens on today, as before).
 */
export const parsePnlParams = (search) => {
  const p = readParams(search);
  const date = parseYmdStrict(p.get('date'));
  if (date) return { view: 'day', date: ymdOf(date) };
  const range = readRange(p, 93);
  if (!range) return null;
  const s = parseYmdStrict(range.start);
  const e = parseYmdStrict(range.end);
  const monthEnd = new Date(s.getFullYear(), s.getMonth() + 1, 0);
  if (s.getDate() === 1 && e.getTime() === monthEnd.getTime()) {
    return { view: 'month', month: range.start.slice(0, 7) };
  }
  if (mondayOfDate(s).getTime() === s.getTime() && daysInclusive(s, e) === 7) {
    return { view: 'week', weekStart: range.start };
  }
  return { view: 'range', start: range.start, end: range.end };
};
