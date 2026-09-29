// Step 7.4: the assistant API (api/assistant.php). Every call goes through authFetch (Bearer token,
// 401 -> session expiry). Errors come back as { kind, status, code } for assistantErrorMessage().
import { authFetch } from './authFetch';
import mysqlDB, { tourGroupsAPI } from './mysqlDB';

export { getAssistantStatus } from './assistantStatus'; // kept for callers of this module

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
const URL_ = `${API_BASE_URL}/assistant.php`;

// A question can take up to ~40 s on the server (tool loop budget) - longer than the 30 s write
// timeout of netPolicy. The chat shows its own error line, so no global "may be saved" toast.
const ASK_TIMEOUT_MS = 55000;

const toError = async (res) => {
  let code = null;
  try {
    const j = await res.json();
    code = j && j.error ? j.error : null;
  } catch (_) { /* not JSON */ }
  return { kind: 'http', status: res.status, code };
};

const networkError = () => ({ kind: 'network', status: 0, code: null });

/** POST a question. Resolves { ok: true, data } or { ok: false, error }. */
export const askAssistant = async (message, conversationId) => {
  try {
    const res = await authFetch(URL_, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, conversation_id: conversationId || null }),
      timeoutMs: ASK_TIMEOUT_MS,
      quietUnknown: true,
    });
    if (!res.ok) return { ok: false, error: await toError(res) };
    return { ok: true, data: await res.json() };
  } catch (_) {
    return { ok: false, error: networkError() };
  }
};

export const listConversations = async () => {
  try {
    const res = await authFetch(`${URL_}?action=conversations`);
    if (!res.ok) return [];
    const j = await res.json();
    return Array.isArray(j.conversations) ? j.conversations : [];
  } catch (_) {
    return [];
  }
};

export const getConversation = async (id) => {
  try {
    const res = await authFetch(`${URL_}?action=conversation&id=${encodeURIComponent(id)}`);
    if (!res.ok) return null;
    const j = await res.json();
    return Array.isArray(j.messages) ? j.messages : null;
  } catch (_) {
    return null;
  }
};

// ---- Step 7.5: the confirm card ----------------------------------------------------------------
// The change itself goes through the SAME calls the Tours page uses (tour-groups.php for a group,
// tours.php for a single tour) with the user's own token, so role checks, guide propagation to the
// group's tours and logging are unchanged. Both clear the local tour cache, so Tours reloads fresh.

const saveError = (e) => {
  const status = e && e.response ? e.response.status : (e && e.status) || 0;
  const data = e && e.response && e.response.data ? e.response.data : {};
  return {
    status,
    code: (e && e.code && status === 409 ? e.code : null) || data.error || null,
    conflict: (e && e.conflict) || data.conflict || null,
  };
};

/**
 * Set the departure's guide. expectedPrev = the guide the card showed (null = no guide); the
 * endpoint answers 409 departure_changed if that is no longer true.
 * @returns {Promise<{ok:true}|{ok:false,status,code,conflict}>}
 */
export const saveDepartureGuide = async (departure, guideId, expectedPrev, { force = false } = {}) => {
  const body = { guide_id: guideId, expected_previous_guide_id: expectedPrev };
  try {
    if (departure.type === 'group') {
      await tourGroupsAPI.update(departure.id, body);
    } else {
      await mysqlDB.updateTour(departure.id, force ? { ...body, force: true } : body);
    }
    return { ok: true };
  } catch (e) {
    return { ok: false, ...saveError(e) };
  }
};

const postAction = async (action, payload) => {
  try {
    const res = await authFetch(`${URL_}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      quietUnknown: true,
    });
    const j = await res.json().catch(() => ({}));
    return res.ok ? { ok: true, data: j } : { ok: false, status: res.status, code: j && j.error ? j.error : null };
  } catch (_) {
    return { ok: false, status: 0, code: null };
  }
};

/** After a successful save: audit row + (if ticked and allowed) the WhatsApp to the new guide. */
export const reportAssignDone = (payload) => postAction('assign_done', payload);

/** After the previous guide was put back: marks the action undone + adds the undo row. */
export const reportUndoDone = (actionId) => postAction('undo_done', { action_id: actionId });
