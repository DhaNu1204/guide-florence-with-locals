import { isVasari, countVasariPax } from '../utils/vasari';

/**
 * Step 6.14: this booking includes the Vasari Corridor.
 *
 * Warm gold with a border, so it reads apart from the plain gold "Mixed"/"Manual"/"Combo"
 * pills, the blue language chip and the grey "old Viator" chip. Uffizi-only bookings get
 * nothing: the absence is the signal.
 */
const CHIP = 'inline-block px-2 py-0.5 text-xs font-medium rounded-tuscan whitespace-nowrap bg-gold-50 text-gold-800 border border-gold-300';

const TITLE = 'Booked on a Vasari Corridor rate (Uffizi + Vasari Corridor)';

const VasariChip = ({ tour, className = '' }) => {
  if (!isVasari(tour)) return null;
  return (
    <span className={`${CHIP} ${className}`} data-testid="vasari-chip" title={TITLE}>
      Vasari
    </span>
  );
};

// Group header: how many PEOPLE in the group do Vasari (cancelled bookings excluded).
export const VasariGroupChip = ({ tours, className = '' }) => {
  const pax = countVasariPax(tours);
  if (pax <= 0) return null;
  return (
    <span className={`${CHIP} ${className}`} data-testid="vasari-group-chip" title={TITLE}>
      Vasari · {pax} PAX
    </span>
  );
};

export default VasariChip;
