import { fetchWithTimeout } from './netPolicy';
import { notifySessionExpired } from './sessionExpiry';

// Step 4.11: ONE poller for the whole app. Tours, Dashboard and Today subscribe while they are
// open; nothing runs when no such page is open, when the tab/app is hidden or when logged out.
//
// Every 60 s (and at once on return to the tab/app, focus, pageshow or back online, at most once
// per 10 s) it asks tours.php?action=changes&since=<token> - an answer under 200 bytes. When the
// token changed it tells the subscribed pages to refetch silently and toasts new bookings and
// cancellations once each. After 3 failures in a row it waits 5 min between checks until one
// works again. Never auto-retried (the next tick is the retry), aborted when the tab hides.

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

export const POLL_MS = 60000;
export const BACKOFF_MS = 300000;
export const BACKOFF_AFTER_FAILURES = 3;
export const NUDGE_MIN_GAP_MS = 10000;
export const POLL_TIMEOUT_MS = 15000;
const MAX_TOASTS = 3;

const subscribers = new Set();
const stampListeners = new Set();
const shownEvents = new Set();
let token = null;
let timer = null;
let inflight = null;
let failures = 0;
let lastAttemptAt = 0;
let lastCheckAt = null;
let listening = false;

const isVisible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden';
const isLoggedIn = () => {
  try { return !!localStorage.getItem('token'); } catch { return false; }
};
const canRun = () => subscribers.size > 0 && isVisible() && isLoggedIn();

function stopTimer() {
  if (timer) clearTimeout(timer);
  timer = null;
}

function abortInflight() {
  if (inflight) inflight.abort();
  inflight = null;
}

function schedule() {
  stopTimer();
  if (!canRun()) return;
  timer = setTimeout(() => { pollNow('timer'); }, nextDelayMs());
}

/** 60 s, or 5 min after BACKOFF_AFTER_FAILURES failed checks in a row. */
export function nextDelayMs() {
  return failures >= BACKOFF_AFTER_FAILURES ? BACKOFF_MS : POLL_MS;
}

const romeYmd = (offsetDays = 0) => {
  const d = new Date(Date.now() + offsetDays * 86400000);
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(d);
};

/** "Uffizi Gallery Small Group Guided Tour with Tickets" -> "Uffizi Small Group". */
export function shortTourTitle(title) {
  let t = String(title || '').replace(/^Florence:\s*/i, '');
  t = t.replace(/\b(with|incl\.?|including)\b.*$/i, '');
  t = t.replace(/\b(Gallery|Guided|Tour|Tours|Skip[- ]the[- ]Line|Ticket|Tickets)\b/gi, ' ');
  t = t.replace(/\s+/g, ' ').replace(/[\s,:–-]+$/, '').trim();
  if (!t) t = String(title || 'Tour').trim();
  return t.length > 34 ? `${t.slice(0, 33).trim()}…` : t;
}

/** "New booking: 15:00 Uffizi Small Group, +1 PAX" (with "Tomorrow" / "Sat 10 Oct" when not today). */
export function describeEvent(ev) {
  let day = '';
  if (ev.date && ev.date !== romeYmd(0)) {
    if (ev.date === romeYmd(1)) day = 'Tomorrow ';
    else {
      const [y, m, d] = ev.date.split('-').map(Number);
      day = `${new Date(y, m - 1, d).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })} `;
    }
  }
  const pax = Number(ev.pax) || 0;
  const what = `${day}${ev.time || ''} ${shortTourTitle(ev.title)}`.trim();
  return ev.kind === 'cancel'
    ? `Cancelled: ${what}, −${pax} PAX`
    : `New booking: ${what}, +${pax} PAX`;
}

function announce(events) {
  const fresh = (Array.isArray(events) ? events : []).filter((ev) => {
    const key = `${ev.kind}:${ev.id}`;
    if (shownEvents.has(key)) return false;
    shownEvents.add(key);
    return true;
  });
  if (fresh.length === 0) return;
  const sub = [...subscribers].reverse().find((s) => typeof s.notify === 'function');
  if (!sub) return;
  fresh.slice(0, MAX_TOASTS).forEach((ev) => sub.notify(describeEvent(ev), ev.kind));
  if (fresh.length > MAX_TOASTS) sub.notify(`…and ${fresh.length - MAX_TOASTS} more booking changes`, 'new');
}

function emitStamp() {
  stampListeners.forEach((fn) => { try { fn(lastCheckAt); } catch { /* a listener never breaks the poller */ } });
}

/** One check now (no-op while one is already in flight or when the poller may not run). */
export async function pollNow(reason = 'manual') {
  if (!canRun()) { stopTimer(); return; }
  if (inflight) return;
  stopTimer();
  const ctl = new AbortController();
  inflight = ctl;
  lastAttemptAt = Date.now();
  try {
    const url = `${API_BASE_URL}/tours.php?action=changes${token ? `&since=${encodeURIComponent(token)}` : ''}`;
    const auth = localStorage.getItem('token');
    const res = await fetchWithTimeout(url, {
      signal: ctl.signal,
      headers: auth ? { Authorization: `Bearer ${auth}` } : {},
    }, POLL_TIMEOUT_MS);
    if (res.status === 401) {
      notifySessionExpired();
      return;
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const body = await res.json();
    const data = body && body.data;
    if (!data || typeof data.token !== 'string') throw new Error('unexpected answer');
    if (ctl.signal.aborted) return;
    const baseline = token === null;
    token = data.token;
    failures = 0;
    lastCheckAt = Date.now();
    if (data.changed && !baseline) {
      announce(data.events);
      subscribers.forEach((s) => { try { s.onChange(data.events || [], reason); } catch (e) { console.warn('[changes] subscriber failed', e); } });
    }
    emitStamp();
  } catch (e) {
    if (!ctl.signal.aborted) failures += 1;
  } finally {
    if (inflight === ctl) inflight = null;
    if (!ctl.signal.aborted) schedule();
  }
}

function nudge(reason) {
  if (!canRun()) return;
  if (Date.now() - lastAttemptAt < NUDGE_MIN_GAP_MS) { if (!timer && !inflight) schedule(); return; }
  pollNow(reason);
}

function onVisibility() {
  if (isVisible()) nudge('visible');
  else { stopTimer(); abortInflight(); }
}
const onFocus = () => nudge('focus');
const onPageShow = () => nudge('pageshow');
const onOnline = () => nudge('online');

function listen(on) {
  if (typeof window === 'undefined' || on === listening) return;
  listening = on;
  const m = on ? 'addEventListener' : 'removeEventListener';
  document[m]('visibilitychange', onVisibility);
  window[m]('focus', onFocus);
  window[m]('pageshow', onPageShow);
  window[m]('online', onOnline);
}

/**
 * Subscribe a page. onChange(events) = refetch silently; notify(text, kind) = show a toast.
 * Returns the unsubscribe function.
 */
export function subscribeChanges({ onChange, notify }) {
  const sub = { onChange: onChange || (() => {}), notify };
  subscribers.add(sub);
  listen(true);
  if (subscribers.size === 1) nudge('subscribe');
  return () => {
    subscribers.delete(sub);
    if (subscribers.size === 0) {
      listen(false);
      stopTimer();
      abortInflight();
    }
  };
}

/** "Updated HH:MM" listeners: called with the time of the last successful check. */
export function subscribeStamp(fn) {
  stampListeners.add(fn);
  return () => stampListeners.delete(fn);
}

export const lastSuccessfulCheckAt = () => lastCheckAt;

/** Tests only. */
export function __resetChangePoller() {
  stopTimer();
  abortInflight();
  subscribers.clear();
  stampListeners.clear();
  shownEvents.clear();
  listen(false);
  token = null;
  failures = 0;
  lastAttemptAt = 0;
  lastCheckAt = null;
}
