import { describe, it, expect } from 'vitest';
import { billingOptions, showCountsAs, currentBillingId, billingOptionLabel } from '../billingProduct';

const COMBO = 'Uffizi & Accademia Walking Tour with Gelato & Art Historian';
const UFFIZI = 'Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit';
const UFFIZI2 = 'Uffizi Gallery Small Group Guided Tour with Tickets';

const group = (tours, extra = {}) => ({ is_manual_merge: true, tours, ...extra });

describe('step 6.17 billing product (Counts as)', () => {
  const mixed = group([
    { id: 7490, product_id: 1130528, title: UFFIZI, cancelled: false },
    { id: 7717, product_id: 962885, title: COMBO, cancelled: false },
  ], { billing_product_id: 962885 });

  it('offers one option per live member product, in booking order', () => {
    expect(billingOptions(mixed)).toEqual([
      { product_id: 1130528, title: UFFIZI, category: 'Uffizi' },
      { product_id: 962885, title: COMBO, category: 'Combo' },
    ]);
    expect(billingOptionLabel(billingOptions(mixed)[1])).toBe(`Combo — ${COMBO}`);
  });

  it('shows the dropdown only on a manual merge that mixes types', () => {
    expect(showCountsAs(mixed)).toBe(true);
    expect(showCountsAs({ ...mixed, is_manual_merge: false })).toBe(false);
    // two Uffizi products: same type, no dropdown (owner decision)
    expect(showCountsAs(group([
      { product_id: 1130528, title: UFFIZI }, { product_id: 961801, title: UFFIZI2 },
    ]))).toBe(false);
  });

  it('ignores cancelled bookings', () => {
    const g = group([
      { product_id: 1130528, title: UFFIZI },
      { product_id: 962885, title: COMBO, cancelled: true },
    ], { billing_product_id: 962885 });
    expect(showCountsAs(g)).toBe(false);
    expect(billingOptions(g)).toHaveLength(1);
    expect(currentBillingId(g)).toBe('');
  });

  it('current value is the stored product while it is a live member product', () => {
    expect(currentBillingId(mixed)).toBe(962885);
    expect(currentBillingId({ ...mixed, billing_product_id: null })).toBe('');
    expect(currentBillingId({ ...mixed, billing_product_id: 999 })).toBe('');
  });
});
