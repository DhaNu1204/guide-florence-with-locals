// Step 7.4: the chat's state lives outside React components, so opening another page (which
// re-renders the layout) never loses the conversation, the draft or a queued offline message.
import { useSyncExternalStore } from 'react';

const initial = {
  open: false,
  conversationId: null,
  messages: [], // { id, role: 'user'|'assistant', text, blocks, waiting? }
  draft: '',
  sending: false,
  error: null, // { kind, status, code }
  queued: null, // text typed while offline, sent once when the connection returns
};

let state = { ...initial };
const listeners = new Set();

export const getAssistantState = () => state;

export const setAssistantState = (patch) => {
  state = { ...state, ...(typeof patch === 'function' ? patch(state) : patch) };
  listeners.forEach((l) => l());
};

export const resetConversation = () =>
  setAssistantState({ conversationId: null, messages: [], error: null, queued: null, sending: false });

const subscribe = (l) => {
  listeners.add(l);
  return () => listeners.delete(l);
};

export const useAssistantState = () => useSyncExternalStore(subscribe, getAssistantState, getAssistantState);

let seq = 0;
export const nextMessageId = () => `m${Date.now()}-${++seq}`;

/** Tests only. */
export const __resetAssistantStore = () => {
  state = { ...initial };
  listeners.forEach((l) => l());
};
