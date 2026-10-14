/**
 * Step 6.20: a manual merge of bookings in different languages asks first - "These bookings are
 * in different languages (English, Italian). Merge anyway?" - and Cancel means no merge.
 * Driven through the phone flow (Select -> tap bookings -> Merge Selected); the desktop
 * drag-and-drop calls the same confirmMergeLanguages() guard (unit-tested in groupLanguages).
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: () => true, userRole: 'admin', userName: 'test' }),
}));

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const p = (n) => String(n).padStart(2, '0');
  const d = new Date();
  const todayStr = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  const row = (id, language, customer) => ({
    id,
    title: 'Uffizi Gallery Guided Tour',
    product_id: 1130528,
    date: todayStr,
    time: '23:30:00',
    start_time_str: '23:30',
    participants: 2,
    total_participants: 2,
    pax_adults: 2, pax_children: 0, pax_infants: 0,
    language,
    booking_channel: 'GetYourGuide',
    customer_name: customer,
    cancelled: false, paid: false, rescheduled: false,
    guide_id: null, guide_name: null, group_id: null, group_info: null,
    product_type: 'tour', notes: null,
  });
  const mysqlDB = {
    fetchTours: vi.fn().mockResolvedValue({
      data: [row(701, 'English', 'Ann Example'), row(702, 'Italian', 'Bruno Esempio'), row(703, 'Italian', 'Carla Esempio')],
      pagination: { current_page: 1, per_page: 500, total: 3, total_pages: 1 },
    }),
    fetchGuides: resolved({ data: [] }),
    getAllGuides: resolved([]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
    getUnassignedCount: resolved(0),
    getTourLanguages: resolved({ success: true, data: [] }),
  };
  return {
    default: mysqlDB,
    tourGroupsAPI: {
      list: resolved({ data: [] }),
      listAll: resolved({ data: [] }),
      manualMerge: resolved({ success: true }),
    },
    getOpenGuideRequests: resolved({ data: [] }),
    createGuideRequest: resolved({}),
    getGuides: resolved({ data: [] }),
    getTourById: resolved(null),
  };
});

vi.mock('../../services/bokunAutoSync', () => ({
  default: { syncNow: vi.fn().mockResolvedValue({}) },
}));

import { tourGroupsAPI } from '../../services/mysqlDB';
import Tours from '../Tours';
import { ToastProvider } from '../../components/Toast/ToastProvider';

const QUESTION = 'These bookings are in different languages (English, Italian). Merge anyway?';

const selectAndMerge = async (ids) => {
  render(
    <ToastProvider>
      <MemoryRouter>
        <Tours />
      </MemoryRouter>
    </ToastProvider>
  );
  await waitFor(() => expect(document.querySelector(`[data-tour-id="${ids[0]}"]`)).toBeTruthy());
  fireEvent.click(screen.getByRole('button', { name: /^Select$/ }));
  for (const id of ids) fireEvent.click(document.querySelector(`[data-tour-id="${id}"]`));
  fireEvent.click(await screen.findByRole('button', { name: /Merge Selected/ }));
};

describe('Tours manual merge across languages (step 6.20)', () => {
  let confirmSpy;
  beforeEach(() => { tourGroupsAPI.manualMerge.mockClear(); });
  afterEach(() => { confirmSpy?.mockRestore(); });

  it('English + Italian asks, and Cancel means no merge', async () => {
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await selectAndMerge([701, 702]);
    await waitFor(() => expect(confirmSpy).toHaveBeenCalledWith(QUESTION));
    await new Promise((r) => setTimeout(r, 50));
    expect(tourGroupsAPI.manualMerge).not.toHaveBeenCalled();
  });

  it('English + Italian asks, and OK merges', async () => {
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    await selectAndMerge([701, 702]);
    await waitFor(() => expect(tourGroupsAPI.manualMerge).toHaveBeenCalledTimes(1));
    expect(confirmSpy).toHaveBeenCalledWith(QUESTION);
    expect(tourGroupsAPI.manualMerge.mock.calls[0][0]).toEqual([701, 702]);
  });

  it('two Italian bookings merge without asking', async () => {
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await selectAndMerge([702, 703]);
    await waitFor(() => expect(tourGroupsAPI.manualMerge).toHaveBeenCalledTimes(1));
    expect(confirmSpy).not.toHaveBeenCalled();
  });
});
