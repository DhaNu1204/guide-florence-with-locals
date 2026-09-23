/**
 * Step 4.8, item 5: a payment POST that times out on a bad link may already be recorded on the
 * server. It must be sent ONCE (no automatic retry), the user must be told the outcome is unknown
 * and to refresh before trying again - and recording is blocked until the list is refreshed.
 * This goes through the real authFetch; only the network (fetch) is faked.
 */
import { render, screen, fireEvent, act, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../contexts/PageTitleContext', () => {
  const setPageTitle = () => {};
  return { usePageTitle: () => ({ setPageTitle }) };
});
vi.mock('../../utils/perfBeacon', () => ({
  markListStart: vi.fn(), markListEnd: vi.fn(), markUserRetry: vi.fn(),
  markRateLimited: vi.fn(), markTimeout: vi.fn(), markAutoRetry: vi.fn(),
}));

import Payments from '../Payments';
import { ToastProvider } from '../../components/Toast/ToastProvider';

const json = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
  clone: () => ({ arrayBuffer: async () => new ArrayBuffer(1) }),
});

const UNPAID = [{
  id: 11, is_group: false, group_id: null, date: '2026-09-22', time: '10:00',
  title: 'Uffizi Gallery Guided Tour', participants: 2, guide_id: 3, guide_name: 'Anna',
}];

let postCalls;
beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  localStorage.getItem.mockImplementation((k) => (k === 'token' ? 'tok' : null));
  postCalls = 0;
  globalThis.fetch = vi.fn((url, opts = {}) => {
    const method = String(opts.method || 'GET').toUpperCase();
    if (method === 'POST' && String(url).includes('/payments.php')) {
      postCalls += 1;
      // the bad link: accepted, never answered - only our own timer ends it
      return new Promise((resolve, reject) => {
        opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
      });
    }
    if (String(url).includes('action=overview')) return Promise.resolve(json({ success: true, data: {} }));
    if (String(url).includes('action=pending_tours')) return Promise.resolve(json({ success: true, data: UNPAID }));
    if (String(url).includes('/guide-payments.php')) return Promise.resolve(json({ success: true, data: [] }));
    return Promise.resolve(json({ success: true, data: [] }));
  });
});
afterEach(() => { vi.useRealTimers(); });

describe('Payments: a timed-out payment POST (step 4.8)', () => {
  it('is sent once, never retried, says "outcome unknown", and blocks re-recording until refreshed', async () => {
    render(<ToastProvider><MemoryRouter><Payments /></MemoryRouter></ToastProvider>);

    // open the Record tab, pick the tour, enter €50
    fireEvent.click((await screen.findAllByText('Record Payment'))[0].closest('button'));
    fireEvent.click(await screen.findAllByText('Uffizi Gallery Guided Tour').then((els) => els[0]));
    const amount = screen.getAllByPlaceholderText('0.00')[0];
    fireEvent.change(amount, { target: { value: '50' } });

    const record = screen.getAllByRole('button', { name: /Record €50\.00/ })[0];
    fireEvent.click(record);

    // the 30 s write timeout fires; nothing is retried behind the user's back
    await act(async () => { await vi.advanceTimersByTimeAsync(31000); });
    await act(async () => { await vi.advanceTimersByTimeAsync(60000); });
    expect(postCalls).toBe(1);

    const notice = await screen.findByTestId('payment-outcome-unknown');
    expect(notice).toHaveTextContent('Payment not confirmed.');
    expect(notice).toHaveTextContent('may or may not have been recorded');
    expect(notice).toHaveTextContent('Refresh the list and check before recording anything again.');

    // pressing Record again is impossible until the list has been refreshed from the server
    const submits = screen.getAllByRole('button', { name: /Record €50\.00/ });
    expect(submits.length).toBeGreaterThan(0);
    for (const b of submits) expect(b).toBeDisabled();

    // "Refresh the list" reloads from the server and lifts the block
    fireEvent.click(within(notice).getByRole('button', { name: /Refresh the list/ }));
    await act(async () => { await vi.advanceTimersByTimeAsync(10); });
    await screen.findAllByText('Record Payment');
    expect(screen.queryByTestId('payment-outcome-unknown')).toBeNull();
    expect(postCalls).toBe(1);
  });
});
