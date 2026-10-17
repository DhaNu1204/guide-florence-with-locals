/**
 * Step 9.1: the sidebar keeps the same links per role, grouped under Operations / Money / Setup;
 * "Load measurements" is labelled "Diagnostics" (route unchanged); the active item is marked.
 */
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

const authState = { role: 'admin', pnl: true };
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({
    userRole: authState.role,
    userName: 'Test',
    logout: vi.fn(),
    isAdmin: () => authState.role === 'admin',
    canSeePnl: () => authState.role === 'admin' && authState.pnl === true,
    isAuthenticated: true,
  }),
}));

import ModernLayout from '../Layout/ModernLayout';

const renderAt = (path) =>
  render(<MemoryRouter initialEntries={[path]}><ModernLayout><div>child</div></ModernLayout></MemoryRouter>);
const links = () => within(screen.getByRole('navigation')).getAllByRole('link').map((a) => [a.textContent, a.getAttribute('href')]);

describe('ModernLayout grouped sidebar (step 9.1)', () => {
  it('owner admin: every item, in groups, Diagnostics -> /load-measurements', () => {
    authState.role = 'admin'; authState.pnl = true;
    renderAt('/tours');
    expect(screen.getByText('Operations')).toBeInTheDocument();
    expect(screen.getByText('Money')).toBeInTheDocument();
    expect(screen.getByText('Setup')).toBeInTheDocument();
    expect(links()).toEqual([
      ['Today', '/today'], ['Dashboard', '/'], ['Tours', '/tours'], ['Guides', '/guides'],
      ['Priority Tickets', '/priority-tickets'], ['Tickets', '/tickets'], ['Radios', '/radios'],
      ['Payments', '/payments'], ['Guide Reports', '/guide-reports'], ['Daily P&L', '/daily-pnl'],
      ['Products', '/products'], ['Bokun Integration', '/bokun-integration'], ['Diagnostics', '/load-measurements'],
    ]);
    expect(screen.queryByText('Load measurements')).not.toBeInTheDocument();
    const active = screen.getByRole('link', { name: 'Tours' });
    expect(active).toHaveAttribute('aria-current', 'page');
    expect(active.className).toContain('bg-accent-soft');
  });

  it('second admin: no Daily P&L', () => {
    authState.role = 'admin'; authState.pnl = false;
    renderAt('/');
    expect(links().map(([t]) => t)).not.toContain('Daily P&L');
    expect(links()).toHaveLength(12);
  });

  it('viewer: no admin items and no empty Setup group', () => {
    authState.role = 'viewer'; authState.pnl = false;
    renderAt('/');
    expect(links().map(([t]) => t)).toEqual(['Today', 'Dashboard', 'Tours', 'Guides', 'Priority Tickets', 'Tickets', 'Payments', 'Guide Reports']);
    expect(screen.queryByText('Setup')).not.toBeInTheDocument();
  });
});
