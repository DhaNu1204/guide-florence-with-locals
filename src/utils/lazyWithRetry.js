import { lazy } from 'react';
import { markChunkStart, markChunkEnd } from './perfBeacon'; // step 4.7: measurement only

// Step 4.1b: a lazy route whose chunk request fails is fatal today - React caches the rejected
// promise, so the route stays broken until the tab is reloaded, and "Try Again" re-renders into
// the same rejection. That is what the owner hit on 2026-09-20 (Sentry 148176284,
// "Importing a module script failed." on a 5G phone opening /tours).
//
// Two cheap defences, in this order:
//   1. retry the import a few times with a short backoff - survives one dropped request;
//   2. if it still fails, reload the page ONCE. The reload refetches index.html, which is
//      `no-store` since step 2.3, so a tab left open across a deploy picks up the new chunk
//      names instead of asking for deleted ones. A sessionStorage flag makes a loop impossible:
//      after one reload we let the error through to the error screen.

export const RELOAD_FLAG = 'fwl:chunk-reload';

// Backoff before retry 1 and retry 2. Exported so the test does not have to hard-code them.
export const RETRY_DELAYS_MS = [300, 900];

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Import with retries. Returns the module, or rejects with the last error after triggering
 * the one-shot reload (when it has not been used yet in this tab).
 *
 * @param {() => Promise<any>} importer  the `() => import('./Page')` thunk
 * @param {object} [deps]  injection points for the unit test
 */
export function importWithRetry(importer, deps = {}) {
  const {
    delays = RETRY_DELAYS_MS,
    sleep = wait,
    storage = typeof sessionStorage !== 'undefined' ? sessionStorage : null,
    reload = () => window.location.reload(),
  } = deps;

  markChunkStart(); // step 4.7: records the FIRST route chunk of a load and ignores later ones
  const attempt = (i) =>
    importer().then((mod) => {
      markChunkEnd(true); // step 4.7
      // A chunk loaded: the tab is healthy, so a later failure may use its own one-shot reload.
      clearChunkReloadFlag(storage);
      return mod;
    }).catch(async (error) => {
      if (i < delays.length) {
        await sleep(delays[i]);
        return attempt(i + 1);
      }
      markChunkEnd(false); // step 4.7: out of retries

      // Out of retries. Reload once - a stale build heals itself, a dead connection does not.
      let alreadyReloaded = true; // if storage is unusable, never reload (safer than looping)
      try {
        alreadyReloaded = storage ? storage.getItem(RELOAD_FLAG) === '1' : true;
        if (!alreadyReloaded && storage) {
          storage.setItem(RELOAD_FLAG, '1');
        }
      } catch (storageError) {
        alreadyReloaded = true; // private mode / blocked storage
      }

      if (!alreadyReloaded) {
        reload();
      }
      throw error;
    });

  return attempt(0);
}

/**
 * Drop-in replacement for React.lazy for every route in App.jsx.
 */
export default function lazyWithRetry(importer, deps) {
  return lazy(() => importWithRetry(importer, deps));
}

/**
 * Called once after a page chunk has loaded successfully: the tab is healthy again, so the
 * next failure is allowed its own reload.
 */
export function clearChunkReloadFlag(storage = typeof sessionStorage !== 'undefined' ? sessionStorage : null) {
  try {
    if (storage) {
      storage.removeItem(RELOAD_FLAG);
    }
  } catch (storageError) {
    /* storage blocked - nothing to clear */
  }
}
