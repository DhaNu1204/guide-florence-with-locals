import axios from 'axios';

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
    const token = localStorage.getItem('authToken');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
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
    console.error('Error fetching tours from server:', error);
    
    // Fallback to cached data if available
    if (cachedData) {
      console.log('Using stale cached data as fallback');
      return cachedData;
    }
    
    // Last resort: try legacy cache key
    try {
      const legacyTours = JSON.parse(localStorage.getItem('tours') || '[]');
      return legacyTours;
    } catch (localError) {
      console.error('All cache fallbacks failed:', localError);
      return [];
    }
  }
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
export const updateTour = async (tourId, tourData) => {
  try {
    // Use PUT request to update the tour
    const response = await axios.put(`${API_BASE_URL}/tours.php/${tourId}`, tourData);
    
    // After updating, force refresh the tour list
    await getTours(true);
    
    return response.data;
  } catch (error) {
    console.error('Error updating tour:', error);
    
    // If API call fails, update localStorage as fallback
    try {
      updateLocalTourCache(tourId, tourData);
      
      // Return the updated tour data
      return { ...tourData, id: tourId };
    } catch (localError) {
      console.error('Error updating local cache:', localError);
      throw error;
    }
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

// Default export object for backwards compatibility
const mysqlDB = {
  // Tours operations
  fetchTours: getTours,
  addTour,
  deleteTour,
  updateTour,
  updateTourPaidStatus,
  updateTourCancelStatus,
  clearTourCache,

  // Guides operations
  fetchGuides: getGuides,
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