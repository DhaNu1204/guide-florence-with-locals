/**
 * Step 4.6: /today - one small request, a plain list, the last copy (with its time) on failure.
 */
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetch = vi.fn();
vi.mock('../../services/authFetch', () => ({
  authFetch: (...a) => mockFetch(...a),
  default: (...a) => mockFetch(...a),
}));

import Today, { TODAY_COPY_KEY } from '../Today';
import { TimeoutError } from '../../services/netPolicy';

const romeToday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(new Date());

const data = {
  generated_at: '2026-10-02T21:30:00+02:00',
  days: [
    {
      date: romeToday,
      departures: [
        { id: 'g1', time: '09:30', title: 'Uffizi Small Group', language: 'English', guests: 9, guide: 'Anna Rossi', meeting_point: 'Statua di Leonardo da Vinci, Piazzale degli Uffizi' },
        { id: 't2', time: '14:00', title: 'Accademia Tour', language: 'Italian', guests: 1, guide: null, meeting_point: null },
      ],
    },
    { date: '2099-01-01', departures: [] },
  ],
};

const ok = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data }) });

describe('Today page', () => {
  beforeEach(() => {
    // the shared setup mocks localStorage with bare vi.fn()s: give it a real store here
    const store = new Map();
    localStorage.getItem.mockImplementation((k) => (store.has(k) ? store.get(k) : null));
    localStorage.setItem.mockImplementation((k, v) => { store.set(k, String(v)); });
    mockFetch.mockReset();
  });

  it('asks today.php once with a short timeout and lists the departures', async () => {
    mockFetch.mockImplementation(ok);
    render(<Today />);
    expect(await screen.findByText('Uffizi Small Group')).toBeInTheDocument();
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch.mock.calls[0][0]).toMatch(/\/today\.php$/);
    expect(mockFetch.mock.calls[0][1]).toEqual({ timeoutMs: 10000 });
    expect(screen.getByText('Anna Rossi')).toBeInTheDocument();
    expect(screen.getByText('No guide')).toBeInTheDocument();
    expect(screen.getByText('English · 9 guests')).toBeInTheDocument();
    expect(screen.getByText('Italian · 1 guest')).toBeInTheDocument();
    expect(screen.getByText('Meet: Statua di Leonardo da Vinci, Piazzale degli Uffizi')).toBeInTheDocument();
    expect(screen.getByText('No departures.')).toBeInTheDocument();
    expect(screen.getByTestId('today-stamp').textContent).toMatch(/^Updated /);
    const saved = JSON.parse(localStorage.getItem(TODAY_COPY_KEY));
    expect(saved.data.days[0].departures).toHaveLength(2);
  });

  it('on failure shows the saved copy with its time and the measured reason', async () => {
    const savedAt = new Date();
    savedAt.setHours(8, 5, 0, 0);
    localStorage.setItem(TODAY_COPY_KEY, JSON.stringify({ savedAt: savedAt.getTime(), data }));
    const err = new TimeoutError(10000);
    mockFetch.mockImplementation(() => Promise.reject(err));
    render(<Today />);
    // the copy is on screen at once, before the request settles
    expect(screen.getByText('Uffizi Small Group')).toBeInTheDocument();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch(/Could not refresh — showing the copy from 08:05/);
    expect(alert.textContent).toMatch(/did not answer/);
    expect(screen.getByTestId('today-stamp').textContent).toMatch(/^Saved copy from 08:05/);
    expect(screen.getByText('Uffizi Small Group')).toBeInTheDocument();
  });

  it('with no copy and a failure says it could not load (no empty list pretending to be data)', async () => {
    mockFetch.mockImplementation(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
    render(<Today />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch(/Could not load the departures/);
    expect(screen.queryByText('No departures.')).not.toBeInTheDocument();
  });

  it('ignores a corrupt saved copy', async () => {
    localStorage.setItem(TODAY_COPY_KEY, '{not json');
    mockFetch.mockImplementation(ok);
    render(<Today />);
    await waitFor(() => expect(screen.getByText('Accademia Tour')).toBeInTheDocument());
  });
});
