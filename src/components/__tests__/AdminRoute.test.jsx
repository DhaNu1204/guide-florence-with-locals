/**
 * Step 1.1: AdminRoute — admin renders the child, a viewer is redirected to /
 * with an "Admin only" toast, an unauthenticated user goes to /login.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const authState = { isAuthenticated: true, role: 'admin' };
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({
    isAuthenticated: authState.isAuthenticated,
    isAdmin: () => authState.role === 'admin',
    userRole: authState.role,
  }),
}));

const toastError = vi.fn();
vi.mock('../Toast/ToastProvider', () => ({
  useToast: () => ({ success: vi.fn(), error: toastError, info: vi.fn() }),
}));

import AdminRoute from '../AdminRoute';

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/" element={<div>home page</div>} />
        <Route path="/login" element={<div>login page</div>} />
        <Route
          path="/daily-pnl"
          element={
            <AdminRoute>
              <div>secret admin page</div>
            </AdminRoute>
          }
        />
      </Routes>
    </MemoryRouter>
  );

describe('AdminRoute', () => {
  beforeEach(() => {
    toastError.mockClear();
    authState.isAuthenticated = true;
    authState.role = 'admin';
  });

  it('renders the child for an admin without a toast', () => {
    renderAt('/daily-pnl');
    expect(screen.getByText('secret admin page')).toBeInTheDocument();
    expect(toastError).not.toHaveBeenCalled();
  });

  it('redirects a viewer to / and shows the "Admin only" toast', () => {
    authState.role = 'viewer';
    renderAt('/daily-pnl');
    expect(screen.queryByText('secret admin page')).not.toBeInTheDocument();
    expect(screen.getByText('home page')).toBeInTheDocument();
    expect(toastError).toHaveBeenCalledWith('Admin only');
  });

  it('sends an unauthenticated user to /login without a toast', () => {
    authState.isAuthenticated = false;
    authState.role = null;
    renderAt('/daily-pnl');
    expect(screen.getByText('login page')).toBeInTheDocument();
    expect(toastError).not.toHaveBeenCalled();
  });
});
