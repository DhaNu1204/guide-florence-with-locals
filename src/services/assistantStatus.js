// Step 7.4: GET assistant.php?action=status - the only assistant call the app makes at start, and
// the only assistant service code in the main bundle (the chat's calls live in assistantService.js,
// loaded with the chat). Cached for the browser session, per user.
import { authFetch } from './authFetch';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
const STATUS_KEY = 'fwl:assistant-status';
const OFF = { enabled: false, can_see_money: false };

/**
 * {enabled, can_see_money}. Callers must only ask for admins: a viewer's 403 would raise the
 * app-wide "no permission" toast.
 */
export const getAssistantStatus = async (userName) => {
  const key = `${STATUS_KEY}:${userName || ''}`;
  try {
    const cached = sessionStorage.getItem(key);
    if (cached) return JSON.parse(cached);
  } catch (_) { /* storage blocked: just ask */ }
  try {
    const res = await authFetch(`${API_BASE_URL}/assistant.php?action=status`);
    if (!res.ok) return OFF;
    const j = await res.json();
    const status = { enabled: j.enabled === true, can_see_money: j.can_see_money === true };
    try { sessionStorage.setItem(key, JSON.stringify(status)); } catch (_) { /* ignore */ }
    return status;
  } catch (_) {
    return OFF; // not cached: asked again on the next page load
  }
};
