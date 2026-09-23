import axios from 'axios';
import { markRateLimited, markTimeout, markAutoRetry } from '../utils/perfBeacon'; // step 4.7/4.8: measurement only
import { notifySessionExpired, notifyForbidden } from './sessionExpiry';
import {
  timeoutFor, mayAutoRetry, isTransient, isOutcomeUnknown, notifyWriteUnknown, classifyError,
} from './netPolicy';

// Use environment variable for API base URL
const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

// Add cache busting to prevent browser caching
const addCacheBuster = (url) => {
  const timestamp = new Date().getTime();
  return `${url}${url.includes('?') ? '&' : '?'}_=${timestamp}`;
};

// Setup axios interceptor to add auth token to all requests
axios.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    // Step 4.8: nothing waits forever. A caller's own timeout wins; otherwise the policy decides
    // (15 s read, 30 s write, 90 s server PDF, 180 s sync - see netPolicy.js).
    if (!config.timeout) {
      config.timeout = timeoutFor(config.method, config.url);
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// On an expired/invalid session (HTTP 401), trigger the global session-expiry
// flow (clear token + toast + redirect to /login). This is the same axios
// singleton used by every service (mysqlDB, ticketsService), so the handler
// covers all axios API calls app-wide. See sessionExpiry.js.
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const config = error?.config;
    if (classifyError(error).kind === 'timeout') {
      try { markTimeout(); } catch (e) { /* never matters */ }
    }
    // Step 4.8: a read that timed out, lost the connection or met a 502/503/504 is tried once
    // more. A write never is - a timed-out POST may already have been done by the server.
    if (config && !config.fwlRetried && mayAutoRetry(config.method, config.url) && isTransient(error)) {
      config.fwlRetried = true;
      return axios(config).then(
        (response) => { markAutoRetry(true); return response; },
        (retryError) => { markAutoRetry(false); return Promise.reject(retryError); }
      );
    }
    if (config && !mayAutoRetry(config.method, config.url) && String(config.method).toLowerCase() !== 'get'
        && isOutcomeUnknown(error)) {
      error.outcomeUnknown = true;
      if (!config.quietUnknown) notifyWriteUnknown();
    }
    // Step 4.7: count 429s for the field beacon. Observation only - nothing is retried,
    // nothing is swallowed, and the rejection below is unchanged.
    if (error?.response?.status === 429) {
      try { markRateLimited(); } catch (e) { /* never matters */ }
    }
    if (error?.response?.status === 401) {
      notifySessionExpired();
    } else if (error?.response?.status === 403) {
      // Step 1.1: not allowed (viewer hit an admin-only write). One toast, keep the session.
      notifyForbidden();
    }
    return Promise.reject(error);
  }
);

// Storage version - increment this when making structural changes
const STORAGE_VERSION = 'v1';
const STORAGE_KEY = `tours_${STORAGE_VERSION}`;
const STORAGE_EXPIRY_MS = 60000; // 1 minute expiry

// Clear local storage completely - use this to force fresh data
export const clearTourCache = () => {
  try {
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem('tours'); // Remove legacy key too
    console.log('Tour cache cleared successfully');
  } catch (error) {
    console.warn('Failed to clear tour cache:', error);
  }
};

// GUIDES OPERATIONS
export const getGuides = async (page = 1, perPage = 20) => {
  try {
    const url = `${API_BASE_URL}/guides.php?page=${page}&per_page=${perPage}`;
    const response = await axios.get(addCacheBuster(url));

    // Handle both paginated response format and legacy array format
    const responseData = response.data;
    const guidesArray = Array.isArray(responseData) ? responseData : (responseData.data || []);

    // Normalize languages to array for each guide (handles both array and string from backend)
    const guides = guidesArray.map(guide => ({
      ...guide,
      languages: Array.isArray(guide.languages)
        ? guide.languages
        : guide.languages
          ? guide.languages.split(',').map(lang => lang.trim()).filter(lang => lang)
          : []
    }));

    // Return in consistent paginated format
    if (Array.isArray(responseData)) {
      // Wrap legacy array response for backward compatibility
      return {
        data: guides,
        pagination: {
          page: page,
          per_page: perPage,
          total: guides.length,
          total_pages: 1
        }
      };
    }

    // Return paginated response with normalized guides
    return {
      data: guides,
      pagination: responseData.pagination || {
        page: page,
        per_page: perPage,
        total: guides.length,
        total_pages: 1
      }
    };
  } catch (error) {
    console.error('Error fetching guides:', error);
    throw error;
  }
};

// Returns the COMPLETE guide list as a flat array (all pages combined).
// Use this for selection lists / dropdowns (assignment, filters, Ask-a-guide,
// reports) — getGuides() returns only one page (default 20), so newly added
// guides beyond the first page would otherwise be missing from pickers.
// The Guides management page intentionally keeps paginated getGuides().
export const getAllGuides = async () => {
  // guides.php caps per_page at 100.
  const first = await getGuides(1, 100);
  const all = Array.isArray(first?.data) ? [...first.data] : [];
  const pagination = first?.pagination || {};

  const totalPages = Number(pagination.total_pages) || 1;
  for (let page = 2; page <= totalPages; page++) {
    const next = await getGuides(page, 100);
    if (Array.isArray(next?.data) && next.data.length > 0) {
      all.push(...next.data);
    } else {
      break;
    }
  }

  return all;
};

export const addGuide = async (guideData) => {
  try {
    // Convert languages array to comma-separated string for backend storage
    const dataToSend = {
      ...guideData,
      languages: Array.isArray(guideData.languages)
        ? guideData.languages.join(',')
        : guideData.languages || ''
    };

    const response = await axios.post(`${API_BASE_URL}/guides.php`, dataToSend);

    // Normalize languages to array (handles both array and string from backend)
    if (response.data) {
      response.data.languages = Array.isArray(response.data.languages)
        ? response.data.languages
        : response.data.languages
          ? response.data.languages.split(',').map(lang => lang.trim()).filter(lang => lang)
          : [];
    }

    return response.data;
  } catch (error) {
    console.error('Error adding guide:', error);
    throw error;
  }
};

export const deleteGuide = async (guideId) => {
  try {
    await axios.delete(`${API_BASE_URL}/guides.php/${guideId}`);
    return true;
  } catch (error) {
    console.error('Error deleting guide:', error);
    throw error;
  }
};

export const updateGuide = async (guideId, guideData) => {
  try {
    // Extract guide ID from guideData if not provided separately
    const id = guideId || guideData.id;

    // Convert languages array to comma-separated string for backend storage
    // Don't include id in the request body since it's in the URL
    const { id: _, ...dataWithoutId } = guideData;
    const updateData = {
      ...dataWithoutId,
      languages: Array.isArray(guideData.languages)
        ? guideData.languages.join(',')
        : guideData.languages || ''
    };

    // Use PUT request with guide ID in the URL path
    const response = await axios.put(`${API_BASE_URL}/guides.php/${id}`, updateData);

    // Normalize languages to array (handles both array and string from backend)
    if (response.data) {
      response.data.languages = Array.isArray(response.data.languages)
        ? response.data.languages
        : response.data.languages
          ? response.data.languages.split(',').map(lang => lang.trim()).filter(lang => lang)
          : [];
    }

    return response.data;
  } catch (error) {
    console.error('Error updating guide:', error);
    throw error;
  }
};

// GUIDE TOUR REPORT (READ-ONLY tour verification — no payment data)
// Returns a month/range overview across all guides, or a single guide's tour list.
export const getGuideTourReport = async ({ guideId = null, period = null, start = null, end = null } = {}) => {
  try {
    const params = new URLSearchParams();
    if (guideId) params.append('guide_id', guideId);
    if (period) params.append('period', period);
    if (start) params.append('start', start);
    if (end) params.append('end', end);

    const queryString = params.toString();
    const url = `${API_BASE_URL}/guide-tour-report.php${queryString ? `?${queryString}` : ''}`;
    const response = await axios.get(addCacheBuster(url));
    return response.data;
  } catch (error) {
    console.error('Error fetching guide tour report:', error);
    throw error;
  }
};

// GUIDE AVAILABILITY REQUESTS (Phase 2) — owner side (authenticated)
// Create a request asking a guide if they're available for a tour.
// Returns { id, token, status, link, message }.
export const createGuideRequest = async ({ tourId, guideId }) => {
  const response = await axios.post(`${API_BASE_URL}/guide-requests.php`, {
    tour_id: tourId,
    guide_id: guideId
  });
  return response.data;
};

// List availability requests for a tour: { success, data: [...] }
export const getGuideRequests = async (tourId) => {
  const url = `${API_BASE_URL}/guide-requests.php?tour_id=${encodeURIComponent(tourId)}`;
  const response = await axios.get(addCacheBuster(url));
  return response.data;
};

// Open requests (pending/declined) for upcoming tours — for persistent badges.
export const getOpenGuideRequests = async () => {
  const url = `${API_BASE_URL}/guide-requests.php?action=open`;
  const response = await axios.get(addCacheBuster(url));
  return response.data;
};

// Recently answered (accepted/declined) requests within N days — dashboard summary.
export const getRecentGuideResponses = async (days = 7) => {
  const url = `${API_BASE_URL}/guide-requests.php?action=recent&days=${encodeURIComponent(days)}`;
  const response = await axios.get(addCacheBuster(url));
  return response.data;
};

// TOURS OPERATIONS
export const getTours = async (forceRefresh = false, page = 1, perPage = 50, filters = {}) => {
  // Check if we need to force a refresh
  if (forceRefresh) {
    clearTourCache();
  }

  // Build cache key based on filters
  const filterKey = JSON.stringify(filters);
  const cacheKeyWithFilters = `${STORAGE_KEY}_${filterKey}`;

  // Skip cache if filters are applied (always fetch fresh for filtered queries)
  // Include upcoming as a filter since it changes the query behavior
  const hasFilters = filters.date || filters.guide_id || filters.upcoming || filters.past || filters.start_date;

  console.log('[getTours] hasFilters:', hasFilters, 'filters:', filters);

  // Check for cached data and its freshness (only for unfiltered queries)
  let cachedData = null;
  let isCacheStale = true;

  if (!hasFilters) {
    console.log('[getTours] Using cache check (no filters applied)');
    try {
      const storedData = localStorage.getItem(STORAGE_KEY);
      if (storedData) {
        const parsed = JSON.parse(storedData);
        // Only use cache if it has a timestamp and isn't expired
        if (parsed.timestamp && (Date.now() - parsed.timestamp < STORAGE_EXPIRY_MS)) {
          cachedData = parsed.data;
          isCacheStale = false;
          console.log('Using fresh cached tour data');
        } else {
          console.log('Cached tour data is stale, fetching fresh data');
        }
      }
    } catch (error) {
      console.warn('Error reading from cache:', error);
    }

    // If we have fresh cached data and aren't forcing a refresh, use it
    if (cachedData && !forceRefresh && !isCacheStale) {
      return cachedData;
    }
  }

  // Otherwise fetch from the server
  try {
    console.log('Fetching fresh tour data from server', { page, perPage, filters });

    // Build URL with query parameters
    let url = `${API_BASE_URL}/tours.php?page=${page}&per_page=${perPage}`;

    // Add optional filters
    if (filters.start_date && filters.end_date) {
      url += `&start_date=${encodeURIComponent(filters.start_date)}&end_date=${encodeURIComponent(filters.end_date)}`;
    } else if (filters.date) {
      url += `&date=${encodeURIComponent(filters.date)}`;
    }
    if (filters.guide_id) {
      url += `&guide_id=${encodeURIComponent(filters.guide_id)}`;
    }
    if (filters.upcoming) {
      url += `&upcoming=true`;
    }
    if (filters.past) {
      url += `&past=true`;
    }
    if (filters.product_type) {
      url += `&product_type=${encodeURIComponent(filters.product_type)}`;
    }
    // Step 4.2: { view: 'list' } asks for light rows (no bokun_data) for list screens
    if (filters.view) {
      url += `&view=${encodeURIComponent(filters.view)}`;
    }

    const response = await axios.get(addCacheBuster(url));

    // CRITICAL: Use server data as the source of truth
    const serverResponse = response.data;

    // Store in cache with timestamp
    try {
      const cacheData = {
        timestamp: Date.now(),
        data: serverResponse
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(cacheData));
      // Also update legacy key for backwards compatibility
      localStorage.setItem('tours', JSON.stringify(serverResponse));
    } catch (localError) {
      console.warn('Could not save tours to localStorage:', localError);
    }

    return serverResponse;
  } catch (error) {
    // Step 4.8: no silent fallback. The old code answered a failed request with whatever copy
    // sat in localStorage - for another date, from another hour - or with [], and the page
    // showed it as if it were fresh. The caller now learns that the load failed and says so.
    console.error('Error fetching tours from server:', error);
    throw error;
  }
};

// Step 4.2: one full tour row (incl. bokun_data) for the booking details modal.
// The list uses { view: 'list' } rows that do not carry the Bokun JSON.
export const getTourById = async (tourId) => {
  const response = await axios.get(addCacheBuster(`${API_BASE_URL}/tours.php/${tourId}?view=full`));
  return response.data && response.data.data ? response.data.data : null;
};

// Step 3.5: unassigned departures, decided on the server (effective guide = group's or tour's).
// Takes the same filters as getTours (upcoming | past | date | start_date + end_date).
export const getUnassignedReport = async (filters = {}) => {
  let url = `${API_BASE_URL}/tours.php?action=unassigned-report`;
  if (filters.start_date && filters.end_date) {
    url += `&start_date=${encodeURIComponent(filters.start_date)}&end_date=${encodeURIComponent(filters.end_date)}`;
  } else if (filters.date) {
    url += `&date=${encodeURIComponent(filters.date)}`;
  }
  if (filters.upcoming) url += `&upcoming=true`;
  if (filters.past) url += `&past=true`;
  if (filters.count_only) url += `&count_only=true`;
  const response = await axios.get(addCacheBuster(url));
  return response.data && response.data.data ? response.data.data : { total: 0, departures: [] };
};

// Step 5.2: the Tours page banner number - the report's own total, nothing computed in the browser.
export const getUnassignedCount = async (filters = {}) => {
  const data = await getUnassignedReport({ ...filters, count_only: true });
  return Number(data.total) || 0;
};

export const addTour = async (tourData) => {
  try {
    const response = await axios.post(`${API_BASE_URL}/tours.php`, tourData);
    
    // After adding a tour, force refresh the tour list
    await getTours(true);
    
    return response.data;
  } catch (error) {
    console.error('Error adding tour:', error);
    
    // Fallback to localStorage if API request fails
    try {
      // Generate a unique ID for the new tour 
      const timestamp = new Date().getTime();
      const newId = `local-${timestamp}`;
      
      // Create the new tour object with the generated ID
      const newTour = { 
        ...tourData,
        id: newId,
        paid: tourData.paid || false,
        cancelled: false,
        booking_channel: tourData.bookingChannel || null,
        guide_name: tourData.guideName || 'Guide name not available'
      };
      
      // Get existing tours from current cache key
      const cachedData = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{"data":[]}');
      const storedTours = cachedData.data || [];
      
      // Add the new tour
      const updatedTours = [...storedTours, newTour];
      
      // Save back to cache with new timestamp
      const newCacheData = {
        timestamp: Date.now(),
        data: updatedTours
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(newCacheData));
      
      // Also update legacy key
      localStorage.setItem('tours', JSON.stringify(updatedTours));
      
      return newTour;
    } catch (localError) {
      console.error('Error saving to localStorage:', localError);
      throw error; // Rethrow the original error
    }
  }
};

export const deleteTour = async (tourId) => {
  try {
    await axios.delete(`${API_BASE_URL}/tours.php/${tourId}`);
    
    // After deleting, force refresh the tour list
    await getTours(true);
    
    return true;
  } catch (error) {
    console.error('Error deleting tour:', error);
    
    // Fallback to localStorage if API request fails
    try {
      // Update in new cache format
      const cachedData = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{"data":[]}');
      const storedTours = cachedData.data || [];
      const updatedTours = storedTours.filter(tour => tour.id !== tourId);
      
      const newCacheData = {
        timestamp: Date.now(),
        data: updatedTours
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(newCacheData));
      
      // Also update legacy key
      localStorage.setItem('tours', JSON.stringify(updatedTours));
      
      return true;
    } catch (localError) {
      console.error('Error updating localStorage:', localError);
      throw error; // Rethrow the original error
    }
  }
};

// Update tour paid status
export const updateTourPaidStatus = async (tourId, isPaid) => {
  try {
    // Use the correct API endpoint path to match our backend implementation
    const response = await axios.put(`${API_BASE_URL}/tours.php/${tourId}/paid`, { paid: isPaid });
    
    // After updating, force refresh the tour list
    await getTours(true);
    
    return response.data;
  } catch (error) {
    console.error('Error updating tour paid status:', error);
    
    // IMPORTANT: Try the API call again with the full endpoint path
    try {
      console.log('Retrying with alternate API path...');
      const retryResponse = await axios.put(`${API_BASE_URL}/tours.php/${tourId}/paid`, { paid: isPaid });
      await getTours(true);
      return retryResponse.data;
    } catch (retryError) {
      console.error('Retry also failed:', retryError);
      
      // If all API calls fail, update localStorage as fallback
      updateLocalTourCache(tourId, { paid: isPaid });
      
      // Throw original error
      throw error;
    }
  }
};

// Update tour cancelled status
export const updateTourCancelStatus = async (tourId, isCancelled) => {
  try {
    // Use the correct API endpoint path to match our backend implementation
    const response = await axios.put(`${API_BASE_URL}/tours.php/${tourId}/cancelled`, { cancelled: isCancelled });
    
    // After updating, force refresh the tour list
    await getTours(true);
    
    return response.data;
  } catch (error) {
    console.error('Error updating tour cancelled status:', error);
    
    // IMPORTANT: Try the API call again with the full endpoint path
    try {
      console.log('Retrying with alternate API path...');
      const retryResponse = await axios.put(`${API_BASE_URL}/tours.php/${tourId}/cancelled`, { cancelled: isCancelled });
      await getTours(true);
      return retryResponse.data;
    } catch (retryError) {
      console.error('Retry also failed:', retryError);
      
      // If all API calls fail, update localStorage as fallback
      updateLocalTourCache(tourId, { cancelled: isCancelled });
      
      // Throw original error
      throw error;
    }
  }
};

// Update tour details
// tourData may include `force: true` to bypass the backend double-booking guard.
export const updateTour = async (tourId, tourData) => {
  try {
    // Use PUT request to update the tour (force flag, if present, is sent in the body)
    const response = await axios.put(`${API_BASE_URL}/tours.php/${tourId}`, tourData);

    // After updating, force refresh the tour list
    await getTours(true);

    return response.data;
  } catch (error) {
    // Surface a double-booking conflict (HTTP 409) to the caller with its payload.
    // Do NOT fall back to the local cache here — that would hide the conflict and
    // make the assignment look successful when it was actually rejected.
    if (error.response && error.response.status === 409) {
      const payload = error.response.data || {};
      const conflictError = new Error(payload.message || 'Guide is double-booked');
      conflictError.code = payload.error || 'guide_double_booked';
      conflictError.conflict = payload.conflict || null;
      conflictError.status = 409;
      throw conflictError;
    }

    // Step 4.8: a failed save is a failed save. The old code wrote the change into the local
    // cache and returned it as if the server had accepted it - a guide "assigned" on a dead
    // link was never assigned. (A timed-out save is marked `outcomeUnknown` by the interceptor.)
    console.error('Error updating tour:', error);
    throw error;
  }
};

// Helper function to update local tour cache
const updateLocalTourCache = (tourId, updates) => {
  try {
    // Update in new cache format
    const cachedData = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{"data":[]}');
    const storedTours = cachedData.data || [];
    
    // Find and update the specific tour
    const updatedTours = storedTours.map(tour => 
      tour.id === tourId ? { ...tour, ...updates } : tour
    );
    
    // Save back with new timestamp
    const newCacheData = {
      timestamp: Date.now(),
      data: updatedTours
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(newCacheData));
    
    // Also update legacy key
    localStorage.setItem('tours', JSON.stringify(updatedTours));
    
    console.log('Local cache updated as fallback');
  } catch (error) {
    console.error('Error updating local cache:', error);
  }
};

// BOKUN SYNC OPERATIONS
export const syncBokun = async (startDate = null, endDate = null, syncType = 'manual') => {
  try {
    const response = await axios.post(`${API_BASE_URL}/bokun_sync.php?action=sync`, {
      start_date: startDate,
      end_date: endDate,
      type: syncType,
      triggered_by: 'user'
    });

    // Clear tour cache after sync to ensure fresh data
    clearTourCache();

    return response.data;
  } catch (error) {
    console.error('Error syncing from Bokun:', error);
    throw error;
  }
};

export const fullSyncBokun = async () => {
  try {
    const response = await axios.post(`${API_BASE_URL}/bokun_sync.php?action=full-sync`, {
      triggered_by: 'user'
    });

    // Clear tour cache after sync to ensure fresh data
    clearTourCache();

    return response.data;
  } catch (error) {
    console.error('Error running full Bokun sync:', error);
    throw error;
  }
};

export const getSyncHistory = async (limit = 20) => {
  try {
    const response = await axios.get(`${API_BASE_URL}/bokun_sync.php?action=sync-history&limit=${limit}`);
    return response.data;
  } catch (error) {
    console.error('Error fetching sync history:', error);
    throw error;
  }
};

export const getSyncInfo = async () => {
  try {
    const response = await axios.get(`${API_BASE_URL}/bokun_sync.php?action=sync-info`);
    return response.data;
  } catch (error) {
    console.error('Error fetching sync info:', error);
    throw error;
  }
};

// TOUR GROUPS OPERATIONS
export const tourGroupsAPI = {
  async list(filters = {}) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.append(key, value);
      }
    });
    const queryString = params.toString();
    const url = `${API_BASE_URL}/tour-groups.php${queryString ? `?${queryString}` : ''}`;
    const response = await axios.get(addCacheBuster(url));
    return response.data;
  },

  // Step 5.2: EVERY group for the given filters. tour-groups.php pages at 100 max; this walks the
  // pages until the server says there is no next one and then checks that what arrived covers the
  // server's total - an incomplete group list would make grouped bookings render as loose rows.
  async listAll(filters = {}) {
    const PER_PAGE = 100;
    const MAX_PAGES = 50;
    const all = [];
    let total = 0;
    for (let page = 1; page <= MAX_PAGES; page++) {
      const res = await this.list({ ...filters, page, per_page: PER_PAGE });
      const rows = Array.isArray(res?.data) ? res.data : [];
      all.push(...rows);
      total = Number(res?.pagination?.total ?? all.length);
      if (!res?.pagination?.has_next || rows.length === 0) break;
    }
    if (all.length < total) {
      throw new Error(`Tour groups incomplete: received ${all.length} of ${total}`);
    }
    return { data: all, pagination: { total, per_page: PER_PAGE, loaded: all.length } };
  },

  async autoGroup(data = {}) {
    const response = await axios.post(`${API_BASE_URL}/tour-groups.php?action=auto-group`, data);
    clearTourCache();
    return response.data;
  },

  async manualMerge(tourIds, displayName = null, notes = null) {
    const response = await axios.post(`${API_BASE_URL}/tour-groups.php?action=manual-merge`, {
      tour_ids: tourIds,
      display_name: displayName,
      notes
    });
    clearTourCache();
    return response.data;
  },

  async unmerge(tourId) {
    const response = await axios.post(`${API_BASE_URL}/tour-groups.php?action=unmerge`, {
      tour_id: tourId
    });
    clearTourCache();
    return response.data;
  },

  async update(groupId, data) {
    const response = await axios.put(`${API_BASE_URL}/tour-groups.php/${groupId}`, data);
    clearTourCache();
    return response.data;
  },

  async dissolve(groupId) {
    const response = await axios.delete(`${API_BASE_URL}/tour-groups.php/${groupId}`);
    clearTourCache();
    return response.data;
  }
};

// ===== Daily P&L (admin only) =====

export const getPnlDay = async (date) => {
  const response = await axios.get(addCacheBuster(`${API_BASE_URL}/pnl.php?date=${encodeURIComponent(date)}`));
  return response.data;
};

export const getPnlRange = async (start, end) => {
  const response = await axios.get(addCacheBuster(
    `${API_BASE_URL}/pnl.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`
  ));
  return response.data;
};

export const getPnlSettings = async () => {
  const response = await axios.get(addCacheBuster(`${API_BASE_URL}/pnl.php?action=settings`));
  return response.data;
};

export const savePnlSettings = async (settings) => {
  const response = await axios.post(`${API_BASE_URL}/pnl.php?action=settings`, { settings });
  return response.data;
};

export const savePnlCosts = async (payload) => {
  // payload: { tour_unit, date, ticket_cost?, guide_cost?, radio_cost?, gelato_cost?,
  //            staff_cost?, other_cost?, revenue_override?, notes? } — null clears an override
  const response = await axios.post(`${API_BASE_URL}/pnl.php?action=costs`, payload);
  return response.data;
};

// Step 6.1: the languages that exist in the range in view, so the dropdown offers only those.
export const getTourLanguages = async (filters = {}) => {
  const params = new URLSearchParams({ ...filters, action: 'languages' });
  const response = await axios.get(`${API_BASE_URL}/tours.php?${params.toString()}`);
  return response.data;
};

// Step 6.8: the printable participant list for one departure (one product, one time, one
// date). The PDF is built on the server so it looks the same wherever he prints it; the
// browser only has to save the bytes.
export const downloadParticipantsPdf = async (unit) => {
  const response = await axios.get(
    `${API_BASE_URL}/participants.php?unit=${encodeURIComponent(unit)}`,
    { responseType: 'blob' }
  );
  const disposition = response.headers?.['content-disposition'] || '';
  const match = /filename="?([^"]+)"?/.exec(disposition);
  const name = match ? match[1] : `${unit}-participants.pdf`;
  const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
  return name;
};

// Step 6.9: the owner's paper fallback - every departure still to run on the Viator account
// he is retiring, with Viator's own reference numbers. Admin only on the server.
export const downloadViatorLegacyCsv = async () => {
  const response = await axios.get(`${API_BASE_URL}/viator_legacy_export.php`, { responseType: 'blob' });
  const disposition = response.headers?.['content-disposition'] || '';
  const match = /filename="?([^"]+)"?/.exec(disposition);
  const name = match ? match[1] : 'viator-old-account-departures.csv';
  const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
  return name;
};

// Step 6.4: departures typed in by hand, for a listing that is not connected to Bokun.
// Admin only on the server; the sync never touches the rows these create.
export const createManualTour = async (payload) => {
  const response = await axios.post(`${API_BASE_URL}/tours.php?action=manual`, payload);
  clearTourCache();
  return response.data;
};

export const updateManualTour = async (id, payload) => {
  const response = await axios.put(`${API_BASE_URL}/tours.php/${id}?action=manual`, payload);
  clearTourCache();
  return response.data;
};

export const deleteManualTour = async (id) => {
  const response = await axios.delete(`${API_BASE_URL}/tours.php/${id}?action=manual`);
  clearTourCache();
  return response.data;
};

// Step 6.3: the afternoon radio order for Vox Firenze (admin only).
export const getRadioPlan = async (date) => {
  const response = await axios.get(`${API_BASE_URL}/radios.php?action=plan&date=${date}`);
  return response.data;
};

export const markRadioOrderSent = async (payload) => {
  // payload: { date, message, receivers_total, transmitters_total }
  const response = await axios.post(`${API_BASE_URL}/radios.php?action=sent`, payload);
  return response.data;
};

// Step 6.2: merged costing units (Daily P&L only - nothing operational changes).
export const mergePnlUnits = async (date, units) => {
  const response = await axios.post(`${API_BASE_URL}/pnl.php?action=link`, { date, units });
  return response.data;
};

export const unmergePnlUnits = async (linkKey) => {
  const response = await axios.post(`${API_BASE_URL}/pnl.php?action=unlink`, { link_key: linkKey });
  return response.data;
};

// Default export object for backwards compatibility
const mysqlDB = {
  // Tours operations
  fetchTours: getTours,
  getTourById,
  getUnassignedReport,
  getUnassignedCount,
  getTourLanguages, // step 6.1
  downloadParticipantsPdf, // step 6.8
  downloadViatorLegacyCsv, // step 6.9
  createManualTour, // step 6.4
  updateManualTour,
  deleteManualTour,
  addTour,
  deleteTour,
  updateTour,
  updateTourPaidStatus,
  updateTourCancelStatus,
  clearTourCache,

  // Guides operations
  fetchGuides: getGuides,
  getAllGuides,
  addGuide,
  updateGuide,
  deleteGuide,

  // Bokun sync operations
  syncBokun,
  fullSyncBokun,
  getSyncHistory,
  getSyncInfo
};

export default mysqlDB;