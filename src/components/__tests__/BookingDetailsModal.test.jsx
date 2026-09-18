/**
 * Step 4.2: the details modal fetches the full tour row on open
 * (list rows no longer carry bokun_data).
 */
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  getTourById: vi.fn(),
}));

import { getTourById } from '../../services/mysqlDB';
import BookingDetailsModal from '../BookingDetailsModal';

const listRow = {
  id: 7,
  title: 'Uffizi Gallery Guided Tour',
  date: '2026-09-20',
  time: '10:00:00',
  participants: 2,
  customer_name: 'Jane Doe',
  booking_channel: 'Viator',
};

const fullRow = {
  ...listRow,
  customer_email: 'jane@example.com',
  bokun_data: JSON.stringify({
    confirmationCode: 'VIA-123',
    customer: { firstName: 'Jane', lastName: 'Doe', email: 'jane@example.com', phoneNumber: '+390550000000' },
    productBookings: [{
      status: 'CONFIRMED',
      specialRequests: 'Wheelchair access please',
      fields: { priceCategoryBookings: [{ quantity: 2, pricingCategory: { ticketCategory: 'ADULT' } }] },
    }],
  }),
};

describe('BookingDetailsModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls the single-row endpoint on open, shows a spinner, then renders the full row', async () => {
    let resolveFetch;
    getTourById.mockReturnValue(new Promise((resolve) => { resolveFetch = resolve; }));

    render(<BookingDetailsModal isOpen={true} onClose={() => {}} ticket={listRow} onUpdateNotes={() => {}} />);

    expect(getTourById).toHaveBeenCalledTimes(1);
    expect(getTourById).toHaveBeenCalledWith(7);
    expect(screen.getByRole('status')).toBeInTheDocument();

    resolveFetch(fullRow);

    expect(await screen.findByText('Wheelchair access please')).toBeInTheDocument();
    expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    expect(screen.getByText('+390550000000')).toBeInTheDocument();
    expect(screen.getByText('VIA-123')).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('does not fetch while closed', () => {
    render(<BookingDetailsModal isOpen={false} onClose={() => {}} ticket={listRow} onUpdateNotes={() => {}} />);
    expect(getTourById).not.toHaveBeenCalled();
  });

  it('falls back to the list row with a notice when the fetch fails', async () => {
    getTourById.mockRejectedValue(new Error('network'));

    render(<BookingDetailsModal isOpen={true} onClose={() => {}} ticket={listRow} onUpdateNotes={() => {}} />);

    await waitFor(() => expect(screen.getByText(/Could not load the full booking details/)).toBeInTheDocument());
    expect(screen.getByText('Uffizi Gallery Guided Tour')).toBeInTheDocument();
  });
});
