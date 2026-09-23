/**
 * Step 4.8 — one network policy for the whole app (Phase 4, Part 2B, fixes 1–3).
 *
 * Before this step no request had a timeout, so a connection that is accepted and then
 * never answers left the app waiting forever. Every request now has one, chosen by what it
 * is, and every failure is classified the same way everywhere:
 *
 *   timeout  - our own timer fired before the server finished answering
 *   network  - the browser could not complete the request at all (no signal, dropped link)
 *   http     - the server (or the hosting edge) answered with an error status
 *
 * Why these values. A healthy load measured in the field (4.7) answers the auth check in
 * 124–360 ms and the whole tour list in 0.9–3.2 s; the largest boot payload is the tour list
 * at ~58 KB brotli, which even a poor 2G link (~6 KB/s) delivers in about 10 s. So 15 s kills
 * nothing that is slow-but-working, and the one automatic retry gives a second full window.
 * Writes get 30 s because giving up on a write early only turns "saved" into "unknown".
 * The sync endpoints and server-side files are exempt from the short values: a sync is
 * allowed 180 s (longer than the hosting edge's own ~110 s cut, so the timer never fires
 * before the edge answers) and a server-built PDF/CSV 90 s.
 */

export const VERIFY_TIMEOUT_MS = 10000;
export const READ_TIMEOUT_MS = 15000;
export const WRITE_TIMEOUT_MS = 30000;
export const FILE_TIMEOUT_MS = 90000;
export const SYNC_TIMEOUT_MS = 180000;

const SYNC_URL = /\/bokun_sync\.php/;
const FILE_URL = /\/(participants|viator_legacy_export)\.php/;
const VERIFY_URL = /\/auth\.php\?(?:.*&)?action=verify/;

const isRead = (method) => {
  const m = String(method || 'get').toLowerCase();
  return m === 'get' || m === 'head';
};

/** The timeout for one request, by what it is. */
export function timeoutFor(method, url) {
  const u = String(url || '');
  if (SYNC_URL.test(u)) return SYNC_TIMEOUT_MS;
  if (FILE_URL.test(u)) return FILE_TIMEOUT_MS;
  if (VERIFY_URL.test(u)) return VERIFY_TIMEOUT_MS;
  return isRead(method) ? READ_TIMEOUT_MS : WRITE_TIMEOUT_MS;
}

/**
 * May this request be retried automatically, once? Only reads, and not the long ones (a sync
 * or a PDF retried behind the user's back would double the server's work). A write is NEVER
 * retried: a payment POST that timed out may already be recorded on the server.
 */
export function mayAutoRetry(method, url) {
  if (!isRead(method)) return false;
  const u = String(url || '');
  return !SYNC_URL.test(u) && !FILE_URL.test(u);
}

/** Edge / gateway statuses: the request may or may not have reached PHP. */
const GATEWAY = new Set([502, 503, 504]);

export class TimeoutError extends Error {
  constructor(ms) {
    super(`No answer within ${Math.round(ms / 1000)} s`);
    this.name = 'TimeoutError';
    this.code = 'ETIMEDOUT';
    this.timeoutMs = ms;
  }
}

/**
 * Classify any failure from fetch, fetchWithTimeout or axios.
 * Returns { kind: 'timeout' | 'network' | 'http' | 'other', status }.
 */
export function classifyError(err) {
  if (!err) return { kind: 'other', status: null };
  if (err.fwlKind) return { kind: err.fwlKind, status: err.status ?? null };
  if (err.name === 'TimeoutError' || err.code === 'ETIMEDOUT' || err.code === 'ECONNABORTED') {
    return { kind: 'timeout', status: null };
  }
  const status = err.response?.status ?? err.status ?? null;
  if (status) return { kind: 'http', status };
  // axios: no response at all; fetch: a TypeError with the browser's network wording
  // (Chrome "Failed to fetch", Safari "Load failed", Firefox "NetworkError ...").
  if (err.code === 'ERR_NETWORK' || err.isAxiosError
      || (err.name === 'TypeError' && /fetch|load failed|network/i.test(String(err.message)))) {
    return { kind: 'network', status: null };
  }
  return { kind: 'other', status: null };
}

/** Is it worth trying the same read again? (A 4xx or a 500 will not change by retrying.) */
export function isTransient(err) {
  const { kind, status } = classifyError(err);
  return kind === 'timeout' || kind === 'network' || (kind === 'http' && GATEWAY.has(status));
}

/**
 * For a write: did it fail in a way that leaves us not knowing whether the server did it?
 * A timeout, a dropped connection or a gateway error all qualify - the request may have been
 * processed and only the answer lost.
 */
export function isOutcomeUnknown(err) {
  return isTransient(err);
}

/** A short, plain reason for a failed load, fit for the owner on a street corner. */
export function describeLoadError(err) {
  const { kind, status } = classifyError(err);
  if (kind === 'timeout') return 'The server did not answer in time — the connection is probably weak.';
  if (kind === 'network') return 'No connection to the server — check your signal.';
  if (kind === 'http' && status >= 500) return `The server had a problem (error ${status}).`;
  if (kind === 'http') return `The server refused the request (error ${status}).`;
  // Not a network problem (e.g. an incomplete group list): the app's own message is the truth.
  return (err && err.message) || 'Something went wrong while loading.';
}

export const WRITE_UNKNOWN_MESSAGE =
  "We couldn't confirm this was saved — the connection dropped before the server answered. " +
  'It may already be recorded. Refresh the page and check before trying again.';

/** The message for a failed write: "unknown outcome" when that is the truth, else the caller's. */
export function writeFailureMessage(err, fallback) {
  return err && err.outcomeUnknown ? WRITE_UNKNOWN_MESSAGE : fallback;
}

/** Window event: a write ended with an unknown outcome (App.jsx shows one toast). */
export const WRITE_UNKNOWN_EVENT = 'app:write-unknown';

export function notifyWriteUnknown() {
  if (typeof window === 'undefined') return;
  try { window.dispatchEvent(new Event(WRITE_UNKNOWN_EVENT)); } catch (e) { /* never matters */ }
}

/**
 * fetch() with a timeout that covers the WHOLE answer, body included: the body is buffered
 * (through a clone) before this resolves, so a link that delivers headers and then stalls
 * cannot hang the caller's later response.json(). The caller's own AbortSignal still works.
 */
export async function fetchWithTimeout(url, options = {}, ms = timeoutFor(options.method, url)) {
  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => { timedOut = true; controller.abort(); }, ms);
  const outer = options.signal;
  const onOuterAbort = () => controller.abort();
  if (outer) {
    if (outer.aborted) controller.abort();
    else outer.addEventListener('abort', onOuterAbort, { once: true });
  }
  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    try {
      if (response && typeof response.clone === 'function') {
        const copy = response.clone();
        if (copy && typeof copy.arrayBuffer === 'function') await copy.arrayBuffer();
      }
    } catch (bodyError) {
      if (timedOut) throw new TimeoutError(ms);
      throw bodyError;
    }
    return response;
  } catch (error) {
    if (timedOut) throw new TimeoutError(ms);
    throw error;
  } finally {
    clearTimeout(timer);
    if (outer) outer.removeEventListener('abort', onOuterAbort);
  }
}

/** Wrap an error that came back as an HTTP response (fetch does not throw on those). */
export function httpError(response) {
  const e = new Error(`HTTP ${response.status}`);
  e.fwlKind = 'http';
  e.status = response.status;
  return e;
}

/** "09:12" today, "22 Sep 18:40" on another day - for "showing data from …". */
export function formatShownAt(at) {
  if (!at) return '';
  const d = at instanceof Date ? at : new Date(at);
  if (Number.isNaN(d.getTime())) return '';
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const today = new Date();
  const sameDay = d.getFullYear() === today.getFullYear()
    && d.getMonth() === today.getMonth() && d.getDate() === today.getDate();
  if (sameDay) return time;
  return `${d.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${time}`;
}
