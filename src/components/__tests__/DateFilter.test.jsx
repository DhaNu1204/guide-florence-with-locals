/**
 * Render test for the themed DateFilter calendar.
 * Verifies it mounts, opens the calendar, selects a day, and that the month
 * arrows jump to the 1st of the adjacent month.
 */
import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import DateFilter from '../DateFilter';

const baseProps = (overrides = {}) => {
  const setFilterDate = vi.fn();
  return {
    props: {
      filterDate: new Date(2026, 6, 15), // Wed, 15 Jul 2026
      setFilterDate,
      showUpcoming: false, setShowUpcoming: vi.fn(),
      showPast: false, setShowPast: vi.fn(),
      showDateRange: false, setShowDateRange: vi.fn(),
      rangeStartDate: '', setRangeStartDate: vi.fn(),
      rangeEndDate: '', setRangeEndDate: vi.fn(),
      ...overrides,
    },
    setFilterDate,
  };
};

describe('DateFilter', () => {
  it('shows the selected date and opens the calendar on click', () => {
    const { props } = baseProps();
    render(<DateFilter {...props} />);
    // Trigger shows the formatted selected date.
    const trigger = screen.getByRole('button', { name: /select date/i });
    expect(trigger).toHaveTextContent('Wed, 15 Jul 2026');
    fireEvent.click(trigger);
    // Calendar dialog with the month label appears.
    const dialog = screen.getByRole('dialog', { name: /calendar/i });
    expect(within(dialog).getByText('July 2026')).toBeInTheDocument();
  });

  it('selects a day and clears the period flags', () => {
    const { props, setFilterDate } = baseProps();
    render(<DateFilter {...props} />);
    fireEvent.click(screen.getByRole('button', { name: /select date/i }));
    const dialog = screen.getByRole('dialog', { name: /calendar/i });
    fireEvent.click(within(dialog).getByText('10'));
    expect(setFilterDate).toHaveBeenCalledTimes(1);
    const picked = setFilterDate.mock.calls[0][0];
    expect(picked.getFullYear()).toBe(2026);
    expect(picked.getMonth()).toBe(6); // July
    expect(picked.getDate()).toBe(10);
    expect(props.setShowUpcoming).toHaveBeenCalledWith(false);
  });

  it('next-month arrow jumps to the 1st of the next month', () => {
    const { props, setFilterDate } = baseProps();
    render(<DateFilter {...props} />);
    fireEvent.click(screen.getByRole('button', { name: /select date/i }));
    fireEvent.click(screen.getByRole('button', { name: /next month/i }));
    const picked = setFilterDate.mock.calls[0][0];
    expect(picked.getMonth()).toBe(7); // August
    expect(picked.getDate()).toBe(1);  // the 1st
  });
});
