/**
 * Step 4.8, fix 2: never log him out because of the network.
 *
 * On 2026-09-23 the owner's phone lost the connection during the auth check, the old code
 * deleted a valid token and he was thrown out. The token may now be cleared ONLY by a real 401.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';

vi.mock('../../utils/perfBeacon', () => ({
  markVerifyStart: vi.fn(), markVerifyEnd: vi.fn(), markVerifyError: vi.fn(),
  markVerifyRetryStart: vi.fn(), markVerifyRetryEnd: vi.fn(), markTimeout: vi.fn(),
}));
vi.mock('../../services/sessionExpiry', () => ({ notifySessionExpired: vi.fn() }));

import { AuthProvider, useAuth } from '../AuthContext';
import { markVerifyError, markVerifyRetryEnd } from '../../utils/perfBeacon';
import { notifySessionExpired } from '../../services/sessionExpiry';

let store;
const Probe = () => {
  const { isAuthenticated, userRole } = useAuth();
  return <div data-testid="state">{isAuthenticated ? `in:${userRole}` : 'out'}</div>;
};
const renderAuth = () => render(<AuthProvider><Probe /></AuthProvider>);

const response = (status, body = {}) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
  clone: () => ({ arrayBuffer: async () => new ArrayBuffer(1) }),
});
const hanging = (url, opts) => new Promise((resolve, reject) => {
  opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
});

beforeEach(() => {
  vi.clearAllMocks();
  store = { token: 'valid-token', userRole: 'admin', userName: 'dhanu' };
  localStorage.getItem.mockImplementation((k) => (k in store ? store[k] : null));
  localStorage.setItem.mockImplementation((k, v) => { store[k] = String(v); });
  localStorage.removeItem.mockImplementation((k) => { delete store[k]; });
});
afterEach(() => { vi.useRealTimers(); });

describe('AuthContext verify (step 4.8)', () => {
  it('verify returns a network error -> still logged in, token kept', async () => {
    globalThis.fetch = vi.fn(async () => { throw new TypeError('Load failed'); });
    renderAuth();
    expect(await screen.findByTestId('state')).toHaveTextContent('in:admin');
    expect(store.token).toBe('valid-token');
    expect(localStorage.removeItem).not.toHaveBeenCalledWith('token');
    expect(markVerifyError).toHaveBeenCalledWith('network');
  });

  it('verify times out -> still logged in and the page renders (after 10 s, not forever)', async () => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn(hanging);
    renderAuth();
    // Step 4.9: a device that knows its user renders at once and checks in parallel
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin');
    await act(async () => { await vi.advanceTimersByTimeAsync(10000); });
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin');
    expect(store.token).toBe('valid-token');
    expect(markVerifyError).toHaveBeenCalledWith('timeout');
  });

  it('a 5xx or the edge 504 keeps the token too', async () => {
    globalThis.fetch = vi.fn(async () => response(504));
    renderAuth();
    expect(await screen.findByTestId('state')).toHaveTextContent('in:admin');
    expect(store.token).toBe('valid-token');
    expect(markVerifyError).toHaveBeenCalledWith('http:504');
  });

  it('verify returns 401 -> logged out, as before', async () => {
    globalThis.fetch = vi.fn(async () => response(401));
    renderAuth();
    expect(await screen.findByTestId('state')).toHaveTextContent('out');
    expect(store.token).toBeUndefined();
  });

  it('verify ok -> logged in with the server role', async () => {
    globalThis.fetch = vi.fn(async () => response(200, { role: 'viewer', username: 'x', pnl_access: false }));
    store.userRole = 'admin';
    renderAuth();
    expect(await screen.findByTestId('state')).toHaveTextContent('in:viewer');
  });

  it('re-checks quietly in the background; a later real 401 logs out through the normal path', async () => {
    vi.useFakeTimers();
    let calls = 0;
    globalThis.fetch = vi.fn(async () => {
      calls += 1;
      if (calls === 1) throw new TypeError('Load failed');
      return response(401);
    });
    renderAuth();
    await act(async () => { await vi.advanceTimersByTimeAsync(0); });
    expect(screen.getByTestId('state')).toHaveTextContent('in:admin');
    await act(async () => { await vi.advanceTimersByTimeAsync(3000); });
    expect(globalThis.fetch).toHaveBeenCalledTimes(2);
    expect(markVerifyRetryEnd).toHaveBeenCalledWith(false);
    expect(store.token).toBeUndefined();
    expect(notifySessionExpired).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('state')).toHaveTextContent('out');
  });

  it('the background re-check succeeding refreshes the role and records the retry as ok', async () => {
    vi.useFakeTimers();
    let calls = 0;
    globalThis.fetch = vi.fn(async () => {
      calls += 1;
      if (calls === 1) throw new TypeError('Load failed');
      return response(200, { role: 'admin', username: 'dhanu', pnl_access: true });
    });
    renderAuth();
    await act(async () => { await vi.advanceTimersByTimeAsync(3000); });
    expect(markVerifyRetryEnd).toHaveBeenCalledWith(true);
    expect(store.pnlAccess).toBe('true');
  });
});
