/**
 * Step 6.16: a manual merge of bookings booked at different times leaves at the time the owner
 * picked (tour_groups.departure_time). NULL keeps the old behaviour (group_time). Members booked
 * at another time show "booked HH:MM".
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import TourGroup from '../TourGroup';
import TourGroupCardMobile from '../TourGroupCardMobile';
import MergeTimeDialog from '../MergeTimeDialog';
import { groupDepartureTime, memberBookedTime, mergeTimes, defaultMergeTime } from '../../utils/groupTime';

const NAME = 'Uffizi Gallery Small Group Guided Tour with Tickets';
const tour = (id, time, extra = {}) => ({
  id, time, language: 'English', participants: 2, cancelled: false, customer_name: `C${id}`,
  title: NAME, date: '2026-10-07', booking_channel: 'Viator', vasari: false, ...extra,
});
// The 7 Oct case: 6930 (14:30, lower id, so the old merge took its time) into 7221 (12:30).
const group = (extra = {}) => ({
  id: 1508446, group_date: '2026-10-07', group_time: '14:30:00', departure_time: '12:30:00',
  display_name: NAME, total_pax: 6, max_pax: 9, is_manual_merge: true, guide_id: 33, guide_name: 'Isabella',
  tours: [tour(6930, '14:30:00'), tour(7221, '12:30:00', { participants: 4 })], ...extra,
});

describe('groupTime helpers (step 6.16)', () => {
  it('departure_time wins, else group_time (old behaviour)', () => {
    expect(groupDepartureTime(group())).toBe('12:30');
    expect(groupDepartureTime(group({ departure_time: null }))).toBe('14:30');
    expect(groupDepartureTime(null)).toBe('');
  });
  it('a member says "booked" only when its own time differs', () => {
    const g = group();
    expect(memberBookedTime(g.tours[0], g)).toBe('14:30');
    expect(memberBookedTime(g.tours[1], g)).toBeNull();
    expect(memberBookedTime(tour(1, null), g)).toBeNull();
  });
  it('mergeTimes = distinct HH:MM, earliest first', () => {
    expect(mergeTimes([tour(1, '14:30:00'), tour(2, '12:30:00'), tour(3, '14:30:00')])).toEqual(['12:30', '14:30']);
    expect(mergeTimes([tour(1, '12:30:00'), tour(2, '12:30')])).toEqual(['12:30']);
  });
  it('defaults to the departure being merged into', () => {
    const times = ['12:30', '14:30'];
    expect(defaultMergeTime({ type: 'tour', tour: tour(7221, '12:30:00') }, times)).toBe('12:30');
    expect(defaultMergeTime({ type: 'tour', tour: tour(6930, '14:30:00') }, times)).toBe('14:30');
    expect(defaultMergeTime({ type: 'group', group: group() }, times)).toBe('12:30');
    expect(defaultMergeTime({ type: 'group', group: group({ departure_time: null }) }, times)).toBe('14:30');
    expect(defaultMergeTime({ type: 'tour', tour: null }, times)).toBe('12:30');
  });
});

describe('group header and member rows (step 6.16)', () => {
  it('desktop: header shows 12:30, the 14:30 booking says "booked 14:30"', () => {
    render(<TourGroup group={group()} guides={[]} />);
    expect(screen.getByText('12:30')).toBeTruthy();
    expect(screen.queryByText('14:30')).toBeNull();
    fireEvent.click(screen.getByText(NAME));
    const chips = screen.getAllByTestId('booked-time-chip');
    expect(chips).toHaveLength(1);
    expect(chips[0].textContent).toBe('booked 14:30');
  });
  it('phone card: same header time and chip', () => {
    render(<TourGroupCardMobile group={group()} guides={[]} />);
    expect(screen.getByText('12:30')).toBeTruthy();
    fireEvent.click(screen.getByText(NAME));
    expect(screen.getAllByTestId('booked-time-chip').map(c => c.textContent)).toEqual(['booked 14:30']);
  });
  it('no departure_time and same times: header as before, no chip', () => {
    const g = group({ departure_time: null, group_time: '12:30:00', tours: [tour(1, '12:30:00'), tour(2, '12:30:00')] });
    render(<TourGroup group={g} guides={[]} />);
    expect(screen.getByText('12:30')).toBeTruthy();
    fireEvent.click(screen.getByText(NAME));
    expect(screen.queryAllByTestId('booked-time-chip')).toHaveLength(0);
  });
});

describe('MergeTimeDialog (step 6.16)', () => {
  const options = [{ time: '12:30', bookings: 1, pax: 4 }, { time: '14:30', bookings: 1, pax: 2 }];
  it('pre-selects the default and confirms it', () => {
    const onConfirm = vi.fn();
    render(<MergeTimeDialog options={options} defaultTime="12:30" onConfirm={onConfirm} onCancel={() => {}} />);
    expect(screen.getByLabelText(/12:30/).checked).toBe(true);
    fireEvent.click(screen.getByText('Merge at 12:30'));
    expect(onConfirm).toHaveBeenCalledWith('12:30');
  });
  it('another time can be picked', () => {
    const onConfirm = vi.fn();
    render(<MergeTimeDialog options={options} defaultTime="12:30" onConfirm={onConfirm} onCancel={() => {}} />);
    fireEvent.click(screen.getByLabelText(/14:30/));
    fireEvent.click(screen.getByText('Merge at 14:30'));
    expect(onConfirm).toHaveBeenCalledWith('14:30');
  });
  it('Cancel and Escape cancel', () => {
    const onCancel = vi.fn();
    render(<MergeTimeDialog options={options} defaultTime="12:30" onConfirm={() => {}} onCancel={onCancel} />);
    fireEvent.click(screen.getByText('Cancel'));
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onCancel).toHaveBeenCalledTimes(2);
  });
});
