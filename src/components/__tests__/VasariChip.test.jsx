/**
 * Step 6.14: the "Vasari" label on Uffizi bookings sold on a Vasari Corridor rate.
 *
 * The rule (rate title contains "Vasari") is decided on the server and arrives as `vasari`;
 * these tests pin where the chip shows, that Uffizi-only bookings get nothing, and that the
 * group count is PEOPLE doing Vasari with cancelled bookings left out.
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import VasariChip, { VasariGroupChip } from '../VasariChip';
import TourGroup from '../TourGroup';
import TourGroupCardMobile from '../TourGroupCardMobile';
import TourCardMobile from '../TourCardMobile';
import { countVasariPax } from '../../utils/vasari';

const NAME = 'Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit';
const tour = (id, extra = {}) => ({
  id, language: 'English', participants: 2, cancelled: false, customer_name: `C${id}`,
  title: NAME, date: '2026-09-26', time: '10:00:00', booking_channel: 'GetYourGuide',
  rate_title: 'Uffizi Gallery Tour', vasari: false, ...extra,
});
const vasari = (id, pax, extra = {}) => tour(id, { participants: pax, rate_title: 'Vasari Corridor Access', vasari: true, ...extra });
const group = (tours) => ({
  id: 1508400, group_date: '2026-09-26', group_time: '10:00:00', display_name: NAME,
  total_pax: 9, max_pax: 9, is_manual_merge: false, guide_id: null, guide_name: null, tours,
});

describe('countVasariPax (step 6.14)', () => {
  it('sums the PAX of Vasari members only', () => {
    expect(countVasariPax([vasari(1, 4), tour(2, { participants: 3 }), vasari(3, 2)])).toBe(6);
  });
  it('leaves cancelled Vasari bookings out', () => {
    expect(countVasariPax([vasari(1, 4), vasari(2, 5, { cancelled: true }), vasari(3, 1, { cancelled: 1 })])).toBe(4);
  });
  it('is 0 for no Vasari, an empty group or nothing at all', () => {
    expect(countVasariPax([tour(1), tour(2)])).toBe(0);
    expect(countVasariPax([])).toBe(0);
    expect(countVasariPax(undefined)).toBe(0);
  });
});

describe('VasariChip (step 6.14)', () => {
  it('marks a Vasari booking (the single-booking row uses this chip)', () => {
    render(<VasariChip tour={vasari(1, 4)} />);
    const chip = screen.getByTestId('vasari-chip');
    expect(chip.textContent).toBe('Vasari');
    expect(chip.className).toMatch(/bg-gold-50/);
    expect(chip.className).toMatch(/border-gold-300/);
  });
  it('says nothing for an Uffizi-only booking or a missing flag', () => {
    const { container } = render(<><VasariChip tour={tour(1)} /><VasariChip tour={{ id: 2 }} /><VasariChip tour={null} /></>);
    expect(container.innerHTML).toBe('');
  });
  it('group chip counts people and hides when nobody does Vasari', () => {
    render(<VasariGroupChip tours={[vasari(1, 4), tour(2, { participants: 5 })]} />);
    expect(screen.getByTestId('vasari-group-chip').textContent).toBe('Vasari · 4 PAX');
  });
  it('group chip is absent when the only Vasari booking is cancelled', () => {
    render(<VasariGroupChip tours={[vasari(1, 4, { cancelled: true }), tour(2)]} />);
    expect(screen.queryByTestId('vasari-group-chip')).toBeNull();
  });
});

describe('where the chip shows (step 6.14)', () => {
  const members = [vasari(6689, 4), tour(7000, { participants: 3 }), vasari(7001, 2, { cancelled: true })];

  it('desktop group: header "Vasari · 4 PAX", and each Vasari member row its own chip', () => {
    render(<TourGroup group={group(members)} guides={[]} />);
    expect(screen.getByTestId('vasari-group-chip').textContent).toBe('Vasari · 4 PAX');
    expect(screen.queryAllByTestId('vasari-chip')).toHaveLength(0); // collapsed: no member rows
    fireEvent.click(screen.getByText(NAME));
    // two Vasari members (one cancelled - still listed struck through, still labelled), none on the Uffizi-only row
    expect(screen.getAllByTestId('vasari-chip')).toHaveLength(2);
  });

  it('phone group card: the same header chip and member chips', () => {
    render(<TourGroupCardMobile group={group(members)} guides={[]} />);
    expect(screen.getByTestId('vasari-group-chip').textContent).toBe('Vasari · 4 PAX');
    fireEvent.click(screen.getByText(NAME));
    expect(screen.getAllByTestId('vasari-chip')).toHaveLength(2);
  });

  it('a group with no Vasari member has no header chip', () => {
    render(<TourGroup group={group([tour(1), tour(2)])} guides={[]} />);
    expect(screen.queryByTestId('vasari-group-chip')).toBeNull();
  });

  const renderCard = (t) => render(
    <TourCardMobile tour={t} guides={[]} tourTime="10:00" tourLanguage="English" participantCount={t.participants}
      editingGuides={{}} editingNotes={{}} savingChanges={{}} onCardClick={() => {}} />
  );

  it('phone single card shows the chip for a Vasari booking', () => {
    renderCard(vasari(6689, 4));
    expect(screen.getByTestId('vasari-chip').textContent).toBe('Vasari');
  });

  it('phone single card shows nothing for an Uffizi-only booking', () => {
    renderCard(tour(7176));
    expect(screen.queryByTestId('vasari-chip')).toBeNull();
  });
});
