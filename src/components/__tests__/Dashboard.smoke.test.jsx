/**
 * Render smoke test for the Dashboard.
 * Mounts with a mocked data layer + auth/sync hook and asserts no render-time throw.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  return {
    getTours: resolved({ data: [] }),
    getGuides: resolved({ data: [] }),
    getRecentGuideResponses: resolved({ data: [] }),
    createGuideRequest: resolved({ id: 1, token: 't', status: 'pending', link: 'x', message: 'm' }),
  };
});

// Auth/sync hook used by the dashboard header
vi.mock('../../hooks/useBokunAutoSync', () => ({
  useBokunSync: () => ({ lastSync: null, isSyncing: false, syncNow: vi.fn(), error: null }),
}));

import Dashboard from '../Dashboard';

beforeEach(() => {
  // Dashboard uses a raw authFetch() for the pending-payments count
  global.fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ success: true, count: 0, data: [] }),
  });
});

describe('Dashboard', () => {
  it('renders without throwing', async () => {
    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );
    expect(await screen.findByText('Dashboard')).toBeInTheDocument();
  });
});
