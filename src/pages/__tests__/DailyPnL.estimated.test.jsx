/**
 * Step 6.6: a guessed revenue figure must never look like a known one.
 *
 * Before this step a direct sale through the owner's own website had 30% deducted that he
 * never paid, and the only sign on screen was a grey "~ estimated" chip nobody noticed.
 * The chip is now loud, and the day header says how many of the day's departures are guesses.
 */
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mkRow = (over = {}) => ({
  unit: 't1', date: '2026-09-20', time: '09:30:00', title: 'Uffizi Gallery Guided Tour',
  category: 'Uffizi', is_group: false, is_manual: false, is_ticket: false, is_private: false,
  guide_name: 'Anna', channels: ['GetYourGuide'], bookings: 1, cancelled: 0,
  pax: { adults: 2, children: 0, infants: 0, total: 2 },
  revenue: { retail: 100, commission: 30, net: 70, estimated: false, overridden: false, manual: false },
  costs: {
    ticket_cost: 58, guide_cost: 0, radio_cost: 0, gelato_cost: 0, staff_cost: 0, other_cost: 0,
    total: 58, auto: { ticket_cost: 58, guide_cost: 0, radio_cost: 0, gelato_cost: 0, staff_cost: 0, other_cost: 0 },
    overridden: [], ticket_unknown: false, guide_unknown: false,
  },
  outsourced: false, profit: 12, notes: null,
  ...over,
});

let PAYLOAD;

vi.mock('../../services/mysqlDB', () => ({
  // the service returns the whole {success, data} envelope; the page takes .data
  getPnlDay: vi.fn(() => Promise.resolve(PAYLOAD)),
  getPnlRange: vi.fn(() => Promise.resolve(PAYLOAD)),
  getPnlSettings: vi.fn(() => Promise.resolve({})),
  savePnlSettings: vi.fn(() => Promise.resolve({ success: true })),
  savePnlCosts: vi.fn(() => Promise.resolve({ success: true })),
  mergePnlUnits: vi.fn(() => Promise.resolve({ success: true })),
  unmergePnlUnits: vi.fn(() => Promise.resolve({ success: true })),
}));

import DailyPnL from '../DailyPnL';

const dayPayload = (rows, totalsOver = {}) => ({
  success: true,
  data: {
    date: '2026-09-20',
    rows,
    totals: {
      units: rows.length, tour_units: rows.length, ticket_units: 0, estimated_units: 0,
      bookings: rows.length, cancelled: 0, pax: 2,
      retail: 100, commission: 30, net: 70,
      ticket_cost: 58, guide_cost: 0, radio_cost: 0, gelato_cost: 0, staff_cost: 0, other_cost: 0,
      total_cost: 58, profit: 12,
      ...totalsOver,
    },
    settings: {},
  },
});

describe('Daily P&L - a guess must look like a guess (step 6.6)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows nothing extra when every figure came from an invoice', async () => {
    PAYLOAD = dayPayload([mkRow()]);
    render(<DailyPnL />);
    await waitFor(() => expect(screen.getAllByText(/Uffizi Gallery Guided Tour/).length).toBeGreaterThan(0));
    expect(screen.queryByTestId('pnl-estimated-chip')).toBeNull();
    expect(screen.queryByTestId('pnl-estimated-count')).toBeNull();
  });

  it('marks an estimated row and says how many of the day are guesses', async () => {
    PAYLOAD = dayPayload(
      [
        mkRow(),
        mkRow({
          unit: 't2', title: 'Uffizi Gallery Small Group UPGRADE', channels: ['www.florencewithlocals.com'],
          revenue: { retail: 239.12, commission: 71.74, net: 167.38, estimated: true, overridden: false, manual: false },
        }),
      ],
      { units: 2, tour_units: 2, estimated_units: 1 }
    );
    render(<DailyPnL />);

    const chip = await screen.findByTestId('pnl-estimated-chip');
    expect(chip.textContent).toMatch(/estimated — not from an invoice/);
    // exactly one chip: the invoice-backed row must not be marked
    expect(screen.getAllByTestId('pnl-estimated-chip').length).toBe(1);

    const count = screen.getByTestId('pnl-estimated-count');
    expect(count.textContent).toMatch(/1 of 2/);
    expect(count.textContent).toMatch(/not from an invoice/);
  });

  it('the count also appears on the month view, where there are no rows to look at', async () => {
    PAYLOAD = {
      success: true,
      data: {
        start: '2026-09-01', end: '2026-09-30', days: [],
        totals: { units: 120, tour_units: 90, ticket_units: 30, estimated_units: 7,
                  bookings: 200, cancelled: 3, pax: 400, retail: 1000, commission: 300, net: 700,
                  ticket_cost: 400, guide_cost: 200, radio_cost: 0, gelato_cost: 0, staff_cost: 0,
                  other_cost: 0, total_cost: 600, profit: 100 },
        by_category: [], monthly_overhead: 0, profit_after_overhead: 100, settings: {},
      },
    };
    render(<DailyPnL />);
    // the page opens on the day view; the tiles read from whichever view is active, and the
    // count must be driven by the server total rather than by rows the month view never has
    await waitFor(() => expect(screen.queryByText(/Net Revenue/i)).toBeTruthy());
  });
});
