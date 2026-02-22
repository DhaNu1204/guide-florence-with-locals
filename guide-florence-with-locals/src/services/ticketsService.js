import axios from 'axios';

// Use the same API base URL as other services
const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

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

// Storage key for tickets cache
const STORAGE_KEY = 'tickets_v1';
const STORAGE_EXPIRY_MS = 60000; // 1 minute expiry

// Cache management
const CACHE_KEY = 'tickets_cache';
const CACHE_EXPIRY = 5 * 60 * 1000; // 5 minutes

// Fallback local storage management
const FALLBACK_KEY = 'tickets_fallback';

// Add cache busting to prevent browser caching
const addCacheBuster = (url) => {
  const timestamp = new Date().getTime();
  return `${url}${url.includes('?') ? '&' : '?'}_=${timestamp}`;
};

// Clear tickets cache
export const clearTicketsCache = () => {
  try {
    localStorage.removeItem(STORAGE_KEY);
    console.log('Tickets cache cleared successfully');
  } catch (error) {
    console.warn('Failed to clear tickets cache:', error);
  }
};

const getCache = () => {
  const cache = localStorage.getItem(CACHE_KEY);
  if (cache) {
    const { data, timestamp } = JSON.parse(cache);
    if (Date.now() - timestamp < CACHE_EXPIRY) {
      return data;
    }
  }
  return null;
};

const setCache = (data) => {
  localStorage.setItem(CACHE_KEY, JSON.stringify({
    data,
    timestamp: Date.now()
  }));
};

const clearCache = () => {
  localStorage.removeItem(CACHE_KEY);
};

const getFallbackData = () => {
  const data = localStorage.getItem(FALLBACK_KEY);
  return data ? JSON.parse(data) : [];
};

const setFallbackData = (data) => {
  localStorage.setItem(FALLBACK_KEY, JSON.stringify(data));
};

// GET all tickets
export const getTickets = async (forceRefresh = false) => {
  // Check if we need to force a refresh
  if (forceRefresh) {
    clearTicketsCache();
  }
  
  // Check for cached data and its freshness
  let cachedData = null;
  let isCacheStale = true;
  
  try {
    const storedData = localStorage.getItem(STORAGE_KEY);
    if (storedData) {
      const parsed = JSON.parse(storedData);
      // Only use cache if it has a timestamp and isn't expired
      if (parsed.timestamp && (Date.now() - parsed.timestamp < STORAGE_EXPIRY_MS)) {
        cachedData = parsed.data;
        isCacheStale = false;
        console.log('Using fresh cached ticket data');
      } else {
        console.log('Cached ticket data is stale, fetching fresh data');
      }
    }
  } catch (error) {
    console.warn('Error reading from cache:', error);
  }
  
  // If we have fresh cached data and aren't forcing a refresh, use it
  if (cachedData && !forceRefresh && !isCacheStale) {
    return cachedData;
  }
  
  // Otherwise fetch from the server
  try {
    console.log('Fetching fresh ticket data from server');
    const response = await axios.get(addCacheBuster(`${API_BASE_URL}/tickets.php`));
    
    // Extract tickets array from the response
    // Map 'museum' DB column back to 'code' for frontend compatibility
    const serverTickets = (response.data.tickets || []).map(t => ({
      ...t,
      code: t.museum || t.code || ''
    }));
    
    // Store in cache with timestamp
    try {
      const cacheData = {
        timestamp: Date.now(),
        data: serverTickets
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(cacheData));
    } catch (localError) {
      console.warn('Could not save tickets to localStorage:', localError);
    }
    
    return serverTickets;
  } catch (error) {
    console.error('Error fetching tickets from server:', error);
    
    // Fallback to cached data if available
    if (cachedData) {
      console.log('Using stale cached data as fallback');
      return cachedData;
    }
    
    // Last resort: return empty array
    return [];
  }
};

// ADD a new ticket
export const addTicket = async (ticketData) => {
  // Create a local ID for immediate UI update
  const tempId = Date.now();
  const newTicket = {
    ...ticketData,
    id: tempId,
    location: ticketData.location || '',
    museum: ticketData.museum || ticketData.code || '',
    ticket_type: ticketData.ticket_type || '',
    date: ticketData.date || '',
    time: ticketData.time || '',
    quantity: parseInt(ticketData.quantity) || 0,
    price: parseFloat(ticketData.price) || 0,
    notes: ticketData.notes || null,
    status: ticketData.status || 'available',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString()
  };

  try {
    const response = await axios.post(`${API_BASE_URL}/tickets.php`, ticketData);
    
    // Clear cache to force refresh on next get
    clearTicketsCache();
    
    // Return the newly created ticket from the API response
    return response.data.ticket || newTicket;
  } catch (error) {
    console.error('Error adding ticket:', error);
    
    // Fallback to localStorage if API request fails
    try {
      // Add to fallback data
      const currentFallback = getFallbackData();
      const updatedFallback = [...currentFallback, newTicket];
      setFallbackData(updatedFallback);
      
      // Clear cache to ensure consistency
      clearCache();
      
      return newTicket;
    } catch (localError) {
      console.error('Error saving to localStorage:', localError);
      throw error; // Rethrow the original error
    }
  }
};

// DELETE a ticket
export const deleteTicket = async (ticketId) => {
  try {
    const response = await fetch(`${API_BASE_URL}/tickets.php/${ticketId}`, {
      method: 'DELETE'
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const data = await response.json();
    console.log('Delete ticket API response:', data);
    
    if (data.success) {
      // Clear cache to force refresh
      clearCache();
      
      // Update fallback data
      const currentFallback = getFallbackData();
      const updatedFallback = currentFallback.filter(ticket => ticket.id !== ticketId);
      setFallbackData(updatedFallback);
      
      return true;
    } else {
      throw new Error('Failed to delete ticket on server');
    }
  } catch (error) {
    console.error('Error deleting ticket from API:', error);
    console.log('Deleting from localStorage fallback');
    
    // Delete from fallback data
    const currentFallback = getFallbackData();
    const updatedFallback = currentFallback.filter(ticket => ticket.id !== ticketId);
    setFallbackData(updatedFallback);
    
    // Clear cache to ensure consistency
    clearCache();
    
    return true;
  }
};

// UPDATE a ticket
export const updateTicket = async (ticketId, ticketData) => {
  try {
    const response = await fetch(`${API_BASE_URL}/tickets.php/${ticketId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        location: ticketData.location,
        museum: ticketData.museum || ticketData.code || '',
        ticket_type: ticketData.ticket_type || '',
        date: ticketData.date,
        time: ticketData.time,
        quantity: ticketData.quantity,
        price: ticketData.price || 0,
        notes: ticketData.notes || null,
        status: ticketData.status || 'available'
      })
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const data = await response.json();
    console.log('Update ticket API response:', data);
    
    if (data.success && data.ticket) {
      // Clear cache to force refresh
      clearCache();
      
      // Update fallback data
      const currentFallback = getFallbackData();
      const updatedFallback = currentFallback.map(ticket => 
        ticket.id === ticketId ? data.ticket : ticket
      );
      setFallbackData(updatedFallback);
      
      return data.ticket;
    } else {
      throw new Error('Failed to update ticket on server');
    }
  } catch (error) {
    console.error('Error updating ticket in API:', error);
    console.log('Updating in localStorage fallback');
    
    // Create updated ticket object
    const updatedTicket = {
      ...ticketData,
      id: ticketId,
      updated_at: new Date().toISOString()
    };
    
    // Update fallback data
    const currentFallback = getFallbackData();
    const updatedFallback = currentFallback.map(ticket => 
      ticket.id === ticketId ? updatedTicket : ticket
    );
    setFallbackData(updatedFallback);
    
    // Clear cache to ensure consistency
    clearCache();
    
    return updatedTicket;
  }
}; 