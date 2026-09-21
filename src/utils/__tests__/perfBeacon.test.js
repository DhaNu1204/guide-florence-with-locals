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
    expect(beacons.length).toBe(0);          // not yet: we wait the full 30s
    vi.advanceTimersByTime(25000);
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
    vi.advanceTimersByTime(30000);

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
    vi.advanceTimersByTime(30000);
    const p = payloadOf();
    expect(p.rate_limited).toBe(2);
    expect(p).toHaveProperty('effective_type');
    expect(p).toHaveProperty('conn_rtt');
    expect(p).toHaveProperty('online');
    expect(p).toHaveProperty('device');
  });

  it('flags the first load after a new release, and only that one', async () => {
    let m = await loadFresh();
    store['fwl:last-release'] = 'fwl@0.0.1';
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(30000);
    expect((payloadOf()).first_after_release).toBe(1);

    // the next load, same release, same device (the store survives)
    vi.resetModules();
    beacons = [];
    m = await import('../perfBeacon');
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(30000);
    expect((payloadOf()).first_after_release).toBe(0);
  });

  it('sends nothing when nobody is logged in', async () => {
    await loadFresh();
    delete store.token;
    mod.markEntry('fwl@0.0.2');
    mod.markVerifyStart();
    vi.advanceTimersByTime(30000);
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
    expect(() => vi.advanceTimersByTime(30000)).not.toThrow();
  });

  it('cannot break the page: a missing sendBeacon is simply skipped', async () => {
    const m = await loadFresh();
    navigator.sendBeacon = undefined;
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    expect(() => vi.advanceTimersByTime(30000)).not.toThrow();
  });
});
