/**
 * paymentBadges.js - step 3.1
 *
 * Two different facts that used to share one green "Paid" badge:
 *   - the GUIDE has been paid for the departure  -> `guide_paid` (server, from the payments table,
 *     group-aware: 1 group = 1 payment). This is the only thing the green badge may mean.
 *   - the CUSTOMER paid Bokun / the OTA            -> `bokun_total_price` + `bokun_currency`.
 *     Shown as a separate, labelled amount, never as the badge.
 *
 * `tours.paid` / `payment_status` are deliberately NOT read here: nothing sets them when a guide
 * payment is recorded, so they cannot answer "has the guide been paid?".
 */

// true only when the server says a payment to the tour's guide exists for this departure
export const isGuidePaid = (tour) => {
  if (!tour) return false;
  const v = tour.guide_paid;
  return v === true || v === 1 || v === '1';
};

// 'paid' | 'partial' | 'unpaid' for the Dashboard chip. "paid" comes from guide_paid only.
export const guidePaymentState = (tour) => {
  if (isGuidePaid(tour)) return 'paid';
  if (tour && tour.payment_status === 'partial') return 'partial';
  return 'unpaid';
};

const SYMBOLS = { EUR: '€', USD: '$', GBP: '£' };

// "€116.46" / "$95.00" / "120.00 CHF"; null when Bokun gave no amount
export const formatCustomerPrice = (tour) => {
  if (!tour || tour.bokun_total_price === null || tour.bokun_total_price === undefined || tour.bokun_total_price === '') {
    return null;
  }
  const amount = Number(tour.bokun_total_price);
  if (!Number.isFinite(amount)) return null;
  const currency = (tour.bokun_currency || 'EUR').toUpperCase();
  const symbol = SYMBOLS[currency];
  return symbol ? `${symbol}${amount.toFixed(2)}` : `${amount.toFixed(2)} ${currency}`;
};
