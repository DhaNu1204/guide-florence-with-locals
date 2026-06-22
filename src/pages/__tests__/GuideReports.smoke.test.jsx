/**
 * Render smoke test for the Guide Reports page.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../contexts/PageTitleContext', () => ({
  usePageTitle: () => ({ setPageTitle: vi.fn() }),
}));

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  return {
    getAllGuides: resolved([]),
    getGuideTourReport: resolved({ data: {} }),
  };
});

import GuideReports from '../GuideReports';

describe('GuideReports page', () => {
  it('renders without throwing', async () => {
    render(
      <MemoryRouter>
        <GuideReports />
      </MemoryRouter>
    );
    expect(await screen.findByText('Guide Reports')).toBeInTheDocument();
  });
});
