import React, { useCallback, useEffect, useState } from 'react';
import { FiRefreshCw } from 'react-icons/fi';
import authFetch from '../services/authFetch';
import { markListStart, markListEnd } from '../utils/perfBeacon'; // step 4.8: measurement only

/**
 * Step 4.7 — the reading end of the field instrumentation. Admin only.
 *
 * One row per page load recorded on a real phone, newest first, with the three phases drawn
 * as a bar so a stall is obvious at a glance: a phase that never finished runs to the right
 * edge in red instead of stopping. This page reads; it records nothing and changes nothing.
 */

const API = import.meta.env.VITE_API_URL || '/api';

// Every bar is drawn against the same scale so rows are comparable down the column.
const SCALE_MS = 10000;
const pct = (ms) => Math.max(0, Math.min(100, (ms / SCALE_MS) * 100));

const PHASES = [
  { key: 'verify', label: 'auth', colour: 'bg-sky-500' },
  { key: 'chunk', label: 'page', colour: 'bg-violet-500' },
  { key: 'list', label: 'list', colour: 'bg-olive-500' },
];

const fmtMs = (ms) => (ms === null || ms === undefined ? '—' : `${(ms / 1000).toFixed(1)}s`);

const PhaseBar = ({ row }) => (
  <div className="space-y-1 min-w-[260px]">
    {PHASES.map(({ key, label, colour }) => {
      const status = row[`${key}_status`];
      const start = row[`${key}_start`];
      const end = row[`${key}_end`];
      if (status === 'none' || start === null) {
        return (
          <div key={key} className="flex items-center gap-2 text-[11px] text-stone-400">
            <span className="w-8 shrink-0">{label}</span>
            <span>not on this page</span>
          </div>
        );
      }
      const pending = status === 'pending';
      const failed = status === 'failed';
      const left = pct(start);
      const width = pending ? 100 - left : Math.max(1, pct(end) - left);
      return (
        <div key={key} className="flex items-center gap-2">
          <span className="w-8 shrink-0 text-[11px] text-stone-500">{label}</span>
          <div className="relative h-3 flex-1 bg-stone-100 rounded">
            <div
              className={`absolute top-0 h-3 rounded ${pending ? 'bg-red-500' : failed ? 'bg-amber-500' : colour}`}
              style={{ left: `${left}%`, width: `${width}%` }}
              title={`${label}: ${status}`}
            />
          </div>
          <span className={`w-20 shrink-0 text-[11px] ${pending ? 'text-red-600 font-semibold' : 'text-stone-500'}`}>
            {pending ? 'never finished' : failed ? 'failed' : fmtMs(end)}
          </span>
        </div>
      );
    })}
  </div>
);

const ClientPerf = () => {
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState(null);
  const [day, setDay] = useState('');
  const [unfinished, setUnfinished] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    markListStart(); // step 4.8: the page's own data fetch, for the field recorder
    try {
      const qs = new URLSearchParams({ action: 'list' });
      if (day) qs.set('date', day);
      if (unfinished) qs.set('unfinished', '1');
      const res = await authFetch(`${API}/client_perf.php?${qs.toString()}`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      setRows(json?.data?.rows || []);
      setSummary(json?.data?.summary || null);
      markListEnd(true);
    } catch (e) {
      markListEnd(false);
      setError(e.message || 'Could not load the measurements');
      setRows([]);
      setSummary(null);
    } finally {
      setLoading(false);
    }
  }, [day, unfinished]);

  useEffect(() => { load(); }, [load]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-stone-900">Load measurements</h1>
          <p className="text-sm text-stone-500">
            One row per page load from a real phone. Recording only — this changes nothing about the app.
          </p>
        </div>
        <button
          onClick={load}
          className="inline-flex items-center gap-2 px-4 py-2 border border-stone-300 rounded-tuscan text-stone-700 hover:bg-stone-50"
        >
          <FiRefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} /> Reload
        </button>
      </div>

      {summary && (
        <div className="bg-white border border-stone-200 rounded-tuscan p-4 text-sm" data-testid="perf-summary">
          <span className="font-semibold">{summary.loads}</span> loads on {summary.day} ·
          {' '}median to list <span className="font-semibold">{fmtMs(summary.median_to_list)}</span> ·
          {' '}worst <span className="font-semibold">{fmtMs(summary.worst_to_list)}</span> ·
          {' '}<span className={summary.unfinished > 0 ? 'text-red-600 font-semibold' : ''}>
            {summary.unfinished} never finished
          </span>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <label className="text-sm text-stone-700">
          Date{' '}
          <input
            type="date"
            value={day}
            onChange={(e) => setDay(e.target.value)}
            className="px-2 py-1.5 border border-stone-300 rounded-tuscan text-sm"
          />
        </label>
        <label className="text-sm text-stone-700 inline-flex items-center gap-2">
          <input
            type="checkbox"
            checked={unfinished}
            onChange={(e) => setUnfinished(e.target.checked)}
            data-testid="perf-unfinished-filter"
          />
          Only loads that did not finish
        </label>
        {day && (
          <button onClick={() => setDay('')} className="text-sm text-terracotta-600">clear date</button>
        )}
      </div>

      {error && (
        <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-tuscan px-3 py-2">{error}</div>
      )}

      <div className="bg-white border border-stone-200 rounded-tuscan overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-stone-50 text-stone-600">
            <tr>
              <th className="px-3 py-2 text-left font-medium">When</th>
              <th className="px-3 py-2 text-left font-medium">Who / page</th>
              <th className="px-3 py-2 text-left font-medium">Phases (0–10s)</th>
              <th className="px-3 py-2 text-left font-medium">Connection</th>
              <th className="px-3 py-2 text-left font-medium">Notes</th>
            </tr>
          </thead>
          <tbody data-testid="perf-rows">
            {rows.length === 0 && !loading && (
              <tr><td colSpan={5} className="px-3 py-6 text-center text-stone-500">No measurements yet.</td></tr>
            )}
            {rows.map((r) => {
              const stalled = ['verify', 'chunk', 'list'].some((k) => r[`${k}_status`] === 'pending');
              return (
                <tr key={r.id} className={`border-t border-stone-100 ${stalled ? 'bg-red-50' : ''}`}>
                  <td className="px-3 py-2 whitespace-nowrap text-stone-700">{String(r.created_at).slice(5, 16)}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    <div className="text-stone-800">{r.username || `user ${r.user_id}`}</div>
                    <div className="text-[11px] text-stone-500">{r.route}</div>
                  </td>
                  <td className="px-3 py-2"><PhaseBar row={r} /></td>
                  <td className="px-3 py-2 whitespace-nowrap text-[11px] text-stone-600">
                    <div>{r.effective_type || 'unknown'}{r.conn_rtt !== null ? ` · ${r.conn_rtt}ms` : ''}</div>
                    <div>{r.device}</div>
                    {!r.online && <div className="text-red-600">offline</div>}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-[11px] text-stone-600">
                    {r.first_after_release === 1 && (
                      <span className="inline-block px-2 py-0.5 rounded-full bg-gold-100 text-gold-800">after deploy</span>
                    )}
                    {r.sw_controlled === 1 && <div>service worker</div>}
                    {r.rate_limited > 0 && <div className="text-red-600">{r.rate_limited}× rate limited</div>}
                    {/* Step 4.8: how a bad load resolved */}
                    {r.shell_fallback === 1 && <div className="text-red-600">started from cached page</div>}
                    {r.verify_error && <div className="text-red-600">login check: {r.verify_error}{r.verify_retry && r.verify_retry !== 'none' ? ` (re-check ${r.verify_retry})` : ''}</div>}
                    {r.timeouts > 0 && <div className="text-red-600">{r.timeouts}× timed out</div>}
                    {r.auto_retries > 0 && <div>auto-retry {r.auto_retry_ok}/{r.auto_retries} ok</div>}
                    {r.user_retries > 0 && <div>Retry pressed {r.user_retries}×</div>}
                    <div className="text-stone-400">sent: {r.reason}</div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default ClientPerf;
