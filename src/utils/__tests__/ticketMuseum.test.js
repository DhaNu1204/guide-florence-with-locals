/**
 * Step 6.11: the tab rule must match the server's radioMuseumForTitle() (step 6.3), which the
 * printed day sheet uses. These are the real ticket titles on production (2026-09-23) plus the
 * first-museum-wins cases from 6.3.
 */
import { describe, it, expect } from 'vitest';
import { ticketMuseum, TICKET_TABS } from '../ticketMuseum';

describe('ticketMuseum (step 6.11)', () => {
  it.each([
    ['Florence: Uffizi Gallery Reserved Ticket & Digital Audio Guide', 'Uffizi'],
    ['Florence: Uffizi Gallery Priority Ticket & Digital Audio Guide', 'Uffizi'],
    ['Uffizi Gallery Priority Entrance Tickets', 'Uffizi'],
    ['Accademia Gallery Entry Ticket with Exclusive Audio Guide App', 'Accademia'],
    ['Florence: Accademia Gallery Skip-the-Line Entry Ticket', 'Accademia'],
    ['Skip the Line: Accademia Gallery Priority Entry Ticket with eBook', 'Accademia'],
    ['Borghese Gallery Entry Ticket and Audio Guide', 'Borghese'],
    ['Palazzo Vecchio Skip the line Entry ticket', 'Palazzo Vecchio'],
    ['Uffizi & Accademia Combo Ticket', 'Uffizi'],        // first museum named wins
    ['David and Uffizi tickets', 'Accademia'],            // 'david' is Accademia, and comes first
    ['Florence Walking Tour', null],
    ['', null],
    [null, null],
  ])('%s -> %s', (title, museum) => {
    expect(ticketMuseum(title)).toBe(museum);
  });

  it('tabs: All museums first (the default), then Uffizi and Accademia', () => {
    expect(TICKET_TABS.map(t => t.key)).toEqual(['', 'Uffizi', 'Accademia']);
  });
});
