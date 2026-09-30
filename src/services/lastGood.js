/**
 * Step 4.10 — the last data a screen loaded successfully, kept on the phone.
 *
 * When a load fails, a screen shows this copy with its time ("Could not refresh — showing data
 * from 18:12") instead of an empty page. It is never shown as if it were fresh, and it never
 * answers a request in place of the server (that was the old localStorage cache's fault: step 4.8
 * removed the silent fallback, and CLAUDE.md forbids adding to that cache).
 *
 * IndexedDB, not localStorage: the Tours copy is several hundred KB, and localStorage is small,
 * synchronous and already holds the old list cache. Where IndexedDB is unavailable (private mode
 * on some browsers, tests) a copy lives in memory for the session only. Nothing here ever throws.
 */

const DB_NAME = 'fwl-last-good';
const STORE = 'screens';
const MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000; // older than two weeks: not worth showing

const memory = new Map();
let dbPromise = null;

function openDb() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve) => {
    try {
      if (typeof indexedDB === 'undefined' || !indexedDB) { resolve(null); return; }
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        try { req.result.createObjectStore(STORE); } catch (e) { /* already there */ }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch (e) {
      resolve(null);
    }
  });
  return dbPromise;
}

function run(db, mode, fn) {
  return new Promise((resolve) => {
    try {
      const tx = db.transaction(STORE, mode);
      const req = fn(tx.objectStore(STORE));
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(undefined);
    } catch (e) {
      resolve(undefined);
    }
  });
}

/** Keep `data` as the last good copy for `key` (e.g. 'dashboard', 'tours:{"upcoming":true}'). */
export async function saveLastGood(key, data) {
  const entry = { at: Date.now(), data };
  memory.set(key, entry);
  const db = await openDb();
  if (db) await run(db, 'readwrite', (s) => s.put(entry, key));
}

/** The last good copy for `key` -> { at, data }, or null. */
export async function loadLastGood(key) {
  let entry = memory.get(key) || null;
  if (!entry) {
    const db = await openDb();
    if (db) entry = (await run(db, 'readonly', (s) => s.get(key))) || null;
  }
  if (!entry || !Number.isFinite(entry.at) || Date.now() - entry.at > MAX_AGE_MS) return null;
  return entry;
}

/** On logout: another user on this phone must not see the previous one's data. */
export async function clearLastGood() {
  memory.clear();
  const db = await openDb();
  if (db) await run(db, 'readwrite', (s) => s.clear());
}

// Testing seam only.
export const __resetLastGoodForTests = () => { memory.clear(); dbPromise = null; };
