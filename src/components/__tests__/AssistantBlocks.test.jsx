import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AssistantBlocks from '../assistant/AssistantBlocks';

const renderBlocks = (blocks, props = {}) =>
  render(<MemoryRouter><AssistantBlocks blocks={blocks} {...props} /></MemoryRouter>);

describe('AssistantBlocks (step 7.4)', () => {
  it('renders consecutive stats as one row of tiles (max 3)', () => {
    renderBlocks([
      { type: 'stat', label: 'Net Revenue', value: '€4,842.05' },
      { type: 'stat', label: 'Total Costs', value: '€4,400.00' },
      { type: 'stat', label: 'Profit', value: '€442.05' },
      { type: 'stat', label: 'Guests', value: 86 },
    ]);
    expect(screen.getAllByTestId('assistant-stat')).toHaveLength(4);
    expect(screen.getByText('€4,842.05')).toBeInTheDocument();
    expect(screen.getByText('86')).toBeInTheDocument();
  });

  it('renders a departure list with "No guide" when there is none', () => {
    renderBlocks([{ type: 'departure_list', rows: [
      { departure_id: 'g12', date: '2026-09-30', time: '09:30', title: 'Uffizi Gallery Tour', language: 'English', guests: 8, guide: 'Caterina Cavalcaselle' },
      { departure_id: 't7', date: '2026-09-30', time: '10:55', title: 'Accademia Tour', guests: 2, guide: null },
    ] }]);
    expect(screen.getByText('Wed 30 Sep · 09:30')).toBeInTheDocument();
    expect(screen.getByText('Caterina Cavalcaselle')).toBeInTheDocument();
    expect(screen.getByText('No guide')).toHaveClass('text-amber-700');
    expect(screen.getByText('8 PAX')).toBeInTheDocument();
  });

  it('renders a table inside a horizontal scroller', () => {
    renderBlocks([{ type: 'table', columns: ['Guide', 'Languages'], rows: [['Anna Marchetti', 'Italian'], ['Ana K', null]] }]);
    const t = screen.getByTestId('assistant-table');
    expect(t).toHaveClass('overflow-x-auto');
    expect(screen.getByText('Anna Marchetti')).toBeInTheDocument();
  });

  it('choices send the chosen value', () => {
    const onChoose = vi.fn();
    renderBlocks([{ type: 'choices', prompt: 'Which Anna?', options: [
      { label: 'Anna Marchetti', value: 'Anna Marchetti' }, { label: 'Anna Sgobbi', value: 'Anna Sgobbi' },
    ] }], { onChoose });
    fireEvent.click(screen.getByRole('button', { name: 'Anna Sgobbi' }));
    expect(onChoose).toHaveBeenCalledWith('Anna Sgobbi');
  });

  it('a link becomes a pill to the allowed route with its query', () => {
    renderBlocks([{ type: 'link', label: 'Open in Daily P&L', route: '/daily-pnl', query: { date: '2026-09-29' } }]);
    expect(screen.getByTestId('assistant-link')).toHaveAttribute('href', '/daily-pnl?date=2026-09-29');
  });

  it('drops a link to a route that is not allowed', () => {
    const { container } = renderBlocks([{ type: 'link', label: 'Admin', route: '/admin', query: {} }]);
    expect(container.querySelector('a')).toBeNull();
  });

  it('ignores invalid and unknown blocks, keeps the valid ones', () => {
    renderBlocks([
      { type: 'html', html: '<b>bold</b>' },
      { type: 'stat', label: 'Only label' },
      { type: 'table', columns: ['A', 'B'], rows: [['one']] },
      { type: 'departure_list', rows: [{ departure_id: 'x', title: 'no date' }] },
      null,
      { type: 'stat', label: 'Kept', value: 1 },
    ]);
    expect(screen.getAllByTestId('assistant-stat')).toHaveLength(1);
    expect(screen.queryByText('bold')).toBeNull();
    expect(screen.queryByTestId('assistant-table')).toBeNull();
  });

  it('shows markup inside values as plain text, never as HTML', () => {
    const { container } = renderBlocks([{ type: 'stat', label: '<script>alert(1)</script>', value: '<img src=x onerror=alert(1)>' }]);
    expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
    expect(container.querySelector('img')).toBeNull();
  });
});
