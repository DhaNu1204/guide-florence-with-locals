import React, { useState, useEffect, useCallback } from 'react';
import { FiChevronLeft, FiChevronRight, FiCopy, FiDownload, FiCheck, FiRadio } from 'react-icons/fi';
import { getRadioPlan, markRadioOrderSent } from '../services/mysqlDB';

// Step 6.3: the afternoon radio order for Vox Firenze.
// One line per departure, grouped by museum, guests only - the supplier adds the guide's
// transmitter itself. The message is editable before it is copied: last-minute additions are
// typed straight into the box.

const shiftDate = (dateStr, days) => {
  const [y, m, d] = dateStr.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  dt.setUTCDate(dt.getUTCDate() + days);
  return dt.toISOString().slice(0, 10);
};

const tomorrowInRome = () => {
  const rome = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(new Date());
  return shiftDate(rome, 1);
};

export default function Radios() {
  const [date, setDate] = useState(tomorrowInRome());
  const [plan, setPlan] = useState(null);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savedNote, setSavedNote] = useState(null);

  const load = useCallback(async (d) => {
    setLoading(true);
    setError(null);
    setCopied(false);
    setSavedNote(null);
    try {
      const res = await getRadioPlan(d);
      setPlan(res.data);
      setMessage(res.data.message);
    } catch (e) {
      setError(e?.response?.status === 403
        ? 'Admin access required for the radio order.'
        : 'Failed to load the radio order.');
      setPlan(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(date); }, [date, load]);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(message);
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    } catch (e) {
      setError('Could not copy — select the text and copy it by hand.');
    }
  };

  const download = () => {
    const blob = new Blob([message], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `radio-${date}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const markSent = async () => {
    setSaving(true);
    try {
      const res = await markRadioOrderSent({
        date,
        message,
        receivers_total: plan?.totals?.receivers ?? 0,
        transmitters_total: plan?.totals?.transmitters ?? 0,
      });
      setSavedNote(res.data);
      setPlan((p) => (p ? { ...p, last_order: res.data } : p));
    } catch (e) {
      setError(e?.response?.data?.error || 'Could not record that order.');
    } finally {
      setSaving(false);
    }
  };

  const totals = plan?.totals;
  const lastOrder = savedNote || plan?.last_order;
  const changedSinceSent = lastOrder && lastOrder.message_text !== message;

  return (
    <div className="p-4 md:p-6 max-w-full">
      <div className="flex flex-wrap items-center gap-3 mb-5">
        <h1 className="text-xl font-bold text-stone-800 flex items-center gap-2">
          <FiRadio className="text-terracotta-600" /> Radios
        </h1>
        <div className="flex items-center gap-1 ml-auto">
          <button onClick={() => setDate(shiftDate(date, -1))} className="p-2 rounded-lg border border-stone-300 bg-white" aria-label="Previous day">
            <FiChevronLeft />
          </button>
          <input
            type="date"
            value={date}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="border border-stone-300 rounded-tuscan px-3 py-2 text-sm"
            data-testid="radio-date"
          />
          <button onClick={() => setDate(shiftDate(date, 1))} className="p-2 rounded-lg border border-stone-300 bg-white" aria-label="Next day">
            <FiChevronRight />
          </button>
          <button onClick={() => setDate(tomorrowInRome())} className="ml-2 px-3 py-2 text-sm rounded-tuscan border border-stone-300 bg-white">
            Tomorrow
          </button>
        </div>
      </div>

      {error && (
        <div className="mb-4 rounded-tuscan border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
          {error}
        </div>
      )}

      {loading ? (
        <div className="bg-white rounded-xl shadow-tuscan p-10 text-center text-stone-500">Loading…</div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* The message, exactly as it will be sent */}
          <div className="bg-white rounded-xl shadow-tuscan p-4">
            <div className="flex items-center justify-between mb-2">
              <h2 className="text-base font-semibold text-stone-800">Message for Vox</h2>
              {totals && (
                <span className="text-xs text-stone-500">
                  {totals.departures} departures · {totals.receivers} receivers · {totals.transmitters} transmitters
                </span>
              )}
            </div>
            <p className="text-xs text-stone-400 mb-2">
              Edit anything you like before copying — the number on each line is guests only, Vox adds the guide&apos;s transmitter.
            </p>
            <textarea
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              rows={Math.max(12, message.split('\n').length + 2)}
              spellCheck={false}
              className="w-full font-mono text-sm border border-stone-300 rounded-tuscan px-3 py-2 focus:outline-none focus:ring-2 focus:ring-terracotta-500"
              data-testid="radio-message"
            />
            <div className="mt-3 flex flex-wrap gap-2">
              <button onClick={copy} className="inline-flex items-center gap-2 px-4 py-2 rounded-tuscan bg-terracotta-500 text-white text-sm font-semibold" data-testid="radio-copy">
                {copied ? <FiCheck /> : <FiCopy />} {copied ? 'Copied' : 'Copy'}
              </button>
              <button onClick={download} className="inline-flex items-center gap-2 px-4 py-2 rounded-tuscan border border-stone-300 bg-white text-sm">
                <FiDownload /> Download .txt
              </button>
              <button onClick={markSent} disabled={saving || !message.trim()} className="inline-flex items-center gap-2 px-4 py-2 rounded-tuscan border border-green-300 bg-green-50 text-green-800 text-sm font-medium disabled:opacity-50" data-testid="radio-mark-sent">
                {saving ? 'Saving…' : 'Mark as sent'}
              </button>
            </div>

            {lastOrder && (
              <div className="mt-3 rounded-tuscan border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600" data-testid="radio-last-order">
                <div className="font-semibold text-stone-700">
                  Already sent for this day{lastOrder.created_at ? ` at ${String(lastOrder.created_at).slice(11, 16)}` : ''} —
                  {' '}{lastOrder.receivers_total} receivers, {lastOrder.transmitters_total} transmitters
                </div>
                {changedSinceSent
                  ? <div className="text-terracotta-700 mt-0.5">The text above has changed since then.</div>
                  : <div className="text-stone-500 mt-0.5">The text above is what you sent.</div>}
                <pre className="mt-1 whitespace-pre-wrap text-[11px] text-stone-500">{lastOrder.message_text}</pre>
              </div>
            )}
          </div>

          {/* The detail behind every line */}
          <div className="bg-white rounded-xl shadow-tuscan p-4">
            <h2 className="text-base font-semibold text-stone-800 mb-2">What is behind each line</h2>
            {plan && plan.departures.length === 0 ? (
              <p className="text-sm text-stone-500">No guided departures on this day.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs uppercase text-stone-400 border-b border-stone-200">
                      <th className="py-1.5 pr-2">Museum</th>
                      <th className="py-1.5 pr-2">Time</th>
                      <th className="py-1.5 pr-2">Product</th>
                      <th className="py-1.5 pr-2">Guide</th>
                      <th className="py-1.5 text-right">PAX</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(plan?.departures || []).map((d) => (
                      <tr key={d.unit} className="border-b border-stone-100" data-testid="radio-row">
                        <td className="py-1.5 pr-2">
                          {d.museum}
                          {!d.museum_confident && (
                            <span className="ml-1 text-[10px] text-amber-700" title="Several museums in the title — I used the first one, where the group meets">?</span>
                          )}
                        </td>
                        <td className="py-1.5 pr-2 font-medium">{(d.time || '').slice(0, 5)}</td>
                        <td className="py-1.5 pr-2 text-stone-600">
                          <span className="block max-w-[22rem] truncate" title={d.title}>{d.title}</span>
                          {d.bookings > 1 && <span className="text-[11px] text-stone-400">{d.bookings} bookings</span>}
                        </td>
                        <td className="py-1.5 pr-2 text-stone-600">{d.guide_name || <span className="text-amber-700">no guide</span>}</td>
                        <td className="py-1.5 text-right font-semibold">{d.pax}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
