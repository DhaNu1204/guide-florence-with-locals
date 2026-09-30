/**
 * Step 4.10: the reachability probe after a failed load, and the saved "last good" screens.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { probeServer, describeProbe } from '../netPolicy';
import { saveLastGood, loadLastGood, clearLastGood } from '../lastGood';
import { departureRows } from '../../components/Dashboard';

afterEach(() => { delete global.fetch; });

describe('probeServer / describeProbe', () => {
  it('a server that answers -> ok with the time, and the sentence says the server is up', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, clone() { return { arrayBuffer: async () => new ArrayBuffer(0) }; } }));
    const p = await probeServer();
    expect(p.status).toBe('ok');
    expect(global.fetch.mock.calls[0][0]).toMatch(/\/health\.php\?probe=1&_=\d+/);
    expect(describeProbe({ status: 'ok', ms: 280 })).toMatch(/reached the server in 0\.3 s, so the server is up/);
  });

  it('nothing comes back -> timeout, "cannot reach the server at the moment"', async () => {
    vi.useFakeTimers();
    global.fetch = vi.fn((url, opts) => new Promise((_, reject) => {
      opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const pending = probeServer(6000);
    await vi.advanceTimersByTimeAsync(6100);
    const p = await pending;
    vi.useRealTimers();
    expect(p.status).toBe('timeout');
    expect(describeProbe(p)).toMatch(/cannot reach the server at the moment/);
  });

  it('never says "probably weak"', () => {
    for (const s of ['ok', 'timeout', 'network', 'http', 'offline']) {
      expect(describeProbe({ status: s, ms: 1000, code: 503 })).not.toMatch(/weak/);
    }
  });
});

describe('lastGood', () => {
  it('keeps the last copy with its time, per key, and forgets it on logout', async () => {
    expect(await loadLastGood('dashboard')).toBeNull();
    await saveLastGood('dashboard', { n: 1 });
    await saveLastGood('dashboard', { n: 2 });
    const got = await loadLastGood('dashboard');
    expect(got.data).toEqual({ n: 2 });
    expect(Math.abs(Date.now() - got.at)).toBeLessThan(1000);
    expect(await loadLastGood('tours:1:{}')).toBeNull();
    await clearLastGood();
    expect(await loadLastGood('dashboard')).toBeNull();
  });
});

describe('Dashboard departureRows (the report rule)', () => {
  const tours = [
    { id: 10, group_id: 7, title: 'Uffizi', date: '2026-10-01', time: '09:30:00', cancelled: 0 },
    { id: 11, group_id: 7, title: 'Uffizi', date: '2026-10-01', time: '09:30:00', cancelled: 0 },
    { id: 12, group_id: null, title: 'Accademia', date: '2026-10-02', time: '12:30:00', cancelled: 1 },
  ];
  it('one row per departure, HH:MM, one member booking kept for "Ask"', () => {
    const rows = departureRows([
      { tour_unit: 'g7', date: '2026-10-01', time: '09:30', title: 'Uffizi', bookings: 2, pax: 5, language: 'English' },
      { tour_unit: 't12', date: '2026-10-02', time: '12:30:00', title: 'Accademia', bookings: 1, pax: 2, language: 'Unknown' },
    ], tours);
    expect(rows).toHaveLength(2);
    expect(rows[0]).toMatchObject({ key: 'g7', time: '09:30', bookings: 2, pax: 5 });
    expect(rows[0].tour.id).toBe(10);
    expect(rows[1].time).toBe('12:30');
    expect(rows[1].tour).toBeNull(); // only a cancelled member here: no "Ask" on it
  });
});
