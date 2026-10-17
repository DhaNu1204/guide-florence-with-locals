import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { FiMoreHorizontal } from 'react-icons/fi';
import IconButton from '../base/IconButton';

// Step 9.2 (rule 6): the ⋯ menu of a Tours row. Rendered in a portal with fixed position, so the
// day card's rounded clip and the page's container never cut it off. Closes on Escape, an outside
// click, scrolling or resizing. Clicks never reach the row (no booking details, no drag).
const RowMenu = ({ items, label = 'More actions' }) => {
  const [pos, setPos] = useState(null);
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const visible = (items || []).filter(Boolean);

  useEffect(() => {
    if (!pos) return undefined;
    const close = () => setPos(null);
    const onDown = (e) => {
      if (menuRef.current?.contains(e.target) || btnRef.current?.contains(e.target)) return;
      close();
    };
    const onKey = (e) => { if (e.key === 'Escape') { close(); btnRef.current?.focus(); } };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [pos]);

  useEffect(() => {
    if (pos && menuRef.current) menuRef.current.querySelector('[role="menuitem"]')?.focus();
  }, [pos]);

  if (visible.length === 0) return <span aria-hidden="true" />;

  const toggle = (e) => {
    e.stopPropagation();
    if (pos) { setPos(null); return; }
    const r = btnRef.current.getBoundingClientRect();
    const below = window.innerHeight - r.bottom > 40 * visible.length + 16;
    setPos({ right: Math.max(8, window.innerWidth - r.right), top: below ? r.bottom + 4 : undefined, bottom: below ? undefined : window.innerHeight - r.top + 4 });
  };

  return (
    <span onClick={(e) => e.stopPropagation()} onDragStart={(e) => { e.preventDefault(); e.stopPropagation(); }} draggable={false}>
      <IconButton
        ref={btnRef}
        icon={FiMoreHorizontal}
        label={label}
        aria-haspopup="menu"
        aria-expanded={Boolean(pos)}
        onClick={toggle}
      />
      {pos && createPortal(
        <div
          ref={menuRef}
          role="menu"
          aria-label={label}
          className="fixed z-50 min-w-[200px] py-1 bg-surface border border-line rounded-lg shadow-lg"
          style={{ right: pos.right, top: pos.top, bottom: pos.bottom }}
          onClick={(e) => e.stopPropagation()}
        >
          {visible.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              data-testid={item.testId}
              disabled={item.disabled}
              onClick={() => { setPos(null); item.onClick(); }}
              className={`flex w-full items-center gap-2 h-9 px-3 text-left text-sm focus:outline-none focus:bg-section hover:bg-section disabled:opacity-50
                ${item.danger ? 'text-danger' : 'text-ink'}`}
            >
              {item.icon && <item.icon aria-hidden="true" className="w-4 h-4 flex-shrink-0 text-ink-2" />}
              {item.label}
            </button>
          ))}
        </div>,
        document.body
      )}
    </span>
  );
};

export default RowMenu;
