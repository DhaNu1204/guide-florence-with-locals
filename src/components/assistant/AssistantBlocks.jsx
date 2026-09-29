// Step 7.4: renders the assistant's answer blocks (validated on the server by
// assistantValidateBlock; checked again here - a malformed block is skipped, never shown raw).
// All text goes through React, so nothing is ever interpreted as HTML.
import React from 'react';
import { Link } from 'react-router-dom';
import { FiArrowRight } from 'react-icons/fi';
import { buildAssistantLink, shortDay } from '../../utils/assistantUi';

const isStr = (v) => typeof v === 'string' && v.trim() !== '';
const isScalar = (v) => typeof v === 'string' || typeof v === 'number';

const validStat = (b) => isStr(b.label) && isScalar(b.value);
const validDepartures = (b) => Array.isArray(b.rows) && b.rows.length > 0
  && b.rows.every((r) => r && isStr(r.departure_id) && isStr(r.date) && isStr(r.time) && isStr(r.title));
const validTable = (b) => Array.isArray(b.columns) && b.columns.length > 0 && b.columns.every(isStr)
  && Array.isArray(b.rows) && b.rows.every((r) => Array.isArray(r) && r.length === b.columns.length
    && r.every((c) => c === null || isScalar(c)));
const validChoices = (b) => isStr(b.prompt) && Array.isArray(b.options) && b.options.length >= 2
  && b.options.every((o) => o && isStr(o.label) && isStr(o.value));

function StatRow({ stats }) {
  return (
    <div className={`grid gap-2 ${stats.length === 1 ? 'grid-cols-1' : stats.length === 2 ? 'grid-cols-2' : 'grid-cols-3'}`}>
      {stats.map((s, i) => (
        <div key={i} className="rounded-tuscan border border-stone-200 bg-white p-3" data-testid="assistant-stat">
          <div className="text-xs font-medium text-stone-500">{s.label}</div>
          <div className="mt-0.5 text-lg font-bold leading-tight text-stone-900 break-words">{String(s.value)}</div>
        </div>
      ))}
    </div>
  );
}

function DepartureList({ rows }) {
  return (
    <ul className="divide-y divide-stone-100 rounded-tuscan border border-stone-200 bg-white" data-testid="assistant-departures">
      {rows.map((r) => (
        <li key={r.departure_id} className="px-3 py-2 text-sm">
          <div className="flex items-baseline gap-2">
            <span className="whitespace-nowrap font-semibold text-stone-800">{shortDay(r.date)} · {r.time}</span>
            <span className="min-w-0 truncate text-stone-700">{r.title}</span>
          </div>
          <div className="mt-0.5 flex flex-wrap gap-x-2 text-xs text-stone-500">
            {isStr(r.language) && <span>{r.language}</span>}
            {typeof r.guests === 'number' && <span>{r.guests} PAX</span>}
            {isStr(r.guide)
              ? <span className="text-stone-700">{r.guide}</span>
              : <span className="font-medium text-amber-700">No guide</span>}
          </div>
        </li>
      ))}
    </ul>
  );
}

function Table({ columns, rows }) {
  return (
    <div className="overflow-x-auto rounded-tuscan border border-stone-200 bg-white" data-testid="assistant-table">
      <table className="min-w-full text-sm">
        <thead className="bg-stone-50 text-left text-xs text-stone-500">
          <tr>{columns.map((c, i) => <th key={i} className="whitespace-nowrap px-3 py-2 font-medium">{c}</th>)}</tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {rows.map((r, i) => (
            <tr key={i}>{r.map((c, j) => <td key={j} className="whitespace-nowrap px-3 py-1.5 text-stone-700">{c === null ? '' : String(c)}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Choices({ block, onChoose, disabled }) {
  return (
    <div data-testid="assistant-choices">
      <p className="mb-1.5 text-sm text-stone-700">{block.prompt}</p>
      <div className="flex flex-wrap gap-2">
        {block.options.map((o, i) => (
          <button
            key={i}
            type="button"
            disabled={disabled}
            // Sends the text the user sees: the model sometimes puts an id in `value` (staging showed
            // a bare "9" as the user's message), and the chat should read like the tap.
            onClick={() => onChoose && onChoose(o.label)}
            className="min-h-[44px] rounded-tuscan-lg border-2 border-terracotta-200 bg-white px-3 py-2 text-sm font-medium text-terracotta-700 hover:bg-terracotta-50 disabled:opacity-50 touch-manipulation"
          >
            {o.label}
          </button>
        ))}
      </div>
    </div>
  );
}

function LinkPill({ block, onNavigate }) {
  const to = buildAssistantLink(block);
  if (!to || !isStr(block.label)) return null;
  return (
    <Link
      to={to}
      onClick={() => onNavigate && onNavigate(to)}
      className="inline-flex min-h-[44px] items-center gap-1.5 rounded-full bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-700 touch-manipulation"
      data-testid="assistant-link"
    >
      {block.label} <FiArrowRight />
    </Link>
  );
}

/**
 * @param blocks      array from the API
 * @param onChoose    (text) => void - a tapped choice is sent as the next message
 * @param onNavigate  (url) => void - called when a link is followed (the phone sheet closes)
 */
export default function AssistantBlocks({ blocks, onChoose, onNavigate, disabled = false }) {
  if (!Array.isArray(blocks) || blocks.length === 0) return null;
  // Consecutive stat blocks share one row of up to 3 tiles.
  const groups = [];
  blocks.forEach((b) => {
    if (!b || typeof b !== 'object') return;
    if (b.type === 'stat') {
      if (!validStat(b)) return;
      const last = groups[groups.length - 1];
      if (last && last.type === 'stats' && last.items.length < 3) last.items.push(b);
      else groups.push({ type: 'stats', items: [b] });
      return;
    }
    if (b.type === 'departure_list' && validDepartures(b)) groups.push({ type: 'departures', block: b });
    else if (b.type === 'table' && validTable(b)) groups.push({ type: 'table', block: b });
    else if (b.type === 'choices' && validChoices(b)) groups.push({ type: 'choices', block: b });
    else if (b.type === 'link' && buildAssistantLink(b) && isStr(b.label)) groups.push({ type: 'link', block: b });
    // anything else (unknown type, bad shape, route not allowed): skipped
  });
  if (groups.length === 0) return null;
  return (
    <div className="mt-2 space-y-2">
      {groups.map((g, i) => {
        if (g.type === 'stats') return <StatRow key={i} stats={g.items} />;
        if (g.type === 'departures') return <DepartureList key={i} rows={g.block.rows} />;
        if (g.type === 'table') return <Table key={i} columns={g.block.columns} rows={g.block.rows} />;
        if (g.type === 'choices') return <Choices key={i} block={g.block} onChoose={onChoose} disabled={disabled} />;
        return <LinkPill key={i} block={g.block} onNavigate={onNavigate} />;
      })}
    </div>
  );
}
