/**
 * Step 4.9: the login check no longer holds the app hostage.
 *
 * With a stored token AND the user from the last successful check, the app renders at once and
 * the check runs in parallel - so the page's own requests start before the check returns. The
 * cached user drives the menus from the first frame; the owner-only P&L item can never flash
 * for anyone else; a real 401 still logs out; a network error still keeps the token (4.8).
 */
import { useEffect } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';

vi.mock('../../utils/perfBeacon', () => ({
  markVerifyStart: vi.fn(), markVerifyEnd: vi.fn(), markVerifyError: vi.fn(),
  markVerifyRetryStart: vi.fn(), markVerifyRetryEnd: vi.fn(), markTimeout: vi.fn(),
}));
vi.mock('../../services/sessionExpiry', () => ({ notifySessionExpired: vi.fn() }));

import { AuthProvider, useAuth } from '../AuthContext';
import { notifySessionExpired } from '../../services/sessionExpiry';

let store;
let verify; // { resolve, reject } of the pending auth.php?action=verify
const response = (status, body = {}) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

// Every render records what the menus would show, so a one-frame flash cannot hide.
let frames;
const Probe = ({ onMount }) => {
  const { isAuthenticated, isAdmin, canSeePnl, userName } = useAuth();
  frames.push({ in: isAuthenticated, admin: isAdmin(), pnl: canSeePnl() });
  useEffect(() => { if (onMount) onMount(); }, [onMount]);
  return (
    <div data-testid="state">
      {isAuthenticated ? `in:${isAdmin() ? 'admin' : 'viewer'}:${userName}` : 'out'}
      {canSeePnl() && <span data-testid="pnl-menu">Daily P&amp;L</span>}
    </div>
  );
};

beforeEach(() => {
  vi.clearAllMocks();
  frames = [];
  store = {};
  localStorage.getItem.mockImplementation((k) => (k in store ? store[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { store[k] = String(v); });
  localStorage.removeItem.mockImplementation((k) => { delete store[k]; });
  globalThis.fetch = vi.fn(() => new Promise((resolve, reject) => { verify = { resolve, reject }; }));
});

describe('AuthContext optimistic start (step 4.9)', () => {
  it('stored token + cached user: renders at once and the page starts its requests BEFORE the check returns', async () => {
    store = { token: 't', userRole: 'admin', userName: 'dhanu', pnlAccess: 'true' };
    const pageData = vi.fn();
    render(<AuthProvider><Probe onMount={pageData} /></AuthProvider>);
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin:dhanu');
    expect(pageData).toHaveBeenCalledTimes(1);            // the page's data request has started...
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);   // ...while the check is still pending
    await act(async () => { verify.resolve(response(200, { role: 'admin', username: 'dhanu', pnl_access: true })); });
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin:dhanu');
  });

  it('the cached user drives the menus from the first frame (a viewer never sees admin items)', () => {
    store = { token: 't', userRole: 'viewer', userName: 'guide1', pnlAccess: 'false' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.getByTestId('state')).toHaveTextContent('in:viewer:guide1');
    expect(frames.every((f) => f.admin === false && f.pnl === false)).toBe(true);
  });

  it('the owner sees his Daily P&L item from the first frame', () => {
    store = { token: 't', userRole: 'admin', userName: 'dhanu', pnlAccess: 'true' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.getByTestId('pnl-menu')).toBeInTheDocument();
  });

  it('a non-owner admin (sudesh) never gets the P&L item - not before the check, not after', async () => {
    store = { token: 't', userRole: 'admin', userName: 'sudesh', pnlAccess: 'false' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.queryByTestId('pnl-menu')).toBeNull();
    await act(async () => { verify.resolve(response(200, { role: 'admin', username: 'sudesh', pnl_access: false })); });
    expect(screen.queryByTestId('pnl-menu')).toBeNull();
    expect(frames.length).toBeGreaterThan(0);
    expect(frames.some((f) => f.pnl)).toBe(false);
  });

  it('no cached P&L answer: the item waits for the server, then appears only if it says so', async () => {
    store = { token: 't', userRole: 'admin', userName: 'dhanu' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.queryByTestId('pnl-menu')).toBeNull();
    await act(async () => { verify.resolve(response(200, { role: 'admin', username: 'dhanu', pnl_access: true })); });
    expect(screen.getByTestId('pnl-menu')).toBeInTheDocument();
  });

  it('a 401 on the check logs out through the normal session-expired path', async () => {
    store = { token: 'dead', userRole: 'admin', userName: 'dhanu', pnlAccess: 'true' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin');
    await act(async () => { verify.resolve(response(401)); });
    expect(screen.getByTestId('state')).toHaveTextContent('out');
    expect(store.token).toBeUndefined();
    expect(store.pnlAccess).toBeUndefined();
    expect(notifySessionExpired).toHaveBeenCalledTimes(1);
  });

  it('a network error on the check keeps the token and stays in (4.8, still true)', async () => {
    store = { token: 't', userRole: 'admin', userName: 'dhanu', pnlAccess: 'true' };
    render(<AuthProvider><Probe /></AuthProvider>);
    await act(async () => { verify.reject(new TypeError('Load failed')); });
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin');
    expect(store.token).toBe('t');
    expect(notifySessionExpired).not.toHaveBeenCalled();
  });

  it('a token without a cached user (older install) still waits for the check, as before', async () => {
    store = { token: 't' };
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.queryByTestId('state')).toBeNull();
    await act(async () => { verify.resolve(response(200, { role: 'admin', username: 'dhanu', pnl_access: false })); });
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin:dhanu');
  });

  it('no token at all (first login): nothing is checked, the login flow is unchanged', () => {
    render(<AuthProvider><Probe /></AuthProvider>);
    expect(screen.getByTestId('state')).toHaveTextContent('out');
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });
});
