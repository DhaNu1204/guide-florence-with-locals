/*
 * Florence with Locals — minimal PWA service worker.
 *
 * Deliberately conservative:
 *  - NEVER touches /api/ requests (always straight to network — live data,
 *    auth headers, rate limits all untouched).
 *  - Navigations are network-first with a cached index.html fallback, so a
 *    fresh deploy is picked up on the next load (no stale-app problem).
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

  // App navigations: network first, cached shell as offline fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put('/index.html', copy));
          return res;
        })
        .catch(() => caches.match('/index.html'))
    );
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
