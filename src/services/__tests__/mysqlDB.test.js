/**
 * MySQL Database Service Tests
 * Florence With Locals - API Service Tests
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Store original localStorage
const originalLocalStorage = global.localStorage;

// Create mock localStorage
const createLocalStorageMock = () => {
  let store = {};
  return {
    getItem: vi.fn((key) => store[key] || null),
    setItem: vi.fn((key, value) => { store[key] = value; }),
    removeItem: vi.fn((key) => { delete store[key]; }),
    clear: vi.fn(() => { store = {}; }),
    get length() { return Object.keys(store).length; },
    key: vi.fn((i) => Object.keys(store)[i] || null),
  };
};

// Mock axios before importing the module
vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    interceptors: {
      request: { use: vi.fn() },
      response: { use: vi.fn() },
    },
  },
}));

// Import axios after mocking
import axios from 'axios';

describe('MySQL Database Service', () => {
  let mysqlDBModule;
  let localStorageMock;

  beforeEach(async () => {
    vi.clearAllMocks();

    // Setup localStorage mock
    localStorageMock = createLocalStorageMock();
    Object.defineProperty(global, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });

    // Clear module cache and reimport
    vi.resetModules();
    mysqlDBModule = await import('../mysqlDB.js');
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('getGuides', () => {
    it('returns guides in correct format', async () => {
      const mockGuides = {
        data: [
          { id: 1, name: 'Marco', languages: 'English,Italian' },
          { id: 2, name: 'Sofia', languages: 'Spanish,English' },
        ],
        pagination: {
          page: 1,
          per_page: 20,
          total: 2,
          total_pages: 1,
        },
      };

      axios.get.mockResolvedValue({ data: mockGuides });

      const result = await mysqlDBModule.getGuides();

      expect(result).toHaveProperty('data');
      expect(result).toHaveProperty('pagination');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('normalizes languages to array', async () => {
      const mockGuides = {
        data: [
          { id: 1, name: 'Marco', languages: 'English,Italian' },
        ],
        pagination: {
          page: 1,
          per_page: 20,
          total: 1,
          total_pages: 1,
        },
      };

      axios.get.mockResolvedValue({ data: mockGuides });

      const result = await mysqlDBModule.getGuides();

      expect(result.data[0].languages).toEqual(['English', 'Italian']);
    });

    it('handles array response format (legacy)', async () => {
      const mockGuides = [
        { id: 1, name: 'Marco', languages: 'English' },
      ];

      axios.get.mockResolvedValue({ data: mockGuides });

      const result = await mysqlDBModule.getGuides();

      expect(result).toHaveProperty('data');
      expect(result).toHaveProperty('pagination');
      expect(result.data.length).toBe(1);
    });

    it('throws error on API failure', async () => {
      axios.get.mockRejectedValue(new Error('Network error'));

      await expect(mysqlDBModule.getGuides()).rejects.toThrow('Network error');
    });

    it('passes pagination parameters', async () => {
      axios.get.mockResolvedValue({ data: { data: [], pagination: {} } });

      await mysqlDBModule.getGuides(2, 50);

      expect(axios.get).toHaveBeenCalledWith(
        expect.stringContaining('page=2')
      );
      expect(axios.get).toHaveBeenCalledWith(
        expect.stringContaining('per_page=50')
      );
    });

    it('handles empty languages gracefully', async () => {
      const mockGuides = {
        data: [
          { id: 1, name: 'Marco', languages: '' },
          { id: 2, name: 'Sofia', languages: null },
        ],
        pagination: { page: 1, per_page: 20, total: 2, total_pages: 1 },
      };

      axios.get.mockResolvedValue({ data: mockGuides });

      const result = await mysqlDBModule.getGuides();

      expect(result.data[0].languages).toEqual([]);
      expect(result.data[1].languages).toEqual([]);
    });
  });

  describe('clearTourCache', () => {
    it('clears tour cache from localStorage', () => {
      // Set up mock data
      localStorageMock.setItem('tours_v1', JSON.stringify({ data: [] }));
      localStorageMock.setItem('tours', JSON.stringify({ data: [] }));

      mysqlDBModule.clearTourCache();

      expect(localStorageMock.removeItem).toHaveBeenCalledWith('tours_v1');
      expect(localStorageMock.removeItem).toHaveBeenCalledWith('tours');
    });

    it('does not throw when localStorage is empty', () => {
      expect(() => mysqlDBModule.clearTourCache()).not.toThrow();
    });
  });

  describe('API Response Format', () => {
    it('handles paginated response correctly', async () => {
      const mockResponse = {
        data: [{ id: 1, name: 'Test' }],
        pagination: {
          current_page: 1,
          per_page: 20,
          total: 100,
          total_pages: 5,
          has_next: true,
          has_prev: false,
        },
      };

      axios.get.mockResolvedValue({ data: mockResponse });

      const result = await mysqlDBModule.getGuides();

      expect(result.pagination).toBeDefined();
    });
  });

  describe('Error Handling', () => {
    it('handles network errors gracefully', async () => {
      axios.get.mockRejectedValue({
        message: 'Network Error',
        code: 'ERR_NETWORK',
      });

      await expect(mysqlDBModule.getGuides()).rejects.toBeDefined();
    });

    it('handles 500 server errors', async () => {
      axios.get.mockRejectedValue({
        response: {
          status: 500,
          data: { error: 'Internal Server Error' },
        },
      });

      await expect(mysqlDBModule.getGuides()).rejects.toBeDefined();
    });

    it('handles 401 unauthorized errors', async () => {
      axios.get.mockRejectedValue({
        response: {
          status: 401,
          data: { error: 'Unauthorized' },
        },
      });

      await expect(mysqlDBModule.getGuides()).rejects.toBeDefined();
    });
  });

  describe('Cache Busting', () => {
    it('adds timestamp to API requests', async () => {
      axios.get.mockResolvedValue({ data: { data: [], pagination: {} } });

      await mysqlDBModule.getGuides();

      // Check that the URL contains a cache buster parameter
      expect(axios.get).toHaveBeenCalledWith(
        expect.stringContaining('_=')
      );
    });
  });
});
