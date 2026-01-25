/**
 * Tour Filtering Utilities
 *
 * Smart keyword detection to distinguish between ticket products and guided tours.
 * Tickets don't need guide assignment, while tours do.
 */

/**
 * Keywords that indicate a product is a ticket (not a guided tour)
 */
const TICKET_KEYWORDS = [
  'Entry Ticket',
  'Entrance Ticket',  // Matches "Uffizi Gallery Priority Entrance Tickets"
  'Priority Ticket',
  'Skip the Line',
  'Skip-the-Line'
];

/**
 * Keywords that indicate a product is a guided tour
 * These override ticket keywords when present
 */
const TOUR_KEYWORDS = [
  'Tour',
  'Guided'
];

/**
 * Patterns that look like tour keywords but are NOT (should not override ticket detection)
 * "Audio Guide" is a device, not a human guide
 */
const FALSE_TOUR_PATTERNS = [
  'Audio Guide',
  'Digital Audio Guide'
];

/**
 * Determines if a booking is a ticket product (not a guided tour)
 * Tickets don't need guide assignment
 *
 * @param {Object} tour - The tour/booking object
 * @param {string} tour.title - The title of the tour/booking
 * @returns {boolean} - True if the product is a ticket (should be excluded from tour management)
 *
 * @example
 * // Returns true (ticket - should be excluded)
 * isTicketProduct({ title: 'Accademia Gallery Priority Entry Ticket with eBook' })
 *
 * // Returns false (tour - should be included)
 * isTicketProduct({ title: 'David and Accademia Gallery VIP Tour' })
 */
export const isTicketProduct = (tour) => {
  if (!tour || !tour.title) {
    return false;
  }

  const title = tour.title;
  const titleLower = title.toLowerCase();

  // Check if title contains any ticket keywords (case-insensitive)
  const hasTicketKeyword = TICKET_KEYWORDS.some(keyword =>
    titleLower.includes(keyword.toLowerCase())
  );

  // If no ticket keyword found, it's not a ticket product
  if (!hasTicketKeyword) {
    return false;
  }

  // Check if title also contains tour keywords (case-insensitive)
  // Tour keywords override ticket keywords (e.g., "Priority Ticket & Guided Tour" is a tour)
  const hasTourKeyword = TOUR_KEYWORDS.some(keyword =>
    titleLower.includes(keyword.toLowerCase())
  );

  // If no tour keyword found, it's definitely a ticket
  if (!hasTourKeyword) {
    return true;
  }

  // Has both ticket and tour keywords - check for false positives
  // "Audio Guide" is NOT a real guide (it's a device), so don't let it override
  const hasFalseTourPattern = FALSE_TOUR_PATTERNS.some(pattern =>
    titleLower.includes(pattern.toLowerCase())
  );

  // If the only "tour" keyword is part of a false pattern like "Audio Guide",
  // then it's still a ticket product
  if (hasFalseTourPattern) {
    // Check if "Tour" or "Guided" appears OUTSIDE the false pattern
    // Remove false patterns from the title and check again
    let cleanedTitle = titleLower;
    FALSE_TOUR_PATTERNS.forEach(pattern => {
      cleanedTitle = cleanedTitle.replace(pattern.toLowerCase(), '');
    });

    const hasRealTourKeyword = TOUR_KEYWORDS.some(keyword =>
      cleanedTitle.includes(keyword.toLowerCase())
    );

    // If no real tour keyword after removing false patterns, it's a ticket
    return !hasRealTourKeyword;
  }

  // Has real tour keywords, so it's a tour (not a ticket)
  return false;
};

/**
 * Filters tours to exclude ticket products
 * Returns only items that are actual guided tours needing guide assignment
 *
 * @param {Array<Object>} tours - Array of tour/booking objects
 * @returns {Array<Object>} - Filtered array containing only guided tours
 *
 * @example
 * const allBookings = [
 *   { title: 'Florence Walking Tour' },
 *   { title: 'Uffizi Gallery Priority Entry Ticket' }
 * ];
 * const tours = filterToursOnly(allBookings);
 * // Result: [{ title: 'Florence Walking Tour' }]
 */
export const filterToursOnly = (tours) => {
  if (!Array.isArray(tours)) {
    return [];
  }
  return tours.filter(tour => !isTicketProduct(tour));
};

/**
 * Filters tours to include only ticket products
 * Useful for ticket management views
 *
 * @param {Array<Object>} tours - Array of tour/booking objects
 * @returns {Array<Object>} - Filtered array containing only ticket products
 */
export const filterTicketsOnly = (tours) => {
  if (!Array.isArray(tours)) {
    return [];
  }
  return tours.filter(tour => isTicketProduct(tour));
};
