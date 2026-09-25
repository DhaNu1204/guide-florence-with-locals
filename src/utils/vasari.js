// Step 6.14: Uffizi bookings that include the Vasari Corridor.
//
// The rule itself ("the Bokun rate title contains 'Vasari'") lives in ONE place, on the
// server (rateIsVasari() in public_html/api/rate_helpers.php); every booking row arrives with
// the answer as `vasari`. This file only reads that flag.

export const isVasari = (tour) => Boolean(tour && tour.vasari);

// People, not bookings, doing Vasari in a group. Cancelled bookings never count - the same
// rule as countActivePax() in tourCapacity.js.
export const countVasariPax = (tours) =>
  (tours || []).reduce(
    (sum, t) => (t && !t.cancelled && isVasari(t) ? sum + (parseInt(t.participants) || 0) : sum),
    0
  );
