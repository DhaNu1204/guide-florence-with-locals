// Step 7.5: the assistant's "assign guide" confirm card. Built by the server (propose_assignment),
// never by the model. Nothing changes until Confirm; Confirm and Undo go through the same endpoints
// the Tours page uses (assistantService.saveDepartureGuide), then report back for the audit row
// and the optional WhatsApp. A card is only good for 10 minutes after it was issued.
import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { FiArrowRight, FiCheck, FiAlertTriangle } from 'react-icons/fi';
import { buildAssistantLink, shortDay } from '../../utils/assistantUi';
import { saveDepartureGuide, reportAssignDone, reportUndoDone } from '../../services/assistantService';

const isStr = (v) => typeof v === 'string' && v.trim() !== '';
const isId = (v) => Number.isInteger(v) && v > 0;
const nullableId = (v) => v === null || isId(v);

/** Shape check - a malformed card is skipped, never half-shown. */
export const validConfirmAssign = (b) => !!b && b.type === 'confirm_assign'
  && !!b.departure && isStr(b.departure.departure_id) && isId(b.departure.id)
  && (b.departure.type === 'group' || b.departure.type === 'single')
  && isStr(b.departure.date) && isStr(b.departure.time) && isStr(b.departure.title)
  && !!b.guide && isId(b.guide.id) && isStr(b.guide.name)
  && nullableId(b.previous_guide_id) && Array.isArray(b.checks);

/** When the card stops accepting Confirm (ms since epoch). issued_at is UTC ISO from the server. */
export const cardExpiresAt = (block, fallbackNow = Date.now()) => {
  const issued = Date.parse(block && block.issued_at);
  const ttl = block && Number.isFinite(block.expires_in) ? block.expires_in : 600;
  return (Number.isFinite(issued) ? issued : fallbackNow) + ttl * 1000;
};

const ERRORS = {
  403: 'Only an admin can change assignments.',
  0: 'No connection - nothing was saved. Try again.',
};

function CheckLine({ level, children }) {
  const warn = level === 'warn';
  return (
    <li className={`flex items-start gap-1.5 text-sm ${warn ? 'text-amber-800' : 'text-green-800'}`}>
      {warn ? <FiAlertTriangle className="mt-0.5 shrink-0" aria-label="warning" /> : <FiCheck className="mt-0.5 shrink-0" aria-label="ok" />}
      <span>{children}</span>
    </li>
  );
}

export default function ConfirmAssignCard({ block, onChoose, onNavigate, disabled = false }) {
  const dep = block.departure;
  const guide = block.guide;
  const prev = block.previous_guide_id;
  const wa = block.whatsapp && typeof block.whatsapp === 'object' ? block.whatsapp : {};
  const clash = Array.isArray(block.clash) ? block.clash : [];
  const alternatives = Array.isArray(block.alternatives) ? block.alternatives.filter((a) => a && isStr(a.name)) : [];

  const [expiresAt] = useState(() => cardExpiresAt(block));
  const [expired, setExpired] = useState(() => Date.now() >= expiresAt);
  // idle | saving | done | undoing | undone | cancelled | changed | conflict | error
  const [phase, setPhase] = useState('idle');
  const [message, setMessage] = useState('');
  const [sendWa, setSendWa] = useState(!!wa.offer && !!wa.default && !wa.disabled_reason);
  const [result, setResult] = useState(null); // assign_done answer
  const [undoResult, setUndoResult] = useState(null);

  useEffect(() => {
    if (expired) return undefined;
    const t = setTimeout(() => setExpired(true), Math.max(0, expiresAt - Date.now()));
    return () => clearTimeout(t);
  }, [expired, expiresAt]);

  const confirm = async (force = false) => {
    setPhase('saving');
    setMessage('');
    // A clash the card already showed is the user's call: confirm means "assign anyway".
    const res = await saveDepartureGuide(dep, guide.id, prev, { force: force || clash.length > 0 });
    if (!res.ok) {
      if (res.status === 409 && res.code === 'departure_changed') {
        setPhase('changed');
      } else if (res.status === 409 && res.code === 'guide_double_booked') {
        setPhase('conflict');
        const c = res.conflict || {};
        setMessage(`${guide.name} is already on another tour at that time${isStr(c.title) ? ` (${c.time ? `${c.time} ` : ''}${c.title})` : ''}.`);
      } else {
        setPhase('error');
        setMessage(ERRORS[res.status] || 'Not saved - something went wrong. Try again.');
      }
      return;
    }
    const rep = await reportAssignDone({
      departure_id: dep.departure_id, from_guide_id: prev, to_guide_id: guide.id,
      send_whatsapp: !!(wa.offer && sendWa && !wa.disabled_reason),
    });
    setResult(rep.ok ? rep.data : { at: new Date().toTimeString().slice(0, 5), by: null, not_logged: true });
    setPhase('done');
  };

  const undo = async () => {
    setPhase('undoing');
    setMessage('');
    // Back to exactly what the card showed; refused (409) if someone changed it since.
    const res = await saveDepartureGuide(dep, prev, guide.id, { force: true });
    if (!res.ok) {
      setPhase('done');
      setMessage(res.status === 409 && res.code === 'departure_changed'
        ? 'Not undone - the departure was changed again since. Check it in Tours.'
        : (ERRORS[res.status] || 'Not undone - something went wrong. Try again.'));
      return;
    }
    const rep = result && result.action_id ? await reportUndoDone(result.action_id) : { ok: false };
    setUndoResult(rep.ok ? rep.data : { at: new Date().toTimeString().slice(0, 5) });
    setPhase('undone');
  };

  const busy = phase === 'saving' || phase === 'undoing';
  const open = phase === 'idle' || phase === 'error' || phase === 'conflict';
  const locked = expired && open;
  const toursLink = buildAssistantLink({ route: '/tours', query: { date: dep.date } });
  const languages = Array.isArray(dep.languages) ? dep.languages.filter(isStr) : [];
  const nowGuide = isStr(dep.current_guide_name) ? dep.current_guide_name : 'no guide';

  return (
    <div className="rounded-tuscan border-2 border-terracotta-200 bg-white p-3" data-testid="assistant-confirm-assign">
      <div className="text-[11px] font-bold uppercase tracking-wider text-terracotta-700">Change · Assign guide</div>
      <div className="mt-1 text-base font-bold text-stone-900">{guide.name}</div>
      <div className="mt-0.5 text-sm text-stone-800">
        <span className="font-semibold">{shortDay(dep.date)} · {dep.time}</span>
        {languages.length > 0 && <span> · {languages.join(', ')}</span>}
        <span className="block truncate text-stone-600">{dep.title}</span>
      </div>
      <div className="mt-0.5 text-xs text-stone-500">
        {Number(dep.guests) || 0} guests in {Number(dep.bookings) || 0} bookings · now: {nowGuide}
      </div>

      {block.checks.length > 0 && (
        <ul className="mt-2 space-y-1" data-testid="assistant-confirm-checks">
          {block.checks.filter((c) => c && isStr(c.text)).map((c, i) => <CheckLine key={i} level={c.level}>{c.text}</CheckLine>)}
        </ul>
      )}

      {clash.length > 0 && alternatives.length > 0 && open && !locked && (
        <div className="mt-2" data-testid="assistant-confirm-alternatives">
          <p className="text-xs text-stone-600">Free at that time:</p>
          <div className="mt-1 flex flex-wrap gap-2">
            {alternatives.map((a) => (
              <button
                key={a.guide_id || a.name}
                type="button"
                disabled={disabled || busy}
                onClick={() => onChoose && onChoose(`Assign ${a.name} to the ${dep.time} ${dep.title} on ${dep.date} instead`)}
                className="min-h-[44px] rounded-tuscan-lg border-2 border-terracotta-200 bg-white px-3 py-2 text-sm font-medium text-terracotta-700 hover:bg-terracotta-50 disabled:opacity-50 touch-manipulation"
              >
                {a.name}
              </button>
            ))}
          </div>
        </div>
      )}

      {open && !locked && (
        wa.offer ? (
          <label className={`mt-2 flex min-h-[44px] items-center gap-2 text-sm ${wa.disabled_reason ? 'text-stone-400' : 'text-stone-700'}`}>
            <input
              type="checkbox"
              className="h-5 w-5 accent-terracotta-600"
              checked={sendWa && !wa.disabled_reason}
              disabled={!!wa.disabled_reason || busy}
              onChange={(e) => setSendWa(e.target.checked)}
              data-testid="assistant-confirm-whatsapp"
            />
            <span>
              Send WhatsApp to {guide.name.split(' ')[0]} now
              {wa.disabled_reason && <span className="block text-xs">{wa.disabled_reason}</span>}
            </span>
          </label>
        ) : (isStr(wa.note) && <p className="mt-2 text-xs text-stone-500" data-testid="assistant-confirm-wa-note">{wa.note}</p>)
      )}

      {message && <p className={`mt-2 text-sm ${phase === 'done' ? 'text-amber-800' : 'text-red-700'}`} role="alert">{message}</p>}

      {phase === 'changed' && (
        <div className="mt-2 rounded-tuscan bg-amber-50 p-2 text-sm text-amber-900" role="alert">
          This departure changed since the card was shown - nothing was saved.
          <button
            type="button"
            disabled={disabled}
            onClick={() => onChoose && onChoose(`Show the ${dep.time} ${dep.title} on ${dep.date} again and propose ${guide.name}`)}
            className="ml-2 font-semibold text-terracotta-700 underline"
          >
            Reload card
          </button>
        </div>
      )}

      {locked && (
        <div className="mt-2 text-sm text-stone-600" data-testid="assistant-confirm-expired">
          This card expired (10 minutes).{' '}
          <button
            type="button"
            disabled={disabled}
            onClick={() => onChoose && onChoose(`Assign ${guide.name} to the ${dep.time} ${dep.title} on ${dep.date}`)}
            className="font-semibold text-terracotta-700 underline"
          >
            Ask again
          </button>
        </div>
      )}

      {phase === 'cancelled' && <p className="mt-2 text-sm text-stone-500">Cancelled - nothing changed.</p>}

      {(phase === 'done' || phase === 'undoing') && result && (
        <div className="mt-2 rounded-tuscan bg-green-50 p-2 text-sm text-green-900" data-testid="assistant-confirm-done">
          <div className="flex items-center gap-1.5 font-semibold">
            <FiCheck /> {guide.name} assigned · {result.at}{result.by ? ` by ${result.by}` : ''}
          </div>
          {result.whatsapp && isStr(result.whatsapp.result) && (
            <div className="mt-0.5 text-xs text-green-800" data-testid="assistant-confirm-wa-result">WhatsApp: {result.whatsapp.result}</div>
          )}
          {result.not_logged && <div className="mt-0.5 text-xs text-amber-800">Saved, but the assistant log could not be written.</div>}
        </div>
      )}

      {phase === 'undone' && (
        <div className="mt-2 rounded-tuscan bg-stone-100 p-2 text-sm text-stone-700" data-testid="assistant-confirm-undone">
          Undone · {undoResult && undoResult.at}{undoResult && undoResult.by ? ` by ${undoResult.by}` : ''} - back to {nowGuide}.
        </div>
      )}

      <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
        {open && !locked && (
          <>
            <button
              type="button"
              disabled={busy}
              onClick={() => setPhase('cancelled')}
              className="min-h-[44px] rounded-tuscan-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 touch-manipulation"
            >
              Cancel
            </button>
            <button
              type="button"
              disabled={busy || disabled}
              onClick={() => confirm(phase === 'conflict')}
              className="min-h-[44px] rounded-tuscan-lg bg-terracotta-600 px-4 py-2 text-sm font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50 touch-manipulation"
            >
              {phase === 'conflict' ? 'Assign anyway' : 'Confirm assignment'}
            </button>
          </>
        )}
        {busy && <span className="text-sm text-stone-500">{phase === 'saving' ? 'Saving…' : 'Undoing…'}</span>}
        {phase === 'done' && (
          <button
            type="button"
            onClick={undo}
            className="min-h-[44px] rounded-tuscan-lg border-2 border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 touch-manipulation"
          >
            Undo
          </button>
        )}
        {(phase === 'done' || phase === 'undone') && toursLink && (
          <Link
            to={toursLink}
            onClick={() => onNavigate && onNavigate(toursLink)}
            className="inline-flex min-h-[44px] items-center gap-1.5 rounded-full bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 touch-manipulation"
          >
            Open in Tours <FiArrowRight />
          </Link>
        )}
      </div>
    </div>
  );
}
