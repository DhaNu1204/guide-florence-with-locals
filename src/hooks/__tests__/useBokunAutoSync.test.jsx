/**
 * Step 4.0: the app never starts a Bokun sync on its own.
 * - no sync on mount, none on focus / visibilitychange, none from a timer
 * - "Sync now" still runs a manual sync
 * - the "last sync" label comes from the server via sync-info, at most every 5 minutes
 */
import { render, screen, act, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAuthenticated: true, userRole: 'admin' }),
}));

vi.mock('../../services/bokunAutoSync', () => ({
  default: {
    initialize: vi.fn(),
    performSync: vi.fn().mockResolvedValue(true),
    addListener: vi.fn(() => () => {}),
    getStatus: vi.fn(() => ({ lastSyncTime: null, syncInProgress: false })),
  },
}));

vi.mock('../../services/mysqlDB', () => ({
  getSyncInfo: vi.fn(),
}));

import bokunAutoSync from '../../services/bokunAutoSync';
import { getSyncInfo } from '../../services/mysqlDB';
import { BokunSyncProvider, useBokunSync, __resetSyncInfoThrottle } from '../useBokunAutoSync';

const Probe = () => {
  const { lastSync, syncNow } = useBokunSync();
  return (
    <div>
      <span data-testid="last">{lastSync ? new Date(lastSync).toISOString() : 'none'}</span>
      <button onClick={syncNow}>Sync now</button>
    </div>
  );
};

const flush = async () => {
  await act(async () => { await Promise.resolve(); await Promise.resolve(); });
};

describe('useBokunAutoSync (step 4.0)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    __resetSyncInfoThrottle();
    localStorage.getItem.mockImplementation(() => null);
    getSyncInfo.mockResolvedValue({ last_sync: { completed_at: '2026-09-18T10:15:04Z', triggered_by: 'cron' } });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('starts no sync on mount, on focus, on visibilitychange or from a timer', async () => {
    render(<BokunSyncProvider><Probe /></BokunSyncProvider>);
    await flush();

    window.dispatchEvent(new Event('focus'));
    document.dispatchEvent(new Event('visibilitychange'));
    await act(async () => { vi.advanceTimersByTime(60 * 60 * 1000); }); // one hour
    await flush();

    expect(bokunAutoSync.performSync).not.toHaveBeenCalled();
  });

  it('shows the last sync time reported by the server (cron)', async () => {
    render(<BokunSyncProvider><Probe /></BokunSyncProvider>);
    await flush();

    expect(getSyncInfo).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('last').textContent).toBe('2026-09-18T10:15:04.000Z');
  });

  it('polls sync-info at most every 5 minutes, shared between hook instances', async () => {
    render(
      <>
        <BokunSyncProvider><Probe /></BokunSyncProvider>
        <BokunSyncProvider><Probe /></BokunSyncProvider>
      </>
    );
    await flush();
    expect(getSyncInfo).toHaveBeenCalledTimes(1);

    await act(async () => { vi.advanceTimersByTime(4 * 60 * 1000); });
    await flush();
    expect(getSyncInfo).toHaveBeenCalledTimes(1);

    await act(async () => { vi.advanceTimersByTime(61 * 1000); });
    await flush();
    expect(getSyncInfo).toHaveBeenCalledTimes(2);
  });

  it('"Sync now" still runs one manual sync', async () => {
    render(<BokunSyncProvider><Probe /></BokunSyncProvider>);
    await flush();

    await act(async () => { fireEvent.click(screen.getByText('Sync now')); });
    await flush();

    expect(bokunAutoSync.performSync).toHaveBeenCalledTimes(1);
    expect(bokunAutoSync.performSync).toHaveBeenCalledWith('manual');
  });
});
