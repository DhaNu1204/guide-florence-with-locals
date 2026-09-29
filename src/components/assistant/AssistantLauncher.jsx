// Step 7.4: the assistant's floating button - the only assistant code in the main bundle, kept
// small on purpose (main chunk budget +-5 KB). It renders nothing unless the user is an admin AND
// GET assistant.php?action=status says the assistant is on (cached for the browser session).
// The chat itself (AssistantPanel, incl. the desktop/phone switch) is a lazy chunk fetched the
// first time the button is pressed.
import React, { Suspense, useEffect, useState } from 'react';
import { FiMessageCircle } from 'react-icons/fi';
import { useAuth } from '../../contexts/AuthContext';
import { getAssistantStatus } from '../../services/assistantStatus';
import { shouldShowAssistant } from '../../utils/assistantVisibility';
import { useAssistantState, setAssistantState } from './assistantStore';
import lazyWithRetry from '../../utils/lazyWithRetry';

const AssistantPanel = lazyWithRetry(() => import('./AssistantPanel'));

const Bubble = ({ onClick, loading }) => (
  <button
    type="button"
    onClick={onClick}
    aria-label={loading ? 'Loading assistant' : 'Assistant'}
    title="Assistant"
    className="fixed right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-terracotta-500 text-white shadow-tuscan-lg hover:bg-terracotta-600 active:scale-95 touch-manipulation"
    style={{ bottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}
  >
    {loading
      ? <span className="h-6 w-6 animate-spin rounded-full border-2 border-white border-t-transparent" />
      : <FiMessageCircle className="text-2xl" />}
  </button>
);

export default function AssistantLauncher() {
  const { userRole, userName } = useAuth();
  const { open } = useAssistantState();
  const [status, setStatus] = useState(null);
  const [loaded, setLoaded] = useState(false); // the panel chunk has been asked for once

  useEffect(() => {
    let alive = true;
    // Only admins ask: a viewer's 403 would pop the app-wide "no permission" toast.
    if (userRole !== 'admin') { setStatus(null); return undefined; }
    getAssistantStatus(userName).then((st) => { if (alive) setStatus(st); });
    return () => { alive = false; };
  }, [userRole, userName]);

  const visible = shouldShowAssistant(userRole, status);
  // Access gone (logged out, another user, assistant switched off): never leave a drawer open.
  useEffect(() => {
    if (!visible && open) setAssistantState({ open: false });
  }, [visible, open]);

  if (!visible) return null;

  return (
    <>
      {!open && <Bubble onClick={() => { setLoaded(true); setAssistantState({ open: true }); }} />}
      {loaded && (
        <Suspense fallback={<Bubble loading />}>
          <AssistantPanel status={status} userName={userName} hidden={!open} onClose={() => setAssistantState({ open: false })} />
        </Suspense>
      )}
    </>
  );
}
