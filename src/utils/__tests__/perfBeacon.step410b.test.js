/**
 * Step 4.10b: every bad load's row carries the server check, or the reason it has none.
 * On 2026-10-01 12:27 the home-screen app's Dashboard failed (all 5 requests stalled) after the owner
 * had already moved to Tours, so no banner was on screen to start the check and the row went out
 * with probe_status empty. Everything here runs offline: fetch is a stub.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

let beacons;
let store;
let healthCalls;

const okResponse = () => ({ ok: true, status: 200, clone: () => ({ arrayBuffer: async () => new ArrayBuffer(0) }) });

/** health.php answers as told; every other request hangs (the stalled path). */
const stubFetch = (health) => {
  healthCalls = 0;
  global.fetch = vi.fn((url, opts = {}) => {
    if (String(url).includes('health.php')) {
      healthCalls += 1;
      if (health === 'ok') return Promise.resolve(okResponse());
      if (health === 'network') return Promise.reject(new TypeError('Load failed'));
    }
    // hang until aborted, like a stalled connection
    return new Promise((_, reject) => {
      if (opts.signal) opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    });
  });
};

const loadFresh = async () => {
  vi.resetModules();
  store = { token: 'a'.repeat(64) };
  localStorage.getItem.mockImplementation((k) => (k in store ? store[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { store[k] = String(v); });
  localStorage.removeItem.mockImplementation((k) => { delete store[k]; });
  globalThis.Blob = class { constructor(parts) { this.__text = parts.join(''); } };
  beacons = [];
  navigator.sendBeacon = vi.fn((url, blob) => { beacons.push(JSON.parse(blob.__text)); return true; });
  return import('../perfBeacon');
};

/** The 12:27 Dashboard: auth ok, all five requests time out (twice for the retried reads), list fails. */
const replayDashboardStall = (m) => {
  m.markEntry('fwl@0.0.2');
  m.markVerifyStart(); m.markVerifyEnd(true);
  m.markListStart();
  for (const f of ['guide-payments.php', 'tours.php', 'guides.php', 'tour-groups.php', 'guide-requests.php']) {
    m.markTimeout(`/api/${f}?x=1`);
  }
  m.markListEnd(false);
};

const setOnline = (v) => Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => v });

beforeEach(() => { vi.useFakeTimers(); setOnline(true); });
afterEach(() => {
  vi.useRealTimers();
  delete global.fetch;
  setOnline(true);
  document.querySelectorAll('meta[name="fwl-shell"]').forEach((el) => el.remove());
});

describe('perfBeacon step 4.10b: the server check is recorded for every bad load', () => {
  it('12:27 replay: page left before it failed (no banner) -> the row still says the server answered', async () => {
    stubFetch('ok');
    const m = await loadFresh();
    replayDashboardStall(m);
    await vi.advanceTimersByTimeAsync(2000);
    expect(beacons).toHaveLength(1);
    expect(beacons[0].list_status).toBe('failed');
    expect(beacons[0].stuck).toBe('guide-payments.php,tours.php,guides.php,tour-groups.php,guide-requests.php');
    expect(beacons[0].probe_status).toBe('ok');
    expect(Number.isFinite(beacons[0].probe_ms)).toBe(true);
    expect(healthCalls).toBe(1); // five timeouts + the failure = ONE check
  });

  it('whole connection dead: the check times out too, and the row waits for it (6 s)', async () => {
    stubFetch('hang');
    const m = await loadFresh();
    replayDashboardStall(m);
    await vi.advanceTimersByTimeAsync(4000);
    expect(beacons).toHaveLength(0);            // still waiting for the check
    await vi.advanceTimersByTimeAsync(4000);
    expect(beacons).toHaveLength(1);
    expect(beacons[0].probe_status).toBe('timeout');
    expect(beacons[0].probe_ms).toBeGreaterThanOrEqual(6000);
  });

  it('a phone with no network at all: the check says offline without a request', async () => {
    stubFetch('ok');
    setOnline(false);
    const m = await loadFresh();
    replayDashboardStall(m);
    await vi.advanceTimersByTimeAsync(2000);
    expect(beacons[0].probe_status).toBe('offline');
    expect(healthCalls).toBe(0);
  });

  it('the check itself cannot connect -> "network"', async () => {
    stubFetch('network');
    const m = await loadFresh();
    replayDashboardStall(m);
    await vi.advanceTimersByTimeAsync(2000);
    expect(beacons[0].probe_status).toBe('network');
  });

  it('app hidden while the check is still out -> the row leaves at once and says "running"', async () => {
    stubFetch('hang');
    const m = await loadFresh();
    replayDashboardStall(m);
    await vi.advanceTimersByTimeAsync(500);
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    expect(beacons).toHaveLength(1);
    expect(beacons[0].reason).toBe('hidden');
    expect(beacons[0].probe_status).toBe('running');
  });

  it('app hidden while still loading, nothing failed yet -> "notrun"', async () => {
    stubFetch('ok');
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart();
    window.dispatchEvent(new Event('pagehide'));
    expect(beacons).toHaveLength(1);
    expect(beacons[0].list_status).toBe('pending');
    expect(beacons[0].probe_status).toBe('notrun');
  });

  it('still pending at the 45 s deadline with no timeout -> a check runs, then the row goes', async () => {
    stubFetch('ok');
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart();
    await vi.advanceTimersByTimeAsync(45000);
    await vi.advanceTimersByTimeAsync(100);
    expect(beacons).toHaveLength(1);
    expect(beacons[0].reason).toBe('deadline');
    expect(beacons[0].list_status).toBe('pending');
    expect(beacons[0].probe_status).toBe('ok');
  });

  it('started from the cached page (shell fallback) -> checked at once', async () => {
    stubFetch('ok');
    const meta = document.createElement('meta'); meta.setAttribute('name', 'fwl-shell');
    document.head.appendChild(meta);
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart(); m.markListEnd(true);
    await vi.advanceTimersByTimeAsync(2000);
    expect(beacons[0].shell_fallback).toBe(1);
    expect(beacons[0].probe_status).toBe('ok');
    expect(healthCalls).toBe(1);
  });

  it('the banner shares the running check (one request), and gets its answer', async () => {
    stubFetch('ok');
    const m = await loadFresh();
    replayDashboardStall(m);
    const shown = await m.serverCheck();
    expect(shown.status).toBe('ok');
    expect(healthCalls).toBe(1);
  });

  it('a healthy load makes no check and sends no probe status', async () => {
    stubFetch('ok');
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart(); m.markListEnd(true);
    await vi.advanceTimersByTimeAsync(2000);
    expect(beacons[0].probe_status).toBeNull();
    expect(healthCalls).toBe(0);
  });
});
