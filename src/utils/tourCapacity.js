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

// Classify a tour by museum from its title. Mirrors classifyTourCategory in
// guide-tour-report.php so the frontend Summary and the backend report agree.
//   - uffizi is checked via keyword 'uffizi'
//   - accademia via 'accademia' or 'david'
//   - pitti via 'pitti' / 'boboli' / 'palatina' / 'palatine'
// 2+ of the three museums present -> 'Combo'; otherwise the single museum;
// nothing matched -> 'Other'.
export const tourCategory = (title) => {
  const t = (title || '').toLowerCase();
  const uffizi = t.includes('uffizi');
  const accademia = t.includes('accademia') || t.includes('david');
  const pitti = t.includes('pitti') || t.includes('boboli') || t.includes('palatina') || t.includes('palatine');
  const n = (uffizi ? 1 : 0) + (accademia ? 1 : 0) + (pitti ? 1 : 0);
  if (n >= 2) return 'Combo';
  if (uffizi) return 'Uffizi';
  if (pitti) return 'Pitti';
  if (accademia) return 'Accademia';
  return 'Other';
};
