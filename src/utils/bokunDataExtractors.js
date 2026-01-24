/**
 * bokunDataExtractors.js - Utility functions for extracting data from Bokun API responses
 *
 * These functions parse the bokun_data JSON field stored in tours table
 * and extract useful information like participants, booking times, language, etc.
 *
 * Usage: import { getParticipantCount, getTourLanguage } from '../utils/bokunDataExtractors';
 */

/**
 * Safely parse JSON data from bokun_data field
 * @param {string|object} bokunData - Raw bokun_data (JSON string or object)
 * @returns {object|null} Parsed object or null if invalid
 */
export const parseBokunData = (bokunData) => {
  if (!bokunData) return null;

  if (typeof bokunData === 'object') return bokunData;

  try {
    return JSON.parse(bokunData);
  } catch (e) {
    console.warn('Failed to parse bokun_data:', e);
    return null;
  }
};

/**
 * Get total participant count from tour data
 * @param {object} tour - Tour object with bokun_data or participants field
 * @returns {number} Total participant count
 */
export const getParticipantCount = (tour) => {
  // First try direct participants field
  if (tour.participants && !isNaN(parseInt(tour.participants))) {
    return parseInt(tour.participants);
  }

  // Try extracting from bokun_data
  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return 0;

  // Check productBookings array for participant breakdown
  if (bokunData.productBookings && Array.isArray(bokunData.productBookings)) {
    let total = 0;
    bokunData.productBookings.forEach(pb => {
      if (pb.priceCategoryBookings && Array.isArray(pb.priceCategoryBookings)) {
        pb.priceCategoryBookings.forEach(pcb => {
          // Exclude INFANT (free tickets)
          if (pcb.category !== 'INFANT') {
            total += pcb.quantity || 0;
          }
        });
      }
    });
    if (total > 0) return total;
  }

  // Fallback to totalParticipants
  return bokunData.totalParticipants || 0;
};

/**
 * Get participant breakdown (adults/children separately)
 * @param {object} tour - Tour object with bokun_data
 * @returns {object} { adults: number, children: number, infants: number, total: number }
 */
export const getParticipantBreakdown = (tour) => {
  const breakdown = { adults: 0, children: 0, infants: 0, total: 0 };

  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) {
    // Fallback to total participants
    breakdown.adults = parseInt(tour.participants) || 0;
    breakdown.total = breakdown.adults;
    return breakdown;
  }

  if (bokunData.productBookings && Array.isArray(bokunData.productBookings)) {
    bokunData.productBookings.forEach(pb => {
      if (pb.priceCategoryBookings && Array.isArray(pb.priceCategoryBookings)) {
        pb.priceCategoryBookings.forEach(pcb => {
          const qty = pcb.quantity || 0;
          const category = (pcb.category || '').toUpperCase();

          if (category === 'ADULT' || category === 'SENIOR' || category === 'STUDENT') {
            breakdown.adults += qty;
          } else if (category === 'CHILD' || category === 'YOUTH') {
            breakdown.children += qty;
          } else if (category === 'INFANT') {
            breakdown.infants += qty; // Count but don't add to total (free)
          } else {
            // Unknown category, count as adult
            breakdown.adults += qty;
          }
        });
      }
    });
  }

  breakdown.total = breakdown.adults + breakdown.children;
  return breakdown;
};

/**
 * Format participant breakdown for display
 * @param {object} tour - Tour object with bokun_data
 * @returns {string} Formatted string like "2A / 1C" or "3"
 */
export const formatParticipants = (tour) => {
  const breakdown = getParticipantBreakdown(tour);

  if (breakdown.children > 0) {
    return `${breakdown.adults}A / ${breakdown.children}C`;
  }

  return breakdown.total.toString();
};

/**
 * Get booking time from tour data
 * @param {object} tour - Tour object with time or bokun_data
 * @returns {string} Time string (HH:MM format) or empty string
 */
export const getBookingTime = (tour) => {
  // First try direct time field
  if (tour.time) {
    return tour.time.substring(0, 5); // Format as HH:MM
  }

  // Try extracting from bokun_data
  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return '';

  // Check productBookings for startTimeLocal
  if (bokunData.productBookings && bokunData.productBookings[0]) {
    const pb = bokunData.productBookings[0];
    if (pb.startTimeLocal) {
      return pb.startTimeLocal.substring(0, 5);
    }
    if (pb.startTime) {
      const date = new Date(pb.startTime);
      return date.toTimeString().substring(0, 5);
    }
  }

  return '';
};

/**
 * Get booking date from tour data
 * @param {object} tour - Tour object with date or bokun_data
 * @returns {string} Date string (YYYY-MM-DD format) or empty string
 */
export const getBookingDate = (tour) => {
  // First try direct date field
  if (tour.date) {
    return tour.date;
  }

  // Try extracting from bokun_data
  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return '';

  // Check productBookings for date
  if (bokunData.productBookings && bokunData.productBookings[0]) {
    const pb = bokunData.productBookings[0];
    if (pb.date) return pb.date;
    if (pb.startDateLocal) return pb.startDateLocal;
  }

  return '';
};

/**
 * Extract tour language from Bokun data
 * Supports Viator (from notes) and GetYourGuide (from product rates)
 * @param {object} tour - Tour object with bokun_data or notes
 * @returns {string|null} Language name or null if not detected
 */
export const getTourLanguage = (tour) => {
  // First check if language is already set
  if (tour.language && tour.language.trim() !== '') {
    return tour.language;
  }

  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return null;

  // Method 1: Extract from Viator notes field (pattern: "GUIDE : English")
  const notes = bokunData.customerExternalNotes || tour.notes || '';
  const viatorMatch = notes.match(/GUIDE\s*:\s*(\w+)/i);
  if (viatorMatch) {
    return viatorMatch[1];
  }

  // Method 2: Extract from GetYourGuide product rate
  if (bokunData.productBookings && bokunData.productBookings[0]) {
    const pb = bokunData.productBookings[0];

    // Check rate title for language
    if (pb.rateTitle) {
      const languages = ['English', 'Italian', 'Spanish', 'German', 'French', 'Portuguese', 'Chinese', 'Japanese'];
      for (const lang of languages) {
        if (pb.rateTitle.toLowerCase().includes(lang.toLowerCase())) {
          return lang;
        }
      }
    }

    // Check product title
    if (pb.productTitle) {
      const languages = ['English', 'Italian', 'Spanish', 'German', 'French', 'Portuguese'];
      for (const lang of languages) {
        if (pb.productTitle.toLowerCase().includes(lang.toLowerCase())) {
          return lang;
        }
      }
    }
  }

  return null;
};

/**
 * Get customer contact information from Bokun data
 * @param {object} tour - Tour object with bokun_data
 * @returns {object} { name, email, phone }
 */
export const getCustomerContact = (tour) => {
  const contact = {
    name: tour.customer_name || '',
    email: tour.customer_email || '',
    phone: tour.customer_phone || ''
  };

  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return contact;

  // Extract from customer object
  if (bokunData.customer) {
    contact.name = contact.name || `${bokunData.customer.firstName || ''} ${bokunData.customer.lastName || ''}`.trim();
    contact.email = contact.email || bokunData.customer.email || '';
    contact.phone = contact.phone || bokunData.customer.phoneNumber || bokunData.customer.phone || '';
  }

  // Check mainContact
  if (bokunData.mainContact) {
    contact.name = contact.name || `${bokunData.mainContact.firstName || ''} ${bokunData.mainContact.lastName || ''}`.trim();
    contact.email = contact.email || bokunData.mainContact.email || '';
    contact.phone = contact.phone || bokunData.mainContact.phoneNumber || '';
  }

  return contact;
};

/**
 * Get booking channel/source
 * @param {object} tour - Tour object with booking_channel or bokun_data
 * @returns {string} Booking channel name
 */
export const getBookingChannel = (tour) => {
  if (tour.booking_channel) {
    return tour.booking_channel;
  }

  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return 'Website';

  // Check various source fields
  if (bokunData.affiliateCode) {
    const code = bokunData.affiliateCode.toLowerCase();
    if (code.includes('viator')) return 'Viator';
    if (code.includes('getyourguide') || code.includes('gyg')) return 'GetYourGuide';
    if (code.includes('tripadvisor')) return 'TripAdvisor';
  }

  if (bokunData.salesChannel) {
    return bokunData.salesChannel;
  }

  return 'Bokun';
};

/**
 * Get special requests from booking
 * @param {object} tour - Tour object with bokun_data or special_requests
 * @returns {string} Special requests text
 */
export const getSpecialRequests = (tour) => {
  if (tour.special_requests) {
    return tour.special_requests;
  }

  const bokunData = parseBokunData(tour.bokun_data);
  if (!bokunData) return '';

  // Check various fields for special requests
  const requests = [];

  if (bokunData.customerExternalNotes) {
    requests.push(bokunData.customerExternalNotes);
  }

  if (bokunData.internalNotes) {
    requests.push(bokunData.internalNotes);
  }

  if (bokunData.productBookings) {
    bokunData.productBookings.forEach(pb => {
      if (pb.customerExternalNotes) {
        requests.push(pb.customerExternalNotes);
      }
    });
  }

  return requests.join('\n').trim();
};

/**
 * Check if tour is a ticket product (Uffizi/Accademia)
 * @param {object} tour - Tour object
 * @returns {boolean} True if this is a ticket product
 */
export const isTicketProduct = (tour) => {
  const title = (tour.title || '').toLowerCase();
  const ticketKeywords = [
    'uffizi gallery priority',
    'accademia gallery priority',
    'skip the line',
    'entrance ticket',
    'priority entrance'
  ];

  return ticketKeywords.some(keyword => title.includes(keyword));
};

/**
 * Date helper functions
 */
export const isToday = (dateStr) => {
  const today = new Date();
  const date = new Date(dateStr);
  return date.toDateString() === today.toDateString();
};

export const isTomorrow = (dateStr) => {
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const date = new Date(dateStr);
  return date.toDateString() === tomorrow.toDateString();
};

export const isDayAfterTomorrow = (dateStr) => {
  const dayAfter = new Date();
  dayAfter.setDate(dayAfter.getDate() + 2);
  const date = new Date(dateStr);
  return date.toDateString() === dayAfter.toDateString();
};

export const isFutureDate = (dateStr) => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const date = new Date(dateStr);
  return date > today;
};

export const isPastDate = (dateStr) => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const date = new Date(dateStr);
  return date < today;
};

/**
 * Get time period (morning/afternoon/evening)
 * @param {string} timeStr - Time string (HH:MM format)
 * @returns {string} 'morning', 'afternoon', or 'evening'
 */
export const getTimePeriod = (timeStr) => {
  if (!timeStr) return 'morning';

  const hour = parseInt(timeStr.split(':')[0], 10);

  if (hour < 12) return 'morning';
  if (hour < 17) return 'afternoon';
  return 'evening';
};

export default {
  parseBokunData,
  getParticipantCount,
  getParticipantBreakdown,
  formatParticipants,
  getBookingTime,
  getBookingDate,
  getTourLanguage,
  getCustomerContact,
  getBookingChannel,
  getSpecialRequests,
  isTicketProduct,
  isToday,
  isTomorrow,
  isDayAfterTomorrow,
  isFutureDate,
  isPastDate,
  getTimePeriod
};
