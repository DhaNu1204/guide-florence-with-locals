/**
 * Step 4.8, item 6: the service worker's page load gets a timeout. A network that accepts the
 * connection and never answers used to leave the page white forever (the cached shell was only
 * used when fetch REJECTED). Now the cached index.html starts the app after NAV_TIMEOUT_MS,
 * marked with <meta name="fwl-shell"> for the field recorder, and the late network answer still
 * refreshes the cache.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const SW_SOURCE = readFileSync(resolve(__dirname, '../../public/sw.js'), 'utf8');
const SHELL = '<!DOCTYPE html><html><head><meta charset="UTF-8" /></head><body><div id="root"></div></body></html>';

let listeners;
let cacheStore;
let networkFetch;

// Runs the real public/sw.js in a sandbox with a fake worker scope, Cache API and network.
function loadWorker() {
  listeners = {};
  cacheStore = new Map();
  const cache = {
    match: async (key) => { const r = cacheStore.get(typeof key === 'string' ? key : key.url); return r ? r.clone() : undefined; },
    put: async (key, res) => { cacheStore.set(typeof key === 'string' ? key : key.url, res); },
    delete: async (key) => cacheStore.delete(typeof key === 'string' ? key : key.url),
    addAll: async () => {},
  };
  const sandbox = {
    self: {
      location: { origin: 'https://withlocals.test' },
      addEventListener: (type, fn) => { listeners[type] = fn; },
      skipWaiting: () => {},
      clients: { claim: () => {} },
    },
    caches: {
      open: async () => cache,
      match: (key) => cache.match(key),
      keys: async () => [],
      delete: async () => true,
    },
    fetch: (...a) => networkFetch(...a),
    Response, Headers, URL, Promise,
    setTimeout: (...a) => setTimeout(...a),
    clearTimeout: (...a) => clearTimeout(...a),
  };
  vm.runInNewContext(SW_SOURCE, sandbox, { filename: 'sw.js' });
}

function navigate(url = 'https://withlocals.test/tickets') {
  let responded;
  listeners.fetch({
    request: { url, method: 'GET', mode: 'navigate' },
    respondWith: (p) => { responded = Promise.resolve(p); },
    waitUntil: () => {},
  });
  return responded;
}

const html = (body) => new Response(body, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });

beforeEach(() => { vi.useFakeTimers(); loadWorker(); });
afterEach(() => { vi.useRealTimers(); });

describe('sw.js navigation (step 4.8)', () => {
  it('a stalled network: after 5 s the cached shell starts the app, marked as cached', async () => {
    cacheStore.set('/index.html', html(SHELL));
    let answer;
    networkFetch = () => new Promise((r) => { answer = r; });
    const responded = navigate();
    let settled = null;
    responded.then((r) => { settled = r; });
    await vi.advanceTimersByTimeAsync(4900);
    expect(settled).toBeNull();                // still giving the network its chance
    await vi.advanceTimersByTimeAsync(200);
    const res = await responded;
    const body = await res.text();
    expect(res.status).toBe(200);
    expect(body).toContain('<meta name="fwl-shell" content="cached">');
    expect(body).toContain('<div id="root">');

    // the late answer still refreshes the cached shell for next time
    answer(html(SHELL.replace('root', 'root-new')));
    await vi.advanceTimersByTimeAsync(0);
    const refreshed = await cacheStore.get('/index.html').clone().text();
    expect(refreshed).toContain('root-new');
  });

  it('a network that answers in time is used as before (no marker)', async () => {
    cacheStore.set('/index.html', html(SHELL));
    networkFetch = async () => html(SHELL.replace('root', 'root-live'));
    const body = await (await navigate()).text();
    expect(body).toContain('root-live');
    expect(body).not.toContain('fwl-shell');
  });

  it('a network that fails outright falls back at once', async () => {
    cacheStore.set('/index.html', html(SHELL));
    networkFetch = async () => { throw new TypeError('Failed to fetch'); };
    const body = await (await navigate()).text();
    expect(body).toContain('fwl-shell');
  });

  it('an error page from the edge never becomes the cached shell', async () => {
    cacheStore.set('/index.html', html(SHELL));
    networkFetch = async () => new Response('<html>503</html>', { status: 503, headers: { 'Content-Type': 'text/html' } });
    await navigate();
    await vi.advanceTimersByTimeAsync(0);
    expect(await cacheStore.get('/index.html').clone().text()).toBe(SHELL);
  });
});
