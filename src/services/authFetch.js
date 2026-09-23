import { notifySessionExpired, notifyForbidden } from './sessionExpiry';
import { markRateLimited, markTimeout, markAutoRetry } from '../utils/perfBeacon'; // step 4.7/4.8: measurement only
import {
  fetchWithTimeout, timeoutFor, mayAutoRetry, isTransient, isOutcomeUnknown,
  notifyWriteUnknown, classifyError, httpError,
} from './netPolicy';

// Shared authenticated fetch wrapper.
//
// Adds the Bearer token from localStorage (the axios interceptor in mysqlDB.js
// only covers axios calls — raw fetch() needs this), and on an HTTP 401
// (expired/invalid session) triggers the global session-expiry flow
// (clear token + toast + redirect to /login). Use this instead of bare fetch()
// in any component/service that calls the API.
//
// The Response is still returned so existing callers (response.ok / .json())
// keep working unchanged.
//
// Step 4.8: every call has a timeout (netPolicy.timeoutFor, or options.timeoutMs). A read that
// times out, loses the connection or gets a 502/503/504 is tried once more; a write NEVER is.
// A write that ends that way throws an error with `outcomeUnknown = true` - a payment POST may
// already be recorded even though its answer was lost - and the app shows one plain toast
// about it (pass `quietUnknown: true` when the caller tells the user itself).
export const authFetch = async (url, options = {}) => {
  const { timeoutMs, quietUnknown, ...fetchOptions } = options;
  const method = String(fetchOptions.method || 'GET').toUpperCase();
  const ms = timeoutMs || timeoutFor(method, url);

  const once = () => {
    const token = localStorage.getItem('token');
    return fetchWithTimeout(url, {
      ...fetchOptions,
      headers: {
        ...fetchOptions.headers,
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    }, ms);
  };

  // A gateway status is a failed attempt for the retry decision, even though fetch resolved.
  const attempt = async () => {
    try {
      const response = await once();
      if (response.status >= 502 && response.status <= 504) {
        const e = httpError(response);
        e.response = response;
        throw e;
      }
      return response;
    } catch (error) {
      if (classifyError(error).kind === 'timeout') markTimeout();
      throw error;
    }
  };

  let response;
  try {
    response = await attempt();
  } catch (error) {
    if (mayAutoRetry(method, url) && isTransient(error)) {
      try {
        response = await attempt();
        markAutoRetry(true);
      } catch (retryError) {
        markAutoRetry(false);
        // A gateway answer on the retry is still an answer: hand it back like before.
        if (retryError.response) return retryError.response;
        throw retryError;
      }
    } else if (method !== 'GET' && method !== 'HEAD' && isOutcomeUnknown(error)) {
      if (!quietUnknown) notifyWriteUnknown();
      error.outcomeUnknown = true;
      throw error;
    } else if (error.response) {
      return error.response; // a read that may not be retried got a gateway status
    } else {
      throw error;
    }
  }

  // Step 4.7: observation only - the response is returned unchanged either way.
  if (response.status === 429) {
    try { markRateLimited(); } catch (e) { /* never matters */ }
  }

  if (response.status === 401) {
    notifySessionExpired();
  } else if (response.status === 403) {
    // Step 1.1: not allowed - one toast, token untouched (only 401 logs out).
    notifyForbidden();
  }

  return response;
};

export default authFetch;
