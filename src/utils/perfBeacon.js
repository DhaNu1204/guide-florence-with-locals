/**
 * Step 4.7 — field instrumentation. MEASUREMENT ONLY.
 *
 * Part 1 of the mobile investigation established that the app has no timeout on any
 * request, so a stalled connection leaves it waiting forever, and that opening the tour
 * list costs eight round trips. What Part 1 could NOT establish is which of those the
 * owner actually hits on a bad morning in the street, because a Florence pavement cannot
 * be simulated from a datacenter.
 *
 * This module records what his phone really experiences and posts it once per page load.
 * It changes nothing: it starts no request of its own beyond the single beacon at the end,
 * it never awaits anything, it never throws into a caller, and every entry point is
 * wrapped so that a fault in here cannot affect the page. If this file were deleted the
 * app would behave identically.
 *
 * Nothing identifying is recorded: no tour data, no customer data, no names, no URLs
 * beyond the route name, no free text. Timings, statuses and connection facts only.
 */

// Same base as every other call, so a dev build posts to its own API instead of a 404.
const ENDPOINT = `${import.meta.env.VITE_API_URL || '/api'}/client_perf.php`;
const HARD_DEADLINE_MS = 30000; // a load that has not finished by now is the case we are hunting
const SETTLE_GRACE_MS = 1500;   // let a late request register before we call the load complete
const RELEASE_KEY = 'fwl:last-release';

// 'none' = never started on this load (e.g. a route that shows no list).
const state = {
  sent: false,
  release: null,
  route: null,
  entryAt: null,
  verify: { start: null, end: null, status: 'none' },
  chunk: { start: null, end: null, status: 'none' },
  list: { start: null, end: null, status: 'none' },
  rateLimited: 0,
  firstAfterRelease: null,
  deadlineTimer: null,
  settleTimer: null,
};

const now = () => Math.round(performance.now());

/** Never let instrumentation break a page: every public entry point goes through this. */
const safe = (fn) => (...args) => {
  try { return fn(...args); } catch (e) { /* measurement must never matter */ }
};

/**
 * The route, with no identifiers in it: '/tours/123' is recorded as '/tours/:id'.
 * Nothing else from the URL is kept - no query string, ever (the guide-response page
 * carries a secret token in its path, so it is collapsed to '/respond/:token').
 */
function routeName(pathname) {
  const p = String(pathname || '/').split('?')[0].split('#')[0];
  return p
    .replace(/\/respond\/[^/]+/, '/respond/:token')
    .replace(/\/\d+(?=\/|$)/g, '/:id')
    .slice(0, 40);
}

/** Coarse device string, e.g. "Android/Chrome" or "iOS/Safari". No full user agent. */
function deviceLabel(ua = navigator.userAgent) {
  const os = /iPhone|iPad|iPod/i.test(ua) ? 'iOS'
    : /Android/i.test(ua) ? 'Android'
    : /Windows/i.test(ua) ? 'Windows'
    : /Macintosh|Mac OS/i.test(ua) ? 'macOS'
    : /Linux/i.test(ua) ? 'Linux' : 'other';
  const br = /Edg\//i.test(ua) ? 'Edge'
    : /OPR\//i.test(ua) ? 'Opera'
    : /Chrome\//i.test(ua) ? 'Chrome'
    : /Firefox\//i.test(ua) ? 'Firefox'
    : /Safari\//i.test(ua) ? 'Safari' : 'other';
  const major = (ua.match(/(?:Chrome|Firefox|Version|Edg|OPR)\/(\d{1,3})/) || [])[1] || '';
  return `${os}/${br}${major ? ' ' + major : ''}`.slice(0, 40);
}

function connectionFacts() {
  const c = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
  return {
    effective_type: c && typeof c.effectiveType === 'string' ? c.effectiveType.slice(0, 12) : null,
    // NetworkInformation is absent on iOS Safari - nulls there are expected, not a bug.
    conn_rtt: c && Number.isFinite(c.rtt) ? Math.round(c.rtt) : null,
    conn_downlink: c && Number.isFinite(c.downlink) ? Math.round(c.downlink * 100) / 100 : null,
  };
}

/** Anything started but not finished when we send is exactly what we came for. */
function freeze(phase) {
  if (phase.status === 'none') return { start: null, end: null, status: 'none' };
  return {
    start: phase.start,
    end: phase.end,
    status: phase.status === 'started' ? 'pending' : phase.status,
  };
}

function buildPayload(reason) {
  const v = freeze(state.verify);
  const c = freeze(state.chunk);
  const l = freeze(state.list);
  return {
    release: state.release,
    route: state.route || routeName(location.pathname),
    reason,                                   // complete | deadline | hidden | pagehide
    nav_start: 0,                             // every mark below is ms from navigation start
    entry_at: state.entryAt,
    verify_start: v.start, verify_end: v.end, verify_status: v.status,
    chunk_start: c.start, chunk_end: c.end, chunk_status: c.status,
    list_start: l.start, list_end: l.end, list_status: l.status,
    rate_limited: state.rateLimited,
    online: navigator.onLine === false ? 0 : 1,
    sw_controlled: (navigator.serviceWorker && navigator.serviceWorker.controller) ? 1 : 0,
    first_after_release: state.firstAfterRelease ? 1 : 0,
    device: deviceLabel(),
    ...connectionFacts(),
    token: localStorage.getItem('token') || null,
  };
}

/**
 * The send itself.
 *
 * sendBeacon is fire-and-forget by definition: it hands the request to the browser, returns
 * a boolean immediately, and its outcome is not observable. A 500, a hang or a refused
 * connection at the other end therefore cannot be seen here and cannot affect the page -
 * which is the property we want, and it is why the token travels in the JSON body: beacons
 * cannot carry an Authorization header, and this is the same secret over the same TLS to
 * the same origin (the endpoint never logs the body).
 */
function send(reason) {
  if (state.sent) return;
  state.sent = true;
  if (state.deadlineTimer) clearTimeout(state.deadlineTimer);
  if (state.settleTimer) clearTimeout(state.settleTimer);
  try {
    if (!navigator.sendBeacon) return;
    const payload = buildPayload(reason);
    if (!payload.token) return; // not logged in: nothing to attribute, nothing to send
    // text/plain on purpose: it is a CORS-safelisted content type, so this request can
    // never trigger a preflight. A beacon cannot answer a preflight, and a blocked
    // preflight means no measurement at all - which is how this was found while testing.
    const blob = new Blob([JSON.stringify(payload)], { type: 'text/plain;charset=UTF-8' });
    navigator.sendBeacon(ENDPOINT, blob);
  } catch (e) { /* silent by design */ }
}

/** All started phases finished? (A phase that never started does not hold the load open.) */
function allSettled() {
  return [state.verify, state.chunk, state.list].every((p) => p.status !== 'started');
}

function scheduleSettleCheck() {
  if (state.sent) return;
  if (state.settleTimer) clearTimeout(state.settleTimer);
  if (!allSettled()) return;
  state.settleTimer = setTimeout(() => {
    if (allSettled()) send('complete');
  }, SETTLE_GRACE_MS);
}

const startPhase = (p) => { if (p.status === 'none') { p.start = now(); p.status = 'started'; } };
const endPhase = (p, ok) => {
  if (p.status !== 'started') return;
  p.end = now();
  p.status = ok ? 'ok' : 'failed';
  scheduleSettleCheck();
};

// --- public API: every one of these is a no-op if anything goes wrong ---------------------

export const markEntry = safe((release) => {
  state.release = String(release || '').slice(0, 32);
  state.route = routeName(location.pathname);
  state.entryAt = now();

  // "Is this the first load since we deployed?" - the slowest load of all, per Part 1.
  try {
    const last = localStorage.getItem(RELEASE_KEY);
    state.firstAfterRelease = last !== null && last !== state.release;
    localStorage.setItem(RELEASE_KEY, state.release);
  } catch (e) {
    state.firstAfterRelease = false; // storage blocked: simply unknown, never a failure
  }

  // The three guarantees that a load which never finishes still reports itself.
  state.deadlineTimer = setTimeout(() => send('deadline'), HARD_DEADLINE_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') send('hidden');
  });
  window.addEventListener('pagehide', () => send('pagehide'));
});

export const markVerifyStart = safe(() => startPhase(state.verify));
export const markVerifyEnd = safe((ok) => endPhase(state.verify, ok));
export const markChunkStart = safe(() => startPhase(state.chunk));
export const markChunkEnd = safe((ok) => endPhase(state.chunk, ok));
export const markListStart = safe(() => startPhase(state.list));
export const markListEnd = safe((ok) => endPhase(state.list, ok));
export const markRateLimited = safe(() => { state.rateLimited += 1; });

// Testing seams only - not used by the app.
export const __state = state;
export const __internals = { routeName, deviceLabel, freeze, buildPayload, allSettled };
