/**
 * Test Setup File
 * Florence With Locals - Vitest Configuration
 *
 * This file runs before each test file and sets up the testing environment.
 */

import '@testing-library/jest-dom';
import { vi, afterEach } from 'vitest';
import { __resetLastGoodForTests } from '../services/lastGood';

// Step 4.10: saved screens live in memory under jsdom (no IndexedDB) - forget them after each test.
afterEach(() => { __resetLastGoodForTests(); });

// Mock window.matchMedia (required for responsive components)
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

// Mock localStorage
const localStorageMock = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
};
Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
});

// Mock sessionStorage
const sessionStorageMock = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
};
Object.defineProperty(window, 'sessionStorage', {
  value: sessionStorageMock,
});

// Reset mocks before each test
beforeEach(() => {
  vi.clearAllMocks();
  localStorageMock.getItem.mockClear();
  localStorageMock.setItem.mockClear();
  sessionStorageMock.getItem.mockClear();
  sessionStorageMock.setItem.mockClear();
});

// Suppress console errors during tests (optional - can comment out for debugging)
// vi.spyOn(console, 'error').mockImplementation(() => {});
