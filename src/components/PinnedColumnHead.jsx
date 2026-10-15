// Step 6.23: the pinned column titles of one time-section table on the Tours page.
//
// The table sits in an overflow-x-auto box (some group rows are wider than the page), and any
// overflow box between the window and a sticky element stops it sticking - so the real <thead>
// cannot be pinned. The titles are a light copy OUTSIDE that box: each column takes its width from
// the real header (kept in the table, collapsed to 0 px, so the columns size exactly as before),
// and the copy follows the box's sideways scroll. Re-measured whenever the table or a header cell
// resizes. The measuring lives in PinnedTable: its layout effect runs after BOTH the copy and the
// table have their refs (a sibling's effect can run before the table's ref is attached).
import React, { useLayoutEffect, useRef, useState } from 'react';

const sameWidths = (a, b) => a && b && a.length === b.length && a.every((w, i) => Math.abs(w - b[i]) < 0.5);

export const PinnedColumnHead = React.forwardRef(({ columns, widths, tableWidth, top }, ref) => (
  <div
    ref={ref}
    data-testid="pinned-column-head"
    className="sticky z-20 box-border h-8 overflow-hidden bg-stone-50 border-b border-stone-200 shadow-[0_3px_6px_-2px_rgba(0,0,0,0.12)]"
    style={{ top }}
  >
    <div className="flex h-full items-center" style={{ width: tableWidth || '100%' }}>
      {columns.map((c, i) => (
        <div
          key={c.label}
          className={`${c.pad} box-border flex-shrink-0 truncate text-left text-xs font-medium uppercase tracking-wider text-stone-500`}
          style={{ width: widths ? widths[i] : undefined }}
        >
          {c.label}
        </div>
      ))}
    </div>
  </div>
));
PinnedColumnHead.displayName = 'PinnedColumnHead';

/**
 * One time-section table with pinned column titles. The real header stays in the table so the
 * columns size exactly as before, but takes no height (visibility: collapse, plus zero padding and
 * line-height for browsers that treat collapse as hidden); the pinned copy above shows the titles.
 */
export const PinnedTable = ({ columns, top, tableClassName = '', children }) => {
  const headRef = useRef(null);
  const tableRef = useRef(null);
  const scrollRef = useRef(null);
  const [widths, setWidths] = useState(null);
  const [tableWidth, setTableWidth] = useState(null);

  useLayoutEffect(() => {
    const table = tableRef.current;
    const scroller = scrollRef.current;
    if (!table) return undefined;
    const measure = () => {
      const row = table.tHead && table.tHead.rows[0];
      if (!row) return;
      const next = [...row.cells].map((c) => c.getBoundingClientRect().width);
      setWidths((prev) => (sameWidths(prev, next) ? prev : next));
      const tw = table.getBoundingClientRect().width;
      setTableWidth((prev) => (prev !== null && Math.abs(prev - tw) < 0.5 ? prev : tw));
    };
    measure();
    let ro = null;
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(measure);
      ro.observe(table);
      const row = table.tHead && table.tHead.rows[0];
      if (row) [...row.cells].forEach((c) => ro.observe(c));
    }
    const onScroll = () => { if (headRef.current) headRef.current.scrollLeft = scroller.scrollLeft; };
    if (scroller) scroller.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      if (ro) ro.disconnect();
      if (scroller) scroller.removeEventListener('scroll', onScroll);
    };
  }, []);

  return (
    <>
      <PinnedColumnHead ref={headRef} columns={columns} widths={widths} tableWidth={tableWidth} top={top} />
      <div ref={scrollRef} className="overflow-x-auto">
        <table ref={tableRef} className={tableClassName}>
          <thead aria-hidden="true">
            <tr style={{ visibility: 'collapse' }}>
              {columns.map((c) => (
                <th key={c.label} scope="col" className={`${c.pad} ${c.w || ''} py-0 leading-[0] text-left text-xs font-medium uppercase tracking-wider`}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          {children}
        </table>
      </div>
    </>
  );
};

export default PinnedColumnHead;
