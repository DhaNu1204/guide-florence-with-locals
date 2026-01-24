/**
 * dateFormatting.js - Date and time formatting utilities
 *
 * Provides consistent date/time formatting across the application
 * with Italian timezone support for the Florence tour business
 */

/**
 * Format date for display (Italian locale)
 * @param {string|Date} date - Date to format
 * @param {object} options - Intl.DateTimeFormat options
 * @returns {string} Formatted date string
 */
export const formatDate = (date, options = {}) => {
  if (!date) return '';

  const dateObj = typeof date === 'string' ? new Date(date) : date;

  const defaultOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'Europe/Rome'
  };

  return new Intl.DateTimeFormat('en-GB', { ...defaultOptions, ...options }).format(dateObj);
};

/**
 * Format date as YYYY-MM-DD (ISO format for API)
 * @param {Date} date - Date object
 * @returns {string} ISO date string
 */
export const toISODate = (date) => {
  if (!date) return '';
  const d = typeof date === 'string' ? new Date(date) : date;
  return d.toISOString().split('T')[0];
};

/**
 * Format time for display (24-hour format)
 * @param {string} time - Time string (HH:MM:SS or HH:MM)
 * @returns {string} Formatted time (HH:MM)
 */
export const formatTime = (time) => {
  if (!time) return '';
  return time.substring(0, 5);
};

/**
 * Format date and time together
 * @param {string} date - Date string
 * @param {string} time - Time string
 * @returns {string} Combined date/time string
 */
export const formatDateTime = (date, time) => {
  const formattedDate = formatDate(date);
  const formattedTime = formatTime(time);

  if (!formattedDate && !formattedTime) return '';
  if (!formattedTime) return formattedDate;
  if (!formattedDate) return formattedTime;

  return `${formattedDate} at ${formattedTime}`;
};

/**
 * Get Italian timezone current date/time
 * @returns {object} { date: string, time: string }
 */
export const getItalianDateTime = () => {
  const now = new Date();

  const italianDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Rome',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).format(now);

  const italianTime = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Rome',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).format(now);

  return { date: italianDate, time: italianTime };
};

/**
 * Get relative date description
 * @param {string} dateStr - Date string (YYYY-MM-DD)
 * @returns {string} Relative description ('Today', 'Tomorrow', 'In 2 days', etc.)
 */
export const getRelativeDate = (dateStr) => {
  if (!dateStr) return '';

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const date = new Date(dateStr);
  date.setHours(0, 0, 0, 0);

  const diffDays = Math.round((date - today) / (1000 * 60 * 60 * 24));

  if (diffDays === 0) return 'Today';
  if (diffDays === 1) return 'Tomorrow';
  if (diffDays === 2) return 'In 2 days';
  if (diffDays === -1) return 'Yesterday';
  if (diffDays < -1) return `${Math.abs(diffDays)} days ago`;
  if (diffDays <= 7) return `In ${diffDays} days`;

  return formatDate(dateStr);
};

/**
 * Get day of week
 * @param {string} dateStr - Date string
 * @returns {string} Day name (Monday, Tuesday, etc.)
 */
export const getDayOfWeek = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-GB', { weekday: 'long' }).format(date);
};

/**
 * Get short day of week
 * @param {string} dateStr - Date string
 * @returns {string} Short day name (Mon, Tue, etc.)
 */
export const getShortDayOfWeek = (dateStr) => {
  if (!dateStr) return '';

  const date = new Date(dateStr);
  return new Intl.DateTimeFormat('en-GB', { weekday: 'short' }).format(date);
};

/**
 * Check if date is in the past
 * @param {string} dateStr - Date string
 * @returns {boolean}
 */
export const isPast = (dateStr) => {
  if (!dateStr) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const date = new Date(dateStr);
  date.setHours(0, 0, 0, 0);

  return date < today;
};

/**
 * Check if date is in the future
 * @param {string} dateStr - Date string
 * @returns {boolean}
 */
export const isFuture = (dateStr) => {
  if (!dateStr) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const date = new Date(dateStr);
  date.setHours(0, 0, 0, 0);

  return date > today;
};

/**
 * Parse date range for API queries
 * @param {string} period - Period name ('today', 'week', 'month', etc.)
 * @returns {object} { startDate: string, endDate: string }
 */
export const getDateRange = (period) => {
  const today = new Date();
  let startDate = new Date();
  let endDate = new Date();

  switch (period) {
    case 'today':
      break;

    case 'tomorrow':
      startDate.setDate(today.getDate() + 1);
      endDate.setDate(today.getDate() + 1);
      break;

    case 'week':
      endDate.setDate(today.getDate() + 7);
      break;

    case 'last7days':
      startDate.setDate(today.getDate() - 7);
      break;

    case 'last30days':
      startDate.setDate(today.getDate() - 30);
      break;

    case 'thisMonth':
      startDate.setDate(1);
      endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
      break;

    case 'lastMonth':
      startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      endDate = new Date(today.getFullYear(), today.getMonth(), 0);
      break;

    default:
      break;
  }

  return {
    startDate: toISODate(startDate),
    endDate: toISODate(endDate)
  };
};

/**
 * Sort tours by date and time chronologically
 * @param {array} tours - Array of tour objects
 * @param {string} direction - 'asc' or 'desc'
 * @returns {array} Sorted array
 */
export const sortByDateTime = (tours, direction = 'asc') => {
  return [...tours].sort((a, b) => {
    const dateTimeA = new Date(`${a.date} ${a.time || '00:00'}`);
    const dateTimeB = new Date(`${b.date} ${b.time || '00:00'}`);

    return direction === 'asc' ? dateTimeA - dateTimeB : dateTimeB - dateTimeA;
  });
};

export default {
  formatDate,
  toISODate,
  formatTime,
  formatDateTime,
  getItalianDateTime,
  getRelativeDate,
  getDayOfWeek,
  getShortDayOfWeek,
  isPast,
  isFuture,
  getDateRange,
  sortByDateTime
};
