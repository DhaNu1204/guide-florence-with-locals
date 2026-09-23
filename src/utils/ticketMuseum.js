/**
 * Step 6.11: which museum a ticket booking belongs to, read from the booking's own title.
 *
 * The same rule as the server's radioMuseumForTitle() (public_html/api/radio_helpers.php,
 * step 6.3), which is what the printed day sheet uses - so the Uffizi / Accademia tabs on
 * Priority Tickets show exactly the bookings the sheet prints. The FIRST museum named in the
 * title wins. Not the products table: three ticket products have a blank title there.
 *
 * Keep the keyword table in step with radio_helpers.php.
 */
const MUSEUM_KEYWORDS = [
  ['Uffizi', ['uffizi']],
  ['Accademia', ['accademia', 'david']],
  ['Pitti', ['pitti', 'boboli', 'palatina', 'palatine']],
  ['Borghese', ['borghese']],
  ['Duomo', ['duomo', 'cathedral', 'cupola', 'brunelleschi']],
  ['Bargello', ['bargello']],
  ['Palazzo Vecchio', ['palazzo vecchio']],
];

/** 'Uffizi' | 'Accademia' | ... | null when the title names no museum. */
export function ticketMuseum(title) {
  const t = String(title || '').toLowerCase();
  let best = null;
  let bestPos = Infinity;
  for (const [museum, words] of MUSEUM_KEYWORDS) {
    for (const w of words) {
      const pos = t.indexOf(w);
      if (pos !== -1 && pos < bestPos) {
        best = museum;
        bestPos = pos;
      }
    }
  }
  return best;
}

/** The tabs on Priority Tickets. '' = All museums (the page as it was before 6.11). */
export const TICKET_TABS = [
  { key: '', label: 'All museums' },
  { key: 'Uffizi', label: 'Uffizi' },
  { key: 'Accademia', label: 'Accademia' },
];
