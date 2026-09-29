import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../../services/assistantService', () => ({
  saveDepartureGuide: vi.fn(),
  reportAssignDone: vi.fn(),
  reportUndoDone: vi.fn(),
}));

import { saveDepartureGuide, reportAssignDone, reportUndoDone } from '../../services/assistantService';
import AssistantBlocks from '../assistant/AssistantBlocks';
import { validConfirmAssign, cardExpiresAt } from '../assistant/ConfirmAssignCard';

const card = (over = {}) => ({
  type: 'confirm_assign',
  departure: {
    departure_id: 'g42', type: 'group', id: 42, date: '2026-09-30', time: '10:00',
    title: 'Uffizi Gallery Small Group Tour', languages: ['English'], guests: 8, bookings: 3,
    current_guide_id: null, current_guide_name: null,
  },
  guide: { id: 7, name: 'Caterina Cavalcaselle', active: true, partner_agency: false },
  previous_guide_id: null,
  replace: false,
  checks: [{ level: 'ok', text: 'No other tour for Caterina Cavalcaselle at that time' }],
  clash: [],
  alternatives: [],
  whatsapp: { offer: false, default: false, disabled_reason: null, note: "Included in tonight's 21:30 message" },
  issued_at: new Date().toISOString(),
  expires_in: 600,
  ...over,
});

const renderCard = (block, props = {}) =>
  render(<MemoryRouter><AssistantBlocks blocks={[block]} {...props} /></MemoryRouter>);

describe('ConfirmAssignCard (step 7.5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows header, guide, tour line, guests line and check lines; nothing saved on render', () => {
    renderCard(card());
    expect(screen.getByText('Change · Assign guide')).toBeInTheDocument();
    expect(screen.getByText('Caterina Cavalcaselle')).toBeInTheDocument();
    expect(screen.getByText('Wed 30 Sep · 10:00')).toBeInTheDocument();
    expect(screen.getByText('8 guests in 3 bookings · now: no guide')).toBeInTheDocument();
    expect(screen.getByText('No other tour for Caterina Cavalcaselle at that time')).toBeInTheDocument();
    expect(screen.getByText("Included in tonight's 21:30 message")).toBeInTheDocument();
    expect(screen.queryByTestId('assistant-confirm-whatsapp')).toBeNull();
    expect(saveDepartureGuide).not.toHaveBeenCalled();
  });

  it('Cancel changes nothing', () => {
    renderCard(card());
    fireEvent.click(screen.getByText('Cancel'));
    expect(screen.getByText('Cancelled - nothing changed.')).toBeInTheDocument();
    expect(screen.queryByText('Confirm assignment')).toBeNull();
    expect(saveDepartureGuide).not.toHaveBeenCalled();
  });

  it('Confirm saves through the Tours endpoint with the expected previous guide, then reports; Undo reverses', async () => {
    saveDepartureGuide.mockResolvedValue({ ok: true });
    reportAssignDone.mockResolvedValue({ ok: true, data: { success: true, action_id: 55, at: '14:02', by: 'dhanu', whatsapp: null } });
    reportUndoDone.mockResolvedValue({ ok: true, data: { success: true, action_id: 56, at: '14:03', by: 'dhanu' } });
    renderCard(card());
    const seen = [];
    const onUpdated = (e) => seen.push(e.detail);
    window.addEventListener('florence:bookings-updated', onUpdated);
    fireEvent.click(screen.getByText('Confirm assignment'));
    await screen.findByTestId('assistant-confirm-done');
    expect(saveDepartureGuide).toHaveBeenCalledWith(expect.objectContaining({ departure_id: 'g42', type: 'group', id: 42 }), 7, null, { force: false });
    // the open Tours page / Dashboard reload at once (same event a Bokun sync sends)
    expect(seen).toEqual([{ trigger: 'assistant', departure_id: 'g42' }]);
    expect(reportAssignDone).toHaveBeenCalledWith({ departure_id: 'g42', from_guide_id: null, to_guide_id: 7, send_whatsapp: false });
    expect(screen.getByText(/Caterina Cavalcaselle assigned · 14:02 by dhanu/)).toBeInTheDocument();
    expect(screen.getByText('Open in Tours').closest('a')).toHaveAttribute('href', '/tours?date=2026-09-30');

    fireEvent.click(screen.getByText('Undo'));
    await screen.findByTestId('assistant-confirm-undone');
    expect(saveDepartureGuide).toHaveBeenLastCalledWith(expect.objectContaining({ id: 42 }), null, 7, { force: true });
    expect(reportUndoDone).toHaveBeenCalledWith(55);
    expect(seen).toHaveLength(2);
    window.removeEventListener('florence:bookings-updated', onUpdated);
  });

  it('a stale card (409 departure_changed) saves nothing and offers to reload', async () => {
    saveDepartureGuide.mockResolvedValue({ ok: false, status: 409, code: 'departure_changed' });
    const onChoose = vi.fn();
    renderCard(card(), { onChoose });
    fireEvent.click(screen.getByText('Confirm assignment'));
    await screen.findByText(/This departure changed since the card was shown/);
    expect(reportAssignDone).not.toHaveBeenCalled();
    fireEvent.click(screen.getByText('Reload card'));
    expect(onChoose).toHaveBeenCalledWith(expect.stringContaining('propose Caterina Cavalcaselle'));
  });

  it('a viewer (403) gets a clear line and no audit row', async () => {
    saveDepartureGuide.mockResolvedValue({ ok: false, status: 403, code: null });
    renderCard(card());
    fireEvent.click(screen.getByText('Confirm assignment'));
    await screen.findByText('Only an admin can change assignments.');
    expect(reportAssignDone).not.toHaveBeenCalled();
  });

  it('a clash shows the other tour and the free guides; tapping one asks for that guide; confirm forces', async () => {
    const onChoose = vi.fn();
    saveDepartureGuide.mockResolvedValue({ ok: true });
    reportAssignDone.mockResolvedValue({ ok: true, data: { action_id: 1, at: '10:00', by: 'dhanu' } });
    renderCard(card({
      departure: { ...card().departure, type: 'single', id: 900, departure_id: 't900' },
      checks: [{ level: 'warn', text: 'Caterina Cavalcaselle already has 09:30-11:30 Accademia Tour' }],
      clash: [{ departure_id: 'g9', time: '09:30', until: '11:30', title: 'Accademia Tour' }],
      alternatives: [{ guide_id: 3, name: 'Marco Rossi' }, { guide_id: 4, name: 'Anna Bianchi' }],
    }), { onChoose });
    expect(screen.getByText('Caterina Cavalcaselle already has 09:30-11:30 Accademia Tour')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Marco Rossi'));
    expect(onChoose).toHaveBeenCalledWith(expect.stringContaining('Assign Marco Rossi'));
    fireEvent.click(screen.getByText('Confirm assignment'));
    await screen.findByTestId('assistant-confirm-done');
    expect(saveDepartureGuide).toHaveBeenCalledWith(expect.objectContaining({ id: 900 }), 7, null, { force: true });
  });

  it('singular wording: 1 guest in 1 booking', () => {
    renderCard(card({ departure: { ...card().departure, guests: 1, bookings: 1 } }));
    expect(screen.getByText('1 guest in 1 booking · now: no guide')).toBeInTheDocument();
  });

  it('replacing: "now:" shows the current guide and the warning line', () => {
    renderCard(card({
      departure: { ...card().departure, current_guide_id: 5, current_guide_name: 'Luca Verdi' },
      previous_guide_id: 5, replace: true,
      checks: [{ level: 'warn', text: "Replace Luca Verdi with Caterina Cavalcaselle - Luca Verdi won't be notified automatically" }],
    }));
    expect(screen.getByText('8 guests in 3 bookings · now: Luca Verdi')).toBeInTheDocument();
    expect(screen.getByText(/won't be notified automatically/)).toBeInTheDocument();
  });

  it('WhatsApp box: ticked by default and sent with the report; disabled with a reason without a phone', async () => {
    saveDepartureGuide.mockResolvedValue({ ok: true });
    reportAssignDone.mockResolvedValue({ ok: true, data: { action_id: 2, at: '09:00', by: 'dhanu', whatsapp: { sent: false, result: 'DRY RUN: would send guide_tour_assigned' } } });
    const { unmount } = renderCard(card({ whatsapp: { offer: true, default: true, disabled_reason: null, note: null } }));
    const box = screen.getByTestId('assistant-confirm-whatsapp');
    expect(box).toBeChecked();
    expect(screen.getByText('Send WhatsApp to Caterina now')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Confirm assignment'));
    await screen.findByTestId('assistant-confirm-wa-result');
    expect(reportAssignDone).toHaveBeenCalledWith(expect.objectContaining({ send_whatsapp: true }));
    expect(screen.getByText(/DRY RUN/)).toBeInTheDocument();
    unmount();

    renderCard(card({ whatsapp: { offer: true, default: false, disabled_reason: 'No WhatsApp number on file', note: null } }));
    expect(screen.getByTestId('assistant-confirm-whatsapp')).toBeDisabled();
    expect(screen.getByTestId('assistant-confirm-whatsapp')).not.toBeChecked();
    expect(screen.getByText('No WhatsApp number on file')).toBeInTheDocument();
  });

  it('expires 10 minutes after it was issued: buttons gone, "Ask again"', () => {
    vi.useFakeTimers();
    const onChoose = vi.fn();
    renderCard(card(), { onChoose });
    expect(screen.getByText('Confirm assignment')).toBeInTheDocument();
    act(() => { vi.advanceTimersByTime(601 * 1000); });
    expect(screen.queryByText('Confirm assignment')).toBeNull();
    fireEvent.click(screen.getByText('Ask again'));
    expect(onChoose).toHaveBeenCalledWith(expect.stringContaining('Assign Caterina Cavalcaselle'));
  });

  it('an old card from history is already expired', () => {
    renderCard(card({ issued_at: '2026-09-01T08:00:00Z' }));
    expect(screen.getByTestId('assistant-confirm-expired')).toBeInTheDocument();
    expect(screen.queryByText('Confirm assignment')).toBeNull();
  });

  it('shape checks and expiry maths', () => {
    expect(validConfirmAssign(card())).toBe(true);
    expect(validConfirmAssign({ ...card(), guide: { id: 'x', name: 'A' } })).toBe(false);
    expect(validConfirmAssign({ ...card(), departure: { ...card().departure, type: 'other' } })).toBe(false);
    expect(cardExpiresAt({ issued_at: '2026-09-30T08:00:00Z', expires_in: 600 })).toBe(Date.parse('2026-09-30T08:10:00Z'));
    expect(cardExpiresAt({ issued_at: 'nonsense' }, 1000)).toBe(601000);
  });

  it('a malformed card is not rendered', () => {
    renderCard({ type: 'confirm_assign', guide: { id: 1, name: 'X' } });
    expect(screen.queryByTestId('assistant-confirm-assign')).toBeNull();
  });
});

