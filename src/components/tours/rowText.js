// Step 9.2: the short texts of one Tours row (docs/DESIGN.md). Pure helpers, no data changes.

/** Booking channel as a short label: "GetYourGuide", "Viator", "Airbnb", "Website", "Direct". */
export function shortChannel(channel) {
  const raw = String(channel || '').trim();
  if (!raw) return 'Website'; // the old row showed 'Website' for an empty channel
  const c = raw.toLowerCase();
  if (c.includes('getyourguide')) return 'GetYourGuide';
  if (c.includes('viator')) return 'Viator';
  if (c.includes('airbnb')) return 'Airbnb';
  if (c.includes('florencewithlocals') || c === 'website' || c.startsWith('www.')) return 'Website';
  if (c === 'direct' || c.includes('direct')) return 'Direct';
  return raw;
}

const LANG_CODES = {
  english: 'EN', italian: 'IT', spanish: 'ES', french: 'FR', german: 'DE', portuguese: 'PT',
  chinese: 'ZH', japanese: 'JA', dutch: 'NL', russian: 'RU', korean: 'KO', polish: 'PL',
};

/** "English" -> "EN"; unknown names -> their first two letters; none -> null. */
export function languageCode(language) {
  const l = String(language || '').trim();
  if (!l) return null;
  return LANG_CODES[l.toLowerCase()] || l.slice(0, 2).toUpperCase();
}

export const isEnglish = (language) => String(language || '').trim().toLowerCase() === 'english';

/** "Maria Rossi +2" from participant_names, else the customer name, else ''. */
export function leadGuest(tour) {
  let names = [];
  try {
    const p = typeof tour?.participant_names === 'string' ? JSON.parse(tour.participant_names) : tour?.participant_names;
    names = Array.isArray(p) ? p : [];
  } catch { names = []; }
  if (names.length > 0) {
    const first = `${names[0].first || ''} ${names[0].last || ''}`.trim();
    return names.length > 1 ? `${first} +${names.length - 1}` : first;
  }
  return tour?.customer_name ? String(tour.customer_name) : '';
}

/** The old row's "Rescheduled" rule, unchanged. */
export const isRescheduled = (tour) => Boolean(
  tour && tour.rescheduled && !tour.cancelled && tour.original_date && tour.original_time
  && (tour.original_date !== tour.date || tour.original_time !== tour.time)
);
