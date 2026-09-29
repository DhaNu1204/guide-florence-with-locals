// Step 7.4: the chat itself - loaded (React.lazy) only when the assistant button is first pressed.
// Desktop (>= 1024 px): a 468 px drawer on the right that pushes the page. Phone: a full-screen sheet.
// Stays mounted (hidden) after the first open, so a message queued while offline is still sent
// when the connection returns.
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { FiArrowLeft, FiX, FiEdit, FiSend, FiWifiOff, FiClock } from 'react-icons/fi';
import AssistantBlocks from './AssistantBlocks';
import {
  useAssistantState, setAssistantState, getAssistantState, resetConversation, nextMessageId,
} from './assistantStore';
import { askAssistant, listConversations, getConversation } from '../../services/assistantService';
import { assistantErrorMessage, firstNameOf, shortDay } from '../../utils/assistantUi';
import { ymdOf } from '../../utils/deepLinks';

const isOnline = () => (typeof navigator === 'undefined' || navigator.onLine !== false);

function useOnline() {
  const [online, setOnline] = useState(isOnline());
  useEffect(() => {
    const up = () => setOnline(true);
    const down = () => setOnline(false);
    window.addEventListener('online', up);
    window.addEventListener('offline', down);
    return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down); };
  }, []);
  return online;
}

const DESKTOP_QUERY = '(min-width: 1024px)'; // the layout pushes the page at the same breakpoint (lg)

function useIsDesktop() {
  const get = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(DESKTOP_QUERY).matches : false);
  const [desktop, setDesktop] = useState(get);
  useEffect(() => {
    if (!window.matchMedia) return undefined;
    const mq = window.matchMedia(DESKTOP_QUERY);
    const on = () => setDesktop(mq.matches);
    if (mq.addEventListener) mq.addEventListener('change', on); else if (mq.addListener) mq.addListener(on);
    return () => { if (mq.removeEventListener) mq.removeEventListener('change', on); else if (mq.removeListener) mq.removeListener(on); };
  }, []);
  return desktop;
}

export default function AssistantPanel({ status, userName, isDesktop: forceDesktop, hidden, onClose }) {
  const detectedDesktop = useIsDesktop();
  const isDesktop = typeof forceDesktop === 'boolean' ? forceDesktop : detectedDesktop;
  const s = useAssistantState();
  const online = useOnline();
  const [recent, setRecent] = useState(null);
  const listRef = useRef(null);
  const inputRef = useRef(null);

  const suggestions = [
    "Today's tours and guests",
    'Unassigned this week',
    'Who is free tomorrow morning?',
    ...(status && status.can_see_money ? ["Today's income"] : []),
  ];

  // --- sending -------------------------------------------------------------------------------
  const send = useCallback(async (raw, { fromDraft = false, fromQueue = false } = {}) => {
    const text = String(raw || '').trim();
    const st = getAssistantState();
    if (!text || st.sending) return;

    if (!isOnline()) {
      // Kept and sent once, automatically, when the connection returns.
      setAssistantState((p) => ({
        queued: text,
        draft: fromDraft ? '' : p.draft,
        error: null,
        messages: [...p.messages.filter((m) => !m.waiting), { id: nextMessageId(), role: 'user', text, waiting: true }],
      }));
      return;
    }

    const userMsgId = nextMessageId();
    setAssistantState((p) => ({
      sending: true,
      error: null,
      queued: fromQueue ? null : p.queued,
      messages: fromQueue
        ? p.messages.map((m) => (m.waiting ? { ...m, id: userMsgId, waiting: false } : m))
        : [...p.messages, { id: userMsgId, role: 'user', text }],
    }));
    const r = await askAssistant(text, getAssistantState().conversationId);
    if (r.ok) {
      setAssistantState((p) => ({
        sending: false,
        conversationId: r.data.conversation_id || p.conversationId,
        draft: fromDraft ? '' : p.draft,
        messages: [...p.messages, {
          id: nextMessageId(), role: 'assistant', text: String(r.data.text || ''),
          blocks: Array.isArray(r.data.blocks) ? r.data.blocks : [],
        }],
      }));
    } else {
      // One clear line; the typed text stays (or comes back) in the box.
      setAssistantState((p) => ({
        sending: false,
        error: r.error,
        draft: fromQueue ? text : p.draft,
        conversationId: r.error && r.error.code === 'conversation_not_found' ? null : p.conversationId,
        messages: p.messages.filter((m) => m.id !== userMsgId),
      }));
    }
  }, []);

  // A queued message goes out once, when the connection is back.
  useEffect(() => {
    if (online && s.queued && !s.sending) {
      send(s.queued, { fromQueue: true });
    }
  }, [online, s.queued, s.sending, send]);

  // --- history -------------------------------------------------------------------------------
  const loadRecent = useCallback(async () => {
    setRecent(null);
    setRecent(await listConversations());
  }, []);

  useEffect(() => {
    if (!hidden && s.messages.length === 0 && online) loadRecent();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hidden, s.messages.length === 0]);

  const openConversation = async (id) => {
    const msgs = await getConversation(id);
    if (!msgs) return;
    setAssistantState({
      conversationId: id,
      error: null,
      messages: msgs.map((m) => ({ id: nextMessageId(), role: m.role, text: m.text || '', blocks: m.blocks || [] })),
    });
  };

  const newChat = () => {
    resetConversation();
    if (inputRef.current) inputRef.current.focus();
  };

  // --- scrolling / focus -----------------------------------------------------------------------
  useEffect(() => {
    if (listRef.current) listRef.current.scrollTop = listRef.current.scrollHeight;
  }, [s.messages.length, s.sending]);

  useEffect(() => {
    if (!hidden && isDesktop && inputRef.current) inputRef.current.focus();
  }, [hidden, isDesktop]);

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      send(s.draft, { fromDraft: true });
    }
  };

  const followLink = () => { if (!isDesktop && onClose) onClose(); };
  const today = ymdOf(new Date());
  const errorLine = assistantErrorMessage(s.error);
  const first = firstNameOf(userName);

  // h-[100dvh]: the visible viewport (a phone's browser bars and the desktop window both), so the
  // input row is never pushed below the bottom edge.
  const shell = isDesktop
    ? 'fixed top-0 right-0 z-40 h-[100dvh] w-[468px] border-l border-stone-200 bg-white shadow-tuscan-xl'
    : 'fixed inset-x-0 top-0 z-[60] h-[100dvh] bg-white';

  return (
    <aside
      className={`${shell} flex flex-col ${hidden ? 'hidden' : ''}`}
      aria-label="Assistant"
      role="dialog"
      aria-modal={isDesktop ? 'false' : 'true'}
      style={isDesktop ? undefined : { paddingTop: 'env(safe-area-inset-top)', paddingBottom: 'env(safe-area-inset-bottom)' }}
    >
      {/* Header */}
      <div className="flex flex-shrink-0 items-center gap-2 border-b border-stone-200 px-3 py-2">
        {!isDesktop && (
          <button type="button" onClick={onClose} aria-label="Back"
            className="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-tuscan-lg hover:bg-stone-100 touch-manipulation">
            <FiArrowLeft className="text-xl text-stone-700" />
          </button>
        )}
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-bold text-stone-800">Assistant</h2>
          <p className="truncate text-xs text-stone-500">Reads live data · asks before changing anything</p>
        </div>
        <button type="button" onClick={newChat} aria-label="New chat" title="New chat"
          className="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-tuscan-lg hover:bg-stone-100 touch-manipulation">
          <FiEdit className="text-lg text-stone-600" />
        </button>
        {isDesktop && (
          <button type="button" onClick={onClose} aria-label="Close assistant" title="Close"
            className="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-tuscan-lg hover:bg-stone-100 touch-manipulation">
            <FiX className="text-xl text-stone-600" />
          </button>
        )}
      </div>

      {!online && (
        <div className="flex flex-shrink-0 items-center gap-2 border-b border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">
          <FiWifiOff className="flex-shrink-0" />
          <span className="flex-1">Offline — the assistant needs a connection</span>
          <Link to={`/tours?date=${today}`} onClick={followLink} className="whitespace-nowrap font-medium underline">
            Today&apos;s tours
          </Link>
        </div>
      )}

      {/* Messages */}
      {/* min-h-0: lets the list scroll inside the column instead of growing past it (which pushed the input off-screen) */}
      <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain bg-tuscan-gradient px-3 py-4">
        {s.messages.length === 0 ? (
          <div>
            <p className="text-lg font-semibold text-stone-800">{first ? `Hi ${first}` : 'Hi'} — what do you need?</p>
            <div className="mt-3 flex flex-col gap-2">
              {suggestions.map((q) => (
                <button key={q} type="button" onClick={() => send(q)} disabled={s.sending}
                  className="min-h-[44px] rounded-tuscan-lg border border-stone-200 bg-white px-3 py-2 text-left text-sm font-medium text-stone-700 hover:border-terracotta-300 hover:bg-terracotta-50 touch-manipulation">
                  {q}
                </button>
              ))}
            </div>
            {recent && recent.length > 0 && (
              <div className="mt-5">
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-stone-500">Recent</p>
                <ul className="divide-y divide-stone-100 rounded-tuscan border border-stone-200 bg-white">
                  {recent.map((c) => (
                    <li key={c.id}>
                      <button type="button" onClick={() => openConversation(c.id)}
                        className="flex min-h-[44px] w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-stone-50 touch-manipulation">
                        <span className="min-w-0 flex-1 truncate text-stone-700">{c.title || 'Conversation'}</span>
                        {c.last_at && <span className="whitespace-nowrap text-xs text-stone-400">{shortDay(c.last_at.slice(0, 10))}</span>}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        ) : (
          s.messages.map((m) => (m.role === 'user' ? (
            <div key={m.id} className="flex justify-end">
              <div className="max-w-[85%] rounded-tuscan-lg bg-stone-800 px-3 py-2 text-sm text-white">
                <p className="whitespace-pre-wrap break-words">{m.text}</p>
                {m.waiting && (
                  <p className="mt-1 flex items-center gap-1 text-xs text-stone-300"><FiClock /> waiting…</p>
                )}
              </div>
            </div>
          ) : (
            <div key={m.id} className="max-w-full">
              {m.text && <p className="whitespace-pre-wrap break-words text-sm text-stone-800">{m.text}</p>}
              <AssistantBlocks blocks={m.blocks} disabled={s.sending} onChoose={(t) => send(t)} onNavigate={followLink} />
            </div>
          )))
        )}
        {s.sending && (
          <p className="flex items-center gap-1 text-sm text-stone-500" role="status" aria-live="polite">
            <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-terracotta-400" /> Thinking…
          </p>
        )}
      </div>

      {/* Error + input */}
      {errorLine && (
        <p className="flex-shrink-0 border-t border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{errorLine}</p>
      )}
      <form
        className="flex flex-shrink-0 items-end gap-2 border-t border-stone-200 bg-white px-3 py-2"
        onSubmit={(e) => { e.preventDefault(); send(s.draft, { fromDraft: true }); }}
      >
        <textarea
          ref={inputRef}
          rows={1}
          value={s.draft}
          onChange={(e) => setAssistantState({ draft: e.target.value })}
          onKeyDown={onKeyDown}
          disabled={s.sending}
          maxLength={1000}
          placeholder="Ask about tours, guides…"
          aria-label="Message"
          className="max-h-32 min-h-[44px] flex-1 resize-none rounded-tuscan-lg border border-stone-300 px-3 py-2.5 text-sm focus:border-terracotta-400 focus:outline-none focus:ring-2 focus:ring-terracotta-200"
        />
        <button type="submit" aria-label="Send" disabled={s.sending || !s.draft.trim()}
          className="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-tuscan-lg bg-terracotta-500 text-white hover:bg-terracotta-600 disabled:opacity-50 touch-manipulation">
          <FiSend />
        </button>
      </form>
    </aside>
  );
}
