/**
 * Step 5.2:
 *  - "N tours still need a guide" is the SERVER's number (same query as the unassigned report),
 *    never something counted in the browser from the rows that happen to be loaded;
 *  - the page asks for ALL groups (listAll) and, when that fails, shows an error state instead of
 *    silently rendering grouped bookings as loose rows.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Step 6.4: Tours now reads the role to decide whether to offer "Add tour".
// These tests render the page outside AuthProvider, so useAuth is stubbed as an admin.
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: () => true, userRole: 'admin', userName: 'test' }),
}));

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const p = (n) => String(n).padStart(2, '0');
  const d = new Date();
  const today = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  // three loose, guide-less bookings: a browser-side count would say 3
  const tours = [1, 2, 3].map((i) => ({
    id: 600 + i, title: 'Uffizi Gallery Guided Tour', date: today, time: '23:4' + i + ':00', start_time_str: '23:4' + i,
    participants: 2, total_participants: 2, pax_adults: 2, pax_children: 0, pax_infants: 0,
    language: 'English', booking_channel: 'Viator', cancelled: false, guide_id: null, guide_name: null,
    group_id: null, group_info: null, product_type: 'tour', notes: null,
  }));
  const mysqlDB = {
    fetchTours: resolved({ data: tours, pagination: { current_page: 1, per_page: 500, total: 3, total_pages: 1 } }),
    fetchGuides: resolved({ data: [] }),
    getAllGuides: resolved([]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
    getUnassignedCount: vi.fn().mockResolvedValue(17),
    getTourLanguages: vi.fn().mockResolvedValue({ success: true, data: [] }), // step 6.1
  };
  return {
    default: mysqlDB,
    tourGroupsAPI: { list: resolved({ data: [] }), listAll: vi.fn().mockResolvedValue({ data: [] }) },
    getOpenGuideRequests: resolved({ data: [] }),
    createGuideRequest: resolved({}),
    getGuides: resolved({ data: [] }),
    getTourById: resolved(null),
  };
});

vi.mock('../../services/bokunAutoSync', () => ({
  default: { syncNow: vi.fn().mockResolvedValue({}) },
}));

import mysqlDB, { tourGroupsAPI } from '../../services/mysqlDB';
import Tours from '../Tours';
import { ToastProvider } from '../../components/Toast/ToastProvider';

const renderPage = () =>
  render(
    <ToastProvider>
      <MemoryRouter>
        <Tours />
      </MemoryRouter>
    </ToastProvider>
  );

describe('Tours page banner + group loading (step 5.2)', () => {
  beforeEach(() => {
    mysqlDB.getUnassignedCount.mockResolvedValue(17);
    tourGroupsAPI.listAll.mockResolvedValue({ data: [] });
  });

  it('shows the server count, not a browser count of the loaded rows', async () => {
    renderPage();
    const banner = await screen.findByTestId('need-guide-banner');
    expect(banner.textContent).toBe('17 tours still need a guide'); // 3 guide-less rows are on screen
    expect(mysqlDB.getUnassignedCount).toHaveBeenCalled();
    const sent = mysqlDB.getUnassignedCount.mock.calls[0][0];
    expect(sent.view).toBeUndefined();
    expect(sent.guide_id).toBeUndefined();
  });

  it('loads ALL groups with the same date filter as the tours', async () => {
    renderPage();
    await screen.findByTestId('need-guide-banner');
    expect(tourGroupsAPI.listAll).toHaveBeenCalled();
    expect(tourGroupsAPI.list).not.toHaveBeenCalled();
    const tourFilters = mysqlDB.fetchTours.mock.calls[0][3];
    const groupFilters = tourGroupsAPI.listAll.mock.calls[0][0];
    if (tourFilters.upcoming) expect(groupFilters.upcoming).toBe('true');
    if (tourFilters.date) expect(groupFilters.date).toBe(tourFilters.date);
  });

  it('a failed group request is an error state - no ungrouped rows are rendered', async () => {
    tourGroupsAPI.listAll.mockRejectedValue(new Error('Tour groups incomplete: received 100 of 163'));
    renderPage();
    expect(await screen.findByTestId('tours-load-error')).toHaveTextContent('Could not load the tours list.');
    expect(screen.getByTestId('tours-load-error')).toHaveTextContent('received 100 of 163');
    expect(screen.queryByText('Uffizi Gallery Guided Tour')).not.toBeInTheDocument();
    expect(screen.queryByTestId('need-guide-banner')).not.toBeInTheDocument();
  });
});
