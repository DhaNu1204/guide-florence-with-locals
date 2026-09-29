// Step 7.4: the assistant API (api/assistant.php). Every call goes through authFetch (Bearer token,
// 401 -> session expiry). Errors come back as { kind, status, code } for assistantErrorMessage().
import { authFetch } from './authFetch';

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
