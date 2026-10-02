import React, { useState, useEffect, useCallback } from 'react';
import { authFetch } from '../services/authFetch';
import { describeLoadError, formatShownAt } from '../services/netPolicy';
import { markListStart, markListEnd } from '../utils/perfBeacon'; // measurement only

// Step 4.6 (moved forward 2026-10-02): the page the installed app opens on.
// ONE small request (today.php, today + tomorrow, a few KB), a plain list, no heavy components.
// The last good answer is kept in localStorage (its own key, not the saved-screens layer) and is
// shown at once on open and whenever a refresh fails, always with the time it was fetched.

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
export const TODAY_COPY_KEY = 'fwl_today_copy_v1';
const TIMEOUT_MS = 10000; // small answer: give up sooner than the 15 s list timeout (one auto retry)

export function readTodayCopy() {
  try {
    const raw = localStorage.getItem(TODAY_COPY_KEY);
    const copy = raw ? JSON.parse(raw) : null;
    return copy && copy.savedAt && copy.data && Array.isArray(copy.data.days) ? copy : null;
  } catch {
    return null;
  }
}

function writeTodayCopy(data) {
  try {
    localStorage.setItem(TODAY_COPY_KEY, JSON.stringify({ savedAt: Date.now(), data }));
  } catch {
    // storage full or blocked: the page still shows the fresh answer
  }
}

const romeDate = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(new Date());

function dayLabel(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const name = dt.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short' });
  if (ymd === romeDate()) return `Today · ${name}`;
  return `Tomorrow · ${name}`;
}

function Departure({ dep }) {
  return (
    <li className="py-3 border-b border-stone-200 last:border-b-0">
      <div className="flex items-baseline gap-3">
        <span className="font-semibold text-lg tabular-nums w-14 shrink-0">{dep.time}</span>
        <div className="min-w-0 flex-1">
          <div className="font-medium text-stone-900 leading-snug">{dep.title}</div>
          <div className="text-sm text-stone-600 mt-0.5">
            {dep.language || 'Unknown language'} · {dep.guests} {dep.guests === 1 ? 'guest' : 'guests'}
          </div>
          <div className={`text-sm mt-0.5 ${dep.guide ? 'text-stone-800' : 'text-red-700 font-semibold'}`}>
            {dep.guide || 'No guide'}
          </div>
          {dep.meeting_point && (
            <div className="text-sm text-stone-500 mt-0.5">Meet: {dep.meeting_point}</div>
          )}
        </div>
      </div>
    </li>
  );
}

export default function Today() {
  const [copy, setCopy] = useState(() => readTodayCopy()); // { savedAt, data }
  const [fresh, setFresh] = useState(false); // copy came from the server during this visit
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    markListStart();
    try {
      const res = await authFetch(`${API_BASE_URL}/today.php`, { timeoutMs: TIMEOUT_MS });
      if (res.status === 401) return; // authFetch already started the session-expired flow
      if (!res.ok) {
        const e = new Error(`HTTP ${res.status}`);
        e.response = res;
        throw e;
      }
      const body = await res.json();
      if (!body || !body.success || !body.data || !Array.isArray(body.data.days)) {
        throw new Error('The server sent an unexpected answer.');
      }
      writeTodayCopy(body.data);
      setCopy({ savedAt: Date.now(), data: body.data });
      setFresh(true);
      markListEnd(true);
    } catch (e) {
      markListEnd(false);
      setError(e);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const days = copy ? copy.data.days : [];

  return (
    <div className="max-w-2xl mx-auto px-4 py-4">
      <div className="flex items-center justify-between gap-3 mb-3">
        <h1 className="text-xl font-semibold text-stone-900">Today &amp; tomorrow</h1>
        <button
          type="button"
          onClick={load}
          disabled={loading}
          className="min-h-[44px] px-4 rounded-lg bg-terracotta-600 text-white font-medium disabled:opacity-60"
        >
          {loading ? 'Loading…' : 'Refresh'}
        </button>
      </div>

      {copy && (
        <p className="text-sm text-stone-500 mb-2" data-testid="today-stamp">
          {fresh ? 'Updated' : 'Saved copy from'} {formatShownAt(copy.savedAt)}
          {!fresh && loading ? ' · refreshing…' : ''}
        </p>
      )}

      {error && (
        <div role="alert" className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          {copy
            ? `Could not refresh — showing the copy from ${formatShownAt(copy.savedAt)}. `
            : 'Could not load the departures. '}
          {describeLoadError(error)}
        </div>
      )}

      {!copy && loading && <p className="text-stone-500">Loading departures…</p>}

      {days.map((day) => (
        <section key={day.date} className="mb-5">
          <h2 className="text-base font-semibold text-terracotta-700 border-b-2 border-terracotta-200 pb-1">
            {dayLabel(day.date)} · {day.departures.length} {day.departures.length === 1 ? 'departure' : 'departures'}
          </h2>
          {day.departures.length === 0 ? (
            <p className="py-3 text-stone-500">No departures.</p>
          ) : (
            <ul>{day.departures.map((dep) => <Departure key={dep.id} dep={dep} />)}</ul>
          )}
        </section>
      ))}
    </div>
  );
}
