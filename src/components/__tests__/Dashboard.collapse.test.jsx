/**
 * Dashboard collapsed-by-default sections.
 * Sections preview a few items and expand/collapse via the "Show all (N) / Show less" toggle:
 * needs-guide alert previews 3, recent guide responses 3, Upcoming Tours 5, Needs Attention 5.
 */
import { render, screen, within, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// 8 unassigned guided tours in the next 7 days (dates today+2..today+6 avoid TZ edge cases)
const isoDate = (daysFromToday) => {
  const d = new Date();
  d.setDate(d.getDate() + daysFromToday);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const mockTours = Array.from({ length: 8 }, (_, i) => ({
  id: i + 1,
  title: `Uffizi Gallery Guided Tour ${i + 1}`,
  date: isoDate(2 + (i % 5)),
  time: `${String(9 + i).padStart(2, '0')}:00`,
  guide_id: '',
  guide_name: null,
  cancelled: 0,
  paid: 0,
}));
const mockResponses = Array.from({ length: 6 }, (_, i) => ({
  id: i + 1,
  status: i % 2 === 0 ? 'accepted' : 'declined',
  guide_name: `Guide ${i + 1}`,
  tour_title: `Some Tour ${i + 1}`,
  tour_date: isoDate(2),
  tour_time: '10:00:00',
}));

vi.mock('../../services/mysqlDB', () => {
  const resolved = (val) => vi.fn().mockResolvedValue(val);
  return {
    getTours: vi.fn().mockImplementation(async () => ({ data: mockTours })),
    getAllGuides: resolved([]),
    getRecentGuideResponses: vi.fn().mockImplementation(async () => ({ data: mockResponses })),
    createGuideRequest: resolved({ id: 1, token: 't', status: 'pending', link: 'x', message: 'm' }),
  };
});

vi.mock('../../hooks/useBokunAutoSync', () => ({
  useBokunSync: () => ({ lastSync: null, isSyncing: false, syncNow: vi.fn(), error: null }),
}));

// The modal's internals are covered elsewhere; here we only care that Ask still opens it
vi.mock('../AskGuideModal', () => ({
  default: ({ tour }) => <div data-testid="ask-guide-modal">{tour.title}</div>,
}));

import Dashboard from '../Dashboard';
import { ToastProvider } from '../Toast/ToastProvider';

const sectionByHeading = (text) => screen.getByText(text).closest('div.bg-white');

beforeEach(() => {
  global.fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ success: true, count: 0, data: [] }),
  });
});

const renderDashboard = async () => {
  render(
    <ToastProvider>
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    </ToastProvider>
  );
  await screen.findByText(/Tours needing a guide/);
};

describe('Dashboard collapsed sections', () => {
  it('previews 3 alert rows and expands/collapses on toggle', async () => {
    await renderDashboard();

    // Only alert rows have Ask buttons — 3 visible in preview
    expect(screen.getAllByTitle('Ask a guide via WhatsApp')).toHaveLength(3);
    // Heading still reports the full count
    expect(screen.getByText(/next 7 days \(8\)/)).toBeInTheDocument();

    // First "Show all" button in DOM order belongs to the alert section
    const [alertToggle] = screen.getAllByRole('button', { name: /show all 8/i });
    fireEvent.click(alertToggle);
    expect(screen.getAllByTitle('Ask a guide via WhatsApp')).toHaveLength(8);

    fireEvent.click(screen.getAllByRole('button', { name: /show less/i })[0]);
    expect(screen.getAllByTitle('Ask a guide via WhatsApp')).toHaveLength(3);
  });

  it('previews 3 recent guide responses with a Show all 6 toggle', async () => {
    await renderDashboard();

    const responses = sectionByHeading('Recent guide responses');
    expect(within(responses).getAllByText(/accepted|declined/)).toHaveLength(3);

    fireEvent.click(within(responses).getByRole('button', { name: /show all 6/i }));
    expect(within(responses).getAllByText(/accepted|declined/)).toHaveLength(6);
    expect(within(responses).getByRole('button', { name: /show less/i })).toBeInTheDocument();
  });

  it('previews 5 Upcoming Tours and 5 Needs Attention rows, each expandable', async () => {
    await renderDashboard();

    for (const heading of ['Upcoming Tours', 'Needs Attention']) {
      const section = sectionByHeading(heading);
      expect(within(section).getAllByRole('heading', { level: 3 })).toHaveLength(5);

      fireEvent.click(within(section).getByRole('button', { name: /show all 8/i }));
      expect(within(section).getAllByRole('heading', { level: 3 })).toHaveLength(8);

      fireEvent.click(within(section).getByRole('button', { name: /show less/i }));
      expect(within(section).getAllByRole('heading', { level: 3 })).toHaveLength(5);
    }
  });

  it('Ask button still opens the ask-guide modal on an expanded row', async () => {
    await renderDashboard();

    fireEvent.click(screen.getAllByRole('button', { name: /show all 8/i })[0]);
    const askButtons = screen.getAllByTitle('Ask a guide via WhatsApp');
    fireEvent.click(askButtons[askButtons.length - 1]); // a row only visible when expanded

    expect(screen.getByTestId('ask-guide-modal')).toBeInTheDocument();
  });
});
