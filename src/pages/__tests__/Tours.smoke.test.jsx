/**
 * Render smoke test for the Tours page.
 * Mounts the page with a fully-mocked data layer and asserts it renders without
 * throwing. A render-time crash (TDZ / use-before-init / undefined call) makes
 * render() throw and this test fail — the build alone does NOT catch that.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  const tourGroupsAPI = {
    list: resolved({ data: [] }),
    autoGroup: resolved({ success: true }),
    manualMerge: resolved({ success: true }),
    unmerge: resolved({ success: true }),
    update: resolved({ success: true }),
    dissolve: resolved({ success: true }),
  };
  const mysqlDB = {
    fetchTours: resolved({ data: [], pagination: { current_page: 1, per_page: 50, total: 0, total_pages: 1 } }),
    fetchGuides: resolved({ data: [] }),
    getAllGuides: resolved([]),
    updateTour: resolved({ success: true }),
    clearTourCache: vi.fn(),
  };
  return {
    default: mysqlDB,
    tourGroupsAPI,
    getOpenGuideRequests: resolved({ data: [] }),
    createGuideRequest: resolved({ id: 1, token: 't', status: 'pending', link: 'x', message: 'm' }),
    getGuides: resolved({ data: [] }),
  };
});

vi.mock('../../services/bokunAutoSync', () => ({
  default: { syncNow: vi.fn().mockResolvedValue({}) },
}));

import Tours from '../Tours';
import { ToastProvider } from '../../components/Toast/ToastProvider';

describe('Tours page', () => {
  it('renders without throwing', async () => {
    render(
      <ToastProvider>
        <MemoryRouter>
          <Tours />
        </MemoryRouter>
      </ToastProvider>
    );
    // Appears once the page mounts past loading — proves the component body ran
    expect(await screen.findByText('Tours Management')).toBeInTheDocument();
  });
});
