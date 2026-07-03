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

// Participant category breakdown for a single tour.
// Bokun stores per-category line items at productBookings[0].fields.priceCategoryBookings.
// Representation is MIXED — sometimes one element per participant (quantity:1), sometimes one
// element with quantity:N — so we SUM el.quantity (default 1) grouped by ticketCategory
// (ADULT/CHILD/INFANT). When ticketCategory is absent we fall back to a title keyword
// ('infant'->infants, 'child'/'children'->children, else adults). If bokun_data is
// missing/unparseable/empty (or yields 0), we fall back to a flat all-adults total taken from
// the JSON's totalParticipants, else the tours.participants column.
// Returns { adults, children, infants, total }.
export const getPaxBreakdown = (tour) => {
  let pcb = null;
  let totalParticipants = null;
  try {
    if (tour && tour.bokun_data) {
      const data = typeof tour.bokun_data === 'string' ? JSON.parse(tour.bokun_data) : tour.bokun_data;
      const fields = data && data.productBookings && data.productBookings[0] && data.productBookings[0].fields;
      if (fields) {
        if (fields.totalParticipants != null) totalParticipants = parseInt(fields.totalParticipants) || 0;
        if (Array.isArray(fields.priceCategoryBookings)) pcb = fields.priceCategoryBookings;
      }
    }
  } catch {
    // fall through to the flat fallback below
  }

  if (pcb && pcb.length > 0) {
    let adults = 0, children = 0, infants = 0;
    for (const el of pcb) {
      if (!el) continue;
      let qty = el.quantity == null ? 1 : parseInt(el.quantity);
      if (isNaN(qty)) qty = 1;
      const pc = el.pricingCategory || {};
      const enumCat = (pc.ticketCategory || '').toString().toUpperCase();
      if (enumCat === 'INFANT') {
        infants += qty;
      } else if (enumCat === 'CHILD') {
        children += qty;
      } else if (enumCat === 'ADULT') {
        adults += qty;
      } else {
        // Unknown/absent enum: fall back to a title keyword
        const label = (pc.title || el.bookedTitle || '').toString().toLowerCase();
        if (label.includes('infant')) infants += qty;
        else if (label.includes('child')) children += qty; // matches 'child' and 'children'
        else adults += qty;
      }
    }
    const total = adults + children + infants;
    if (total > 0) return { adults, children, infants, total };
  }

  // Fallback: no usable breakdown — treat everyone as adults.
  const total = totalParticipants != null ? totalParticipants : (parseInt(tour && tour.participants) || 0);
  return { adults: total, children: 0, infants: 0, total };
};

// Sum the participant breakdown across bookings, EXCLUDING cancelled ones. Parallels
// countActivePax so a group's aggregate breakdown matches its PAX count.
export const aggregateBreakdown = (tours) =>
  (tours || []).reduce((acc, t) => {
    if (!t || t.cancelled) return acc;
    const b = getPaxBreakdown(t);
    acc.adults += b.adults;
    acc.children += b.children;
    acc.infants += b.infants;
    acc.total += b.total;
    return acc;
  }, { adults: 0, children: 0, infants: 0, total: 0 });

// Human-readable breakdown, spelling out only the non-zero parts with correct singular/plural
// (e.g. "5 adults, 1 child", "2 adults, 1 child, 1 infant"). Returns '' when the booking is
// all-adults (no children AND no infants) so callers can keep all-adult PAX clean.
export const formatBreakdown = ({ adults = 0, children = 0, infants = 0 } = {}) => {
  if (children === 0 && infants === 0) return '';
  const parts = [];
  if (adults > 0) parts.push(`${adults} ${adults === 1 ? 'adult' : 'adults'}`);
  if (children > 0) parts.push(`${children} ${children === 1 ? 'child' : 'children'}`);
  if (infants > 0) parts.push(`${infants} ${infants === 1 ? 'infant' : 'infants'}`);
  return parts.join(', ');
};

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
