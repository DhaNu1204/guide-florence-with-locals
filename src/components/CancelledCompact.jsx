// Step 6.23: a cancelled booking is one grey line (40 px desktop / 44 px phone) until opened.
// The chevron (or the line) opens the full row the Tours page always showed; the open state is
// per booking, held by the caller, default closed, never persisted. Counts never change here -
// cancelled bookings were already excluded from every count.
import React from 'react';
import { FiChevronDown, FiChevronUp } from 'react-icons/fi';

export const CANCELLED_BG = '#fbf3f3';
export const CANCELLED_TEXT = '#8c8c8c';

/** "First Last +N" from participant_names, else the customer name, else ''. Pure. */
export const firstGuestLabel = (tour) => {
  let names = [];
  try {
    const p = typeof tour?.participant_names === 'string' ? JSON.parse(tour.participant_names) : tour?.participant_names;
    names = Array.isArray(p) ? p : [];
  } catch { names = []; }
  if (names.length > 0) {
    const first = `${names[0].first || ''} ${names[0].last || ''}`.trim();
    return names.length > 1 ? `${first} +${names.length - 1}` : first;
  }
  return tour?.customer_name ? String(tour.customer_name) : '';
};

export const CancelledPill = () => (
  <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 whitespace-nowrap">
    Cancelled
  </span>
);

/** The real <button> that opens / closes a cancelled booking. Stops the click reaching the row. */
export const CancelledToggle = ({ expanded, onToggle, className = '' }) => (
  <button
    type="button"
    onClick={(e) => { e.stopPropagation(); onToggle(); }}
    aria-label={expanded ? 'Hide cancelled booking details' : 'Show cancelled booking details'}
    aria-expanded={expanded}
    className={`inline-flex items-center justify-center min-h-[32px] min-w-[32px] rounded-tuscan text-stone-500 hover:bg-stone-200/60 hover:text-stone-700 touch-manipulation ${className}`}
  >
    {expanded ? <FiChevronUp size={16} /> : <FiChevronDown size={16} />}
  </button>
);

const cell = 'h-10 py-0 whitespace-nowrap text-sm';

/**
 * Desktop Tours table: the compact line in the same 7 columns as a normal row
 * (time · channel · tour · language · people · guide · notes).
 */
export const CompactCancelledTourRow = ({ tour, time, language, pax, guideName, onToggle }) => (
  <tr
    data-testid={`cancelled-compact-${tour.id}`}
    className="cursor-pointer"
    style={{ backgroundColor: CANCELLED_BG, color: CANCELLED_TEXT, height: 40 }}
    onClick={onToggle}
  >
    <td className={`px-4 ${cell} font-medium`}>{time}</td>
    <td className={`px-4 ${cell}`}><div className="truncate max-w-[9rem]">{tour.booking_channel || 'Website'}</div></td>
    <td className={`px-6 ${cell}`}>
      <div className="truncate max-w-[24rem]">
        {tour.title}
        {firstGuestLabel(tour) && <span className="ml-2 text-stone-400">{firstGuestLabel(tour)}</span>}
      </div>
    </td>
    <td className={`px-4 ${cell}`}>{language || 'Unknown'}</td>
    <td className={`px-4 ${cell}`}>{pax} PAX</td>
    <td className={`px-4 ${cell}`}><div className="truncate max-w-[10rem]">{guideName || 'Unassigned'}</div></td>
    <td className={`px-6 ${cell}`}>
      <div className="flex items-center justify-end gap-2">
        <CancelledPill />
        <CancelledToggle expanded={false} onToggle={onToggle} />
      </div>
    </td>
  </tr>
);

/** Group members table (desktop): icon · customer · type · channel · PAX · confirmation · actions. */
export const CompactCancelledMemberRow = ({ tour, typeLabel, onToggle }) => (
  <tr
    data-testid={`cancelled-compact-${tour.id}`}
    className="cursor-pointer"
    style={{ backgroundColor: CANCELLED_BG, color: CANCELLED_TEXT, height: 40 }}
    onClick={onToggle}
  >
    <td className={`px-4 ${cell}`} />
    <td className={`px-4 ${cell}`}><div className="truncate max-w-[16rem]">{firstGuestLabel(tour) || 'N/A'}</div></td>
    <td className={`px-4 ${cell}`}>{typeLabel}</td>
    <td className={`px-4 ${cell}`}>{tour.booking_channel || 'Direct'}</td>
    <td className={`px-4 ${cell}`}>{tour.participants || 1}</td>
    <td className={`px-4 ${cell} text-xs font-mono`}>{tour.bokun_confirmation_code || '-'}</td>
    <td className={`px-4 ${cell}`}>
      <div className="flex items-center gap-1">
        <CancelledPill />
        <CancelledToggle expanded={false} onToggle={onToggle} />
      </div>
    </td>
  </tr>
);

/** Phone: one 44 px line - "09:30 · Uffizi & Accademia · 5 PAX · Caterina · [Cancelled] ˅". */
export const CompactCancelledCard = ({ tour, time, pax, guideName, onToggle, className = '' }) => (
  <div
    data-testid={`cancelled-compact-${tour.id}`}
    className={`flex items-center gap-2 h-11 pl-3 pr-1 rounded-tuscan-lg border border-stone-200 cursor-pointer touch-manipulation ${className}`}
    style={{ backgroundColor: CANCELLED_BG, color: CANCELLED_TEXT }}
    onClick={onToggle}
  >
    <span className="min-w-0 flex-1 truncate text-sm">
      {[time, tour.title, `${pax} PAX`, guideName].filter(Boolean).join(' · ')}
    </span>
    <CancelledPill />
    <CancelledToggle expanded={false} onToggle={onToggle} />
  </div>
);

/** A tiny hook-free helper for a {id: true} open-state object. */
export const toggleId = (state, id) => {
  const next = { ...state };
  if (next[id]) delete next[id]; else next[id] = true;
  return next;
};
