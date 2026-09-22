/**
 * Step 6.10: the "Daily P&L" menu item is there for the owner and gone for everybody
 * else — a viewer and a second admin alike. (Hiding the menu protects nothing on its
 * own; pnl.php answers 403. This only stops the link being offered.)
 */
import { render, screen } from '@testing-library/react';
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

const renderLayout = () =>
  render(<MemoryRouter><ModernLayout><div>child</div></ModernLayout></MemoryRouter>);

describe('ModernLayout Daily P&L menu item (step 6.10)', () => {
  it('is present for the owner', () => {
    authState.role = 'admin';
    authState.pnl = true;
    renderLayout();
    expect(screen.getAllByText('Daily P&L').length).toBeGreaterThan(0);
  });

  it('is absent for a viewer', () => {
    authState.role = 'viewer';
    authState.pnl = false;
    renderLayout();
    expect(screen.queryByText('Daily P&L')).not.toBeInTheDocument();
  });

  it('is absent for a second admin who is not the owner', () => {
    authState.role = 'admin';
    authState.pnl = false;
    renderLayout();
    expect(screen.queryByText('Daily P&L')).not.toBeInTheDocument();
  });
});
