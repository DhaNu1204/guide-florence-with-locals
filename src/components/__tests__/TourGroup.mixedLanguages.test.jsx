/**
 * Step 6.12: a group that mixes languages is never silent. Auto-grouping no longer mixes
 * languages, so in practice this is a manual merge the owner made on purpose - it is left as
 * it is, with a warning chip, on desktop and on the phone card.
 */
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import TourGroup from '../TourGroup';
import TourGroupCardMobile from '../TourGroupCardMobile';

const tour = (id, language, extra = {}) => ({
  id, language, participants: 2, cancelled: 0, customer_name: `C${id}`,
  title: 'Uffizi Gallery Small Group Guided Tour with Tickets', date: '2026-09-25', time: '14:30:00', ...extra,
});
const group = (tours, extra = {}) => ({
  id: 1508371, group_date: '2026-09-25', group_time: '14:30:00', display_name: 'Uffizi Gallery Small Group Guided Tour with Tickets',
  total_pax: 7, max_pax: 9, is_manual_merge: true, guide_id: null, guide_name: null, tours, ...extra,
});

describe('mixed-languages chip (step 6.12)', () => {
  it('a manual mixed merge shows the chip next to Manual (desktop)', () => {
    render(<TourGroup group={group([tour(7276, 'Italian'), tour(7299, 'English'), tour(7335, 'English')])} guides={[]} />);
    const chip = screen.getByTestId('mixed-languages-chip');
    expect(chip.textContent).toBe('Mixed languages');
    expect(chip.getAttribute('title')).toBe('Languages in this group: English, Italian');
    expect(screen.getByText('Manual')).toBeTruthy();
  });

  it('a manual mixed merge shows the chip on the phone card', () => {
    render(<TourGroupCardMobile group={group([tour(1, 'Spanish'), tour(2, 'English')])} guides={[]} />);
    expect(screen.getByTestId('mixed-languages-chip').textContent).toBe('Mixed languages');
  });

  it('a one-language group has no chip', () => {
    render(<TourGroup group={group([tour(1, 'English'), tour(2, 'English'), tour(3, '')], { is_manual_merge: false })} guides={[]} />);
    expect(screen.queryByTestId('mixed-languages-chip')).toBeNull();
  });
});
