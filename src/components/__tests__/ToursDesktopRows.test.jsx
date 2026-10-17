/**
 * Step 9.2: the Tours desktop rows (docs/DESIGN.md rule 3) - a single booking, a group that
 * opens to its members, and an unassigned booking with its Assign button and Needs guide pill.
 */
import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import BookingRow from '../tours/BookingRow';
import TourGroup from '../TourGroup';

const booking = (extra = {}) => ({
  id: 7701, title: 'Uffizi Gallery Small Group Guided Tour with Tickets', date: '2026-10-18', time: '09:30:00',
  participants: 3, booking_channel: 'www.florencewithlocals.com', cancelled: false, guide_id: 5,
  customer_name: 'Maria Rossi', participant_names: JSON.stringify([{ first: 'Maria', last: 'Rossi' }, { first: 'Luca', last: 'Rossi' }]),
  notes: null, ...extra,
});

const rowProps = (tour, extra = {}) => ({
  tour, time: '09:30', language: 'English', pax: 3, guideName: 'Caterina Bianchi',
  onEditGuide: vi.fn(), onEditNote: vi.fn(), onOpen: vi.fn(),
  menuItems: [{ label: 'Booking details', onClick: vi.fn() }, { label: 'Participant list (PDF)', onClick: vi.fn() }],
  ...extra,
});

describe('Tours desktop rows (step 9.2)', () => {
  it('a single booking: one grid row, short channel, EN, guests, guide, no status pill, menu holds the actions', () => {
    const props = rowProps(booking());
    render(<BookingRow {...props} />);
    const row = screen.getByText('09:30').closest('[role="row"]');
    expect(row.className).toContain('fwl-tours-grid');
    expect(row.className).toContain('min-h-14'); // 56 px
    const cells = row.children;
    expect(cells).toHaveLength(9);
    expect(cells[1].textContent).toContain('Maria Rossi +1');
    expect(cells[2].textContent).toBe('Website');
    expect(cells[3].textContent).toBe('EN');
    expect(cells[4].textContent).toBe('3');
    expect(cells[5].textContent).toContain('Caterina Bianchi');
    expect(cells[6].textContent).toBe(''); // a confirmed, assigned booking has no pill and no colour
    expect(row.parentElement.className).not.toMatch(/bg-(green|danger)/);
    fireEvent.click(row);
    expect(props.onOpen).toHaveBeenCalledWith(props.tour);
    fireEvent.click(screen.getByRole('button', { name: /^Actions for 09:30/ }));
    const menu = screen.getByRole('menu');
    expect(within(menu).getAllByRole('menuitem').map((b) => b.textContent)).toEqual(['Booking details', 'Participant list (PDF)']);
    fireEvent.click(within(menu).getByText('Booking details'));
    expect(props.menuItems[0].onClick).toHaveBeenCalled();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('an unassigned booking shows the dashed Assign guide button and the Needs guide pill', () => {
    const props = rowProps(booking({ guide_id: null }), { guideName: null, language: 'Italian' });
    render(<BookingRow {...props} />);
    expect(screen.getByText('Needs guide')).toBeTruthy();
    expect(screen.getByTestId('tour-language-chip').textContent).toBe('IT'); // non-English = info pill
    const assign = screen.getByRole('button', { name: /Assign guide/ });
    expect(assign.className).toContain('border-dashed');
    fireEvent.click(assign);
    expect(props.onEditGuide).toHaveBeenCalled();
    expect(props.onOpen).not.toHaveBeenCalled(); // the click stays in the cell
  });

  it('a group row: "N bookings" meta, guests over capacity, Mixed channel, chevron opens 40 px member rows', () => {
    const group = {
      id: 1508500, group_date: '2026-10-18', group_time: '09:30:00', display_name: 'Uffizi Gallery Small Group Guided Tour with Tickets',
      is_manual_merge: false, guide_id: 5, guide_name: 'Caterina Bianchi',
      tours: [
        { id: 1, title: 'Uffizi Gallery Small Group Guided Tour with Tickets', participants: 2, customer_name: 'Ann Lee', booking_channel: 'Viator', language: 'English', bokun_confirmation_code: 'VIA-1', cancelled: false },
        { id: 2, title: 'Uffizi Gallery Small Group Guided Tour with Tickets', participants: 3, customer_name: 'Bo Chen', booking_channel: 'GetYourGuide', language: 'English', bokun_confirmation_code: 'GYG-2', cancelled: false },
      ],
    };
    const onTourClick = vi.fn();
    render(<TourGroup group={group} guides={[]} onTourClick={onTourClick} />);
    const row = screen.getByText('09:30').closest('[role="row"]');
    expect(row.children[1].textContent).toContain('2 bookings');
    expect(row.children[2].textContent).toBe('Mixed');
    expect(screen.getByTestId('group-guests-1508500').textContent).toBe('5 / 9');
    expect(screen.queryByTestId('group-member-1')).toBeNull();
    const chevron = screen.getByRole('button', { name: 'Show bookings in this group' });
    expect(chevron.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(chevron);
    expect(screen.getByRole('button', { name: 'Hide bookings in this group' }).getAttribute('aria-expanded')).toBe('true');
    const member = screen.getByTestId('group-member-1');
    expect(member.className).toContain('h-10'); // 40 px
    expect(member.textContent).toContain('Ann Lee');
    expect(member.textContent).toContain('VIA-1');
    expect(member.textContent).toContain('Group guide');
    fireEvent.click(member);
    expect(onTourClick).toHaveBeenCalledWith(group.tours[0]);
    fireEvent.click(within(member).getByRole('button', { name: /^Actions for Ann Lee/ }));
    expect(screen.getByRole('menuitem', { name: 'Remove from group' })).toBeTruthy();
  });
});
