/**
 * Step 4.7: the reading end. A stalled load must be obvious at a glance.
 */
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const rows = [
  {
    id: 2, created_at: '2026-09-22 09:04:11', user_id: 1, username: 'dhanu',
    release_tag: 'fwl@0.0.2', route: '/tours', reason: 'deadline', entry_at: 900,
    verify_start: 1000, verify_end: 1400, verify_status: 'ok',
    chunk_start: 1450, chunk_end: 2100, chunk_status: 'ok',
    list_start: 2200, list_end: null, list_status: 'pending',
    rate_limited: 0, online: 1, sw_controlled: 1, first_after_release: 1,
    effective_type: '4g', conn_rtt: 700, conn_downlink: 0.6, device: 'Android/Chrome 120',
  },
  {
    id: 1, created_at: '2026-09-22 08:12:03', user_id: 1, username: 'dhanu',
    release_tag: 'fwl@0.0.2', route: '/tours', reason: 'complete', entry_at: 300,
    verify_start: 320, verify_end: 480, verify_status: 'ok',
    chunk_start: 490, chunk_end: 700, chunk_status: 'ok',
    list_start: 710, list_end: 1180, list_status: 'ok',
    rate_limited: 0, online: 1, sw_controlled: 1, first_after_release: 0,
    effective_type: '4g', conn_rtt: 150, conn_downlink: 4.2, device: 'Android/Chrome 120',
  },
];

vi.mock('../../services/authFetch', () => ({
  default: vi.fn(() => Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve({
      success: true,
      data: {
        day: '2026-09-22',
        rows,
        summary: { loads: 2, list_ok: 1, unfinished: 1, median_to_list: 1180, worst_to_list: 1180 },
      },
    }),
  })),
}));

import ClientPerf from '../ClientPerf';

describe('Load measurements page (step 4.7)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('summarises the day and makes a stalled load visible', async () => {
    render(<ClientPerf />);

    const summary = await screen.findByTestId('perf-summary');
    expect(summary.textContent).toMatch(/2 loads/);
    expect(summary.textContent).toMatch(/1 never finished/);

    // the stalled row says so in words, not just in colour
    expect(await screen.findByText('never finished')).toBeTruthy();
    // and the healthy one shows when the list arrived
    expect(screen.getAllByText('1.2s').length).toBeGreaterThan(0);
    // the post-deploy load is marked
    expect(screen.getByText('after deploy')).toBeTruthy();
    // connection facts are shown
    expect(screen.getAllByText(/4g · \d+ms/).length).toBe(2);
  });

  it('offers a filter for loads that did not finish', async () => {
    render(<ClientPerf />);
    await screen.findByTestId('perf-summary');
    expect(screen.getByTestId('perf-unfinished-filter')).toBeTruthy();
  });

  it('shows no customer or tour information, only route names', async () => {
    render(<ClientPerf />);
    await screen.findByTestId('perf-summary');
    const text = document.body.textContent;
    expect(text).toMatch('/tours');
    expect(text).not.toMatch(/Uffizi|Accademia|GetYourGuide|booking/i);
  });
});
