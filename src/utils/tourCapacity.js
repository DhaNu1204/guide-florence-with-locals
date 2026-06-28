// Shared tour-capacity rules. Single source of truth — do NOT duplicate the rule.
//
// Museum capacity per departure:
//   - Uffizi (incl. any Uffizi + Accademia combo) -> 9
//   - Accademia / David                            -> 19
//   - anything else                                -> 9 (safe default)
// 'uffizi' is checked first so an "Uffizi & Accademia" combo correctly caps at 9.
export const getMaxPax = (title) => {
  const t = (title || '').toLowerCase();
  if (t.includes('uffizi')) return 9;
  if (t.includes('accademia') || t.includes('david')) return 19;
  return 9;
};

// Sum participants across bookings, EXCLUDING cancelled ones.
export const countActivePax = (tours) =>
  (tours || []).reduce((sum, t) => (t && t.cancelled ? sum : sum + (parseInt(t && t.participants) || 0)), 0);

// Count of bookings, EXCLUDING cancelled ones.
export const countActiveBookings = (tours) =>
  (tours || []).filter(t => t && !t.cancelled).length;
