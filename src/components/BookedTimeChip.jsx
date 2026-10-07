import { memberBookedTime } from '../utils/groupTime';

/**
 * Step 6.16: this booking was booked at another time than its group leaves at
 * (e.g. a 14:30 booking moved into the 12:30 departure). Nothing when the times match.
 */
const BookedTimeChip = ({ tour, group, className = '' }) => {
  const booked = memberBookedTime(tour, group);
  if (!booked) return null;
  return (
    <span
      className={`inline-block px-2 py-0.5 text-xs font-medium rounded-tuscan whitespace-nowrap bg-stone-100 text-stone-700 border border-stone-300 ${className}`}
      data-testid="booked-time-chip"
      title="This booking was made for another time; the group leaves at the group's time"
    >
      booked {booked}
    </span>
  );
};

export default BookedTimeChip;
