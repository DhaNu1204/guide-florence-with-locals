/**
 * Step 3.1: the mobile card's green badge follows guide_paid (payments table),
 * never the legacy tours.paid flag that the Bokun sync used to write.
 */
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import TourCardMobile from '../TourCardMobile';

const base = {
  id: 1,
  title: 'Uffizi Gallery Guided Tour',
  date: '2026-09-20',
  time: '10:00:00',
  participants: 2,
  booking_channel: 'Viator',
  cancelled: false,
  rescheduled: false,
  is_private: 0,
};

const renderCard = (tour) =>
  render(
    <TourCardMobile
      tour={tour}
      guides={[]}
      tourTime="10:00"
      tourLanguage="English"
      participantCount={2}
      editingGuides={{}}
      editingNotes={{}}
      savingChanges={{}}
      onCardClick={() => {}}
    />
  );

describe('TourCardMobile guide-paid badge', () => {
  it('guide paid -> green "Guide paid" badge', () => {
    renderCard({ ...base, guide_paid: true, paid: false });
    expect(screen.getByText('Guide paid')).toBeInTheDocument();
  });

  it('customer paid (legacy paid flag, Bokun amount) but guide unpaid -> no green badge', () => {
    renderCard({ ...base, guide_paid: false, paid: true, payment_status: 'paid', bokun_total_price: 116.46, bokun_currency: 'EUR' });
    expect(screen.queryByText('Guide paid')).not.toBeInTheDocument();
    expect(screen.queryByText('Paid')).not.toBeInTheDocument();
  });
});
