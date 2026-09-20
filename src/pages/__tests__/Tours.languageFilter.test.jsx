/**
 * Step 6.1: the language filter on the Tours page.
 * The dropdown offers only the languages that exist in the range in view (the server answers
 * that question), and choosing one is sent to the server as `language=` - the page never
 * filters a half-loaded list in the browser.
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const p = (n) => String(n).padStart(2, '0');
  const d = new Date();
  const todayStr = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  const row = (id, language) => ({
    id,
    title: 'Uffizi Gallery Guided Tour',
    date: todayStr,
    time: '23:45:00',
    start_time_str: '23:45',
    participants: 2,
    total_participants: 2,
    pax_adults: 2, pax_children: 0, pax_infants: 0,
    language,
    booking_channel: 'Viator',
    customer_name: 'Jane Doe',
    cancelled: false, paid: false, rescheduled: false,
    guide_id: null, guide_name: null, group_id: null, group_info: null,
    product_type: 'tour', notes: null,
  });
  const mysqlDB = {
    fetchTours: vi.fn().mockResolvedValue({
      data: [row(601, 'English'), row(602, null)],
      pagination: { current_page: 1, per_page: 500, total: 2, total_pages: 1 },
    }),
    fetchGuides: resolved({ data: [] }),
    getAllGuides: resolved([]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
    getUnassignedCount: resolved(0),
    // Only these three exist in the range the user is looking at.
    getTourLanguages: vi.fn().mockResolvedValue({
      success: true,
      data: [
        { language: 'English', departures: 12, bookings: 20 },
        { language: 'Spanish', departures: 3, bookings: 4 },
        { language: 'Unknown', departures: 1, bookings: 1 },
      ],
    }),
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

const renderTours = () =>
  render(
    <ToastProvider>
      <MemoryRouter>
        <Tours />
      </MemoryRouter>
    </ToastProvider>
  );

describe('Tours language filter (step 6.1)', () => {
  it('offers only the languages that exist in the range, plus "All Languages"', async () => {
    renderTours();
    const select = await screen.findByTestId('tours-language-filter');
    const options = [...select.querySelectorAll('option')].map((o) => o.textContent);
    expect(options).toEqual(['All Languages', 'English (12)', 'Spanish (3)', 'Unknown (1)']);
    // German is not in the range, so it is not offered
    expect(options.some((o) => o.startsWith('German'))).toBe(false);
    expect(mysqlDB.getTourLanguages).toHaveBeenCalled();
  });

  it('asks the server for that language - it never filters the loaded rows in the browser', async () => {
    renderTours();
    const select = await screen.findByTestId('tours-language-filter');
    mysqlDB.fetchTours.mockClear();

    fireEvent.change(select, { target: { value: 'Spanish' } });

    await waitFor(() => expect(mysqlDB.fetchTours).toHaveBeenCalled());
    const filters = mysqlDB.fetchTours.mock.calls[0][3];
    expect(filters.language).toBe('Spanish');
  });

  it('"Unknown" is a real choice, sent as such', async () => {
    renderTours();
    const select = await screen.findByTestId('tours-language-filter');
    mysqlDB.fetchTours.mockClear();

    fireEvent.change(select, { target: { value: 'Unknown' } });

    await waitFor(() => expect(mysqlDB.fetchTours).toHaveBeenCalled());
    expect(mysqlDB.fetchTours.mock.calls[0][3].language).toBe('Unknown');
  });

  it('"All Languages" (the default) sends no language at all', async () => {
    renderTours();
    await waitFor(() => expect(mysqlDB.fetchTours).toHaveBeenCalled());
    // The first load happens with the filter on "All Languages": no language key is sent, so
    // the server returns everything - the page never asks for a language it does not need.
    expect(mysqlDB.fetchTours.mock.calls[0][3].language).toBeUndefined();
    expect(mysqlDB.fetchTours.mock.calls[0][3].view).toBe('list');
  });

  it('a row with no language shows the chip as Unknown', async () => {
    renderTours();
    const chips = await screen.findAllByTestId('tour-language-chip');
    const texts = chips.map((c) => c.textContent);
    expect(texts).toContain('English');
    expect(texts).toContain('Unknown');
  });
});
