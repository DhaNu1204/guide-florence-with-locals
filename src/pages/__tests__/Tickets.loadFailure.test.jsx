/**
 * Step 4.8, fix 3: the tickets page that "did not load" on 2026-09-23. A failed fetch must be
 * said out loud - never an empty list ("No tickets available") when the truth is "we don't know".
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: () => true, userRole: 'admin', userName: 'test' }),
}));
// A stable setter, like the real context's (a new function per render would re-run the page's
// load effect on every render).
vi.mock('../../contexts/PageTitleContext', () => {
  const setPageTitle = () => {};
  return { usePageTitle: () => ({ setPageTitle }) };
});
vi.mock('../../utils/perfBeacon', () => ({
  markListStart: vi.fn(), markListEnd: vi.fn(), markUserRetry: vi.fn(),
}));
vi.mock('../../services/ticketsService', () => ({
  getTickets: vi.fn(), addTicket: vi.fn(), deleteTicket: vi.fn(), updateTicket: vi.fn(),
}));

import Tickets from '../Tickets';
import { getTickets } from '../../services/ticketsService';
import { markListStart, markListEnd, markUserRetry } from '../../utils/perfBeacon';

const renderPage = () => render(<MemoryRouter><Tickets /></MemoryRouter>);
const networkError = (lastGood = null) =>
  Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK', isAxiosError: true, lastGood });

beforeEach(() => { vi.clearAllMocks(); });

describe('Tickets page when the fetch fails (step 4.8)', () => {
  it('nothing cached: shows "Could not load the tickets" with Retry - and no empty list', async () => {
    getTickets.mockRejectedValueOnce(networkError(null));
    renderPage();
    const box = await screen.findByTestId('load-problem');
    expect(box).toHaveTextContent('Could not load the tickets.');
    expect(box).toHaveTextContent('No connection to the server');
    expect(screen.queryByText('No tickets available')).toBeNull();
    expect(markListStart).toHaveBeenCalled();
    expect(markListEnd).toHaveBeenCalledWith(false);
  });

  it('a last good copy exists: shows it under "Could not refresh — showing data from HH:MM"', async () => {
    const at = new Date();
    at.setHours(9, 12, 0, 0);
    getTickets.mockRejectedValueOnce(networkError({
      data: [{ id: 1, code: 'UFF', location: 'Uffizi', date: '2099-01-01', time: '10:00', quantity: 4, status: 'available' }],
      savedAt: at.getTime(),
    }));
    renderPage();
    const banner = await screen.findByTestId('load-problem-stale');
    expect(banner.textContent).toMatch(/Could not refresh — showing data from 0?9.12/);
    expect(screen.queryByText('No tickets available')).toBeNull();
  });

  it('Retry fetches again, records the press, and the list appears when the link is back', async () => {
    getTickets
      .mockRejectedValueOnce(networkError(null))
      .mockResolvedValueOnce([]);
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /^retry$/i }));
    expect(markUserRetry).toHaveBeenCalledTimes(1);
    expect(await screen.findByText('No tickets available')).toBeInTheDocument(); // a REAL empty answer
    expect(screen.queryByTestId('load-problem')).toBeNull();
    expect(getTickets).toHaveBeenCalledTimes(2);
  });
});
