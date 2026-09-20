/**
 * Step 1.1: bokunAutoSync role gate.
 * - viewer: performSync makes no request, resolves false, lastSync untouched
 * - admin: performSync calls config + sync and resolves true on success
 * - 403 from the API: treated as "not allowed" (skipped, not failed), lastSync untouched
 * - step 1.2: one request only (action=sync, no config round-trip); {success:false,error:'sync_disabled'} = skip
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } })); // step 3.9: a sync is a POST
vi.mock('../mysqlDB', () => ({ clearTourCache: vi.fn() }));
const notifyForbidden = vi.fn();
vi.mock('../sessionExpiry', () => ({ notifyForbidden: (...a) => notifyForbidden(...a) }));

import axios from 'axios';
import bokunAutoSync from '../bokunAutoSync';

const storage = {};
const setStorage = (values) => {
  Object.keys(storage).forEach((k) => delete storage[k]);
  Object.assign(storage, values);
  localStorage.getItem.mockImplementation((k) => (k in storage ? storage[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { storage[k] = v; });
};

describe('bokunAutoSync role gate (step 1.1)', () => {
  let events;

  beforeEach(() => {
    events = [];
    axios.get.mockReset();
    axios.post.mockReset();
    bokunAutoSync.lastSyncTime = null;
    bokunAutoSync.syncInProgress = false;
    bokunAutoSync.userRole = null;
    bokunAutoSync.listeners.clear();
    bokunAutoSync.addListener((e) => events.push(e));
  });

  it('viewer: no request is made and the result is false', async () => {
    setStorage({ token: 't', userRole: 'viewer' });
    bokunAutoSync.initialize('viewer');

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(false);
    expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
    expect(bokunAutoSync.lastSyncTime).toBeNull();
    expect(events).toEqual([{ type: 'sync_skipped', trigger: 'periodic', reason: 'not_allowed' }]);
    expect(notifyForbidden).not.toHaveBeenCalled();
  });

  it('viewer clicking "Sync now" (manual) gets the permission toast, still no request', async () => {
    setStorage({ token: 't', userRole: 'viewer' });
    notifyForbidden.mockClear();

    const result = await bokunAutoSync.performSync('manual');

    expect(result).toBe(false);
    expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
    expect(notifyForbidden).toHaveBeenCalledTimes(1);
  });

  it('admin: exactly one request (action=sync, no config call) and a successful sync resolves true', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.post.mockResolvedValueOnce({ data: { success: true, synced_count: 2, total_bookings: 10 } });

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(true);
    expect(axios.post).toHaveBeenCalledTimes(1);
    expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post.mock.calls[0][0]).toContain('action=sync');
    expect(axios.post.mock.calls[0][0]).not.toContain('action=config');
    expect(bokunAutoSync.lastSyncTime).not.toBeNull();
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_completed']);
  });

  it('sync_disabled from the server is a skip: no failure event, lastSync untouched, one request', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.post.mockResolvedValueOnce({ data: { success: false, error: 'sync_disabled' } });

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(false);
    expect(axios.post).toHaveBeenCalledTimes(1);
    expect(bokunAutoSync.lastSyncTime).toBeNull();
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_skipped']);
    expect(events[1].reason).toBe('sync_disabled');
  });

  it('403 from the API is "not allowed": no failure event, lastSync untouched', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.post.mockRejectedValueOnce({ response: { status: 403 }, message: 'Request failed with status code 403' });

    const result = await bokunAutoSync.performSync('focus');

    expect(result).toBe(false);
    expect(bokunAutoSync.lastSyncTime).toBeNull();
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_skipped']);
    expect(events[1].reason).toBe('not_allowed');
    expect(events.some((e) => e.type === 'sync_failed')).toBe(false);
  });

  it('a real failure still reports sync_failed', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.post.mockRejectedValueOnce({ response: { status: 500 }, message: 'boom' });

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(false);
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_failed']);
  });
});

// Step 4.0: the app never starts a Bokun sync on its own
describe('bokunAutoSync has no automatic triggers (step 4.0)', () => {
  beforeEach(() => {
    axios.get.mockReset();
    axios.post.mockReset();
    bokunAutoSync.syncInProgress = false;
    setStorage({ token: 't', userRole: 'admin' });
  });

  it('initialize(admin) starts no sync, no timer', () => {
    vi.useFakeTimers();
    try {
      bokunAutoSync.initialize('admin');
      vi.advanceTimersByTime(60 * 60 * 1000); // one hour
      expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
      expect(vi.getTimerCount()).toBe(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it('window focus and visibilitychange start no sync', () => {
    vi.useFakeTimers();
    try {
      bokunAutoSync.initialize('admin');
      window.dispatchEvent(new Event('focus'));
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new Event('visibilitychange'));
      vi.advanceTimersByTime(5000);
      expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });

  it('the old automatic entry points are gone', () => {
    for (const name of ['startPeriodicSync', 'onAppFocus', 'onVisibilityChange', 'shouldSyncOnFocus', 'updateConfig', 'stop']) {
      expect(bokunAutoSync[name]).toBeUndefined();
    }
  });

  it('syncNow() POSTs action=sync as a manual sync (step 3.9: a sync is never a GET)', async () => {
    axios.post.mockResolvedValueOnce({ data: { success: true, synced_count: 0, total_bookings: 0 } });

    const result = await bokunAutoSync.syncNow();

    expect(result).toBe(true);
    expect(axios.get).not.toHaveBeenCalled();
    expect(axios.post).toHaveBeenCalledTimes(1);
    const [url, body] = axios.post.mock.calls[0];
    expect(url).toContain('bokun_sync.php?action=sync');
    expect(body).toEqual({ type: 'manual', triggered_by: 'manual' });
  });
});
