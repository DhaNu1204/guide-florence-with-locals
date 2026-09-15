/**
 * Step 1.1: bokunAutoSync role gate.
 * - viewer: performSync makes no request, resolves false, lastSync untouched
 * - admin: performSync calls config + sync and resolves true on success
 * - 403 from the API: treated as "not allowed" (skipped, not failed), lastSync untouched
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));
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
    bokunAutoSync.stop();
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
    expect(bokunAutoSync.lastSyncTime).toBeNull();
    expect(bokunAutoSync.syncInterval).toBeNull();
    expect(events).toEqual([{ type: 'sync_skipped', trigger: 'periodic', reason: 'not_allowed' }]);
    expect(notifyForbidden).not.toHaveBeenCalled();
  });

  it('viewer clicking "Sync now" (manual) gets the permission toast, still no request', async () => {
    setStorage({ token: 't', userRole: 'viewer' });
    notifyForbidden.mockClear();

    const result = await bokunAutoSync.performSync('manual');

    expect(result).toBe(false);
    expect(axios.get).not.toHaveBeenCalled();
    expect(notifyForbidden).toHaveBeenCalledTimes(1);
  });

  it('admin: config + sync are requested and a successful sync resolves true', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.get
      .mockResolvedValueOnce({ data: { sync_enabled: true } })
      .mockResolvedValueOnce({ data: { success: true, synced_count: 2, total_bookings: 10 } });

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(true);
    expect(axios.get).toHaveBeenCalledTimes(2);
    expect(axios.get.mock.calls[0][0]).toContain('action=config');
    expect(axios.get.mock.calls[1][0]).toContain('action=sync');
    expect(bokunAutoSync.lastSyncTime).not.toBeNull();
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_completed']);
  });

  it('403 from the API is "not allowed": no failure event, lastSync untouched', async () => {
    setStorage({ token: 't', userRole: 'admin' });
    bokunAutoSync.userRole = 'admin';
    axios.get.mockRejectedValueOnce({ response: { status: 403 }, message: 'Request failed with status code 403' });

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
    axios.get.mockRejectedValueOnce({ response: { status: 500 }, message: 'boom' });

    const result = await bokunAutoSync.performSync('periodic');

    expect(result).toBe(false);
    expect(events.map((e) => e.type)).toEqual(['sync_started', 'sync_failed']);
  });
});
