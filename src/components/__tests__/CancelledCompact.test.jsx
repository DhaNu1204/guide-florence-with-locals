/**
 * Step 6.23: a cancelled booking is one grey line until opened - on the Tours table, inside an
 * opened group and on the phone. Opening it never changes a count (cancelled bookings were never
 * counted).
 */
import React, { useState } from 'react';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import TourGroup from '../TourGroup';
import TourGroupCardMobile from '../TourGroupCardMobile';
import { CompactCancelledTourRow, CompactCancelledCard, firstGuestLabel, toggleId } from '../CancelledCompact';

const COMBO = 'Uffizi & Accademia Walking Tour with Gelato & Art Historian';
const cancelled = {
  id: 7615, title: COMBO, cancelled: true, participants: 5, booking_channel: 'GetYourGuide',
  customer_name: 'Ann Lee', participant_names: JSON.stringify([{ first: 'Ann', last: 'Lee' }, { first: 'Bo', last: 'Lee' }, { first: 'Cy', last: 'Lee' }]),
  language: 'English', date: '2026-10-16', time: '09:30:00',
};

// The Tours page pattern: the compact line until the chevron (or the row) opens it.
function Harness() {
  const [open, setOpen] = useState({});
  return (
    <table><tbody>
      {open[cancelled.id] ? (
        <tr data-testid="full-row"><td>full cancelled row</td></tr>
      ) : (
        <CompactCancelledTourRow tour={cancelled} time="09:30" language="English" pax={5} guideName="Caterina"
          onToggle={() => setOpen((p) => toggleId(p, cancelled.id))} />
      )}
    </tbody></table>
  );
}

describe('step 6.23 compact cancelled booking', () => {
  it('first guest + N, falling back to the customer name', () => {
    expect(firstGuestLabel(cancelled)).toBe('Ann Lee +2');
    expect(firstGuestLabel({ customer_name: 'Solo' })).toBe('Solo');
    expect(firstGuestLabel({})).toBe('');
  });

  it('desktop: one 40 px line with the same 7 columns, Cancelled pill and a real chevron button', () => {
    render(<Harness />);
    const row = screen.getByTestId('cancelled-compact-7615');
    expect(row.style.height).toBe('40px');
    expect(row.style.backgroundColor).toBe('rgb(251, 243, 243)');
    expect(row.querySelectorAll('td')).toHaveLength(7);
    expect(row.textContent).toContain('09:30');
    expect(row.textContent).toContain('Ann Lee +2');
    expect(row.textContent).toContain('Caterina');
    expect(within(row).getByText('Cancelled')).toBeTruthy();
    const btn = within(row).getByRole('button', { name: 'Show cancelled booking details' });
    expect(btn.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(btn);
    expect(screen.getByTestId('full-row')).toBeTruthy();
    expect(screen.queryByTestId('cancelled-compact-7615')).toBeNull();
  });

  it('clicking the line itself also opens it', () => {
    render(<Harness />);
    fireEvent.click(screen.getByTestId('cancelled-compact-7615'));
    expect(screen.getByTestId('full-row')).toBeTruthy();
  });

  it('phone: one 44 px line "time · tour · PAX · guide" with the pill and chevron', () => {
    const onToggle = vi.fn();
    render(<CompactCancelledCard tour={cancelled} time="09:30" pax={5} guideName="Caterina" onToggle={onToggle} />);
    const card = screen.getByTestId('cancelled-compact-7615');
    expect(card.className).toContain('h-11');
    expect(card.textContent).toContain(`09:30 · ${COMBO} · 5 PAX · Caterina`);
    fireEvent.click(screen.getByRole('button', { name: 'Show cancelled booking details' }));
    expect(onToggle).toHaveBeenCalledTimes(1);
  });

  it('inside an opened group: the cancelled member is compact, opens and closes, PAX never changes', () => {
    const group = {
      id: 1508307, group_date: '2026-10-16', group_time: '09:30:00', display_name: COMBO, total_pax: 9, max_pax: 9,
      is_manual_merge: false, guide_id: null, guide_name: null,
      tours: [
        { id: 6835, title: COMBO, cancelled: false, participants: 2, customer_name: 'A', language: 'English', date: '2026-10-16', time: '09:30:00' },
        { id: 7095, title: COMBO, cancelled: false, participants: 2, customer_name: 'B', language: 'English', date: '2026-10-16', time: '09:30:00' },
        cancelled,
      ],
    };
    render(<TourGroup group={group} guides={[]} />);
    const guests = () => screen.getByTestId(`group-guests-${group.id}`).textContent;
    expect(guests()).toMatch(/^4 \/ /);                              // 2 + 2, the cancelled 5 never counts (step 9.2: "4 / max")
    fireEvent.click(screen.getByRole('button', { name: 'Show bookings in this group' })); // step 9.2 chevron
    const compact = screen.getByTestId('cancelled-compact-7615');
    expect(compact.className).toContain('h-10'); // 40 px
    fireEvent.click(within(compact).getByRole('button', { name: 'Show cancelled booking details' }));
    expect(screen.queryByTestId('cancelled-compact-7615')).toBeNull();
    const hide = screen.getByRole('button', { name: 'Hide cancelled booking details' });
    expect(guests()).toMatch(/^4 \/ /);                              // opening changed no count
    fireEvent.click(hide);
    expect(screen.getByTestId('cancelled-compact-7615')).toBeTruthy();
  });

  it('phone group card: the cancelled member is a 44 px line', () => {
    const group = {
      id: 1, group_date: '2026-10-16', group_time: '09:30:00', display_name: COMBO, total_pax: 2, max_pax: 9,
      is_manual_merge: false, tours: [
        { id: 1, title: COMBO, cancelled: false, participants: 2, customer_name: 'A', date: '2026-10-16', time: '09:30:00' },
        cancelled,
      ],
    };
    render(<TourGroupCardMobile group={group} guides={[]} />);
    fireEvent.click(screen.getAllByText(COMBO)[0].closest('div.cursor-pointer'));
    const line = screen.getByTestId('cancelled-compact-7615');
    expect(line.className).toContain('h-11');
    expect(line.textContent).toContain('Ann Lee +2 · 5 PAX · GetYourGuide');
  });
});
