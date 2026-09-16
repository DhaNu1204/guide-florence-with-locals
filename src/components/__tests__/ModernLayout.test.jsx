/**
 * Step 1.5: ModernLayout shows the logged-in user's name from AuthContext and its
 * Logout button goes through useAuth().logout() before navigating to /login.
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const logout = vi.fn(() => Promise.resolve());
vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ userRole: 'admin', userName: 'Dhanu', logout, isAdmin: () => true, isAuthenticated: true }),
}));
const navigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom');
  return { ...actual, useNavigate: () => navigate };
});

import ModernLayout from '../Layout/ModernLayout';

describe('ModernLayout (step 1.5)', () => {
  beforeEach(() => { logout.mockClear(); navigate.mockClear(); });

  it('shows the user name from the context in the header', () => {
    render(<MemoryRouter><ModernLayout><div>child</div></ModernLayout></MemoryRouter>);
    expect(screen.getAllByText('Dhanu').length).toBeGreaterThan(0);
    expect(screen.getByText('child')).toBeInTheDocument();
  });

  it('Logout calls the context logout, then navigates to /login with replace', async () => {
    render(<MemoryRouter><ModernLayout><div>child</div></ModernLayout></MemoryRouter>);
    const buttons = screen.getAllByText(/logout/i);
    fireEvent.click(buttons[0]);
    await waitFor(() => expect(logout).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/login', { replace: true }));
  });
});
