/**
 * Step 6.10: OwnerRoute — the owner sees the Daily P&L, everyone else (a viewer AND a
 * second admin) gets a plain "you don't have access to this page" instead of a redirect,
 * a broken screen or a spinner. An unauthenticated visitor still goes to /login.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { describe, it, expect, beforeEach, vi } from 'vitest';

const authState = { isAuthenticated: true, role: 'admin', pnl: true };
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({
    isAuthenticated: authState.isAuthenticated,
    isAdmin: () => authState.role === 'admin',
    canSeePnl: () => authState.role === 'admin' && authState.pnl === true,
    userRole: authState.role,
  }),
}));

import OwnerRoute from '../OwnerRoute';

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/" element={<div>home page</div>} />
        <Route path="/login" element={<div>login page</div>} />
        <Route
          path="/daily-pnl"
          element={
            <OwnerRoute>
              <div>daily pnl page</div>
            </OwnerRoute>
          }
        />
      </Routes>
    </MemoryRouter>
  );

describe('OwnerRoute (step 6.10)', () => {
  beforeEach(() => {
    authState.isAuthenticated = true;
    authState.role = 'admin';
    authState.pnl = true;
  });

  it('renders the P&L for the owner', () => {
    renderAt('/daily-pnl');
    expect(screen.getByText('daily pnl page')).toBeInTheDocument();
  });

  it('shows the access message to a viewer, and stays on the page', () => {
    authState.role = 'viewer';
    authState.pnl = false;
    renderAt('/daily-pnl');
    expect(screen.queryByText('daily pnl page')).not.toBeInTheDocument();
    expect(screen.getByText(/don't have access to this page/i)).toBeInTheDocument();
    expect(screen.queryByText('home page')).not.toBeInTheDocument();
  });

  it('shows the access message to a second admin who is not the owner', () => {
    authState.role = 'admin';
    authState.pnl = false;
    renderAt('/daily-pnl');
    expect(screen.queryByText('daily pnl page')).not.toBeInTheDocument();
    expect(screen.getByText(/don't have access to this page/i)).toBeInTheDocument();
  });

  it('sends an unauthenticated visitor to /login', () => {
    authState.isAuthenticated = false;
    authState.role = null;
    authState.pnl = false;
    renderAt('/daily-pnl');
    expect(screen.getByText('login page')).toBeInTheDocument();
  });
});
