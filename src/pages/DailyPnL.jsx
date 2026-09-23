import React, { useState, useEffect, useCallback } from 'react';
import {
  FiChevronLeft, FiChevronRight, FiSettings, FiTrendingUp, FiTrendingDown,
  FiCalendar, FiX, FiRotateCcw
} from 'react-icons/fi';
import {
  getPnlDay, getPnlRange, getPnlSettings, savePnlSettings, savePnlCosts,
  mergePnlUnits, unmergePnlUnits // step 6.2
} from '../services/mysqlDB';
import { markListStart, markListEnd } from '../utils/perfBeacon'; // step 4.8: measurement only

const eur = (v) =>
  '€' + Number(v || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const todayStr = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const shiftDate = (dateStr, days) => {
  const d = new Date(dateStr + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Monday of the week containing dateStr
const mondayOf = (dateStr) => {
  const d = new Date(dateStr + 'T12:00:00');
  return shiftDate(dateStr, -((d.getDay() + 6) % 7));
};

const shortDate = (dateStr) =>
  new Date(dateStr + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });

const COST_FIELDS = [
  { key: 'ticket_cost', label: 'Tickets' },
  { key: 'guide_cost', label: 'Guide' },
  { key: 'radio_cost', label: 'Radio' },
  { key: 'gelato_cost', label: 'Gelato' },
  { key: 'staff_cost', label: 'Staff' },
  { key: 'other_cost', label: 'Other' }
];

const SETTING_GROUPS = [
  {
    title: 'Guide pay per tour (€60/h — hours vary by tour type)',
    keys: [
      ['guide_rate_combo', 'Combo tour (shared, 3.5h)'],
      ['guide_rate_uffizi', 'Uffizi tour'],
      ['guide_rate_accademia', 'Accademia tour'],
      ['guide_rate_pitti', 'Pitti tour'],
      ['guide_rate_other', 'Other tour'],
      ['guide_rate_private_combo', 'Private Combo (4h)'],
      ['guide_rate_private_uffizi', 'Private Uffizi (2h)'],
      ['guide_rate_private_accademia', 'Private Accademia (1.5h)'],
      ['guide_rate_private_pitti', 'Private Pitti (2h)'],
      ['guide_rate_private_other', 'Private other (2h)']
    ]
  },
  {
    title: 'Museum ticket cost per person (what you pay)',
    keys: [
      ['ticket_uffizi_adult', 'Uffizi — adult'],
      ['ticket_uffizi_child', 'Uffizi — child/reduced'],
      ['ticket_uffizi_adult_pm', 'Uffizi from 16:00 — adult'],
      ['ticket_uffizi_child_pm', 'Uffizi from 16:00 — child'],
      ['ticket_accademia_adult', 'Accademia — adult'],
      ['ticket_accademia_child', 'Accademia — child/reduced'],
      ['ticket_pitti_adult', 'Pitti — adult'],
      ['ticket_pitti_child', 'Pitti — child/reduced'],
      ['ticket_borghese_adult', 'Borghese — adult'],
      ['ticket_borghese_child', 'Borghese — child (no child ticket = adult price)']
    ]
  },
  {
    title: 'Per-person extras & agency fee',
    keys: [
      ['radio_per_person', 'Radio / headset per person'],
      ['gelato_per_person', 'Gelato per person (gelato tours only)'],
      ['outsource_fee', 'Given-to-agency fee (per booking)']
    ]
  },
  {
    title: 'Monthly overheads (not per tour)',
    keys: [
      ['staff_monthly', 'Staff salary / month'],
      ['office_monthly', 'Office / month'],
      ['other_monthly', 'Other fixed / month']
    ]
  },
  {
    title: 'Card processing fee — direct sales',
    keys: [
      ['fee_card_direct', 'Card processing fee — direct sales (%)']
    ]
  },
  {
    title: 'Fallback commission % (only used when Bokun has no invoice data)',
    keys: [
      ['comm_getyourguide', 'GetYourGuide %'],
      ['comm_viator', 'Viator %'],
      ['comm_airbnb', 'Airbnb %'],
      ['comm_headout', 'Headout %'],
      ['comm_default', 'Other channels %']
    ]
  }
];

// ---------------------------------------------------------------------------
// Category styling + ordering for the day view sections
// ---------------------------------------------------------------------------
const CATEGORY_ORDER = ['Combo', 'Uffizi', 'Accademia', 'Pitti', 'Borghese', 'Mixed', 'Other'];
const CATEGORY_BADGE = {
  Combo: 'bg-amber-100 text-amber-800',
  Uffizi: 'bg-emerald-100 text-emerald-800',
  Accademia: 'bg-blue-100 text-blue-800',
  Pitti: 'bg-rose-100 text-rose-800',
  Borghese: 'bg-teal-100 text-teal-800',
  Mixed: 'bg-amber-100 text-amber-800',
  Other: 'bg-stone-100 text-stone-600'
};

// ---------------------------------------------------------------------------
// Inline editable money chip — shows auto value; click to override; ↺ resets
// ---------------------------------------------------------------------------
function EditableChip({ row, field, label, value, autoValue, overridden, onSave, strong = false, unknown = false }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');

  const startEdit = () => {
    setDraft(value === 0 ? '' : String(value));
    setEditing(true);
  };

  const commit = async () => {
    setEditing(false);
    const trimmed = draft.trim();
    if (trimmed === '') return;
    const num = parseFloat(trimmed);
    if (isNaN(num) || num === value) return;
    await onSave(row, field, num);
  };

  const reset = async (e) => {
    e.stopPropagation();
    await onSave(row, field, null); // null clears the override -> back to auto
  };

  if (editing) {
    return (
      <span
        onClick={(e) => e.stopPropagation()}
        className="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-terracotta-400 bg-white text-xs text-stone-600"
      >
        {label}
        <input
          autoFocus
          type="number"
          step="0.01"
          min="0"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === 'Enter') commit();
            if (e.key === 'Escape') setEditing(false);
          }}
          className="w-16 px-1 border-0 text-right text-xs focus:outline-none"
        />
      </span>
    );
  }

  return (
    <button
      onClick={(e) => { e.stopPropagation(); startEdit(); }}
      title={unknown
        ? 'Not known for a tour added by hand - click to enter the real cost.'
        : (overridden ? `Manual (auto: ${eur(autoValue)}) — click to change` : 'Click to change')}
      className={`inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-xs transition-colors ${
        overridden
          ? 'border-terracotta-300 bg-terracotta-50 text-terracotta-700 font-semibold'
          : 'border-stone-200 bg-stone-50 text-stone-600 hover:border-terracotta-300 hover:bg-terracotta-50'
      } ${strong ? 'font-semibold' : ''}`}
    >
      <span>{label}</span>
      {/* Step 6.4: an unknown cost prints "-", never a confident 0.00. */}
      <span data-testid={unknown ? 'pnl-unknown-cost' : undefined}>{unknown ? '—' : eur(value)}</span>
      {overridden && (
        <span
          onClick={reset}
          title="Reset to automatic value"
          className="text-stone-400 hover:text-terracotta-600"
        >
          <FiRotateCcw size={10} />
        </span>
      )}
    </button>
  );
}

// ---------------------------------------------------------------------------
// One tour (or ticket product) as a simple card: who/when + money in − money
// out = big green/red profit. Chips are editable.
// ---------------------------------------------------------------------------
function UnitCard({ row, onCostSave, onOpenDetail, selectable, selected, onToggleSelect, onUnmerge }) {
  const chipKeys = row.is_ticket
    ? ['ticket_cost', 'other_cost']
    : COST_FIELDS.map((f) => f.key);

  const paxDetail =
    row.pax.children > 0 || row.pax.infants > 0
      ? ` (${row.pax.adults} adults, ${row.pax.children} children${row.pax.infants > 0 ? `, ${row.pax.infants} infants` : ''})`
      : '';

  const merged = row.merged || null;

  return (
    <div
      onClick={() => onOpenDetail && onOpenDetail(row)}
      className={`bg-white rounded-xl shadow-tuscan p-4 cursor-pointer hover:shadow-tuscan-xl transition-shadow ${
        merged ? 'ring-2 ring-terracotta-300' : ''
      }`}
      title="Tap to see how this is calculated and edit amounts"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            {/* Step 6.2: pick two departures on this day and cost them as one guide */}
            {selectable && !merged && (
              <input
                type="checkbox"
                checked={!!selected}
                onClick={(e) => e.stopPropagation()}
                onChange={() => onToggleSelect && onToggleSelect(row)}
                className="w-4 h-4 accent-terracotta-500"
                aria-label={`Select ${row.title} for merging`}
                data-testid="pnl-merge-select"
              />
            )}
            <span className="text-sm font-semibold text-stone-500">{(row.time || '').slice(0, 5)}</span>
            {merged && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-terracotta-100 text-terracotta-800 font-semibold" data-testid="pnl-merged-badge">
                Merged — one guide
              </span>
            )}
            <span className={`px-2 py-0.5 rounded-full text-[11px] font-medium ${CATEGORY_BADGE[row.category] || CATEGORY_BADGE.Other}`}>
              {row.category}
            </span>
            {row.is_ticket && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-purple-100 text-purple-800">Ticket / Audio</span>
            )}
            {row.is_private && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-purple-100 text-purple-800">Private</span>
            )}
            {row.outsourced && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-indigo-100 text-indigo-800">Given to agency</span>
            )}
            {/* Step 6.6: a guessed figure must never look like a known one. This used to be
                grey-on-grey and was missed for months while 30% was wrongly deducted from
                direct sales; it is now as loud as the other "not known" markers. */}
            {/* Step 6.7: named "card fee" so it can never be read as an OTA commission. */}
            {row.revenue.card_fee > 0 && (
              <span
                className="px-2 py-0.5 rounded-full text-[11px] bg-sky-100 text-sky-800"
                data-testid="pnl-card-fee-chip"
                title="Your own sale — the only deduction is what the card processor keeps"
              >
                card fee {eur(row.revenue.card_fee)}
              </span>
            )}
            {row.revenue.estimated && (
              <span
                className="px-2 py-0.5 rounded-full text-[11px] bg-amber-100 text-amber-800 font-medium"
                data-testid="pnl-estimated-chip"
                title="No invoice from Bokun for this booking — the commission below is a percentage we guessed, not a figure we know"
              >
                estimated — not from an invoice
              </span>
            )}
          </div>
          <p className="font-medium text-stone-800 mt-1 leading-snug">{row.title}</p>
          <p className="text-xs text-stone-500 mt-0.5">
            {row.pax.total} PAX{paxDetail}
            {row.is_group && ` · ${row.bookings} bookings`}
            {row.guide_name
              ? ` · Guide: ${row.guide_name}`
              : (!row.is_ticket ? ' · no guide assigned' : '')}
            {` · ${row.channels.join(', ')}`}
          </p>
          {merged && (
            <div className="mt-2 rounded-tuscan border border-terracotta-200 bg-terracotta-50/60 px-3 py-2" data-testid="pnl-merged-detail">
              <div className="text-[11px] text-terracotta-900 font-semibold">
                These ran together with one guide — {merged.guide_rule}
              </div>
              <ul className="mt-1 text-[11px] text-stone-700 space-y-0.5">
                {merged.members.map((m) => (
                  <li key={m.unit} className="flex justify-between gap-3">
                    <span className="truncate">{(m.time || '').slice(0, 5)} {m.title} — {m.pax} PAX</span>
                    <span className="shrink-0 text-stone-500">
                      in {eur(m.net)} · tickets {eur(m.ticket_cost)}
                    </span>
                  </li>
                ))}
              </ul>
              <button
                onClick={(e) => { e.stopPropagation(); onUnmerge && onUnmerge(merged.link_key); }}
                className="mt-1.5 text-[11px] font-medium text-terracotta-700 underline"
                data-testid="pnl-unmerge"
              >
                Unmerge
              </button>
            </div>
          )}
        </div>
        <div className="text-right shrink-0">
          <p className={`text-xl font-bold ${row.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
            {row.profit >= 0 ? '+' : ''}{eur(row.profit)}
          </p>
          <p className="text-[11px] text-stone-400">
            in {eur(row.revenue.net)} − out {eur(row.costs.total)}
            {row.costs.ticket_unknown && <span className="text-amber-600"> (tickets not known)</span>}
            {row.costs.guide_unknown && <span className="text-amber-600"> (guide fee not known)</span>}
          </p>
        </div>
      </div>

      <p className="sm:hidden text-[11px] text-terracotta-600 mt-2">Tap to see &amp; edit costs ▸</p>
      <div className="hidden sm:flex flex-wrap items-center gap-1.5 mt-3">
        <EditableChip
          row={row}
          field="revenue_override"
          label="Money in"
          value={row.revenue.net}
          autoValue={row.revenue.retail - row.revenue.commission}
          overridden={row.revenue.overridden}
          onSave={onCostSave}
          strong
        />
        <span className="text-stone-300 text-xs">−</span>
        {COST_FIELDS.filter((f) => chipKeys.includes(f.key)).map((f) => (
          <EditableChip
            key={f.key}
            row={row}
            field={f.key}
            label={f.label}
            value={row.costs[f.key]}
            autoValue={row.costs.auto[f.key]}
            unknown={(f.key === 'ticket_cost' && !!row.costs.ticket_unknown)
                     || (f.key === 'guide_cost' && !!row.costs.guide_unknown)}
            overridden={row.costs.overridden.includes(f.key)}
            onSave={onCostSave}
          />
        ))}
        <span className="w-px h-4 bg-stone-200 mx-1" />
        <button
          onClick={(e) => { e.stopPropagation(); onCostSave(row, 'outsourced', row.outsourced ? 0 : 1); }}
          title="Given to another agency: no guide/radio/gelato cost from you — just the ticket + the agency fee from Settings. Click again to undo."
          className={`inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-xs transition-colors ${
            row.outsourced
              ? 'border-indigo-300 bg-indigo-50 text-indigo-700 font-semibold'
              : 'border-dashed border-stone-300 bg-white text-stone-400 hover:border-indigo-300 hover:text-indigo-600'
          }`}
        >
          {row.outsourced ? '✓ Given to agency' : 'Given to agency?'}
        </button>
      </div>
    </div>
  );
}

// Small "in · out · profit" summary used in section headers
function SectionTotals({ rows }) {
  const t = rows.reduce(
    (a, r) => ({ net: a.net + r.revenue.net, cost: a.cost + r.costs.total, profit: a.profit + r.profit }),
    { net: 0, cost: 0, profit: 0 }
  );
  return (
    <span className="text-xs text-stone-500 whitespace-nowrap">
      in {eur(t.net)} · out {eur(t.cost)} ·{' '}
      <span className={`font-bold text-sm ${t.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
        {t.profit >= 0 ? '+' : ''}{eur(t.profit)}
      </span>
    </span>
  );
}

// ---------------------------------------------------------------------------
// Settings modal
// ---------------------------------------------------------------------------
function SettingsModal({ settings, onClose, onSaved }) {
  const [draft, setDraft] = useState({ ...settings });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const numeric = {};
      Object.entries(draft).forEach(([k, v]) => {
        const n = parseFloat(v);
        numeric[k] = isNaN(n) ? 0 : n;
      });
      const res = await savePnlSettings(numeric);
      onSaved(res.data || numeric);
      onClose();
    } catch (e) {
      setError('Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div
        className="bg-white rounded-xl shadow-tuscan-xl w-full max-w-2xl max-h-[85vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-5 py-4 border-b border-stone-200 sticky top-0 bg-white rounded-t-xl">
          <h2 className="text-lg font-semibold text-stone-800">Cost &amp; Rate Settings</h2>
          <button onClick={onClose} className="text-stone-400 hover:text-stone-600 p-2">
            <FiX size={20} />
          </button>
        </div>
        <div className="p-5 space-y-6">
          {SETTING_GROUPS.map((group) => (
            <div key={group.title}>
              <h3 className="text-sm font-semibold text-stone-600 mb-2">{group.title}</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {group.keys.map(([key, label]) => (
                  <label key={key} className="flex items-center justify-between gap-2 text-sm text-stone-700">
                    <span className="flex-1">{label}</span>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      value={draft[key] ?? 0}
                      onChange={(e) => setDraft((d) => ({ ...d, [key]: e.target.value }))}
                      className="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right focus:outline-none focus:ring-2 focus:ring-terracotta-400"
                    />
                  </label>
                ))}
              </div>
            </div>
          ))}
          {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
        <div className="flex justify-end gap-3 px-5 py-4 border-t border-stone-200 sticky bottom-0 bg-white rounded-b-xl">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
            Cancel
          </button>
          <button
            onClick={save}
            disabled={saving}
            className="px-4 py-2 text-sm rounded-lg bg-terracotta-600 text-white hover:bg-terracotta-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save Settings'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------
export default function DailyPnL() {
  const [view, setView] = useState('day'); // 'day' | 'week' | 'month'
  const [date, setDate] = useState(todayStr());
  const [weekStart, setWeekStart] = useState(mondayOf(todayStr())); // Monday
  const [month, setMonth] = useState(todayStr().slice(0, 7)); // YYYY-MM
  const [dayData, setDayData] = useState(null);
  const [monthData, setMonthData] = useState(null);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showSettings, setShowSettings] = useState(false);
  const [detailRow, setDetailRow] = useState(null);
  // Step 6.2: departures ticked for a costing merge, and the in-flight flag
  const [selectedUnits, setSelectedUnits] = useState([]);
  const [merging, setMerging] = useState(false);

  const loadDay = useCallback(async (d) => {
    setLoading(true);
    setError(null);
    markListStart(); // step 4.8: the page's own data fetch (first load only), for the field recorder
    try {
      const res = await getPnlDay(d);
      setDayData(res.data);
      if (res.data?.settings) setSettings(res.data.settings);
      markListEnd(true);
    } catch (e) {
      markListEnd(false);
      setError(e?.response?.status === 403
        ? 'Admin access required for the P&L page.'
        : 'Failed to load P&L data.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadRange = useCallback(async (start, end) => {
    setLoading(true);
    setError(null);
    markListStart(); // step 4.8: the page's own data fetch (first load only), for the field recorder
    try {
      const res = await getPnlRange(start, end);
      setMonthData(res.data);
      if (res.data?.settings) setSettings(res.data.settings);
      markListEnd(true);
    } catch (e) {
      markListEnd(false);
      setError(e?.response?.status === 403
        ? 'Admin access required for the P&L page.'
        : 'Failed to load P&L data.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadMonth = useCallback((m) => {
    const endD = new Date(parseInt(m.slice(0, 4), 10), parseInt(m.slice(5, 7), 10), 0);
    return loadRange(`${m}-01`, `${m}-${String(endD.getDate()).padStart(2, '0')}`);
  }, [loadRange]);

  useEffect(() => {
    if (view === 'day') loadDay(date);
    else if (view === 'week') loadRange(weekStart, shiftDate(weekStart, 6));
    else loadMonth(month);
  }, [view, date, weekStart, month, loadDay, loadRange, loadMonth]);

  // Step 6.2 --------------------------------------------------------------------------------
  // Tick two departures on the same day and cost them as one guide. P&L only: nothing in Tours,
  // grouping, payments, reminders or the digest is affected.
  const toggleMergeSelect = (row) => {
    if (row === null) { setSelectedUnits([]); return; }
    setSelectedUnits((prev) =>
      prev.includes(row.unit) ? prev.filter((u) => u !== row.unit) : [...prev, row.unit]
    );
  };

  const handleMerge = async () => {
    if (selectedUnits.length < 2) return;
    setMerging(true);
    try {
      await mergePnlUnits(date, selectedUnits);
      setSelectedUnits([]);
      await loadDay(date);
    } catch (e) {
      setError(e?.response?.data?.error || 'Could not merge those departures.');
    } finally {
      setMerging(false);
    }
  };

  const handleUnmerge = async (linkKey) => {
    try {
      await unmergePnlUnits(linkKey);
      await loadDay(date);
    } catch (e) {
      setError(e?.response?.data?.error || 'Could not undo that merge.');
    }
  };

  const handleCostSave = async (row, field, value) => {
    try {
      await savePnlCosts({ tour_unit: row.unit, date: row.date, [field]: value });
      await loadDay(date);
    } catch (e) {
      setError('Failed to save cost. Try again.');
    }
  };

  const totals = view === 'day' ? dayData?.totals : monthData?.totals;
  const profit = totals?.profit ?? 0;
  const profitAfterOverhead = monthData?.profit_after_overhead ?? null;

  return (
    <div className="p-4 md:p-6 max-w-full">
      {/* Header controls */}
      <div className="flex flex-wrap items-center gap-3 mb-5">
        <div className="flex rounded-lg overflow-hidden border border-stone-300">
          {['day', 'week', 'month'].map((v) => (
            <button
              key={v}
              onClick={() => setView(v)}
              className={`px-4 py-2 text-sm font-medium capitalize ${view === v ? 'bg-terracotta-600 text-white' : 'bg-white text-stone-600 hover:bg-stone-50'}`}
            >
              {v}
            </button>
          ))}
        </div>

        {view === 'week' && (
          <div className="flex items-center gap-1">
            <button onClick={() => setWeekStart(shiftDate(weekStart, -7))} className="p-2 rounded-lg border border-stone-300 bg-white hover:bg-stone-50">
              <FiChevronLeft />
            </button>
            <span className="px-3 py-2 text-sm bg-white border border-stone-300 rounded-lg whitespace-nowrap">
              {shortDate(weekStart)} – {shortDate(shiftDate(weekStart, 6))}
            </span>
            <button onClick={() => setWeekStart(shiftDate(weekStart, 7))} className="p-2 rounded-lg border border-stone-300 bg-white hover:bg-stone-50">
              <FiChevronRight />
            </button>
            <button
              onClick={() => setWeekStart(mondayOf(todayStr()))}
              className="ml-1 px-3 py-2 text-sm rounded-lg border border-stone-300 bg-white text-stone-600 hover:bg-stone-50"
            >
              This week
            </button>
          </div>
        )}

        {view === 'day' ? (
          <div className="flex items-center gap-1">
            <button onClick={() => setDate(shiftDate(date, -1))} className="p-2 rounded-lg border border-stone-300 bg-white hover:bg-stone-50">
              <FiChevronLeft />
            </button>
            <input
              type="date"
              value={date}
              onChange={(e) => e.target.value && setDate(e.target.value)}
              className="px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white"
            />
            <button onClick={() => setDate(shiftDate(date, 1))} className="p-2 rounded-lg border border-stone-300 bg-white hover:bg-stone-50">
              <FiChevronRight />
            </button>
            <button
              onClick={() => setDate(todayStr())}
              className="ml-1 px-3 py-2 text-sm rounded-lg border border-stone-300 bg-white text-stone-600 hover:bg-stone-50"
            >
              Today
            </button>
          </div>
        ) : view === 'month' ? (
          <input
            type="month"
            value={month}
            onChange={(e) => e.target.value && setMonth(e.target.value)}
            className="px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white"
          />
        ) : null}

        <div className="flex-1" />
        <button
          onClick={() => setShowSettings(true)}
          className="flex items-center gap-2 px-4 py-2 text-sm rounded-lg border border-stone-300 bg-white text-stone-700 hover:bg-stone-50"
        >
          <FiSettings size={16} /> Rates &amp; Costs
        </button>
      </div>

      {error && (
        <div className="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">{error}</div>
      )}

      {/* Summary cards */}
      {totals && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
          <div className="bg-white rounded-xl shadow-tuscan p-4">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Net Revenue</p>
            <p className="text-xl font-bold text-stone-800 mt-1">{eur(totals.net)}</p>
            <p className="text-xs text-stone-400 mt-1">
              {/* Step 6.7: the card fee is NOT an OTA commission and is named separately. */}
              Retail {eur(totals.retail)} − commission {eur(totals.commission)}
              {totals.card_fee > 0 && <> − card fee {eur(totals.card_fee)}</>}
            </p>
            {/* Step 6.6: how much of this number is known and how much is guessed. */}
            {totals.estimated_units > 0 && (
              <p className="text-xs text-amber-700 mt-1" data-testid="pnl-estimated-count">
                {totals.estimated_units} of {totals.units}{' '}
                {totals.estimated_units === 1 ? 'departure is' : 'departures are'} estimated,
                not from an invoice
              </p>
            )}
          </div>
          <div className="bg-white rounded-xl shadow-tuscan p-4">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Total Costs</p>
            <p className="text-xl font-bold text-stone-800 mt-1">{eur(totals.total_cost)}</p>
            <p className="text-xs text-stone-400 mt-1">
              Tickets {eur(totals.ticket_cost)} · Guides {eur(totals.guide_cost)}
            </p>
          </div>
          <div className={`rounded-xl shadow-tuscan p-4 ${profit >= 0 ? 'bg-green-50' : 'bg-red-50'}`}>
            <p className="text-xs text-stone-500 uppercase tracking-wide flex items-center gap-1">
              {profit >= 0 ? <FiTrendingUp className="text-green-600" /> : <FiTrendingDown className="text-red-600" />}
              {view === 'day' ? 'Day Profit' : view === 'week' ? 'Week Profit' : 'Month Profit (tours)'}
            </p>
            <p className={`text-xl font-bold mt-1 ${profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
              {profit >= 0 ? '+' : ''}{eur(profit)}
            </p>
            {view === 'month' && profitAfterOverhead !== null && (
              <p className={`text-xs mt-1 ${profitAfterOverhead >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                After monthly costs ({eur(monthData.monthly_overhead)}): {profitAfterOverhead >= 0 ? '+' : ''}{eur(profitAfterOverhead)}
              </p>
            )}
          </div>
          <div className="bg-white rounded-xl shadow-tuscan p-4">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Volume</p>
            <p className="text-xl font-bold text-stone-800 mt-1">
              {totals.tour_units ?? totals.units} <span className="text-sm font-normal text-stone-500">tours</span>
              {(totals.ticket_units ?? 0) > 0 && (
                <> · {totals.ticket_units} <span className="text-sm font-normal text-stone-500">tickets</span></>
              )}
              {' '}· {totals.pax} <span className="text-sm font-normal text-stone-500">PAX</span>
            </p>
            {totals.cancelled > 0 && (
              <p className="text-xs text-stone-400 mt-1">{totals.cancelled} cancelled (excluded)</p>
            )}
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex items-center justify-center py-20 text-stone-500">Loading…</div>
      ) : view === 'day' ? (
        <DayTable
          data={dayData}
          onCostSave={handleCostSave}
          onOpenDetail={setDetailRow}
          selectedUnits={selectedUnits}
          onToggleSelect={toggleMergeSelect}
          onMerge={handleMerge}
          onUnmerge={handleUnmerge}
          merging={merging}
        />
      ) : (
        <>
          <CategoryTiles cats={monthData?.by_category} />
          <MonthTable
            data={monthData}
            onOpenDay={(d) => { setDate(d); setView('day'); }}
            showOverhead={view === 'month'}
          />
        </>
      )}

      {detailRow && settings && (
        <CostDetailModal
          row={detailRow}
          settings={settings}
          onClose={() => setDetailRow(null)}
          onSave={async (payload) => {
            try {
              await savePnlCosts({ tour_unit: detailRow.unit, date: detailRow.date, ...payload });
              setDetailRow(null);
              await loadDay(date);
            } catch (e) {
              setError('Failed to save. Try again.');
            }
          }}
        />
      )}

      {showSettings && settings && (
        <SettingsModal
          settings={settings}
          onClose={() => setShowSettings(false)}
          onSaved={(s) => {
            setSettings(s);
            if (view === 'day') loadDay(date); else loadMonth(month);
          }}
        />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Cost detail modal — tap a tour card to see HOW each amount is calculated
// and edit any value. Mobile-first (full width, scrollable).
// ---------------------------------------------------------------------------
function CostDetailModal({ row, settings, onClose, onSave }) {
  const fieldKeys = row.is_ticket ? ['ticket_cost', 'other_cost'] : COST_FIELDS.map((f) => f.key);
  const autoNet = Math.round((row.revenue.retail - row.revenue.commission) * 100) / 100;

  const [draft, setDraft] = useState(() => {
    const d = { revenue: String(row.revenue.net) };
    COST_FIELDS.forEach((f) => { d[f.key] = String(row.costs[f.key]); });
    return d;
  });
  const [notes, setNotes] = useState(row.notes || '');
  const [outsourced, setOutsourced] = useState(!!row.outsourced);
  const [saving, setSaving] = useState(false);

  const people = row.pax.adults + row.pax.children;
  const isPm = (row.time || '') >= '16:00';
  const MUSEUMS = { Combo: ['uffizi', 'accademia'], Uffizi: ['uffizi'], Accademia: ['accademia'], Pitti: ['pitti'], Borghese: ['borghese'] };
  const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

  const ticketExplain = () => {
    // Step 6.4: a hand-entered departure has no Bokun product, and if its title names no
    // museum there is nothing to compute from. Say so instead of implying €0.00.
    if (row.costs.ticket_unknown) {
      return 'Not known — this tour was added by hand and its name does not say which museum. Type the real ticket cost here.';
    }
    const mus = MUSEUMS[row.category];
    if (!mus) return 'Sum over the bookings in this group';
    return mus.map((m) => {
      const pm = m === 'uffizi' && isPm;
      const a = settings[pm ? 'ticket_uffizi_adult_pm' : `ticket_${m}_adult`] ?? 0;
      const c = settings[pm ? 'ticket_uffizi_child_pm' : `ticket_${m}_child`] ?? 0;
      let s = `${cap(m)}${pm ? ' (after 16:00)' : ''}: ${row.pax.adults} adult × €${a}`;
      if (row.pax.children > 0) s += ` + ${row.pax.children} child × €${c}`;
      return s;
    }).join('   +   ');
  };

  const explains = {
    ticket_cost: ticketExplain(),
    guide_cost: outsourced ? 'Given to agency — no guide cost from you'
      : row.is_ticket ? 'Ticket product — no guide'
      : row.is_private ? `Private ${row.category} rate from Settings (€${
          settings[{
            Combo: 'guide_rate_private_combo',
            Uffizi: 'guide_rate_private_uffizi',
            Accademia: 'guide_rate_private_accademia',
            Pitti: 'guide_rate_private_pitti'
          }[row.category] || 'guide_rate_private_other'] ?? 0})`
      : row.category === 'Mixed' ? 'Mixed group — highest member category rate'
      : `${row.category} tour rate from Settings`,
    radio_cost: outsourced || row.is_ticket ? '—' : `${people} people × €${settings.radio_per_person}`,
    gelato_cost: outsourced || row.is_ticket ? '—'
      : row.costs.auto.gelato_cost > 0 ? `${people} people × €${settings.gelato_per_person} (gelato tour)` : 'Not a gelato tour',
    staff_cost: 'Manual — extra staff for this tour only',
    other_cost: outsourced ? `Agency handling fee (€${settings.outsource_fee} from Settings)` : 'Manual — taxi, extra tickets, anything else'
  };

  const save = async () => {
    setSaving(true);
    try {
      const payload = { outsourced: outsourced ? 1 : 0 };
      fieldKeys.forEach((f) => {
        const num = parseFloat(draft[f]);
        if (isNaN(num)) return;
        if (Math.abs(num - row.costs.auto[f]) < 0.005) {
          // Equals the automatic value: clear any manual override
          if (row.costs.overridden.includes(f)) payload[f] = null;
        } else {
          payload[f] = num;
        }
      });
      const rev = parseFloat(draft.revenue);
      if (!isNaN(rev)) {
        if (Math.abs(rev - autoNet) < 0.005) {
          if (row.revenue.overridden) payload.revenue_override = null;
        } else {
          payload.revenue_override = rev;
        }
      }
      if (notes !== (row.notes || '')) payload.notes = notes.trim() === '' ? null : notes.trim();
      await onSave(payload);
    } finally {
      setSaving(false);
    }
  };

  const numInput = (key) => (
    <input
      type="number"
      step="0.01"
      min="0"
      inputMode="decimal"
      value={draft[key]}
      onChange={(e) => setDraft((d) => ({ ...d, [key]: e.target.value }))}
      className="w-24 px-2 py-1.5 border border-stone-300 rounded-lg text-right text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-400"
    />
  );

  const totalCost = fieldKeys.reduce((s, f) => s + (parseFloat(draft[f]) || 0), 0);
  const netVal = parseFloat(draft.revenue) || 0;
  const liveProfit = Math.round((netVal - totalCost) * 100) / 100;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 sm:p-4" onClick={onClose}>
      <div
        className="bg-white sm:rounded-xl rounded-t-2xl shadow-tuscan-xl w-full sm:max-w-lg max-h-[92vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between px-4 py-3 border-b border-stone-200 sticky top-0 bg-white z-10">
          <div className="min-w-0 pr-2">
            <p className="text-sm font-semibold text-stone-800 leading-snug">{row.title}</p>
            <p className="text-xs text-stone-500 mt-0.5">
              {(row.time || '').slice(0, 5)} · {row.category} · {row.pax.total} PAX
              ({row.pax.adults} adults{row.pax.children > 0 ? `, ${row.pax.children} children` : ''}{row.pax.infants > 0 ? `, ${row.pax.infants} infants` : ''})
              {row.guide_name && ` · ${row.guide_name}`}
            </p>
          </div>
          <button onClick={onClose} className="text-stone-400 hover:text-stone-600 p-2 -mr-2 shrink-0">
            <FiX size={20} />
          </button>
        </div>

        <div className="p-4 space-y-4">
          {/* Money in */}
          <div className="bg-stone-50 rounded-lg p-3">
            <div className="flex items-center justify-between gap-2">
              <div>
                <p className="text-sm font-semibold text-stone-700">Money in (net)</p>
                <p className="text-xs text-stone-500 mt-0.5">
                  {row.revenue.manual
                    ? (row.revenue.estimated
                        ? 'Added by hand — no amount was entered, so nothing is counted as money in'
                        : `Added by hand — ${eur(row.revenue.net)} is what the channel pays, net of its commission`)
                    : <>
                        Retail {eur(row.revenue.retail)} − {row.channels.join(', ')} commission{' '}
                        {row.revenue.estimated && '~'}{eur(row.revenue.commission)}
                        {/* Step 6.7: his own sale, so the only deduction is what the card costs him. */}
                        {row.revenue.card_fee > 0 && <> − card fee {eur(row.revenue.card_fee)}</>}
                      </>}
                </p>
              </div>
              {numInput('revenue')}
            </div>
            {(parseFloat(draft.revenue) || 0) !== autoNet && (
              <button
                onClick={() => setDraft((d) => ({ ...d, revenue: String(autoNet) }))}
                className="mt-1 text-[11px] text-terracotta-600 flex items-center gap-1"
              >
                <FiRotateCcw size={10} /> Back to automatic ({eur(autoNet)})
              </button>
            )}
          </div>

          {/* Given to agency toggle */}
          {!row.is_ticket && (
            <label className="flex items-center justify-between gap-2 px-1 cursor-pointer">
              <span className="text-sm text-stone-700">
                Given to another agency
                <span className="block text-xs text-stone-400">Ticket + €{settings.outsource_fee} fee — no guide/radio/gelato</span>
              </span>
              <input
                type="checkbox"
                checked={outsourced}
                onChange={(e) => setOutsourced(e.target.checked)}
                className="w-5 h-5 accent-terracotta-600"
              />
            </label>
          )}

          {/* Cost lines */}
          <div className="space-y-3">
            {COST_FIELDS.filter((f) => fieldKeys.includes(f.key)).map((f) => (
              <div key={f.key} className="flex items-center justify-between gap-2 border-b border-stone-100 pb-2">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-stone-700">{f.label}</p>
                  <p className="text-[11px] text-stone-400 leading-snug">{explains[f.key]}</p>
                  {Math.abs((parseFloat(draft[f.key]) || 0) - row.costs.auto[f.key]) >= 0.005 && (
                    <button
                      onClick={() => setDraft((d) => ({ ...d, [f.key]: String(row.costs.auto[f.key]) }))}
                      className="text-[11px] text-terracotta-600 flex items-center gap-1 mt-0.5"
                    >
                      <FiRotateCcw size={10} /> Auto: {eur(row.costs.auto[f.key])}
                    </button>
                  )}
                </div>
                {numInput(f.key)}
              </div>
            ))}
          </div>

          {/* Notes */}
          <div>
            <p className="text-sm font-medium text-stone-700 mb-1">Notes</p>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              placeholder="e.g. gave to Marco's agency, guest was late…"
              className="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-400"
            />
          </div>

          {/* Live result */}
          <div className={`rounded-lg p-3 text-center ${liveProfit >= 0 ? 'bg-green-50' : 'bg-red-50'}`}>
            <p className="text-xs text-stone-500">
              {eur(netVal)} in − {eur(totalCost)} out =
            </p>
            <p className={`text-2xl font-bold ${liveProfit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
              {liveProfit >= 0 ? '+' : ''}{eur(liveProfit)}
            </p>
          </div>
        </div>

        <div className="flex gap-3 px-4 py-3 border-t border-stone-200 sticky bottom-0 bg-white">
          <button onClick={onClose} className="flex-1 px-4 py-2.5 text-sm rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
            Cancel
          </button>
          <button
            onClick={save}
            disabled={saving}
            className="flex-1 px-4 py-2.5 text-sm rounded-lg bg-terracotta-600 text-white hover:bg-terracotta-700 disabled:opacity-50 font-medium"
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Profit by product line (week/month views)
// ---------------------------------------------------------------------------
function CategoryTiles({ cats }) {
  if (!cats || cats.length === 0) return null;
  return (
    <div className="mb-4">
      <h2 className="text-sm font-semibold text-stone-600 mb-2">Profit by product</h2>
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
        {cats.map((c) => (
          <div key={c.category} className="bg-white rounded-lg shadow-tuscan p-3">
            <div className="flex items-center justify-between gap-1">
              <span className={`px-2 py-0.5 rounded-full text-[11px] font-medium ${
                c.category === 'Tickets' ? 'bg-purple-100 text-purple-800' : (CATEGORY_BADGE[c.category] || CATEGORY_BADGE.Other)
              }`}>
                {c.category}
              </span>
              <span className="text-[11px] text-stone-400 whitespace-nowrap">{c.units}× · {c.pax} PAX</span>
            </div>
            <p className={`text-base font-bold mt-1 ${c.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
              {c.profit >= 0 ? '+' : ''}{eur(c.profit)}
            </p>
            <p className="text-[11px] text-stone-400">in {eur(c.net)} · out {eur(c.cost)}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Day detail view — sectioned cards:
//   1. Guided Tours, grouped by location/category (Combo, Uffizi, Accademia…)
//   2. Tickets & Audio Guides
//   3. Cancelled (collapsed, excluded from money)
// ---------------------------------------------------------------------------
function DayTable({ data, onCostSave, onOpenDetail, selectedUnits, onToggleSelect, onMerge, onUnmerge, merging }) {
  if (!data || !data.rows || data.rows.length === 0) {
    return (
      <div className="bg-white rounded-xl shadow-tuscan p-10 text-center text-stone-500">
        <FiCalendar className="mx-auto mb-2 text-stone-300" size={28} />
        No bookings on this day.
      </div>
    );
  }

  const active = data.rows.filter((r) => r.bookings > 0);
  const guided = active.filter((r) => !r.is_ticket);
  const tickets = active.filter((r) => r.is_ticket);
  const cancelledRows = data.rows.filter((r) => r.bookings === 0);

  const byCategory = {};
  guided.forEach((r) => {
    if (!byCategory[r.category]) byCategory[r.category] = [];
    byCategory[r.category].push(r);
  });
  const categories = CATEGORY_ORDER.filter((c) => byCategory[c]);

  return (
    <div className="space-y-6">
      <p className="text-xs text-stone-400">
        Click any chip (Money in, Tickets, Guide…) to type your real amount.{' '}
        <span className="font-semibold text-terracotta-600">Orange</span> = manual value — click ↺ on it to go back to automatic.
      </p>

      {/* Step 6.2: merge two departures that ran together under one guide (P&L only) */}
      <div className="bg-white rounded-xl shadow-tuscan px-4 py-3" data-testid="pnl-merge-bar">
        <p className="text-xs text-stone-500">
          <span className="font-semibold text-stone-700">Ran together with one guide?</span>{' '}
          Tick two departures on this day and merge them: you are charged <strong>one</strong> guide fee for
          the pair, while tickets, radios, revenue and PAX still count per product. Costs you set on the
          merged row win; anything you set on a single tour still counts. This changes the P&amp;L only —
          Tours, groups, payments and the guide WhatsApp are untouched.
        </p>
        {selectedUnits && selectedUnits.length > 0 && (
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <span className="text-xs text-stone-600">{selectedUnits.length} selected</span>
            <button
              onClick={onMerge}
              disabled={selectedUnits.length < 2 || merging}
              className="px-3 py-1.5 rounded-tuscan bg-terracotta-500 text-white text-xs font-semibold disabled:bg-stone-300"
              data-testid="pnl-merge-button"
            >
              {merging ? 'Merging…' : `Merge ${selectedUnits.length} for costing`}
            </button>
            <button onClick={() => onToggleSelect(null)} className="text-xs text-stone-500 underline">
              Clear
            </button>
          </div>
        )}
      </div>

      {/* 1. Guided tours, by location */}
      {guided.length > 0 && (
        <div>
          <div className="flex items-center justify-between mb-2">
            <h2 className="text-base font-semibold text-stone-800">Guided Tours ({guided.length})</h2>
            <SectionTotals rows={guided} />
          </div>
          <div className="space-y-4">
            {categories.map((cat) => (
              <div key={cat}>
                <div className="flex items-center justify-between mb-1.5">
                  <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${CATEGORY_BADGE[cat]}`}>
                    {cat} · {byCategory[cat].length} {byCategory[cat].length === 1 ? 'tour' : 'tours'}
                  </span>
                  <SectionTotals rows={byCategory[cat]} />
                </div>
                <div className="space-y-2">
                  {byCategory[cat].map((row) => (
                    <UnitCard
                      key={row.unit}
                      row={row}
                      onCostSave={onCostSave}
                      onOpenDetail={onOpenDetail}
                      selectable
                      selected={selectedUnits && selectedUnits.includes(row.unit)}
                      onToggleSelect={onToggleSelect}
                      onUnmerge={onUnmerge}
                    />
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* 2. Tickets & audio guides */}
      {tickets.length > 0 && (
        <div>
          <div className="flex items-center justify-between mb-2">
            <h2 className="text-base font-semibold text-stone-800">Tickets &amp; Audio Guides ({tickets.length})</h2>
            <SectionTotals rows={tickets} />
          </div>
          <div className="space-y-2">
            {tickets.map((row) => (
              <UnitCard
                key={row.unit}
                row={row}
                onCostSave={onCostSave}
                onOpenDetail={onOpenDetail}
                selectable
                selected={selectedUnits && selectedUnits.includes(row.unit)}
                onToggleSelect={onToggleSelect}
                onUnmerge={onUnmerge}
              />
            ))}
          </div>
        </div>
      )}

      {/* 3. Cancelled — excluded from all money */}
      {cancelledRows.length > 0 && (
        <div className="bg-white/60 rounded-xl border border-stone-200 p-3">
          <p className="text-xs font-semibold text-stone-500 mb-1">
            Cancelled ({cancelledRows.length}) — not counted
          </p>
          {cancelledRows.map((row) => (
            <p key={row.unit} className="text-xs text-stone-400 line-through">
              {(row.time || '').slice(0, 5)} · {row.title}
            </p>
          ))}
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Month summary table
// ---------------------------------------------------------------------------
function MonthTable({ data, onOpenDay, showOverhead = true }) {
  if (!data || !data.days || data.days.length === 0) {
    return (
      <div className="bg-white rounded-xl shadow-tuscan p-10 text-center text-stone-500">
        <FiCalendar className="mx-auto mb-2 text-stone-300" size={28} />
        No bookings in this period.
      </div>
    );
  }

  return (
    <div className="bg-white rounded-xl shadow-tuscan overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-stone-50 text-stone-500 text-xs uppercase tracking-wide">
              <th className="px-3 py-2 text-left">Date</th>
              <th className="px-2 py-2 text-right">Tours</th>
              <th className="px-2 py-2 text-right">PAX</th>
              <th className="px-2 py-2 text-right">Net Revenue</th>
              <th className="px-2 py-2 text-right">Tickets</th>
              <th className="px-2 py-2 text-right">Guides</th>
              <th className="px-2 py-2 text-right">Other Costs</th>
              <th className="px-2 py-2 text-right">Total Costs</th>
              <th className="px-3 py-2 text-right">Profit</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-stone-100">
            {data.days.map((d) => {
              const otherCosts = d.radio_cost + d.gelato_cost + d.staff_cost + d.other_cost;
              return (
                <tr
                  key={d.date}
                  onClick={() => onOpenDay(d.date)}
                  className="hover:bg-terracotta-50/50 cursor-pointer"
                  title="Open day detail"
                >
                  <td className="px-3 py-2 font-medium text-stone-700 whitespace-nowrap">
                    {new Date(d.date + 'T12:00:00').toLocaleDateString('en-GB', {
                      weekday: 'short', day: 'numeric', month: 'short'
                    })}
                  </td>
                  <td className="px-2 py-2 text-right text-stone-600">
                    {d.tour_units ?? d.units}
                    {(d.ticket_units ?? 0) > 0 && <span className="text-stone-400 text-xs"> +{d.ticket_units}t</span>}
                  </td>
                  <td className="px-2 py-2 text-right text-stone-600">{d.pax}</td>
                  <td className="px-2 py-2 text-right text-stone-700">{eur(d.net)}</td>
                  <td className="px-2 py-2 text-right text-stone-500">{eur(d.ticket_cost)}</td>
                  <td className="px-2 py-2 text-right text-stone-500">{eur(d.guide_cost)}</td>
                  <td className="px-2 py-2 text-right text-stone-500">{eur(otherCosts)}</td>
                  <td className="px-2 py-2 text-right text-stone-700">{eur(d.total_cost)}</td>
                  <td className={`px-3 py-2 text-right font-semibold ${d.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                    {d.profit >= 0 ? '+' : ''}{eur(d.profit)}
                  </td>
                </tr>
              );
            })}
          </tbody>
          <tfoot className="bg-stone-50 font-semibold text-stone-800">
            <tr>
              <td className="px-3 py-2">Month total</td>
              <td className="px-2 py-2 text-right">
                {data.totals.tour_units ?? data.totals.units}
                {(data.totals.ticket_units ?? 0) > 0 && <span className="text-stone-400 text-xs"> +{data.totals.ticket_units}t</span>}
              </td>
              <td className="px-2 py-2 text-right">{data.totals.pax}</td>
              <td className="px-2 py-2 text-right">{eur(data.totals.net)}</td>
              <td className="px-2 py-2 text-right">{eur(data.totals.ticket_cost)}</td>
              <td className="px-2 py-2 text-right">{eur(data.totals.guide_cost)}</td>
              <td className="px-2 py-2 text-right">
                {eur(data.totals.radio_cost + data.totals.gelato_cost + data.totals.staff_cost + data.totals.other_cost)}
              </td>
              <td className="px-2 py-2 text-right">{eur(data.totals.total_cost)}</td>
              <td className={`px-3 py-2 text-right ${data.totals.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                {data.totals.profit >= 0 ? '+' : ''}{eur(data.totals.profit)}
              </td>
            </tr>
            {showOverhead && (
              <tr className="text-stone-600 font-normal text-xs">
                <td className="px-3 py-2" colSpan={7}>Monthly overhead (staff, office, other fixed — from Settings)</td>
                <td className="px-2 py-2 text-right">{eur(data.monthly_overhead)}</td>
                <td className={`px-3 py-2 text-right font-semibold text-sm ${data.profit_after_overhead >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                  {data.profit_after_overhead >= 0 ? '+' : ''}{eur(data.profit_after_overhead)}
                </td>
              </tr>
            )}
          </tfoot>
        </table>
      </div>
    </div>
  );
}
