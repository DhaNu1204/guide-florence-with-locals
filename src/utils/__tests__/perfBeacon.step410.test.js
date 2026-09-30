/**
 * Step 4.10: the recorder tells the home-screen app from a tab, names what stalled, and delivers
 * the row of a failed load later (on 2026-09-30 the PWA's failed loads left no row at all).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

let beacons;
let store;
let mod;

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
  const keep = store; // a "later load" in the same install keeps its storage
  installEnv();
  if (keep) Object.assign(store, keep);
  store.token = 'a'.repeat(64);
  mod = await import('../perfBeacon');
  return mod;
};

const payloadOf = (i = 0) => JSON.parse(beacons[i].blob.__text);
const outbox = () => JSON.parse(store['fwl:perf-outbox'] || '[]');

beforeEach(() => { store = null; vi.useFakeTimers(); });
afterEach(() => { vi.useRealTimers(); delete global.fetch; window.matchMedia.mockImplementation((q) => ({ matches: false, media: q, addEventListener: vi.fn(), removeEventListener: vi.fn() })); });

describe('perfBeacon step 4.10', () => {
  it('records display mode, install id, build, load id', async () => {
    window.matchMedia.mockImplementation((q) => ({ matches: q === '(display-mode: standalone)', media: q }));
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart(); m.markListEnd(true);
    vi.advanceTimersByTime(1600);
    const p = payloadOf();
    expect(p.display_mode).toBe('standalone');
    expect(p.install_id).toMatch(/^[0-9a-f]{8,16}$/);
    expect(p.load_id).toMatch(/^[a-z0-9]{8,20}$/);
    expect(typeof p.at_s).toBe('number');
    expect(outbox()).toEqual([]); // a healthy load relies on its beacon
  });

  it('a tab is "browser"; the install id stays the same across loads', async () => {
    let m = await loadFresh();
    m.markEntry('fwl@0.0.2'); m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(1600);
    const first = payloadOf();
    expect(first.display_mode).toBe('browser');
    m = await loadFresh();
    m.markEntry('fwl@0.0.2'); m.markVerifyStart(); m.markVerifyEnd(true);
    vi.advanceTimersByTime(1600);
    expect(payloadOf().install_id).toBe(first.install_id);
    expect(payloadOf().load_id).not.toBe(first.load_id);
  });

  it('names the requests that stalled (file names only) and the probe result', async () => {
    const m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart();
    m.markTimeout('https://withlocals.deetech.cc/api/tours.php?page=1&upcoming=true&view=list');
    m.markTimeout('/api/tours.php?page=1');
    m.markTimeout('/api/guide-payments.php?action=pending_tours');
    m.markProbe({ status: 'ok', ms: 312.4 });
    m.markListEnd(false);
    vi.advanceTimersByTime(1600);
    const p = payloadOf();
    expect(p.stuck).toBe('tours.php,guide-payments.php');
    expect(p.timeouts).toBe(3);
    expect(p.probe_status).toBe('ok');
    expect(p.probe_ms).toBe(312);
  });

  it('a failed load keeps its row; the next load delivers it in one request and forgets it', async () => {
    let m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    m.markListStart(); m.markTimeout('/api/tours.php'); m.markListEnd(false);
    vi.advanceTimersByTime(1600);
    const failed = payloadOf();
    expect(outbox().map((x) => x.load_id)).toEqual([failed.load_id]);

    // next open: the auth check answers -> the kept row goes out (not the current load's own)
    const posts = [];
    global.fetch = vi.fn(async (url, opts) => { posts.push({ url, body: JSON.parse(opts.body) }); return { ok: true, status: 200 }; });
    m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    await vi.advanceTimersByTimeAsync(0);
    expect(posts).toHaveLength(1);
    expect(posts[0].url).toContain('/client_perf.php');
    expect(posts[0].body.items.map((x) => x.load_id)).toEqual([failed.load_id]);
    expect(outbox()).toEqual([]);
  });

  it('while the server still cannot be reached the row stays kept', async () => {
    let m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(false);
    vi.advanceTimersByTime(1600);
    expect(outbox()).toHaveLength(1);

    global.fetch = vi.fn(async () => { throw new TypeError('Load failed'); });
    m = await loadFresh();
    m.markEntry('fwl@0.0.2');
    m.markVerifyStart(); m.markVerifyEnd(true);
    await vi.advanceTimersByTimeAsync(0);
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(outbox()).toHaveLength(1);
  });

  it('endpointName never keeps a query string', async () => {
    const m = await loadFresh();
    expect(m.__internals.endpointName('/api/tours.php?token=secret')).toBe('tours.php');
    expect(m.__internals.endpointName('/api/')).toBeNull();
  });
});
