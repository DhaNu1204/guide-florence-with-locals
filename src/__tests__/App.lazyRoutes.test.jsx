/**
 * Step 4.1: pages are code-split with React.lazy, so App must render the shared
 * Suspense fallback while a page chunk is still loading and the page once it is in.
 *
 * The Tours page is replaced by a stub whose module resolves on a timer — that is
 * what makes "fallback first, page after" deterministic here. The real Tours page is
 * covered by Tours.smoke.test.jsx / Tours.listView.test.jsx.
 */
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../pages/Tours', async () => {
  await new Promise((resolve) => setTimeout(resolve, 30));
  return { default: () => <h1>Tours Management</h1> };
});

vi.mock('../services/mysqlDB', () => ({
  getSyncInfo: vi.fn().mockResolvedValue({ last_sync: null }),
  getTours: vi.fn().mockResolvedValue({ data: [], pagination: {} }),
  getAllGuides: vi.fn().mockResolvedValue([]),
  clearTourCache: vi.fn(),
  default: {},
}));

vi.mock('../services/bokunAutoSync', () => ({
  default: {
    initialize: vi.fn(),
    performSync: vi.fn().mockResolvedValue(false),
    addListener: vi.fn(() => () => {}),
    getStatus: vi.fn(() => ({ lastSyncTime: null, syncInProgress: false })),
    syncNow: vi.fn().mockResolvedValue(false),
  },
}));

import App from '../App';

describe('App lazy routes (step 4.1)', () => {
  beforeEach(() => {
    const store = { token: 'test-token', userRole: 'admin', userName: 'tester' };
    localStorage.getItem.mockImplementation((k) => store[k] ?? null);
    localStorage.setItem.mockImplementation(() => {});
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, valid: true, role: 'admin', username: 'tester' }),
    });
    window.history.pushState({}, '', '/tours');
  });

  it('shows the page spinner while the Tours chunk loads, then the page', async () => {
    render(<App />);

    // the lazy chunk has not resolved yet -> shared Suspense fallback is on screen
    expect(await screen.findByLabelText('Loading page')).toBeInTheDocument();

    // ...and the page itself renders once the chunk is in
    expect(await screen.findByText('Tours Management')).toBeInTheDocument();
    expect(screen.queryByLabelText('Loading page')).not.toBeInTheDocument();
  });
});
