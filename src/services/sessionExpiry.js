// Central session-expiry signal.
//
// Axios interceptors and plain fetch() wrappers cannot use React hooks
// (useNavigate / useToast), so on an HTTP 401 they call notifySessionExpired(),
// which clears the stored token and dispatches a window event. A top-level
// listener mounted inside the ToastProvider + Router tree (see App.jsx) reacts
// by showing a toast and redirecting to /login.

export const SESSION_EXPIRED_EVENT = 'app:session-expired';

// Dedupe a burst of parallel 401s into a single toast + redirect.
let alreadyNotified = false;

export const notifySessionExpired = () => {
  if (typeof window === 'undefined') return;

  // Never bounce while already on the login page — avoids redirect loops and a
  // confusing "session expired" toast when a fresh login attempt itself 401s.
  if (window.location && window.location.pathname === '/login') return;

  if (alreadyNotified) return;
  alreadyNotified = true;

  try {
    localStorage.removeItem('token');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userName');
  } catch (_) {
    // Ignore storage access errors (private mode, etc.)
  }

  window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT));
};

// Re-arm the dedupe guard so a future session can expire and notify again
// within the same tab. The listener calls this shortly after redirecting.
export const resetSessionExpiryGuard = () => {
  alreadyNotified = false;
};

// Step 1.1: central 403 signal. A 403 means "logged in, but not allowed" -
// the token stays, nothing is cleared, the user just gets one toast. Bursts of
// parallel 403s (e.g. a page firing several writes) collapse into one toast.
export const FORBIDDEN_EVENT = 'app:forbidden';
const FORBIDDEN_TOAST_THROTTLE_MS = 2000;
let lastForbiddenAt = 0;

export const notifyForbidden = () => {
  if (typeof window === 'undefined') return;
  const now = Date.now();
  if (now - lastForbiddenAt < FORBIDDEN_TOAST_THROTTLE_MS) return;
  lastForbiddenAt = now;
  window.dispatchEvent(new Event(FORBIDDEN_EVENT));
};
