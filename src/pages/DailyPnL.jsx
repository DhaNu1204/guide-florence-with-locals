import React, { useState, useEffect, useCallback } from 'react';
import {
  FiChevronLeft, FiChevronRight, FiSettings, FiTrendingUp, FiTrendingDown,
  FiCalendar, FiX, FiRotateCcw
} from 'react-icons/fi';
import {
  getPnlDay, getPnlRange, getPnlSettings, savePnlSettings, savePnlCosts
} from '../services/mysqlDB';

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
    title: 'Guide pay per tour (by category)',
    keys: [
      ['guide_rate_combo', 'Combo tour'],
      ['guide_rate_uffizi', 'Uffizi tour'],
      ['guide_rate_accademia', 'Accademia tour'],
      ['guide_rate_pitti', 'Pitti tour'],
      ['guide_rate_other', 'Other tour']
    ]
  },
  {
    title: 'Museum ticket cost per person (what you pay)',
    keys: [
      ['ticket_uffizi_adult', 'Uffizi — adult'],
      ['ticket_uffizi_child', 'Uffizi — child/reduced'],
      ['ticket_accademia_adult', 'Accademia — adult'],
      ['ticket_accademia_child', 'Accademia — child/reduced'],
      ['ticket_pitti_adult', 'Pitti — adult'],
      ['ticket_pitti_child', 'Pitti — child/reduced']
    ]
  },
  {
    title: 'Per-person extras',
    keys: [
      ['radio_per_person', 'Radio / headset per person'],
      ['gelato_per_person', 'Gelato per person (gelato tours only)']
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
    title: 'Fallback commission % (only used when Bokun has no invoice data)',
    keys: [
      ['comm_getyourguide', 'GetYourGuide %'],
      ['comm_viator', 'Viator %'],
      ['comm_headout', 'Headout %'],
      ['comm_default', 'Other channels %']
    ]
  }
];

// ---------------------------------------------------------------------------
// Category styling + ordering for the day view sections
// ---------------------------------------------------------------------------
const CATEGORY_ORDER = ['Combo', 'Uffizi', 'Accademia', 'Pitti', 'Mixed', 'Other'];
const CATEGORY_BADGE = {
  Combo: 'bg-amber-100 text-amber-800',
  Uffizi: 'bg-emerald-100 text-emerald-800',
  Accademia: 'bg-blue-100 text-blue-800',
  Pitti: 'bg-rose-100 text-rose-800',
  Mixed: 'bg-amber-100 text-amber-800',
  Other: 'bg-stone-100 text-stone-600'
};

// ---------------------------------------------------------------------------
// Inline editable money chip — shows auto value; click to override; ↺ resets
// ---------------------------------------------------------------------------
function EditableChip({ row, field, label, value, autoValue, overridden, onSave, strong = false }) {
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
      <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-terracotta-400 bg-white text-xs text-stone-600">
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
      onClick={startEdit}
      title={overridden ? `Manual (auto: ${eur(autoValue)}) — click to change` : 'Click to change'}
      className={`inline-flex items-center gap-1 px-2 py-1 rounded-lg border text-xs transition-colors ${
        overridden
          ? 'border-terracotta-300 bg-terracotta-50 text-terracotta-700 font-semibold'
          : 'border-stone-200 bg-stone-50 text-stone-600 hover:border-terracotta-300 hover:bg-terracotta-50'
      } ${strong ? 'font-semibold' : ''}`}
    >
      <span>{label}</span>
      <span>{eur(value)}</span>
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
function UnitCard({ row, onCostSave }) {
  const chipKeys = row.is_ticket
    ? ['ticket_cost', 'other_cost']
    : COST_FIELDS.map((f) => f.key);

  const paxDetail =
    row.pax.children > 0 || row.pax.infants > 0
      ? ` (${row.pax.adults} adults, ${row.pax.children} children${row.pax.infants > 0 ? `, ${row.pax.infants} infants` : ''})`
      : '';

  return (
    <div className="bg-white rounded-xl shadow-tuscan p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-semibold text-stone-500">{(row.time || '').slice(0, 5)}</span>
            <span className={`px-2 py-0.5 rounded-full text-[11px] font-medium ${CATEGORY_BADGE[row.category] || CATEGORY_BADGE.Other}`}>
              {row.category}
            </span>
            {row.is_ticket && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-purple-100 text-purple-800">Ticket / Audio</span>
            )}
            {row.revenue.estimated && (
              <span className="px-2 py-0.5 rounded-full text-[11px] bg-stone-100 text-stone-500" title="Commission estimated from % — no exact Bokun invoice">
                ~ estimated
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
        </div>
        <div className="text-right shrink-0">
          <p className={`text-xl font-bold ${row.profit >= 0 ? 'text-green-700' : 'text-red-600'}`}>
            {row.profit >= 0 ? '+' : ''}{eur(row.profit)}
          </p>
          <p className="text-[11px] text-stone-400">
            in {eur(row.revenue.net)} − out {eur(row.costs.total)}
          </p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-1.5 mt-3">
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
            overridden={row.costs.overridden.includes(f.key)}
            onSave={onCostSave}
          />
        ))}
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
  const [view, setView] = useState('day'); // 'day' | 'month'
  const [date, setDate] = useState(todayStr());
  const [month, setMonth] = useState(todayStr().slice(0, 7)); // YYYY-MM
  const [dayData, setDayData] = useState(null);
  const [monthData, setMonthData] = useState(null);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showSettings, setShowSettings] = useState(false);

  const loadDay = useCallback(async (d) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getPnlDay(d);
      setDayData(res.data);
      if (res.data?.settings) setSettings(res.data.settings);
    } catch (e) {
      setError(e?.response?.status === 403
        ? 'Admin access required for the P&L page.'
        : 'Failed to load P&L data.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadMonth = useCallback(async (m) => {
    setLoading(true);
    setError(null);
    try {
      const start = `${m}-01`;
      const endD = new Date(parseInt(m.slice(0, 4), 10), parseInt(m.slice(5, 7), 10), 0);
      const end = `${m}-${String(endD.getDate()).padStart(2, '0')}`;
      const res = await getPnlRange(start, end);
      setMonthData(res.data);
      if (res.data?.settings) setSettings(res.data.settings);
    } catch (e) {
      setError(e?.response?.status === 403
        ? 'Admin access required for the P&L page.'
        : 'Failed to load P&L data.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (view === 'day') loadDay(date);
    else loadMonth(month);
  }, [view, date, month, loadDay, loadMonth]);

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
          <button
            onClick={() => setView('day')}
            className={`px-4 py-2 text-sm font-medium ${view === 'day' ? 'bg-terracotta-600 text-white' : 'bg-white text-stone-600 hover:bg-stone-50'}`}
          >
            Day
          </button>
          <button
            onClick={() => setView('month')}
            className={`px-4 py-2 text-sm font-medium ${view === 'month' ? 'bg-terracotta-600 text-white' : 'bg-white text-stone-600 hover:bg-stone-50'}`}
          >
            Month
          </button>
        </div>

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
        ) : (
          <input
            type="month"
            value={month}
            onChange={(e) => e.target.value && setMonth(e.target.value)}
            className="px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white"
          />
        )}

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
              Retail {eur(totals.retail)} − commission {eur(totals.commission)}
            </p>
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
              {view === 'day' ? 'Day Profit' : 'Month Profit (tours)'}
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
              {totals.units} <span className="text-sm font-normal text-stone-500">tours</span> · {totals.pax}{' '}
              <span className="text-sm font-normal text-stone-500">PAX</span>
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
        <DayTable data={dayData} onCostSave={handleCostSave} />
      ) : (
        <MonthTable data={monthData} onOpenDay={(d) => { setDate(d); setView('day'); }} />
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
// Day detail view — sectioned cards:
//   1. Guided Tours, grouped by location/category (Combo, Uffizi, Accademia…)
//   2. Tickets & Audio Guides
//   3. Cancelled (collapsed, excluded from money)
// ---------------------------------------------------------------------------
function DayTable({ data, onCostSave }) {
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
                    <UnitCard key={row.unit} row={row} onCostSave={onCostSave} />
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
              <UnitCard key={row.unit} row={row} onCostSave={onCostSave} />
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
function MonthTable({ data, onOpenDay }) {
  if (!data || !data.days || data.days.length === 0) {
    return (
      <div className="bg-white rounded-xl shadow-tuscan p-10 text-center text-stone-500">
        <FiCalendar className="mx-auto mb-2 text-stone-300" size={28} />
        No bookings in this month.
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
                  <td className="px-2 py-2 text-right text-stone-600">{d.units}</td>
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
              <td className="px-2 py-2 text-right">{data.totals.units}</td>
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
            <tr className="text-stone-600 font-normal text-xs">
              <td className="px-3 py-2" colSpan={7}>Monthly overhead (staff, office, other fixed — from Settings)</td>
              <td className="px-2 py-2 text-right">{eur(data.monthly_overhead)}</td>
              <td className={`px-3 py-2 text-right font-semibold text-sm ${data.profit_after_overhead >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                {data.profit_after_overhead >= 0 ? '+' : ''}{eur(data.profit_after_overhead)}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}
