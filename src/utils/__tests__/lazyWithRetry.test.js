/**
 * Step 4.1b: a lazy route chunk that fails to download must not be fatal.
 * Reproduces the production failure (Sentry 148176284, "Importing a module script failed.").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { importWithRetry, clearChunkReloadFlag, RELOAD_FLAG, RETRY_DELAYS_MS } from '../lazyWithRetry';

// A sessionStorage stand-in so the test never depends on the jsdom one.
function fakeStorage(initial = {}) {
  const data = { ...initial };
  return {
    data,
    getItem: (k) => (k in data ? data[k] : null),
    setItem: (k, v) => { data[k] = String(v); },
    removeItem: (k) => { delete data[k]; },
  };
}

const MODULE = { default: () => null };

describe('importWithRetry', () => {
  let storage;
  let reload;
  let sleep;
  let slept;

  beforeEach(() => {
    storage = fakeStorage();
    reload = vi.fn();
    slept = [];
    sleep = vi.fn((ms) => { slept.push(ms); return Promise.resolve(); });
  });

  const deps = () => ({ storage, reload, sleep });

  it('resolves without retrying when the import works', async () => {
    const importer = vi.fn(() => Promise.resolve(MODULE));
    await expect(importWithRetry(importer, deps())).resolves.toBe(MODULE);
    expect(importer).toHaveBeenCalledTimes(1);
    expect(sleep).not.toHaveBeenCalled();
    expect(reload).not.toHaveBeenCalled();
  });

  it('resolves after a simulated first failure', async () => {
    const importer = vi.fn()
      .mockRejectedValueOnce(new TypeError('Importing a module script failed.'))
      .mockResolvedValue(MODULE);

    await expect(importWithRetry(importer, deps())).resolves.toBe(MODULE);
    expect(importer).toHaveBeenCalledTimes(2);
    expect(slept).toEqual([RETRY_DELAYS_MS[0]]);
    expect(reload).not.toHaveBeenCalled();
  });

  it('retries with the documented backoff and survives two failures', async () => {
    const importer = vi.fn()
      .mockRejectedValueOnce(new TypeError('boom'))
      .mockRejectedValueOnce(new TypeError('boom'))
      .mockResolvedValue(MODULE);

    await expect(importWithRetry(importer, deps())).resolves.toBe(MODULE);
    expect(importer).toHaveBeenCalledTimes(3);
    expect(slept).toEqual([...RETRY_DELAYS_MS]);
    expect(reload).not.toHaveBeenCalled();
  });

  it('gives up after N attempts, reloads once and still rejects', async () => {
    const error = new TypeError('Importing a module script failed.');
    const importer = vi.fn(() => Promise.reject(error));

    await expect(importWithRetry(importer, deps())).rejects.toBe(error);

    expect(importer).toHaveBeenCalledTimes(RETRY_DELAYS_MS.length + 1);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(storage.getItem(RELOAD_FLAG)).toBe('1');
  });

  it('never reloads twice: a second failure in the same tab falls through to the error screen', async () => {
    const error = new TypeError('boom');
    const importer = vi.fn(() => Promise.reject(error));

    await expect(importWithRetry(importer, deps())).rejects.toBe(error);
    await expect(importWithRetry(importer, deps())).rejects.toBe(error);
    await expect(importWithRetry(importer, deps())).rejects.toBe(error);

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('a successful load clears the flag, so a later failure gets its own reload', async () => {
    const error = new TypeError('boom');
    const failing = vi.fn(() => Promise.reject(error));
    const working = vi.fn(() => Promise.resolve(MODULE));

    await expect(importWithRetry(failing, deps())).rejects.toBe(error);
    expect(reload).toHaveBeenCalledTimes(1);

    await expect(importWithRetry(working, deps())).resolves.toBe(MODULE);
    expect(storage.getItem(RELOAD_FLAG)).toBeNull();

    await expect(importWithRetry(failing, deps())).rejects.toBe(error);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('does not reload when sessionStorage is unusable (private mode) - no loop risk', async () => {
    const error = new TypeError('boom');
    const blocked = {
      getItem: () => { throw new Error('blocked'); },
      setItem: () => { throw new Error('blocked'); },
      removeItem: () => { throw new Error('blocked'); },
    };
    const importer = vi.fn(() => Promise.reject(error));

    await expect(importWithRetry(importer, { storage: blocked, reload, sleep })).rejects.toBe(error);
    expect(reload).not.toHaveBeenCalled();
  });

  it('clearChunkReloadFlag removes the flag and tolerates blocked storage', () => {
    storage.setItem(RELOAD_FLAG, '1');
    clearChunkReloadFlag(storage);
    expect(storage.getItem(RELOAD_FLAG)).toBeNull();

    expect(() => clearChunkReloadFlag({
      removeItem: () => { throw new Error('blocked'); },
    })).not.toThrow();
  });
});
