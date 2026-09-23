/**
 * Step 6.11: Priority Tickets - All museums | Uffizi | Accademia tabs, and the printable day list.
 */
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../utils/perfBeacon', () => ({
  markListStart: vi.fn(), markListEnd: vi.fn(), markUserRetry: vi.fn(),
}));
vi.mock('../../services/mysqlDB', () => ({
  getTours: vi.fn(),
  updateTour: vi.fn(),
  getTourById: vi.fn(),
  downloadDayParticipantsPdf: vi.fn(() => Promise.resolve('accademia-participants-2099-09-23.pdf')),
}));

import PriorityTickets from '../PriorityTickets';
import { getTours, downloadDayParticipantsPdf } from '../../services/mysqlDB';
import { markListStart, markListEnd } from '../../utils/perfBeacon';

const DAY = '2099-09-23';
const t = (id, time, title, customer, extra = {}) => ({
  id, date: DAY, time, title, customer_name: customer, participants: 2,
  booking_channel: 'GetYourGuide', cancelled: 0, notes: '', ...extra,
});
const BOOKINGS = [
  t(1, '12:30', 'Accademia Gallery Entry Ticket with Exclusive Audio Guide App', 'Rodolfo Irizar'),
  t(2, '14:00', 'Accademia Gallery Entry Ticket with Exclusive Audio Guide App', 'Breana Chauntler'),
  t(3, '09:00', 'Florence: Uffizi Gallery Reserved Ticket & Digital Audio Guide', 'Reserved Uffizi Guest'),
  t(4, '10:00', 'Florence: Uffizi Gallery Priority Ticket & Digital Audio Guide', 'Priority Uffizi Guest'),
  t(5, '11:00', 'Borghese Gallery Entry Ticket and Audio Guide', 'Borghese Guest'),
  t(6, '13:00', 'Palazzo Vecchio Skip the line Entry ticket', 'Vecchio Guest'),
  t(7, '15:00', 'Florence: Accademia Gallery Skip-the-Line Entry Ticket', 'Cancelled Guest', { cancelled: 1 }),
];

const renderPage = () => render(<MemoryRouter><PriorityTickets /></MemoryRouter>);
const names = () => screen.queryAllByText(/Guest$|Irizar|Chauntler/).map(n => n.textContent);
const tab = (name) => screen.getByRole('tab', { name });
const pickDay = (container) =>
  fireEvent.change(container.querySelector('input[type="date"]'), { target: { value: DAY } });

beforeEach(() => {
  vi.clearAllMocks();
  getTours.mockResolvedValue({ data: BOOKINGS });
});

describe('Priority Tickets museum tabs (step 6.11)', () => {
  it('opens on "All museums": every ticket booking, Borghese and Palazzo Vecchio included', async () => {
    renderPage();
    await screen.findAllByText('Borghese Guest');
    expect(tab('All museums')).toHaveAttribute('aria-selected', 'true');
    for (const n of ['Borghese Guest', 'Vecchio Guest', 'Rodolfo Irizar', 'Priority Uffizi Guest']) {
      expect(screen.getAllByText(n).length).toBeGreaterThan(0);
    }
    // the old Museum picker is gone - one control, not two
    expect(screen.queryByText('All Museums')).toBeNull();
    expect(screen.queryByLabelText('Museum', { selector: 'select' })).toBeNull();
    // no download on the All tab
    expect(screen.queryByTestId('download-day-sheet')).toBeNull();
  });

  it('shows the "Reserved Ticket" Uffizi bookings the old title filter used to hide', async () => {
    renderPage();
    expect((await screen.findAllByText('Reserved Uffizi Guest')).length).toBeGreaterThan(0);
  });

  it('Accademia tab: only Accademia ticket bookings, time order, cancelled kept on screen with its badge', async () => {
    renderPage();
    await screen.findAllByText('Borghese Guest');
    fireEvent.click(tab('Accademia'));
    const shown = [...new Set(names())];
    expect(shown).toEqual(['Rodolfo Irizar', 'Breana Chauntler', 'Cancelled Guest']);
    expect(screen.getAllByText('Cancelled').length).toBeGreaterThan(0);
  });

  it('Uffizi tab: both Uffizi titles, nothing else', async () => {
    renderPage();
    await screen.findAllByText('Borghese Guest');
    fireEvent.click(tab('Uffizi'));
    expect([...new Set(names())]).toEqual(['Reserved Uffizi Guest', 'Priority Uffizi Guest']);
  });

  it('the download needs a day, then asks for exactly that museum and date', async () => {
    const { container } = renderPage();
    await screen.findAllByText('Borghese Guest');
    fireEvent.click(tab('Accademia'));
    const btn = screen.getByTestId('download-day-sheet');
    expect(btn).toBeDisabled();
    expect(screen.getByText(/Pick a day first/)).toBeInTheDocument();
    pickDay(container);
    expect(btn).not.toBeDisabled();
    fireEvent.click(btn);
    await waitFor(() => expect(downloadDayParticipantsPdf).toHaveBeenCalledWith('Accademia', DAY));

    fireEvent.click(tab('Uffizi'));
    fireEvent.click(screen.getByTestId('download-day-sheet'));
    await waitFor(() => expect(downloadDayParticipantsPdf).toHaveBeenLastCalledWith('Uffizi', DAY));
  });

  it('a failed download is said out loud', async () => {
    downloadDayParticipantsPdf.mockRejectedValueOnce(
      Object.assign(new Error('timeout of 90000ms exceeded'), { code: 'ECONNABORTED', isAxiosError: true }));
    const { container } = renderPage();
    await screen.findAllByText('Borghese Guest');
    fireEvent.click(tab('Accademia'));
    pickDay(container);
    fireEvent.click(screen.getByTestId('download-day-sheet'));
    expect(await screen.findByText(/Could not download the participants list/)).toBeInTheDocument();
  });

  it('a failed fetch shows the banner on the Accademia tab, never "No ticket bookings found"', async () => {
    getTours.mockRejectedValueOnce(Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK', isAxiosError: true }));
    renderPage();
    const box = await screen.findByTestId('load-problem');
    expect(box).toHaveTextContent('Could not load the ticket bookings');
    expect(screen.queryByText('No ticket bookings found')).toBeNull();
    expect(markListStart).toHaveBeenCalled();
    expect(markListEnd).toHaveBeenCalledWith(false);
    // the retry reloads, and the tabs work on what arrives
    getTours.mockResolvedValueOnce({ data: BOOKINGS });
    fireEvent.click(within(box).getByRole('button', { name: /retry/i }));
    await screen.findAllByText('Borghese Guest');
    fireEvent.click(tab('Accademia'));
    expect([...new Set(names())]).toEqual(['Rodolfo Irizar', 'Breana Chauntler', 'Cancelled Guest']);
  });
});
