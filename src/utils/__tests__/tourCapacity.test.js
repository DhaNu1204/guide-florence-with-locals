/**
 * tourCapacity util tests
 * Florence With Locals - tourCategory classifier
 */

import { describe, it, expect } from 'vitest';
import { tourCategory, getPaxBreakdown, aggregateBreakdown, formatBreakdown } from '../tourCapacity';

describe('tourCategory', () => {
  it('classifies Uffizi tours', () => {
    expect(tourCategory('Uffizi Gallery Skip-the-Line Tour')).toBe('Uffizi');
  });

  it('classifies Accademia tours', () => {
    expect(tourCategory('Accademia Gallery Guided Tour')).toBe('Accademia');
  });

  it("classifies 'David' as Accademia", () => {
    expect(tourCategory('David & Michelangelo Masterpieces')).toBe('Accademia');
  });

  it('classifies Pitti/Boboli tours', () => {
    expect(tourCategory('Pitti Palace & Boboli Gardens')).toBe('Pitti');
  });

  it('classifies a 2+ museum combo as Combo', () => {
    expect(tourCategory('Uffizi & Accademia Walking Tour')).toBe('Combo');
  });

  it('returns Other when nothing matches', () => {
    expect(tourCategory('Ponte Vecchio Food Walk')).toBe('Other');
  });

  it('handles empty/undefined titles', () => {
    expect(tourCategory('')).toBe('Other');
    expect(tourCategory(undefined)).toBe('Other');
  });
});

// Wrap a priceCategoryBookings array into a tour with bokun_data JSON.
const wrapPcb = (pcb, extraFields = {}) => ({
  bokun_data: JSON.stringify({
    productBookings: [{ fields: { priceCategoryBookings: pcb, ...extraFields } }],
  }),
});

describe('getPaxBreakdown', () => {
  it('sums a single element with quantity:N', () => {
    const tour = wrapPcb([{ quantity: 3, pricingCategory: { ticketCategory: 'ADULT' } }]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 3, children: 0, infants: 0, total: 3 });
  });

  it('sums one-element-per-participant (quantity:1 each)', () => {
    const tour = wrapPcb([
      { quantity: 1, pricingCategory: { ticketCategory: 'ADULT' } },
      { quantity: 1, pricingCategory: { ticketCategory: 'ADULT' } },
    ]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 2, children: 0, infants: 0, total: 2 });
  });

  it('handles a mixed adult + child + infant booking', () => {
    const tour = wrapPcb([
      { quantity: 2, pricingCategory: { ticketCategory: 'ADULT' } },
      { quantity: 1, pricingCategory: { ticketCategory: 'CHILD' } },
      { quantity: 1, pricingCategory: { ticketCategory: 'INFANT' } },
    ]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 2, children: 1, infants: 1, total: 4 });
  });

  it('defaults missing quantity to 1', () => {
    const tour = wrapPcb([
      { pricingCategory: { ticketCategory: 'ADULT' } },
      { pricingCategory: { ticketCategory: 'CHILD' } },
    ]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 1, children: 1, infants: 0, total: 2 });
  });

  it('falls back to title keyword when ticketCategory is absent', () => {
    const tour = wrapPcb([
      { quantity: 2, pricingCategory: { title: 'Adults' } },
      { quantity: 1, bookedTitle: 'Child (6-17)' },
      { quantity: 1, pricingCategory: { title: 'Infant' } },
    ]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 2, children: 1, infants: 1, total: 4 });
  });

  it('falls back to tours.participants when bokun_data is missing', () => {
    expect(getPaxBreakdown({ participants: 5 })).toEqual({ adults: 5, children: 0, infants: 0, total: 5 });
  });

  it('falls back to totalParticipants when priceCategoryBookings is empty', () => {
    const tour = { bokun_data: JSON.stringify({ productBookings: [{ fields: { totalParticipants: 4, priceCategoryBookings: [] } }] }) };
    expect(getPaxBreakdown(tour)).toEqual({ adults: 4, children: 0, infants: 0, total: 4 });
  });

  it('falls back on malformed bokun_data', () => {
    expect(getPaxBreakdown({ bokun_data: 'not-json', participants: 3 })).toEqual({ adults: 3, children: 0, infants: 0, total: 3 });
  });

  it('PREFERS server-computed pax_* fields over bokun_data', () => {
    // Grouped member rows arrive without bokun_data but WITH pax_* from the server.
    const tour = { pax_adults: 6, pax_children: 2, pax_infants: 0 };
    expect(getPaxBreakdown(tour)).toEqual({ adults: 6, children: 2, infants: 0, total: 8 });
  });

  it('uses server pax_* even when bokun_data is also present', () => {
    const tour = {
      pax_adults: 2, pax_children: 2, pax_infants: 0,
      bokun_data: JSON.stringify({ productBookings: [{ fields: { priceCategoryBookings: [{ quantity: 9, pricingCategory: { ticketCategory: 'ADULT' } }] } }] }),
    };
    expect(getPaxBreakdown(tour)).toEqual({ adults: 2, children: 2, infants: 0, total: 4 });
  });

  it('treats partial server fields as authoritative (missing ones = 0)', () => {
    // Only pax_children present (an all-children edge) — the others default to 0.
    expect(getPaxBreakdown({ pax_children: 3 })).toEqual({ adults: 0, children: 3, infants: 0, total: 3 });
  });

  it('ignores server fields when none are present (falls to bokun_data)', () => {
    const tour = wrapPcb([
      { quantity: 2, pricingCategory: { ticketCategory: 'ADULT' } },
      { quantity: 2, pricingCategory: { ticketCategory: 'CHILD' } },
    ]);
    expect(getPaxBreakdown(tour)).toEqual({ adults: 2, children: 2, infants: 0, total: 4 });
  });
});

describe('aggregateBreakdown', () => {
  it('sums across bookings and excludes cancelled ones', () => {
    const tours = [
      wrapPcb([{ quantity: 2, pricingCategory: { ticketCategory: 'ADULT' } }, { quantity: 1, pricingCategory: { ticketCategory: 'CHILD' } }]),
      wrapPcb([{ quantity: 1, pricingCategory: { ticketCategory: 'ADULT' } }, { quantity: 1, pricingCategory: { ticketCategory: 'INFANT' } }]),
      { ...wrapPcb([{ quantity: 5, pricingCategory: { ticketCategory: 'ADULT' } }]), cancelled: 1 },
    ];
    expect(aggregateBreakdown(tours)).toEqual({ adults: 3, children: 1, infants: 1, total: 5 });
  });

  it('returns zeros for empty/undefined input', () => {
    expect(aggregateBreakdown([])).toEqual({ adults: 0, children: 0, infants: 0, total: 0 });
    expect(aggregateBreakdown(undefined)).toEqual({ adults: 0, children: 0, infants: 0, total: 0 });
  });
});

describe('formatBreakdown', () => {
  it('returns empty string when only adults are present', () => {
    expect(formatBreakdown({ adults: 5, children: 0, infants: 0 })).toBe('');
  });

  it('formats adults + a single child with correct singular', () => {
    expect(formatBreakdown({ adults: 5, children: 1, infants: 0 })).toBe('5 adults, 1 child');
  });

  it('formats a full mixed breakdown with plurals', () => {
    expect(formatBreakdown({ adults: 2, children: 2, infants: 1 })).toBe('2 adults, 2 children, 1 infant');
  });

  it('uses singular for a single adult', () => {
    expect(formatBreakdown({ adults: 1, children: 1, infants: 0 })).toBe('1 adult, 1 child');
  });

  it('omits the adults part when there are none', () => {
    expect(formatBreakdown({ adults: 0, children: 2, infants: 0 })).toBe('2 children');
  });

  it('handles missing/undefined input', () => {
    expect(formatBreakdown()).toBe('');
    expect(formatBreakdown({ children: 1 })).toBe('1 child');
  });
});
