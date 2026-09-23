/*
 * Florence with Locals — minimal PWA service worker.
 *
 * Deliberately conservative:
 *  - NEVER touches /api/ requests (always straight to network — live data,
 *    auth headers, rate limits all untouched).
 *  - Navigations are network-first with a cached index.html fallback, so a
 *    fresh deploy is picked up on the next load (no stale-app problem).
 *    Step 4.8: the network gets NAV_TIMEOUT_MS to answer. On a link that accepts
 *    the connection and then delivers nothing, the page used to stay white forever
 *    (the fallback only ran when fetch rejected). Now the cached shell starts the
 *    app after a few seconds, marked with <meta name="fwl-shell"> so the field
 *    recorder can tell, and the network answer still refreshes the cache.
 *  - Hashed static assets (/assets/*) are cached stale-while-revalidate.
 *
 * Bump CACHE_VERSION to force-clear old caches on deploy if ever needed.
 */
const CACHE_VERSION = 'fwl-v2'; // step 4.1b: bumped to drop caches that hold an HTML fallback under an asset URL

// Step 4.1b: a hashed asset that is missing on the server does NOT 404 - the SPA rewrite answers
// with index.html and HTTP 200. Caching that under the asset's URL poisons the entry permanently:
// the module import then fails with "Expected a JavaScript-or-Wasm module script but the server
// responded with a MIME type of text/html" on every later load, even after the file is back.
// Observed on staging on 2026-09-20 while verifying this step. So: never store, and never serve,
// an HTML response for an /assets/ request.
function isHtml(res) {
  return !!res && (res.headers.get('content-type') || '').toLowerCase().includes('text/html');
}

// Step 4.8: how long a page load waits for the network before starting from the cached shell.
// index.html is ~1 KB, so a link that has not answered in 5 s is stalled, not slow; and the
// shell is the same app either way - its own requests then have their own timeouts.
const NAV_TIMEOUT_MS = 5000;

// The cached index.html, marked so the page knows it was started from the cache.
async function cachedShell() {
  const cached = await caches.match('/index.html');
  if (!cached) return null;
  const html = await cached.text();
  const headers = new Headers(cached.headers);
  headers.delete('content-length');
  headers.delete('content-encoding');
  return new Response(html.replace('<head>', '<head><meta name="fwl-shell" content="cached">'), {
    status: 200,
    statusText: 'OK',
    headers,
  });
}

async function navigationResponse(network) {
  const timeout = new Promise((resolve) => setTimeout(() => resolve('timeout'), NAV_TIMEOUT_MS));
  try {
    const winner = await Promise.race([network, timeout]);
    if (winner !== 'timeout') return winner;
  } catch (e) {
    /* the network failed outright: fall through to the cached shell */
  }
  const shell = await cachedShell();
  // Nothing cached yet (a very first visit): there is nothing better than waiting.
  return shell || network;
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(['/', '/index.html']))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Only same-origin GETs; never intercept the API.
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return;
  }

  // App navigations: network first, cached shell after NAV_TIMEOUT_MS or on failure.
  if (req.mode === 'navigate') {
    const network = fetch(req).then((res) => {
      // Step 4.8: only a real page becomes the shell - never an error page from the edge.
      if (res && res.ok && isHtml(res)) {
        const copy = res.clone();
        caches.open(CACHE_VERSION).then((cache) => cache.put('/index.html', copy));
      }
      return res;
    });
    // Keep the worker alive until the network answers, so a late answer still refreshes the cache.
    event.waitUntil(network.then(() => undefined, () => undefined));
    event.respondWith(navigationResponse(network));
    return;
  }

  // Static assets: stale-while-revalidate (step 4.1b: HTML never counts as an asset).
  event.respondWith(
    caches.open(CACHE_VERSION).then(async (cache) => {
      let cached = await cache.match(req);
      if (isHtml(cached)) {
        // A poisoned entry from before this version: drop it and go to the network.
        await cache.delete(req);
        cached = undefined;
      }
      const network = fetch(req)
        .then((res) => {
          if (res && res.status === 200 && !isHtml(res)) cache.put(req, res.clone());
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});
