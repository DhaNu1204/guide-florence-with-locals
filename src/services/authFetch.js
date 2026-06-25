import { notifySessionExpired } from './sessionExpiry';

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
export const authFetch = async (url, options = {}) => {
  const token = localStorage.getItem('token');
  const response = await fetch(url, {
    ...options,
    headers: {
      ...options.headers,
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (response.status === 401) {
    notifySessionExpired();
  }

  return response;
};

export default authFetch;
