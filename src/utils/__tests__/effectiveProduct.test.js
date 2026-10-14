/**
 * Step 6.22: a product linked with "Counts as" (products.groups_as_product_id) counts as its
 * original. The server sends rule_title (the original's title) and the effective product_type;
 * the frontend rules read those, and every booking keeps its own title on screen.
 */
import { describe, it, expect } from 'vitest';
import { isTicketProduct, filterToursOnly } from '../tourFilters';
import { billingOptions, showCountsAs } from '../billingProduct';
import { buildUnassignedReportText } from '../unassignedReport';

const NEW_P3 = 'Uffizi Accademia and David Tour with Art Historian';
const COMBO = 'Uffizi & Accademia Walking Tour with Gelato & Art Historian';
const NEW_P1 = 'Uffizi Gallery Reserved Entry Ticket with Audio Guide App';
const NEW_P4 = 'Uffizi Small Group Tour with Optional Vasari Corridor Access';
const UFFIZI = 'Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit';

describe('tour vs ticket follows the server product_type', () => {
  it('a ticket product is a ticket even when its title looks like a tour, and the other way round', () => {
    expect(isTicketProduct({ title: NEW_P1, product_type: 'ticket' })).toBe(true);
    expect(isTicketProduct({ title: 'Accademia Gallery Priority Entry Ticket', product_type: 'tour' })).toBe(false);
  });
  it('rows without product_type keep the old title keywords', () => {
    expect(isTicketProduct({ title: 'Accademia Gallery Priority Entry Ticket' })).toBe(true);
    expect(isTicketProduct({ title: 'David and Accademia Gallery VIP Tour' })).toBe(false);
  });
  it('filterToursOnly drops the linked ticket product', () => {
    const rows = [{ id: 1, title: NEW_P1, product_type: 'ticket' }, { id: 2, title: NEW_P3, product_type: 'tour' }];
    expect(filterToursOnly(rows).map((r) => r.id)).toEqual([2]);
  });
});

describe('category reads the rule title', () => {
  it('a manual merge of the original Combo and its new-account copy is ONE type - no "Counts as"', () => {
    const g = {
      is_manual_merge: true,
      tours: [
        { product_id: 962885, title: COMBO, rule_title: COMBO, cancelled: false },
        { product_id: 1324953, title: NEW_P3, rule_title: COMBO, cancelled: false },
      ],
    };
    expect(showCountsAs(g)).toBe(false);
    expect(billingOptions(g).map((o) => [o.product_id, o.title, o.category])).toEqual([
      [962885, COMBO, 'Combo'],
      [1324953, NEW_P3, 'Combo'], // own title shown, category from the rule title
    ]);
  });
  it('a merge of the Vasari copy with a Combo still offers "Counts as"', () => {
    const g = {
      is_manual_merge: true,
      tours: [
        { product_id: 962885, title: COMBO, rule_title: COMBO },
        { product_id: 1324952, title: NEW_P4, rule_title: UFFIZI },
      ],
    };
    expect(showCountsAs(g)).toBe(true);
  });
});

describe('unassigned report label', () => {
  it('uses the rule title for the location, the departure keeps its own title', () => {
    const text = buildUnassignedReportText([
      { tour_unit: 't7876', date: '2026-10-15', time: '14:15', title: 'Some renamed copy', rule_title: COMBO, bookings: 1, pax: 2, language: 'English' },
    ], new Date(2026, 9, 14, 21, 0));
    expect(text).toContain('Uffizi + Accademia');
  });
});
