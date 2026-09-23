/**
 * Step 4.8: the network policy - timeouts per request kind, what may be retried, how failures
 * are classified, and a fetch that can no longer wait forever.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  timeoutFor, mayAutoRetry, classifyError, isTransient, isOutcomeUnknown, describeLoadError,
  fetchWithTimeout, TimeoutError, writeFailureMessage, WRITE_UNKNOWN_MESSAGE, formatShownAt,
  VERIFY_TIMEOUT_MS, READ_TIMEOUT_MS, WRITE_TIMEOUT_MS, FILE_TIMEOUT_MS, SYNC_TIMEOUT_MS,
} from '../netPolicy';

afterEach(() => { vi.useRealTimers(); });

describe('timeoutFor', () => {
  it('gives the auth check, reads and writes their own allowance', () => {
    expect(timeoutFor('GET', '/api/auth.php?action=verify')).toBe(VERIFY_TIMEOUT_MS);
    expect(timeoutFor('get', '/api/tickets.php?_=1')).toBe(READ_TIMEOUT_MS);
    expect(timeoutFor('POST', '/api/payments.php')).toBe(WRITE_TIMEOUT_MS);
    expect(timeoutFor('put', '/api/tours.php/5')).toBe(WRITE_TIMEOUT_MS);
  });

  it('exempts the long operations: sync 180 s, server PDFs/CSV 90 s', () => {
    expect(timeoutFor('POST', '/api/bokun_sync.php?action=sync')).toBe(SYNC_TIMEOUT_MS);
    expect(timeoutFor('GET', '/api/bokun_sync.php?action=test')).toBe(SYNC_TIMEOUT_MS);
    expect(timeoutFor('GET', '/api/participants.php?unit=g12')).toBe(FILE_TIMEOUT_MS);
    expect(timeoutFor('GET', '/api/viator_legacy_export.php')).toBe(FILE_TIMEOUT_MS);
    expect(SYNC_TIMEOUT_MS).toBeGreaterThan(110000); // longer than the hosting edge's own cut
  });
});

describe('mayAutoRetry', () => {
  it('retries reads only - never a write', () => {
    expect(mayAutoRetry('GET', '/api/tickets.php')).toBe(true);
    expect(mayAutoRetry('POST', '/api/payments.php')).toBe(false);
    expect(mayAutoRetry('PUT', '/api/tours.php/1')).toBe(false);
    expect(mayAutoRetry('DELETE', '/api/payments.php?id=1')).toBe(false);
  });

  it('does not retry the long reads (sync, server files)', () => {
    expect(mayAutoRetry('GET', '/api/bokun_sync.php?action=test')).toBe(false);
    expect(mayAutoRetry('GET', '/api/participants.php?unit=g1')).toBe(false);
  });
});

describe('classifyError / isTransient', () => {
  it('knows a timeout, a dropped connection and an HTTP answer apart', () => {
    expect(classifyError(new TimeoutError(15000)).kind).toBe('timeout');
    expect(classifyError({ code: 'ECONNABORTED', isAxiosError: true }).kind).toBe('timeout');
    expect(classifyError({ code: 'ERR_NETWORK', isAxiosError: true }).kind).toBe('network');
    expect(classifyError(new TypeError('Load failed')).kind).toBe('network'); // iOS Safari
    expect(classifyError(new TypeError('Failed to fetch')).kind).toBe('network'); // Chrome
    expect(classifyError({ response: { status: 504 }, isAxiosError: true })).toEqual({ kind: 'http', status: 504 });
  });

  it('does not call an ordinary code bug a network problem', () => {
    expect(classifyError(new TypeError("Cannot read properties of undefined (reading 'x')")).kind).toBe('other');
  });

  it('treats timeouts, dropped links and 502/503/504 as transient; 401/500 are not', () => {
    expect(isTransient(new TimeoutError(1))).toBe(true);
    expect(isTransient({ code: 'ERR_NETWORK', isAxiosError: true })).toBe(true);
    expect(isTransient({ response: { status: 503 } })).toBe(true);
    expect(isTransient({ response: { status: 401 } })).toBe(false);
    expect(isTransient({ response: { status: 500 } })).toBe(false);
    expect(isOutcomeUnknown({ response: { status: 504 } })).toBe(true);
  });
});

describe('messages', () => {
  it('describes a failed load in plain words', () => {
    expect(describeLoadError(new TimeoutError(15000))).toMatch(/did not answer in time/);
    expect(describeLoadError({ code: 'ERR_NETWORK', isAxiosError: true })).toMatch(/No connection/);
    expect(describeLoadError({ response: { status: 503 } })).toMatch(/error 503/);
  });

  it('says "outcome unknown" for a lost write, never "try again"', () => {
    expect(writeFailureMessage({ outcomeUnknown: true }, 'Failed. Try again.')).toBe(WRITE_UNKNOWN_MESSAGE);
    expect(WRITE_UNKNOWN_MESSAGE).toMatch(/may already be recorded/);
    expect(WRITE_UNKNOWN_MESSAGE).toMatch(/Refresh/);
    expect(writeFailureMessage(new Error('x'), 'Failed. Try again.')).toBe('Failed. Try again.');
  });

  it('formats "showing data from" as HH:MM today', () => {
    const d = new Date();
    d.setHours(9, 12, 0, 0);
    expect(formatShownAt(d)).toMatch(/09.12|9.12/);
  });
});

describe('fetchWithTimeout', () => {
  it('gives up on a server that accepts the connection and never answers', async () => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn((url, opts) => new Promise((resolve, reject) => {
      opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const p = fetchWithTimeout('/api/tickets.php', {}, 15000);
    const assertion = expect(p).rejects.toBeInstanceOf(TimeoutError);
    await vi.advanceTimersByTimeAsync(15000);
    await assertion;
  });

  it('returns the response when the server answers in time', async () => {
    const response = { ok: true, status: 200, clone: () => ({ arrayBuffer: async () => new ArrayBuffer(2) }) };
    globalThis.fetch = vi.fn(async () => response);
    await expect(fetchWithTimeout('/api/x', {}, 1000)).resolves.toBe(response);
  });

  it('covers the body too: headers that arrive and then stall still time out', async () => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn(async (url, opts) => ({
      ok: true,
      status: 200,
      clone: () => ({
        arrayBuffer: () => new Promise((resolve, reject) => {
          opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }),
      }),
    }));
    const p = fetchWithTimeout('/api/tours.php', {}, 15000);
    const assertion = expect(p).rejects.toBeInstanceOf(TimeoutError);
    await vi.advanceTimersByTimeAsync(15000);
    await assertion;
  });
});
