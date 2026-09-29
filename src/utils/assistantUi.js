// Step 7.4: small pure rules for the assistant chat UI (unit-tested in __tests__/assistantUi.test.js).

export { shouldShowAssistant } from './assistantVisibility'; // the button's rule (main bundle)

// The app's real routes a link block may open, and the query keys each page reads.
// Anything else is dropped (the server validates too - this is the second fence).
export const ASSISTANT_LINK_ROUTES = {
  '/tours': ['date', 'start', 'end', 'unassigned', 'guide_id', 'language'],
  '/daily-pnl': ['date', 'start', 'end'],
  '/guides': [],
  '/payments': [],
  '/tickets': [],
  '/priority-tickets': [],
  '/radios': [],
  '/guide-reports': [],
};

const SAFE_VALUE = /^[A-Za-z0-9 _:.,-]{1,60}$/;

/**
 * A link block -> an in-app URL ("/tours?start=...&end=..."), or null when the route is not in
 * the allowlist. Unknown query keys and unsafe values are left out.
 */
export const buildAssistantLink = (block) => {
  if (!block || typeof block.route !== 'string') return null;
  if (!Object.prototype.hasOwnProperty.call(ASSISTANT_LINK_ROUTES, block.route)) return null;
  const allowed = ASSISTANT_LINK_ROUTES[block.route];
  const params = new URLSearchParams();
  const query = block.query && typeof block.query === 'object' ? block.query : {};
  allowed.forEach((key) => {
    const v = query[key];
    if (v === undefined || v === null) return;
    const s = String(v);
    if (SAFE_VALUE.test(s)) params.set(key, s);
  });
  const qs = params.toString();
  return qs ? `${block.route}?${qs}` : block.route;
};

/** 'dhanu' -> 'Dhanu', 'anna.b' -> 'Anna' - for the greeting only. */
export const firstNameOf = (name) => {
  const first = String(name || '').trim().split(/[\s._-]+/)[0] || '';
  return first ? first.charAt(0).toUpperCase() + first.slice(1) : '';
};

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const YMD = /^(\d{4})-(\d{2})-(\d{2})$/;

/** 'YYYY-MM-DD' -> 'Tue 29 Sep' (from parts, no UTC shift); anything else returned as is. */
export const shortDay = (ymd) => {
  const m = YMD.exec(String(ymd || ''));
  if (!m) return String(ymd || '');
  const dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return `${DAYS[dt.getDay()]} ${dt.getDate()} ${MONTHS[dt.getMonth()]}`;
};

/** One clear line per failure; the typed text stays in the box (the caller keeps it). */
export const assistantErrorMessage = (err) => {
  if (!err) return null;
  if (err.kind === 'network') return 'No connection to the server — your message is still in the box.';
  if (err.status === 429 && err.code === 'daily_cap_reached') return "Today's assistant limit is reached — it resets at midnight.";
  if (err.status === 429) return 'Too many questions in a minute — wait a moment, then send again.';
  if (err.status === 503) return 'The assistant is switched off right now.';
  if (err.status === 502) return 'The assistant is busy — try again in a moment.';
  if (err.status === 403) return 'Your account cannot use the assistant.';
  return 'Something went wrong — try again.';
};
