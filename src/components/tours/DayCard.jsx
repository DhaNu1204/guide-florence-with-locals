import React from 'react';
import { ColumnHeader, STACK_DATE_PX } from './RowParts';

// Step 9.2: one surface card per day. The day header (44 px) and the column titles (32 px) are
// pinned for the whole day, each section bar under them for its section - the step 6.23 stack.
// The card clips its rounded corners with overflow: clip (no scroll box, so sticky still works).

/** "10 departures · 58 guests · 4 cancelled" */
export function dayCountsText({ activeTours, pax, cancelledCount }) {
  const parts = [
    `${activeTours} ${activeTours === 1 ? 'departure' : 'departures'}`,
    `${pax} ${pax === 1 ? 'guest' : 'guests'}`,
  ];
  if (cancelledCount > 0) parts.push(`${cancelledCount} cancelled`);
  return parts.join(' · ');
}

const DayCard = ({ title, relative, stats, categories, children }) => (
  <section className="bg-surface border border-line rounded-xl overflow-clip" data-testid="tours-day-card">
    <div
      className="sticky top-0 z-20 flex items-center justify-between gap-4 px-4 bg-surface border-b border-line"
      style={{ height: STACK_DATE_PX }}
      data-testid="tours-date-row"
    >
      <div className="flex items-center gap-2 min-w-0">
        <h3 className="text-[17px] leading-tight font-semibold text-ink truncate">{title}</h3>
        {relative && <span className="text-[13px] font-medium text-accent">{relative}</span>}
      </div>
      <div className="flex items-center gap-3 text-[13px] text-ink-2 tabular-nums whitespace-nowrap" data-testid="tours-day-counts">
        <span>{dayCountsText(stats)}</span>
        {categories.length > 0 && (
          <>
            <span className="h-4 w-px bg-line" aria-hidden="true" />
            <span data-testid="tours-day-categories">{categories.map(([c, n]) => `${c} ${n}`).join(' · ')}</span>
          </>
        )}
      </div>
    </div>
    <ColumnHeader />
    {children}
  </section>
);

export default DayCard;
