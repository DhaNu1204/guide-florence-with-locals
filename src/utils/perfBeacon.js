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
// Step 4.8: 45 s, not 30. Every request now has a timeout (15 s for a read, one automatic
// retry), so a stalled load resolves itself by ~30 s; the extra margin lets the row record how
// it resolved (timeout fired, retry ok or not) instead of an early "pending".
const HARD_DEADLINE_MS = 45000;
const SETTLE_GRACE_MS = 1500;   // let a late request register before we call the load complete
const RELEASE_KEY = 'fwl:last-build';

/**
 * What identifies "this build" for the post-deploy question.
 *
 * The release tag is `fwl@<package.json version>`, which we hardly ever bump, so on its own
 * it would miss almost every deploy. The entry script's content hash changes on every single
 * one, so that is what is compared. In dev there is no hashed entry and it falls back to the
 * release tag.
 */
function buildId(release) {
  try {
    const el = document.querySelector('script[type="module"][src]');
    const m = el && el.getAttribute('src').match(/-([A-Za-z0-9_-]{6,})\.js$/);
    if (m) return m[1];
  } catch (e) { /* fall through */ }
  return String(release || 'dev');
}

// 'none' = never started on this load. Since step 4.8 every page with a data fetch marks it,
// so a 'none' in `list` means "this page has no data fetch", never "we were not looking".
const state = {
  sent: false,
  release: null,
  route: null,
  entryAt: null,
  entryToken: null,        // step 4.8: captured at start, so a token wiped mid-load cannot silence the row
  verify: { start: null, end: null, status: 'none' },
  verifyRetry: { start: null, end: null, status: 'none' }, // step 4.8: the automatic re-check
  verifyError: null,       // step 4.8: 'network' | 'timeout' | 'http:503' ...
  chunk: { start: null, end: null, status: 'none' },
  list: { start: null, end: null, status: 'none' },
  rateLimited: 0,
  timeouts: 0,             // step 4.8: how many request timers fired
  autoRetries: 0,          // step 4.8: automatic retries of reads, and how many of them worked
  autoRetryOk: 0,
  userRetries: 0,          // step 4.8: presses of a Retry button
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

/**
 * Step 4.8: did the service worker start this page from its cached copy of index.html because
 * the network did not answer in time? sw.js marks that copy with a <meta name="fwl-shell">.
 */
function shellFallback() {
  try {
    return document.querySelector('meta[name="fwl-shell"]') ? 1 : 0;
  } catch (e) {
    return 0;
  }
}

function currentToken() {
  try { return localStorage.getItem('token'); } catch (e) { return null; }
}

function buildPayload(reason) {
  const v = freeze(state.verify);
  const vr = freeze(state.verifyRetry);
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
    verify_error: state.verifyError,
    verify_retry: vr.status,
    timeouts: state.timeouts,
    auto_retries: state.autoRetries,
    auto_retry_ok: state.autoRetryOk,
    user_retries: state.userRetries,
    shell_fallback: shellFallback(),
    online: navigator.onLine === false ? 0 : 1,
    sw_controlled: (navigator.serviceWorker && navigator.serviceWorker.controller) ? 1 : 0,
    first_after_release: state.firstAfterRelease ? 1 : 0,
    device: deviceLabel(),
    ...connectionFacts(),
    // Step 4.8: the token as it is now, or as it was when the page started. The bad morning of
    // 2026-09-23 wiped the token on a network error and so deleted its own row; the session
    // itself was still valid, so the start-of-load token still identifies the user.
    token: currentToken() || state.entryToken || null,
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
  return [state.verify, state.verifyRetry, state.chunk, state.list].every((p) => p.status !== 'started');
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
  state.entryToken = currentToken();

  // "Is this the first load since we deployed?" - the slowest load of all, per Part 1.
  // Step 4.8: only a load that can report itself (a token is present) marks the build as
  // seen. On 2026-09-22 a logged-out login-page load used the flag up and the phone's first
  // reported load after the deploy came through as "not after a deploy".
  try {
    const id = buildId(state.release);
    const last = localStorage.getItem(RELEASE_KEY);
    state.firstAfterRelease = last !== null && last !== id;
    if (state.entryToken) localStorage.setItem(RELEASE_KEY, id);
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

// Step 4.8 ------------------------------------------------------------------------------------
/** Why the auth check failed: 'network', 'timeout' or 'http:<status>'. The first reason wins. */
export const markVerifyError = safe((reason) => {
  if (!state.verifyError) state.verifyError = String(reason || '').slice(0, 16) || null;
});
export const markVerifyRetryStart = safe(() => startPhase(state.verifyRetry));
export const markVerifyRetryEnd = safe((ok) => endPhase(state.verifyRetry, ok));
export const markTimeout = safe(() => { state.timeouts += 1; });
export const markAutoRetry = safe((ok) => {
  state.autoRetries += 1;
  if (ok) state.autoRetryOk += 1;
});
export const markUserRetry = safe(() => { state.userRetries += 1; });

// Testing seams only - not used by the app.
export const __state = state;
export const __internals = { routeName, deviceLabel, freeze, buildPayload, allSettled, buildId, shellFallback };
