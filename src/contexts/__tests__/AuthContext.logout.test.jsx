/**
 * Step 1.5: AuthContext.logout() calls the server (POST auth.php?action=logout with the
 * Bearer token) and clears storage + state, even when the call fails.
 */
import { render, screen, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { AuthProvider, useAuth } from '../AuthContext';

const storage = {};
const setStorage = (values) => {
  Object.keys(storage).forEach((k) => delete storage[k]);
  Object.assign(storage, values);
  localStorage.getItem.mockImplementation((k) => (k in storage ? storage[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { storage[k] = v; });
  localStorage.removeItem.mockImplementation((k) => { delete storage[k]; });
};

const Probe = () => {
  const { isAuthenticated, userName, logout } = useAuth();
  return (
    <div>
      <span data-testid="auth">{isAuthenticated ? 'in' : 'out'}</span>
      <span data-testid="name">{userName || '-'}</span>
      <button onClick={() => logout()}>Logout</button>
    </div>
  );
};

describe('AuthContext logout (step 1.5)', () => {
  beforeEach(() => {
    setStorage({ token: 'tok-123', userRole: 'admin', userName: 'dhanu' });
    global.fetch = vi.fn((url) => {
      if (String(url).includes('action=verify')) {
        return Promise.resolve({ ok: true, json: async () => ({ success: true, role: 'admin', username: 'dhanu' }) });
      }
      return Promise.resolve({ ok: true, json: async () => ({ success: true }) });
    });
  });

  it('calls the logout endpoint with the Bearer token, then clears storage and state', async () => {
    render(<AuthProvider><Probe /></AuthProvider>);
    await waitFor(() => expect(screen.getByTestId('auth').textContent).toBe('in'));
    expect(screen.getByTestId('name').textContent).toBe('dhanu');

    await act(async () => { screen.getByText('Logout').click(); });

    const logoutCall = global.fetch.mock.calls.find(([url]) => String(url).includes('action=logout'));
    expect(logoutCall).toBeTruthy();
    expect(logoutCall[1].method).toBe('POST');
    expect(logoutCall[1].headers.Authorization).toBe('Bearer tok-123');
    expect(localStorage.removeItem).toHaveBeenCalledWith('token');
    expect(localStorage.removeItem).toHaveBeenCalledWith('userRole');
    expect(localStorage.removeItem).toHaveBeenCalledWith('userName');
    expect(storage.token).toBeUndefined();
    await waitFor(() => expect(screen.getByTestId('auth').textContent).toBe('out'));
  });

  it('still clears locally when the logout call fails', async () => {
    global.fetch = vi.fn((url) => {
      if (String(url).includes('action=verify')) {
        return Promise.resolve({ ok: true, json: async () => ({ success: true, role: 'admin', username: 'dhanu' }) });
      }
      return Promise.reject(new Error('network down'));
    });
    render(<AuthProvider><Probe /></AuthProvider>);
    await waitFor(() => expect(screen.getByTestId('auth').textContent).toBe('in'));

    await act(async () => { screen.getByText('Logout').click(); });

    expect(storage.token).toBeUndefined();
    await waitFor(() => expect(screen.getByTestId('auth').textContent).toBe('out'));
  });
});
