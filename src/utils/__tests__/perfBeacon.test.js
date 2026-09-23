/**
 * Step 4.7: the field beacon. The point of these tests is the case the instrumentation
 * exists for — a load that never finishes must still produce a row, marked pending.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const ENDPOINT = '/api/client_perf.php';

let beacons;
let mod;
let store;

// The shared test setup mocks localStorage with bare vi.fn()s and jsdom's Blob has no
// .text(), so both get a tiny real implementation here. Nothing in the module changes.
const installEnv = () => {
  store = {};
  localStorage.getItem.mockImplementation((k) => (k in store ? store[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { store[k] = String(v); });
  localStorage.removeItem.mockImplementation((k) => { delete store[k]; });
  globalThis.Blob = class { constructor(parts) { this.__text = parts.join(''); } };
  beacons = [];
  navigator.sendBeacon = vi.fn((url, blob) => { beacons.push({ url, blob }); return true; });
};

const loadFresh = async () => {
  vi.resetModules();
  installEnv();
  store.token = 'a'.repeat(64);
  mod = await import('../perfBeacon');
  return mod;
};

const payloadOf = (i = 0) => JSON.parse(beacons[i].blob.__text);

beforeEach(() => { vi.useFakeTimers(); });
afterEach(() => { vi.useRealTimers(); });

describe('perfBeacon (step 4.7)', () => {
  it('a normal load sends exactly one row with sensible timings', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markChunkStart(); m.markChunkEnd(true);
    m.markListStart(); m.markListEnd(true);

    expect(beacons.length).toBe(0);   // nothing is sent until the load has settled
    vi.advanceTimersByTime(1600);
    expect(beacons.length).toBe(1);

    const p = payloadOf();
    expect(p.reason).toBe('complete');
    expect(p.release).toBe('fwl@0.0.2');
    expect(p.verify_status).toBe('ok');
    expect(p.chunk_status).toBe('ok');
    expect(p.list_status).toBe('ok');
    for (const k of ['entry_at', 'verify_start', 'verify_end', 'chunk_start', 'chunk_end', 'list_start', 'list_end']) {
      expect(typeof p[k]).toBe('number');
      expect(p[k]).toBeGreaterThanOrEqual(0);
    }
    expect(beacons[0].url).toContain(ENDPOINT);
  });

  it('a load where the list never returns STILL sends a row, marked pending', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markChunkStart(); m.markChunkEnd(true);
    m.markListStart();                       // and never ends - the bad morning

    vi.advanceTimersByTime(5000);
    expect(beacons.length).toBe(0);          // not yet: we wait the full 45s (step 4.8)
    vi.advanceTimersByTime(40000);
    expect(beacons.length).toBe(1);

    const p = payloadOf();
    expect(p.reason).toBe('deadline');
    expect(p.list_status).toBe('pending');
    expect(p.list_end).toBeNull();
    expect(p.verify_status).toBe('ok');
  });

  it('a load where the auth check never returns also sends a row', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart();                     // the white-screen case from Part 1
    vi.advanceTimersByTime(45000);

    const p = payloadOf();
    expect(p.verify_status).toBe('pending');
    expect(p.chunk_status).toBe('none');     // never even started - correctly not "pending"
    expect(p.list_status).toBe('none');
  });

  it('sends when the tab is hidden or closed before the load finishes', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart();
    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
    expect(beacons.length).toBe(1);
    expect((payloadOf()).reason).toBe('hidden');
  });

  it('never sends more than one row per load', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markChunkStart(); m.markChunkEnd(true);
    m.markListStart(); m.markListEnd(true);
    vi.advanceTimersByTime(2000);
    window.dispatchEvent(new Event('pagehide'));
    vi.advanceTimersByTime(60000);
    expect(beacons.length).toBe(1);
  });

  it('counts 429s and reports the connection facts', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markRateLimited(); m.markRateLimited();
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(45000);
    const p = payloadOf();
    expect(p.rate_limited).toBe(2);
    expect(p).toHaveProperty('effective_type');
    expect(p).toHaveProperty('conn_rtt');
    expect(p).toHaveProperty('online');
    expect(p).toHaveProperty('device');
  });

  it('flags the first load after a new release, and only that one', async () => {
    let m = await loadFresh();
    // the build id, not the version string: a deploy changes the entry hash every time
    document.head.innerHTML = '<script type="module" src="/assets/index-NEWHASH1.js"></scr' + 'ipt>';
    store['fwl:last-build'] = 'OLDHASH9';
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(45000);
    expect((payloadOf()).first_after_release).toBe(1);

    // the next load, same release, same device (the store survives)
    vi.resetModules();
    beacons = [];
    m = await import('../perfBeacon');
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(45000);
    expect((payloadOf()).first_after_release).toBe(0);
  });

  it('sends nothing when nobody is logged in', async () => {
    await loadFresh();
    delete store.token;
    mod.markEntry('fwl@0.0.2');
    mod.markVerifyStart();
    vi.advanceTimersByTime(45000);
    expect(beacons.length).toBe(0);
  });

  it('records no identifying data: route ids and secret tokens are stripped', async () => {
    const m = await loadFresh();
    const { routeName } = m.__internals;
    expect(routeName('/tours/12345')).toBe('/tours/:id');
    expect(routeName('/respond/abc123secret')).toBe('/respond/:token');
    expect(routeName('/tours?guide=Anna&date=2026-08-21')).toBe('/tours');
  });

  it('cannot break the page: a thrown sendBeacon is swallowed', async () => {
    const m = await loadFresh();
    navigator.sendBeacon = () => { throw new Error('beacon exploded'); };
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    expect(() => vi.advanceTimersByTime(45000)).not.toThrow();
  });

  it('cannot break the page: a missing sendBeacon is simply skipped', async () => {
    const m = await loadFresh();
    navigator.sendBeacon = undefined;
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    expect(() => vi.advanceTimersByTime(45000)).not.toThrow();
  });
});

describe('perfBeacon (step 4.8 repairs)', () => {
  it('a token wiped mid-load no longer silences the row (the 2026-09-23 morning)', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart();
    delete store.token;                       // the old verify catch deleted it here
    m.markVerifyEnd(false);
    m.markVerifyError('network');
    vi.advanceTimersByTime(1600);
    expect(beacons.length).toBe(1);
    const p = payloadOf();
    expect(p.token).toBe('a'.repeat(64));     // the start-of-load token still identifies the user
    expect(p.verify_error).toBe('network');
  });

  it('records how the load resolved: timeouts, automatic retries, Retry presses, the verify re-check', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(false); m.markVerifyError('timeout');
    m.markVerifyRetryStart();                 // holds the row open until it answers
    m.markTimeout(); m.markTimeout();
    m.markAutoRetry(true); m.markAutoRetry(false);
    m.markUserRetry();
    m.markListStart(); m.markListEnd(false);
    vi.advanceTimersByTime(5000);
    expect(beacons.length).toBe(0);           // the re-check is still running
    m.markVerifyRetryEnd(true);
    vi.advanceTimersByTime(1600);
    const p = payloadOf();
    expect(p.verify_error).toBe('timeout');
    expect(p.verify_retry).toBe('ok');
    expect(p.timeouts).toBe(2);
    expect(p.auto_retries).toBe(2);
    expect(p.auto_retry_ok).toBe(1);
    expect(p.user_retries).toBe(1);
    expect(p.list_status).toBe('failed');
  });

  it('reports whether the service worker started the page from its cached shell', async () => {
    const m = await loadFresh();
    document.head.innerHTML = '<meta name="fwl-shell" content="cached">';
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(1600);
    expect(payloadOf().shell_fallback).toBe(1);
    document.head.innerHTML = '';
    expect(m.__internals.shellFallback()).toBe(0);
  });

  it('a load with no token does not use up the post-deploy flag', async () => {
    let m = await loadFresh();
    document.head.innerHTML = '<script type="module" src="/assets/index-NEWHASH1.js"></scr' + 'ipt>';
    store['fwl:last-build'] = 'OLDHASH9';
    delete store.token;                       // the login page after a deploy
    m.markEntry('fwl@0.0.2');
    expect(store['fwl:last-build']).toBe('OLDHASH9');

    vi.resetModules();
    beacons = [];
    store.token = 'a'.repeat(64);             // logged in: the first REPORTED load after the deploy
    m = await import('../perfBeacon');
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(1600);
    expect(payloadOf().first_after_release).toBe(1);
    expect(store['fwl:last-build']).toBe('NEWHASH1');
  });
});

