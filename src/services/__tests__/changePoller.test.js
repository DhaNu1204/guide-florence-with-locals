/**
 * Step 4.11: one app-wide change poller - 60 s while visible, nothing when hidden / logged out /
 * no page subscribed, 5 min after 3 failures, toasts each new booking / cancellation once.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const mockFetch = vi.fn();
vi.mock('../netPolicy', async (importOriginal) => ({
  ...(await importOriginal()),
  fetchWithTimeout: (...a) => mockFetch(...a),
}));
const mockExpired = vi.fn();
vi.mock('../sessionExpiry', () => ({ notifySessionExpired: (...a) => mockExpired(...a) }));

import {
  subscribeChanges, subscribeStamp, __resetChangePoller, describeEvent, shortTourTitle,
  POLL_MS, BACKOFF_MS,
} from '../changePoller';

const romeYmd = (offset = 0) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' })
  .format(new Date(Date.now() + offset * 86400000));

const answer = (data) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data }) });
const flush = () => vi.advanceTimersByTimeAsync(0);

let visibility = 'visible';
let store;

describe('changePoller', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    __resetChangePoller();
    mockFetch.mockReset();
    mockExpired.mockReset();
    visibility = 'visible';
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
    store = new Map([['token', 'session-token']]);
    localStorage.getItem.mockImplementation((k) => (store.has(k) ? store.get(k) : null));
  });
  afterEach(() => {
    __resetChangePoller();
    vi.useRealTimers();
  });

  it('does nothing while no page is subscribed', async () => {
    await vi.advanceTimersByTimeAsync(5 * POLL_MS);
    expect(mockFetch).not.toHaveBeenCalled();
  });

  it('baseline at once, then every 60 s with since=<token>; unchanged = no refetch', async () => {
    mockFetch.mockImplementation(() => answer({ token: '1791550800.aaaaaaaaaa', changed: false }));
    const onChange = vi.fn();
    const stamps = [];
    subscribeStamp((at) => stamps.push(at));
    subscribeChanges({ onChange, notify: vi.fn() });
    await flush();
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch.mock.calls[0][0]).toMatch(/tours\.php\?action=changes$/);
    expect(mockFetch.mock.calls[0][1].headers.Authorization).toBe('Bearer session-token');
    expect(mockFetch.mock.calls[0][1].signal).toBeInstanceOf(AbortSignal);

    await vi.advanceTimersByTimeAsync(POLL_MS - 1);
    expect(mockFetch).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(mockFetch).toHaveBeenCalledTimes(2);
    expect(mockFetch.mock.calls[1][0]).toMatch(/since=1791550800\.aaaaaaaaaa$/);
    expect(onChange).not.toHaveBeenCalled();
    expect(stamps.length).toBe(2);
  });

  it('a changed token refetches the page and toasts each event once', async () => {
    const ev = { kind: 'new', id: 7708, date: romeYmd(0), time: '15:00', title: 'Uffizi Gallery Small Group Guided Tour with Tickets', pax: 1 };
    mockFetch
      .mockImplementationOnce(() => answer({ token: '1.aaaaaaaaaa', changed: false }))
      .mockImplementationOnce(() => answer({ token: '2.bbbbbbbbbb', changed: true, events: [ev] }))
      .mockImplementationOnce(() => answer({ token: '3.cccccccccc', changed: true, events: [ev] }));
    const onChange = vi.fn();
    const notify = vi.fn();
    subscribeChanges({ onChange, notify });
    await flush();
    await vi.advanceTimersByTimeAsync(POLL_MS);
    expect(onChange).toHaveBeenCalledTimes(1);
    expect(notify).toHaveBeenCalledWith('New booking: 15:00 Uffizi Small Group, +1 PAX', 'new');
    await vi.advanceTimersByTimeAsync(POLL_MS);
    expect(onChange).toHaveBeenCalledTimes(2); // the page still refetches
    expect(notify).toHaveBeenCalledTimes(1);   // but the same booking is not toasted twice
  });

  it('hidden: aborts the check in flight and makes no requests; visible again: checks at once', async () => {
    let seenSignal = null;
    mockFetch.mockImplementation((url, opts) => {
      seenSignal = opts.signal;
      return new Promise(() => {}); // never answers
    });
    subscribeChanges({ onChange: vi.fn() });
    await flush();
    expect(mockFetch).toHaveBeenCalledTimes(1);
    visibility = 'hidden';
    document.dispatchEvent(new Event('visibilitychange'));
    expect(seenSignal.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(10 * POLL_MS);
    expect(mockFetch).toHaveBeenCalledTimes(1);

    mockFetch.mockImplementation(() => answer({ token: '1.aaaaaaaaaa', changed: false }));
    visibility = 'visible';
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    expect(mockFetch).toHaveBeenCalledTimes(2);
  });

  it('backs off to 5 min after 3 failures in a row, and back to 60 s after a success', async () => {
    mockFetch.mockImplementation(() => Promise.reject(new Error('network')));
    subscribeChanges({ onChange: vi.fn() });
    await flush();                                   // failure 1
    await vi.advanceTimersByTimeAsync(POLL_MS);      // failure 2
    await vi.advanceTimersByTimeAsync(POLL_MS);      // failure 3
    expect(mockFetch).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(BACKOFF_MS - 1);
    expect(mockFetch).toHaveBeenCalledTimes(3);
    mockFetch.mockImplementation(() => answer({ token: '1.aaaaaaaaaa', changed: false }));
    await vi.advanceTimersByTimeAsync(1);
    expect(mockFetch).toHaveBeenCalledTimes(4);
    await vi.advanceTimersByTimeAsync(POLL_MS);
    expect(mockFetch).toHaveBeenCalledTimes(5);
  });

  it('logged out: no requests', async () => {
    store.delete('token');
    subscribeChanges({ onChange: vi.fn() });
    await vi.advanceTimersByTimeAsync(3 * POLL_MS);
    expect(mockFetch).not.toHaveBeenCalled();
  });

  it('a 401 starts the session-expired flow', async () => {
    mockFetch.mockImplementation(() => Promise.resolve({ ok: false, status: 401 }));
    subscribeChanges({ onChange: vi.fn() });
    await flush();
    expect(mockExpired).toHaveBeenCalledTimes(1);
  });

  it('the last page leaving stops the poller', async () => {
    mockFetch.mockImplementation(() => answer({ token: '1.aaaaaaaaaa', changed: false }));
    const off = subscribeChanges({ onChange: vi.fn() });
    await flush();
    off();
    await vi.advanceTimersByTimeAsync(5 * POLL_MS);
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('focus within 10 s of a check does not ask again', async () => {
    mockFetch.mockImplementation(() => answer({ token: '1.aaaaaaaaaa', changed: false }));
    subscribeChanges({ onChange: vi.fn() });
    await flush();
    window.dispatchEvent(new Event('focus'));
    await flush();
    expect(mockFetch).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(10000);
    window.dispatchEvent(new Event('focus'));
    await flush();
    expect(mockFetch).toHaveBeenCalledTimes(2);
  });
});

describe('toast text', () => {
  it('cancellation tomorrow', () => {
    expect(describeEvent({ kind: 'cancel', date: romeYmd(1), time: '09:30', title: 'Accademia Gallery Small Group Tour', pax: 2 }))
      .toBe('Cancelled: Tomorrow 09:30 Accademia Small Group, −2 PAX');
  });
  it('short titles', () => {
    expect(shortTourTitle('Uffizi Gallery Small Group Guided Tour with Tickets')).toBe('Uffizi Small Group');
    expect(shortTourTitle("Florence: Michelangelo's Life and Legacy 3.5 Hr Guided Tour")).toBe("Michelangelo's Life and Legacy 3.…") // 34-char cap;
    expect(shortTourTitle('')).toBe('Tour');
  });
});
