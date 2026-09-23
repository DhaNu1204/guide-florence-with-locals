/**
 * Step 4.8: the axios side of the network policy (mysqlDB.js interceptors) and the end of the
 * silent fallbacks in getTours / updateTour / getTickets / ticket writes.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const handlers = vi.hoisted(() => ({ request: null, responseErr: null }));

vi.mock('axios', () => {
  const axiosFn = vi.fn();
  axiosFn.get = vi.fn();
  axiosFn.post = vi.fn();
  axiosFn.put = vi.fn();
  axiosFn.delete = vi.fn();
  axiosFn.interceptors = {
    request: { use: vi.fn((ok) => { handlers.request = ok; }) },
    response: { use: vi.fn((ok, err) => { handlers.responseErr = err; }) },
  };
  return { default: axiosFn };
});
vi.mock('../../utils/perfBeacon', () => ({
  markRateLimited: vi.fn(), markTimeout: vi.fn(), markAutoRetry: vi.fn(),
}));
vi.mock('../sessionExpiry', () => ({ notifySessionExpired: vi.fn(), notifyForbidden: vi.fn() }));

import axios from 'axios';
import { getTours, updateTour } from '../mysqlDB';
import { getTickets, addTicket, updateTicket } from '../ticketsService';
import { markAutoRetry, markTimeout } from '../../utils/perfBeacon';
import { notifySessionExpired } from '../sessionExpiry';
import { WRITE_UNKNOWN_EVENT, READ_TIMEOUT_MS, WRITE_TIMEOUT_MS, SYNC_TIMEOUT_MS } from '../netPolicy';

let store;
beforeEach(() => {
  vi.clearAllMocks();
  store = {};
  localStorage.getItem.mockImplementation((k) => (k in store ? store[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { store[k] = String(v); });
  localStorage.removeItem.mockImplementation((k) => { delete store[k]; });
});

const timeoutError = (config) => Object.assign(new Error('timeout of 15000ms exceeded'), {
  code: 'ECONNABORTED', isAxiosError: true, config,
});

describe('axios request interceptor (step 4.8)', () => {
  it('stamps a timeout on every request by kind, and keeps an explicit one', () => {
    expect(handlers.request({ method: 'get', url: '/api/tickets.php', headers: {} }).timeout).toBe(READ_TIMEOUT_MS);
    expect(handlers.request({ method: 'post', url: '/api/payments.php', headers: {} }).timeout).toBe(WRITE_TIMEOUT_MS);
    expect(handlers.request({ method: 'post', url: '/api/bokun_sync.php?action=sync', headers: {} }).timeout).toBe(SYNC_TIMEOUT_MS);
    expect(handlers.request({ method: 'get', url: '/x', headers: {}, timeout: 5 }).timeout).toBe(5);
  });
});

describe('axios response interceptor (step 4.8)', () => {
  it('retries a timed-out GET exactly once', async () => {
    const config = { method: 'get', url: '/api/tours.php' };
    axios.mockResolvedValueOnce({ data: 'ok' });
    await expect(handlers.responseErr(timeoutError(config))).resolves.toEqual({ data: 'ok' });
    expect(axios).toHaveBeenCalledTimes(1);
    expect(axios.mock.calls[0][0].fwlRetried).toBe(true);
    expect(markTimeout).toHaveBeenCalled();
    expect(markAutoRetry).toHaveBeenCalledWith(true);
    // the retried request failing again is not retried a second time
    const again = timeoutError({ ...config, fwlRetried: true });
    await expect(handlers.responseErr(again)).rejects.toBe(again);
    expect(axios).toHaveBeenCalledTimes(1);
  });

  it('never retries a POST; a lost write is marked outcome unknown and announced once', async () => {
    const heard = vi.fn();
    window.addEventListener(WRITE_UNKNOWN_EVENT, heard);
    const err = timeoutError({ method: 'post', url: '/api/tickets.php' });
    await expect(handlers.responseErr(err)).rejects.toBe(err);
    expect(axios).not.toHaveBeenCalled();
    expect(err.outcomeUnknown).toBe(true);
    expect(heard).toHaveBeenCalledTimes(1);
    window.removeEventListener(WRITE_UNKNOWN_EVENT, heard);
  });

  it('a 401 still runs the session-expired path; a network error does not', async () => {
    const e401 = { response: { status: 401 }, config: { method: 'get', url: '/api/tours.php' } };
    await expect(handlers.responseErr(e401)).rejects.toBe(e401);
    expect(notifySessionExpired).toHaveBeenCalledTimes(1);
    const net = { code: 'ERR_NETWORK', isAxiosError: true, config: { method: 'get', url: '/api/tours.php', fwlRetried: true } };
    await expect(handlers.responseErr(net)).rejects.toBe(net);
    expect(notifySessionExpired).toHaveBeenCalledTimes(1);
  });
});

describe('no silent stale data (step 4.8, fix 3)', () => {
  it('getTours throws instead of answering with an old localStorage copy', async () => {
    store.tours_v1 = JSON.stringify({ timestamp: 1, data: { data: [{ id: 1 }] } });
    store.tours = JSON.stringify([{ id: 99, title: 'stale' }]);
    axios.get.mockRejectedValueOnce(Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' }));
    await expect(getTours(false, 1, 500, { view: 'list' })).rejects.toThrow('Network Error');
  });

  it('updateTour does not fake a success in the local cache', async () => {
    axios.put.mockRejectedValueOnce(Object.assign(new Error('timeout'), { code: 'ECONNABORTED' }));
    await expect(updateTour(5, { guide_id: 3 })).rejects.toThrow('timeout');
    expect(localStorage.setItem).not.toHaveBeenCalled();
  });

  it('getTickets never answers "we don\'t know" with []: it throws, carrying the last good copy', async () => {
    store.tickets_v1 = JSON.stringify({ timestamp: 1695455520000, data: [{ id: 7, code: 'UFF' }] });
    axios.get.mockRejectedValueOnce(Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' }));
    const err = await getTickets().catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    expect(err.lastGood).toEqual({ data: [{ id: 7, code: 'UFF' }], savedAt: 1695455520000 });
  });

  it('getTickets with nothing cached throws with lastGood = null', async () => {
    axios.get.mockRejectedValueOnce(new Error('Network Error'));
    const err = await getTickets().catch((e) => e);
    expect(err.lastGood).toBeNull();
  });

  it('ticket writes report failure instead of storing a local-only copy', async () => {
    axios.post.mockRejectedValueOnce(new Error('Network Error'));
    await expect(addTicket({ code: 'UFF', quantity: 2 })).rejects.toThrow('Network Error');
    globalThis.fetch = vi.fn(async () => { throw new TypeError('Load failed'); });
    await expect(updateTicket(3, { code: 'UFF' })).rejects.toBeTruthy();
    expect(store.tickets_fallback).toBeUndefined();
  });
});
