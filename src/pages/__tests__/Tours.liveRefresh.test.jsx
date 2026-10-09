/**
 * Step 4.11: the auto-refresh reloads the Tours list IN PLACE - no "Loading tours..." screen, so
 * focus, scroll and open editors survive - and a new booking appears without touching the page.
 */
import { render, screen, act, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

// the shared poller is replaced by a handle the test fires itself
let fireChange = null;
vi.mock('../../hooks/useLiveRefresh', () => ({
  useLiveRefresh: (cb) => { fireChange = cb; },
  useLastCheckAt: () => null,
}));

// Step 6.4: Tours now reads the role to decide whether to offer "Add tour".
// These tests render the page outside AuthProvider, so useAuth is stubbed as an admin.
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: () => true, userRole: 'admin', userName: 'test' }),
}));

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const p = (n) => String(n).padStart(2, '0');
  const d = new Date();
  const todayStr = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  const lightTour = {
    id: 501,
    title: 'Uffizi Gallery Guided Tour',
    date: todayStr,
    time: '23:45:00',
    start_time_str: '23:45',
    participants: 3,
    total_participants: 3,
    pax_adults: 3, pax_children: 0, pax_infants: 0,
    language: 'Portuguese',
    booking_channel: 'Viator',
    customer_name: 'Jane Doe',
    cancelled: false, paid: false, rescheduled: false,
    guide_id: null, guide_name: null, group_id: null, group_info: null,
    product_type: 'tour', notes: null,
    // deliberately NO bokun_data
  };
  const mysqlDB = {
    fetchTours: resolved({ data: [lightTour], pagination: { current_page: 1, per_page: 500, total: 1, total_pages: 1 } }),
    fetchGuides: resolved({ data: [] }),
    getAllGuides: resolved([]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
    getUnassignedCount: resolved(0),
    getTourLanguages: resolved({ success: true, data: [] }), // step 6.1
  };
  return {
    default: mysqlDB,
    tourGroupsAPI: { list: resolved({ data: [] }), listAll: resolved({ data: [] }) },
    getOpenGuideRequests: resolved({ data: [] }),
    createGuideRequest: resolved({}),
    getGuides: resolved({ data: [] }),
    getTourById: resolved(null),
  };
});

vi.mock('../../services/bokunAutoSync', () => ({
  default: { syncNow: vi.fn().mockResolvedValue({}) },
}));

import mysqlDB from '../../services/mysqlDB';
import Tours from '../Tours';
import { ToastProvider } from '../../components/Toast/ToastProvider';

describe('Tours page - step 4.11 silent refresh', () => {
  it('a change reloads the list in place, keeps a focused field, and shows the new booking', async () => {
    render(
      <ToastProvider>
        <MemoryRouter>
          <Tours />
        </MemoryRouter>
      </ToastProvider>
    );
    await screen.findAllByText('Portuguese');
    expect(screen.getByTestId('updated-stamp')).toBeInTheDocument();

    // something on the page has focus (a stand-in for a note being typed)
    const field = document.createElement('input');
    field.value = 'half-typed note';
    screen.getAllByText('Portuguese')[0].closest('div').appendChild(field);
    field.focus();

    const before = mysqlDB.fetchTours.mock.calls.length;
    const p = (n) => String(n).padStart(2, '0');
    const d = new Date();
    const todayStr = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    mysqlDB.fetchTours.mockResolvedValueOnce({ data: [
      { ...(await mysqlDB.fetchTours.mock.results[0].value).data[0] },
      { id: 502, title: 'Uffizi Gallery Guided Tour', date: todayStr, time: '23:50:00', participants: 1,
        language: 'Portuguese', booking_channel: 'GetYourGuide', customer_name: 'Laurence Faubert',
        cancelled: false, paid: false, rescheduled: false, guide_id: null, guide_name: null, group_id: null,
        product_type: 'tour', notes: null },
    ], pagination: { current_page: 1, per_page: 500, total: 2, total_pages: 1 } });

    await act(async () => { fireChange([]); });
    expect(screen.queryByText('Loading tours...')).toBeNull();
    await waitFor(() => expect(mysqlDB.fetchTours.mock.calls.length).toBe(before + 1));
    // forceRefresh=true: never answered from the 1-minute cache
    expect(mysqlDB.fetchTours.mock.calls[before][0]).toBe(true);
    expect((await screen.findAllByText(/(^|\D)1 PAX/)).length).toBeGreaterThan(0); // the new booking's row
    expect(document.activeElement).toBe(field);
    expect(field.value).toBe('half-typed note');
  });
});
