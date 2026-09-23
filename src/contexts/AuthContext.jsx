import { createContext, useContext, useState, useEffect } from 'react';
import {
  markVerifyStart, markVerifyEnd, markVerifyError, markVerifyRetryStart, markVerifyRetryEnd, markTimeout,
} from '../utils/perfBeacon'; // step 4.7/4.8: measurement only
import { fetchWithTimeout, classifyError, VERIFY_TIMEOUT_MS, WRITE_TIMEOUT_MS } from '../services/netPolicy';
import { notifySessionExpired } from '../services/sessionExpiry';

const AuthContext = createContext(null);

// Step 4.9: a device that already has a token AND the user from its last successful check
// (role, name, P&L access) renders the app at once and checks the token in parallel, so the
// page's code and its data requests start during the check instead of after it - one round
// trip less on every open. The server authorises every data call, so nothing can leak while
// the check is in flight; a real 401 still logs out. Without a cached role (first login on
// this device, or an install from before roles were cached) the app waits for the check as
// before, so an admin-only route can never bounce a real admin to the Dashboard.
const readStored = (key) => {
  try { return localStorage.getItem(key); } catch (_) { return null; }
};

export const AuthProvider = ({ children }) => {
  const [token, setToken] = useState(() => readStored('token'));
  const [userRole, setUserRole] = useState(() => readStored('userRole'));
  // Rendering before the check is only safe when this device already knows who the user is.
  const [optimistic] = useState(() => Boolean(readStored('token') && readStored('userRole')));
  const [isAuthenticated, setIsAuthenticated] = useState(optimistic);
  const [userName, setUserName] = useState(localStorage.getItem('userName'));
  // Step 6.10: may this account see money (Daily P&L)? The server answers it on every
  // verify; the cached value only avoids a flash before that answer arrives.
  // Step 4.9: only an explicit 'true' from a previous check shows the P&L item before this
  // check answers; no cached value means the item waits for the server.
  const [pnlAccess, setPnlAccess] = useState(() => readStored('pnlAccess') === 'true');
  const [loading, setLoading] = useState(!optimistic);

  // Step 4.8: the token is cleared ONLY when the server has actually said the session is
  // invalid - a real 401 from auth.php?action=verify. On 2026-09-23 the owner's phone lost the
  // connection during this check, the old catch deleted a perfectly valid token, and he was
  // thrown out (and the field recorder lost its row with it). Now a network error, a timeout,
  // a 5xx or the edge's 504 keep the token, the app renders as logged in with the role it
  // already knew, and the check is repeated quietly in the background. The server enforces
  // auth on every data call anyway: if the token really is dead, the next call gets its 401
  // and the normal session-expired path runs.
  useEffect(() => {
    if (!token) {
      setLoading(false);
      return undefined;
    }
    let cancelled = false;
    let retryTimer = null;
    let backgroundTries = 0;

    const clearSession = () => {
      localStorage.removeItem('token');
      localStorage.removeItem('userRole');
      localStorage.removeItem('userName');
      localStorage.removeItem('pnlAccess');
      setToken(null);
      setUserRole(null);
      setUserName(null);
      setPnlAccess(false);
      setIsAuthenticated(false);
    };

    const accept = (data) => {
      setIsAuthenticated(true);
      setUserRole(data.role);
      setUserName(data.username);
      setPnlAccess(data.pnl_access === true);
      localStorage.setItem('userRole', data.role);
      localStorage.setItem('userName', data.username);
      localStorage.setItem('pnlAccess', data.pnl_access === true ? 'true' : 'false');
    };

    // One check. Returns 'ok' | 'invalid' | 'unknown' (plus the reason, for the recorder).
    const check = async () => {
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      try {
        const response = await fetchWithTimeout(`${API_BASE}/auth.php?action=verify`, {
          headers: { Authorization: `Bearer ${token}` },
        }, VERIFY_TIMEOUT_MS);
        if (response.ok) return { result: 'ok', data: await response.json() };
        if (response.status === 401) return { result: 'invalid' };
        return { result: 'unknown', reason: `http:${response.status}` };
      } catch (error) {
        const { kind } = classifyError(error);
        if (kind === 'timeout') markTimeout();
        return { result: 'unknown', reason: kind === 'timeout' ? 'timeout' : 'network' };
      }
    };

    // Quiet background re-checks after an unknown answer: 3 s, 15 s, 30 s, then every 60 s,
    // and at once when the phone says it is back online. The FIRST one is the "automatic
    // retry" the recorder reports on (its phase opens as soon as it is scheduled, so the row
    // waits for its outcome); the rest are not recorded.
    const BACKGROUND_DELAYS_MS = [3000, 15000, 30000];
    const scheduleBackground = () => {
      if (backgroundTries === 0) markVerifyRetryStart();
      retryTimer = setTimeout(runBackground, BACKGROUND_DELAYS_MS[backgroundTries] ?? 60000);
    };
    const runBackground = async () => {
      if (cancelled) return;
      retryTimer = null;
      const first = backgroundTries === 0;
      backgroundTries += 1;
      const outcome = await check();
      if (cancelled) return;
      if (first) markVerifyRetryEnd(outcome.result === 'ok');
      if (outcome.result === 'ok') {
        accept(outcome.data);
      } else if (outcome.result === 'invalid') {
        clearSession();
        notifySessionExpired();
      } else {
        scheduleBackground();
      }
    };
    const onOnline = () => {
      if (retryTimer) {
        clearTimeout(retryTimer);
        runBackground();
      }
    };

    (async () => {
      markVerifyStart(); // step 4.7
      const outcome = await check();
      if (cancelled) return;
      markVerifyEnd(outcome.result === 'ok'); // step 4.7
      if (outcome.result === 'ok') {
        accept(outcome.data);
      } else if (outcome.result === 'invalid') {
        markVerifyError('http:401');
        // Step 4.9: the app may already be on screen (optimistic render) - take the normal
        // session-expired path: one toast and the redirect to /login.
        if (optimistic) notifySessionExpired();
        clearSession();
      } else {
        // Keep the token. Render as logged in with what this device already knew.
        markVerifyError(outcome.reason);
        console.warn('Token check could not reach the server - keeping the session:', outcome.reason);
        setIsAuthenticated(true);
        window.addEventListener('online', onOnline);
        scheduleBackground();
      }
      setLoading(false);
    })();

    return () => {
      cancelled = true;
      if (retryTimer) clearTimeout(retryTimer);
      window.removeEventListener('online', onOnline);
    };
  }, [token]);

  const login = async (username, password) => {
    try {
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      // Step 4.8: a login that never gets an answer ends with a message, not an endless button.
      const response = await fetchWithTimeout(`${API_BASE}/auth.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username, password }),
      }, WRITE_TIMEOUT_MS);

      const data = await response.json();

      if (data.success) {
        localStorage.setItem('token', data.token);
        localStorage.setItem('userRole', data.role);
        localStorage.setItem('userName', data.username);
        localStorage.setItem('pnlAccess', data.pnl_access === true ? 'true' : 'false');
        setToken(data.token);
        setUserRole(data.role);
        setUserName(data.username);
        setPnlAccess(data.pnl_access === true);
        setIsAuthenticated(true);
        return { success: true };
      } else {
        return { success: false, message: data.message };
      }
    } catch (error) {
      console.error('Login failed:', error);
      const { kind } = classifyError(error);
      if (kind === 'timeout' || kind === 'network') {
        return { success: false, message: 'Could not reach the server — check your signal and try again.' };
      }
      return { success: false, message: 'Login failed. Please try again.' };
    }
  };

  // Step 1.5: real logout. The server deletes the session row first (so the token
  // is dead everywhere), then local state/storage are cleared - even if the call
  // failed (offline, already expired). Callers navigate to /login afterwards.
  const logout = async () => {
    const current = token || localStorage.getItem('token');
    if (current) {
      try {
        const API_BASE = import.meta.env.VITE_API_URL || '/api';
        await fetchWithTimeout(`${API_BASE}/auth.php?action=logout`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${current}` },
        }, VERIFY_TIMEOUT_MS); // step 4.8: never hang the Log out button
      } catch (error) {
        console.error('Logout call failed (clearing locally anyway):', error);
      }
    }
    localStorage.removeItem('token');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userName');
    localStorage.removeItem('pnlAccess');
    setToken(null);
    setUserRole(null);
    setUserName(null);
    setPnlAccess(false);
    setIsAuthenticated(false);
  };

  const isAdmin = () => userRole === 'admin';
  // Step 6.10: Daily P&L is the owner's alone. The server is the authority (pnl.php
  // answers 403 to everyone else); this only keeps the menu and the route honest.
  const canSeePnl = () => userRole === 'admin' && pnlAccess === true;

  if (loading) {
    return <div>Loading...</div>;
  }

  return (
    <AuthContext.Provider value={{ isAuthenticated, login, logout, isAdmin, canSeePnl, userRole, userName }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}; 