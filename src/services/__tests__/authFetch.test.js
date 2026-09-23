/**
 * Step 4.8: authFetch - a read is retried once, a write never; a lost write is "unknown";
 * a real 401 still logs out.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../utils/perfBeacon', () => ({
  markRateLimited: vi.fn(), markTimeout: vi.fn(), markAutoRetry: vi.fn(),
}));
vi.mock('../sessionExpiry', () => ({
  notifySessionExpired: vi.fn(), notifyForbidden: vi.fn(),
}));

import { authFetch } from '../authFetch';
import { markAutoRetry, markTimeout } from '../../utils/perfBeacon';
import { notifySessionExpired } from '../sessionExpiry';
import { WRITE_UNKNOWN_EVENT } from '../netPolicy';

const okResponse = (status = 200, body = { success: true }) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
  clone: () => ({ arrayBuffer: async () => new ArrayBuffer(1) }),
});

// A fetch that never answers until our own timer aborts it.
const hanging = () => (url, opts) => new Promise((resolve, reject) => {
  opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
});

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.getItem.mockImplementation((k) => (k === 'token' ? 'tok' : null));
});
afterEach(() => { vi.useRealTimers(); });

describe('authFetch (step 4.8)', () => {
  it('retries a timed-out read once and reports that the retry worked', async () => {
    vi.useFakeTimers();
    let calls = 0;
    globalThis.fetch = vi.fn((url, opts) => {
      calls += 1;
      return calls === 1 ? hanging()(url, opts) : Promise.resolve(okResponse());
    });
    const p = authFetch('/api/tickets.php');
    await vi.advanceTimersByTimeAsync(15000);
    const res = await p;
    expect(res.status).toBe(200);
    expect(globalThis.fetch).toHaveBeenCalledTimes(2);
    expect(markTimeout).toHaveBeenCalledTimes(1);
    expect(markAutoRetry).toHaveBeenCalledWith(true);
  });

  it('gives up after one retry and throws a timeout', async () => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn(hanging());
    const p = authFetch('/api/tickets.php');
    const assertion = expect(p).rejects.toMatchObject({ name: 'TimeoutError' });
    await vi.advanceTimersByTimeAsync(30000);
    await assertion;
    expect(globalThis.fetch).toHaveBeenCalledTimes(2);
    expect(markAutoRetry).toHaveBeenCalledWith(false);
  });

  it('a payment POST that times out is sent ONCE, never retried, and marked outcome unknown', async () => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn(hanging());
    const heard = vi.fn();
    window.addEventListener(WRITE_UNKNOWN_EVENT, heard);
    const p = authFetch('/api/payments.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"amount":50}',
    });
    const assertion = expect(p).rejects.toMatchObject({ outcomeUnknown: true });
    await vi.advanceTimersByTimeAsync(60000);
    await assertion;
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    expect(markAutoRetry).not.toHaveBeenCalled();
    expect(heard).toHaveBeenCalledTimes(1);
    window.removeEventListener(WRITE_UNKNOWN_EVENT, heard);
  });

  it('a write answered 504 by the edge is outcome unknown too (and is not retried)', async () => {
    globalThis.fetch = vi.fn(async () => okResponse(504, {}));
    await expect(authFetch('/api/payments.php', { method: 'POST', quietUnknown: true }))
      .rejects.toMatchObject({ outcomeUnknown: true });
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  it('a data call answered 401 still runs the normal logout path', async () => {
    globalThis.fetch = vi.fn(async () => okResponse(401, {}));
    const res = await authFetch('/api/guide-payments.php');
    expect(res.status).toBe(401);
    expect(notifySessionExpired).toHaveBeenCalledTimes(1);
  });

  it('a network error on a read does NOT log out', async () => {
    globalThis.fetch = vi.fn(async () => { throw new TypeError('Load failed'); });
    await expect(authFetch('/api/tickets.php')).rejects.toBeInstanceOf(TypeError);
    expect(notifySessionExpired).not.toHaveBeenCalled();
  });
});
