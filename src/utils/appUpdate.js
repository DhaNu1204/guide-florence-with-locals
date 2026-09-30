/**
 * Step 4.10 — an installed app (home-screen PWA) always picks up a new version.
 *
 * Before this step nothing ever asked "is there a newer version?". The service worker was
 * registered once per page load, and an iOS home-screen app that is resumed from the background
 * does not load the page again - it keeps running the JavaScript it started with, for days.
 *
 * Now the app compares the build it runs (the hash in its own entry <script> tag) with the build
 * the site serves (health.php?probe=1 reads it from the deployed index.html):
 *   - when the app is opened or brought back to the front, and when the phone comes back online;
 *   - a different build right after opening/resuming, before the user touched anything and with
 *     no form field in use -> reload straight into the new version;
 *   - otherwise -> a "New version — tap to update" bar (never a reload under someone's fingers).
 * A reload that did not change the build (e.g. the network was too slow and the service worker
 * started the cached copy) is not repeated automatically: the bar is shown instead.
 */
import { fetchWithTimeout } from '../services/netPolicy';

export const UPDATE_EVENT = 'app:update-available';
const RELOAD_GUARD_KEY = 'fwl:update-reload';
const CHECK_TIMEOUT_MS = 8000;
const MIN_GAP_MS = 60000;      // at most one check a minute
const AUTO_WINDOW_MS = 20000;  // "just opened": auto-reload only this soon after open/resume

/** The build this page runs: the hash of /assets/index-<hash>.js (null in dev - no check there). */
export function currentBuild(doc = typeof document !== 'undefined' ? document : null) {
  try {
    const el = doc && doc.querySelector('script[type="module"][src*="/assets/index-"]');
    const m = el && el.getAttribute('src').match(/\/assets\/index-([A-Za-z0-9_-]{6,})\.js$/);
    return m ? m[1] : null;
  } catch (e) {
    return null;
  }
}

/** 'same' | 'new' | 'unknown' (no build to compare, or the server could not be asked). */
export async function checkForUpdate({ current = currentBuild(), fetcher = fetchWithTimeout } = {}) {
  if (!current) return { status: 'unknown', current, server: null };
  const base = import.meta.env.VITE_API_URL || '/api';
  try {
    const res = await fetcher(`${base}/health.php?probe=1&_=${Date.now()}`, { cache: 'no-store' }, CHECK_TIMEOUT_MS);
    if (!res || !res.ok) return { status: 'unknown', current, server: null };
    const body = await res.json();
    const server = body && typeof body.build === 'string' ? body.build : null;
    if (!server) return { status: 'unknown', current, server: null };
    return { status: server === current ? 'same' : 'new', current, server };
  } catch (e) {
    return { status: 'unknown', current, server: null };
  }
}

/** Reload straight away, or show the bar? Pure, so it can be tested. */
export function shouldAutoReload({ sinceShownMs, interacted, editing, alreadyReloadedFor, server }) {
  if (alreadyReloadedFor && alreadyReloadedFor === server) return false; // reload did not help
  return !interacted && !editing && sinceShownMs <= AUTO_WINDOW_MS;
}

function readGuard() {
  try { return sessionStorage.getItem(RELOAD_GUARD_KEY); } catch (e) { return null; }
}

/** Load the new version now (the "Update" button, or the automatic case). */
export function reloadToNewVersion(server) {
  try { if (server) sessionStorage.setItem(RELOAD_GUARD_KEY, server); } catch (e) { /* fine */ }
  try {
    // Let the browser fetch a changed sw.js too; the reload does not wait for it.
    if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
      navigator.serviceWorker.getRegistration().then((r) => r && r.update()).catch(() => {});
    }
  } catch (e) { /* fine */ }
  window.location.reload();
}

function isEditing() {
  const el = typeof document !== 'undefined' ? document.activeElement : null;
  if (!el) return false;
  const tag = (el.tagName || '').toLowerCase();
  return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable === true;
}

let started = false;

/**
 * Start watching (once per page). Calls onNew(server) when the bar should be shown; reloads by
 * itself in the "just opened" case. Returns a stop function (tests).
 */
export function startUpdateWatch(onNew) {
  if (started || typeof window === 'undefined') return () => {};
  started = true;
  let shownAt = Date.now();
  let interacted = false;
  let lastCheck = 0;
  let announced = null;

  const run = async (force = false) => {
    if (!force && Date.now() - lastCheck < MIN_GAP_MS) return;
    lastCheck = Date.now();
    const r = await checkForUpdate();
    if (r.status !== 'new') return;
    const auto = shouldAutoReload({
      sinceShownMs: Date.now() - shownAt,
      interacted,
      editing: isEditing(),
      alreadyReloadedFor: readGuard(),
      server: r.server,
    });
    if (auto) { reloadToNewVersion(r.server); return; }
    if (announced !== r.server) {
      announced = r.server;
      onNew(r.server);
    }
  };

  const onVisible = () => {
    if (document.visibilityState !== 'visible') return;
    shownAt = Date.now();   // a resumed home-screen app counts as "just opened"
    interacted = false;
    run(true);
  };
  const onInteract = () => { interacted = true; };
  const onOnline = () => run(true);

  document.addEventListener('visibilitychange', onVisible);
  window.addEventListener('pageshow', onVisible);
  window.addEventListener('online', onOnline);
  window.addEventListener('pointerdown', onInteract, true);
  window.addEventListener('keydown', onInteract, true);
  const first = setTimeout(() => run(true), 1500); // right after start, off the critical path

  return () => {
    started = false;
    clearTimeout(first);
    document.removeEventListener('visibilitychange', onVisible);
    window.removeEventListener('pageshow', onVisible);
    window.removeEventListener('online', onOnline);
    window.removeEventListener('pointerdown', onInteract, true);
    window.removeEventListener('keydown', onInteract, true);
  };
}
