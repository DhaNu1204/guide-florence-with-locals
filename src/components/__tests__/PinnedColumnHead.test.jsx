/**
 * Step 6.23: the pinned column titles take each column's width from the real (collapsed) header.
 * First staging build: the copy measured before the table's ref existed and never got widths.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { PinnedTable } from '../PinnedColumnHead';

const COLS = [{ label: 'Time', pad: 'px-4', w: 'w-20' }, { label: 'Tour', pad: 'px-6', w: 'w-96' }, { label: 'Notes', pad: 'px-6' }];
const WIDTHS = { TIME: 80, TOUR: 384, NOTES: 308 };

describe('PinnedTable (step 6.23)', () => {
  afterEach(() => vi.restoreAllMocks());

  it('copies the real header column widths and the table width into the pinned titles', () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function rect() {
      if (this.tagName === 'TH') return { width: WIDTHS[this.textContent.trim().toUpperCase()] || 0, height: 0, left: 0, top: 0 };
      if (this.tagName === 'TABLE') return { width: 772, height: 100, left: 0, top: 0 };
      return { width: 0, height: 0, left: 0, top: 0 };
    });
    render(
      <PinnedTable columns={COLS} top={80} tableClassName="min-w-full">
        <tbody><tr><td>09:30</td><td>Uffizi</td><td>-</td></tr></tbody>
      </PinnedTable>
    );
    const head = screen.getByTestId('pinned-column-head');
    expect(head.style.top).toBe('80px');
    expect(head.firstChild.style.width).toBe('772px');
    expect([...head.firstChild.children].map((c) => c.style.width)).toEqual(['80px', '384px', '308px']);
    // the real header stays in the table for sizing, hidden from view and from screen readers
    const realHead = document.querySelector('table thead');
    expect(realHead.getAttribute('aria-hidden')).toBe('true');
    expect(realHead.rows[0].style.visibility).toBe('collapse');
  });
});
