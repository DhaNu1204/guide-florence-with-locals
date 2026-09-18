/**
 * Step 4.2: the Tours page asks for the light list view and renders the
 * language chip / PAX from server-provided fields (no bokun_data on the row).
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

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

describe('Tours page - light list view', () => {
  it('requests view=list and renders the language chip from the server language field', async () => {
    render(
      <ToastProvider>
        <MemoryRouter>
          <Tours />
        </MemoryRouter>
      </ToastProvider>
    );

    const chips = await screen.findAllByText('Portuguese');
    expect(chips.length).toBeGreaterThan(0);
    expect(screen.getAllByText(/3 PAX/).length).toBeGreaterThan(0);

    expect(mysqlDB.fetchTours).toHaveBeenCalled();
    const filters = mysqlDB.fetchTours.mock.calls[0][3];
    expect(filters.view).toBe('list');
  });
});
