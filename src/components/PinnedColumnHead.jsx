// Step 6.23: the pinned column titles of one time-section table on the Tours page.
//
// The table sits in an overflow-x-auto box (some group rows are wider than the page), and any
// overflow box between the window and a sticky element stops it sticking - so the real <thead>
// cannot be pinned. This is a light copy OUTSIDE that box: it takes each column's width from the
// real header (kept in the table, collapsed to 0 px, so the columns size exactly as before) and
// follows the box's sideways scroll. Re-measured whenever the table or a header cell resizes.
import React, { useLayoutEffect, useRef, useState } from 'react';

const PinnedColumnHead = ({ columns, tableRef, scrollRef, top, className = '' }) => {
  const headRef = useRef(null);
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
      setWidths((prev) => (prev && prev.length === next.length && prev.every((w, i) => Math.abs(w - next[i]) < 0.5) ? prev : next));
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
    const onScroll = () => { if (headRef.current && scroller) headRef.current.scrollLeft = scroller.scrollLeft; };
    if (scroller) scroller.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      if (ro) ro.disconnect();
      if (scroller) scroller.removeEventListener('scroll', onScroll);
    };
  }, [tableRef, scrollRef]);

  return (
    <div
      ref={headRef}
      data-testid="pinned-column-head"
      className={`sticky z-20 overflow-hidden bg-stone-50 border-b border-stone-200 shadow-[0_3px_6px_-2px_rgba(0,0,0,0.12)] ${className}`}
      style={{ top }}
    >
      <div className="flex h-8 items-center" style={{ width: tableWidth || '100%' }}>
        {columns.map((c, i) => (
          <div
            key={c.label}
            className={`${c.pad} flex-shrink-0 truncate text-left text-xs font-medium uppercase tracking-wider text-stone-500`}
            style={{ width: widths ? widths[i] : undefined }}
          >
            {c.label}
          </div>
        ))}
      </div>
    </div>
  );
};

/**
 * One time-section table with pinned column titles. The real header stays in the table so the
 * columns size exactly as before, but takes no height (visibility: collapse, plus zero padding and
 * line-height for browsers that treat collapse as hidden); the pinned copy above shows the titles.
 */
export const PinnedTable = ({ columns, top, tableClassName = '', children }) => {
  const tableRef = useRef(null);
  const scrollRef = useRef(null);
  return (
    <>
      <PinnedColumnHead columns={columns} tableRef={tableRef} scrollRef={scrollRef} top={top} />
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
