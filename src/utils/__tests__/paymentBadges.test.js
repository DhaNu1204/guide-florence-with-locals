/**
 * Step 3.1: the green badge means "the guide has been paid" and nothing else.
 */
import { describe, it, expect } from 'vitest';
import { isGuidePaid, guidePaymentState, formatCustomerPrice } from '../paymentBadges';

describe('isGuidePaid', () => {
  it('guide paid -> green', () => {
    expect(isGuidePaid({ guide_paid: true })).toBe(true);
    expect(isGuidePaid({ guide_paid: 1 })).toBe(true);
    expect(isGuidePaid({ guide_paid: '1' })).toBe(true);
  });

  it('customer paid Bokun but the guide is unpaid -> NOT green', () => {
    const tour = { guide_paid: false, paid: true, payment_status: 'paid', total_amount_paid: 116.46, bokun_total_price: 116.46 };
    expect(isGuidePaid(tour)).toBe(false);
  });

  it('ignores the legacy paid / payment_status columns entirely', () => {
    expect(isGuidePaid({ paid: 1 })).toBe(false);
    expect(isGuidePaid({ payment_status: 'paid' })).toBe(false);
    expect(isGuidePaid({})).toBe(false);
    expect(isGuidePaid(null)).toBe(false);
  });
});

describe('guidePaymentState', () => {
  it('paid only from guide_paid; partial from payment_status; otherwise unpaid', () => {
    expect(guidePaymentState({ guide_paid: true, payment_status: 'unpaid' })).toBe('paid');
    expect(guidePaymentState({ guide_paid: false, payment_status: 'partial' })).toBe('partial');
    expect(guidePaymentState({ guide_paid: false, payment_status: 'paid', paid: true })).toBe('unpaid');
    expect(guidePaymentState({})).toBe('unpaid');
  });
});

describe('formatCustomerPrice (bokun_total_price / bokun_currency)', () => {
  it('formats the Bokun customer amount with its currency', () => {
    expect(formatCustomerPrice({ bokun_total_price: 116.46, bokun_currency: 'EUR' })).toBe('€116.46');
    expect(formatCustomerPrice({ bokun_total_price: '95', bokun_currency: 'usd' })).toBe('$95.00');
    expect(formatCustomerPrice({ bokun_total_price: 120, bokun_currency: 'CHF' })).toBe('120.00 CHF');
    expect(formatCustomerPrice({ bokun_total_price: 50 })).toBe('€50.00');
  });

  it('returns null when Bokun gave no amount', () => {
    expect(formatCustomerPrice({ bokun_total_price: null })).toBeNull();
    expect(formatCustomerPrice({})).toBeNull();
    expect(formatCustomerPrice({ bokun_total_price: 'abc' })).toBeNull();
    expect(formatCustomerPrice({ bokun_total_price: 0, bokun_currency: 'EUR' })).toBeNull(); // cancelled booking
    expect(formatCustomerPrice(null)).toBeNull();
  });

  it('never falls back to the local payment columns', () => {
    expect(formatCustomerPrice({ total_amount_paid: 80, expected_amount: 80 })).toBeNull();
  });
});
