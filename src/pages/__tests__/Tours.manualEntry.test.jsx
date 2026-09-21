/**
 * Step 6.4: departures typed in by hand.
 *
 * A GetYourGuide listing that is not connected to Bokun never reaches the sync, so the
 * owner records the departure himself. These tests cover what the Tours page must do
 * with such a row: show it on the right date with the right PAX, mark it as manual,
 * flag it (never merge it) when Bokun starts sending the same departure, and offer the
 * Add tour button to an admin only.
 */
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

let ADMIN = true;
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: () => ADMIN, userRole: ADMIN ? 'admin' : 'viewer', userName: 'test' }),
}));

const p = (n) => String(n).padStart(2, '0');
const d = new Date();
const TODAY = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;

const manualTour = {
  id: 9001,
  title: "Florence: Michelangelo's Life and Legacy 3.5 Hr Guided Tour",
  date: TODAY,
  time: '09:30:00',
  start_time_str: '09:30',
  participants: 2,
  total_participants: 2,
  pax_adults: 2, pax_children: 0, pax_infants: 0,
  language: 'English',
  booking_channel: 'GetYourGuide (direct)',
  source: 'manual',
  is_manual: true,
  manual_revenue: 418.29,
  manual_currency: 'EUR',
  possible_duplicate_of: [],
  product_id: null,
  product_type: null,
  cancelled: false, paid: false, rescheduled: false,
  guide_id: null, guide_name: null, group_id: null, group_info: null,
  notes: null,
};

const syncedTour = {
  ...manualTour,
  id: 9002,
  title: 'Uffizi Gallery Guided Tour',
  booking_channel: 'GetYourGuide',
  source: null,
  is_manual: false,
  manual_revenue: null,
  manual_currency: null,
  product_id: 1130528,
  product_type: 'tour',
};

let TOURS = [manualTour];

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const mysqlDB = {
    fetchTours: vi.fn(() => Promise.resolve({
      data: TOURS,
      pagination: { current_page: 1, per_page: 500, total: TOURS.length, total_pages: 1 },
    })),
    fetchGuides: resolved({ data: [{ id: 3, name: 'Giulia' }] }),
    getAllGuides: resolved([{ id: 3, name: 'Giulia' }]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
    getUnassignedCount: resolved(0),
    getTourLanguages: resolved({ success: true, data: [] }),
    createManualTour: vi.fn().mockResolvedValue({ success: true }),
    updateManualTour: vi.fn().mockResolvedValue({ success: true }),
    deleteManualTour: vi.fn().mockResolvedValue({ success: true }),
  };
  return {
    default: mysqlDB,
    tourGroupsAPI: { list: resolved({ data: [] }), listAll: resolved({ data: [] }) },
    getOpenGuideRequests: resolved({ data: [] }),
    createGuideRequest: resolved({}),
    getGuides: resolved({ data: [{ id: 3, name: 'Giulia' }] }),
    getTourById: resolved(null),
  };
});

vi.mock('../../services/bokunAutoSync', () => ({
  default: { syncNow: vi.fn().mockResolvedValue({}) },
}));

import mysqlDB from '../../services/mysqlDB';
import Tours from '../Tours';
import { ToastProvider } from '../../components/Toast/ToastProvider';
import { validateManualTour } from '../../components/ManualTourModal';

const renderPage = () => render(
  <ToastProvider>
    <MemoryRouter>
      <Tours />
    </MemoryRouter>
  </ToastProvider>
);

describe('Tours - hand-entered departures (step 6.4)', () => {
  beforeEach(() => {
    ADMIN = true;
    TOURS = [manualTour];
    vi.clearAllMocks();
  });

  it('shows a manual tour on its date with its PAX, marked as added by hand', async () => {
    renderPage();
    expect(await screen.findAllByText(/Michelangelo's Life and Legacy/)).toBeTruthy();
    expect(screen.getAllByText(/2 PAX/).length).toBeGreaterThan(0);
    expect(screen.getAllByTestId('manual-tour-badge').length).toBeGreaterThan(0);
    // and the channel is shown exactly as it was typed
    expect(screen.getAllByText('GetYourGuide (direct)').length).toBeGreaterThan(0);
  });

  it('carries the language it was given, so the 6.1 filter can find it', async () => {
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    const chips = screen.getAllByTestId('tour-language-chip');
    expect(chips.some((c) => c.textContent === 'English')).toBe(true);
  });

  it('offers Add tour to an admin', async () => {
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    expect(screen.getByTestId('add-manual-tour')).toBeTruthy();
  });

  it('does NOT offer Add tour to a viewer', async () => {
    ADMIN = false;
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    expect(screen.queryByTestId('add-manual-tour')).toBeNull();
    expect(screen.queryByTestId('manual-edit-9001')).toBeNull();
    expect(screen.queryByTestId('manual-delete-9001')).toBeNull();
  });

  it('sends the typed departure to the manual endpoint', async () => {
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    fireEvent.click(screen.getByTestId('add-manual-tour'));

    const form = await screen.findByTestId('manual-tour-form');
    fireEvent.change(within(form).getByLabelText('Tour name'), { target: { value: 'A hand-entered tour' } });
    fireEvent.change(within(form).getByLabelText('Date'), { target: { value: '2026-08-21' } });
    fireEvent.change(within(form).getByLabelText('Start time'), { target: { value: '09:30' } });
    fireEvent.change(within(form).getByLabelText('Participants'), { target: { value: '2' } });
    fireEvent.change(within(form).getByLabelText('Revenue (optional)'), { target: { value: '418.29' } });
    fireEvent.click(screen.getByRole('button', { name: /Add tour$/ }));

    await waitFor(() => expect(mysqlDB.createManualTour).toHaveBeenCalled());
    const payload = mysqlDB.createManualTour.mock.calls[0][0];
    expect(payload).toMatchObject({
      title: 'A hand-entered tour',
      date: '2026-08-21',
      time: '09:30',
      participants: 2,
      booking_channel: 'GetYourGuide (direct)', // the default, unchanged
      manual_revenue: 418.29,
      guide_id: null,                            // legal: assign a guide later
    });
  });

  it('refuses a bad time and a bad amount, and sends nothing', async () => {
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    fireEvent.click(screen.getByTestId('add-manual-tour'));
    const form = await screen.findByTestId('manual-tour-form');

    const fill = (label, value) =>
      fireEvent.change(within(form).getByLabelText(label), { target: { value } });
    const submit = () => fireEvent.click(screen.getByRole('button', { name: /Add tour$/ }));

    fill('Tour name', 'A hand-entered tour');
    fill('Date', '2026-08-21');
    fill('Participants', '2');

    // no start time
    submit();
    expect((await screen.findByTestId('manual-tour-error')).textContent).toMatch(/HH:MM/);

    // an amount that is not a number
    fill('Start time', '09:30');
    fill('Revenue (optional)', 'abc');
    submit();
    expect((await screen.findByTestId('manual-tour-error')).textContent)
      .toMatch(/number greater than 0/);

    expect(mysqlDB.createManualTour).not.toHaveBeenCalled();
  });

  // PAX is also guarded by the browser itself (min="1" on a number field stops the submit
  // before React sees it), so the rule is asserted against the validator directly.
  it('refuses an impossible PAX, date, time and amount in the validator', () => {
    const base = {
      title: 'A hand-entered tour', date: '2026-08-21', time: '09:30',
      participants: '2', booking_channel: 'GetYourGuide (direct)', manual_revenue: '',
    };
    expect(validateManualTour(base)).toBeNull();
    expect(validateManualTour({ ...base, participants: '0' })).toMatch(/whole number of 1 or more/);
    expect(validateManualTour({ ...base, participants: '-2' })).toMatch(/whole number of 1 or more/);
    expect(validateManualTour({ ...base, participants: '2.5' })).toMatch(/whole number of 1 or more/);
    expect(validateManualTour({ ...base, participants: '500' })).toMatch(/over 200/);
    expect(validateManualTour({ ...base, date: '2026-02-31' })).toMatch(/real date/);
    expect(validateManualTour({ ...base, time: '25:00' })).toMatch(/HH:MM/);
    expect(validateManualTour({ ...base, manual_revenue: '0' })).toMatch(/greater than 0/);
    expect(validateManualTour({ ...base, manual_revenue: '-50' })).toMatch(/greater than 0/);
    expect(validateManualTour({ ...base, manual_revenue: '999999' })).toMatch(/over 100000/);
    expect(validateManualTour({ ...base, booking_channel: '  ' })).toMatch(/channel is required/);
    // the two optional fields really are optional
    expect(validateManualTour({ ...base, manual_revenue: '' })).toBeNull();
    expect(validateManualTour({ ...base, guide_id: '' })).toBeNull();
  });

  it('flags a manual row against a synced booking without merging them', async () => {
    TOURS = [
      { ...manualTour, possible_duplicate_of: [9002] },
      { ...syncedTour, possible_duplicate_of: [9001] },
    ];
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);

    // both rows say so, and both rows are still there - nothing was merged
    expect(screen.getAllByTestId('duplicate-badge').length).toBe(2);
    expect(screen.getAllByText(/Michelangelo's Life and Legacy/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Uffizi Gallery Guided Tour/).length).toBeGreaterThan(0);

    // one click removes the owner's copy - and only his copy
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    fireEvent.click(screen.getByTestId('manual-dedupe-9001'));
    await waitFor(() => expect(mysqlDB.deleteManualTour).toHaveBeenCalledWith(9001));
    confirmSpy.mockRestore();
  });

  it('asks before deleting and does nothing when the owner says no', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    renderPage();
    await screen.findAllByText(/Michelangelo's Life and Legacy/);
    fireEvent.click(screen.getByTestId('manual-delete-9001'));
    expect(confirmSpy).toHaveBeenCalled();
    expect(mysqlDB.deleteManualTour).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
});
