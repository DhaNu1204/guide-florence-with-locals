import { describe, it, expect } from 'vitest';
import {
  shouldShowAssistant, buildAssistantLink, firstNameOf, shortDay, assistantErrorMessage,
} from '../assistantUi';

describe('shouldShowAssistant (step 7.4 visibility rule)', () => {
  it('shows for an admin when the server says enabled', () => {
    expect(shouldShowAssistant('admin', { enabled: true, can_see_money: false })).toBe(true);
  });
  it('hides when the assistant is switched off (production)', () => {
    expect(shouldShowAssistant('admin', { enabled: false })).toBe(false);
  });
  it('hides for a viewer even if enabled', () => {
    expect(shouldShowAssistant('viewer', { enabled: true })).toBe(false);
  });
  it('hides while the status is unknown', () => {
    expect(shouldShowAssistant('admin', null)).toBe(false);
    expect(shouldShowAssistant('admin', { enabled: 'true' })).toBe(false);
  });
});

describe('buildAssistantLink (route allowlist)', () => {
  it('builds a Tours link with the keys Tours reads', () => {
    expect(buildAssistantLink({ route: '/tours', query: { start: '2026-09-29', end: '2026-10-04', unassigned: '1' } }))
      .toBe('/tours?start=2026-09-29&end=2026-10-04&unassigned=1');
  });
  it('builds a Daily P&L link for one day', () => {
    expect(buildAssistantLink({ route: '/daily-pnl', query: { date: '2026-09-29' } })).toBe('/daily-pnl?date=2026-09-29');
  });
  it('drops an unknown route', () => {
    expect(buildAssistantLink({ route: '/admin', query: {} })).toBeNull();
    expect(buildAssistantLink({ route: 'https://evil.example/', query: {} })).toBeNull();
    expect(buildAssistantLink({ route: 'constructor' })).toBeNull();
    expect(buildAssistantLink({ route: '/login' })).toBeNull();
  });
  it('leaves out keys the page does not read, and unsafe values', () => {
    expect(buildAssistantLink({ route: '/daily-pnl', query: { date: '2026-09-29', guide_id: '4' } })).toBe('/daily-pnl?date=2026-09-29');
    expect(buildAssistantLink({ route: '/tours', query: { language: '<script>' } })).toBe('/tours');
    expect(buildAssistantLink({ route: '/guides', query: { date: '2026-09-29' } })).toBe('/guides');
  });
  it('handles a missing or broken block', () => {
    expect(buildAssistantLink(null)).toBeNull();
    expect(buildAssistantLink({ route: 5 })).toBeNull();
  });
});

describe('small helpers', () => {
  it('firstNameOf', () => {
    expect(firstNameOf('dhanu')).toBe('Dhanu');
    expect(firstNameOf('anna.bianchi')).toBe('Anna');
    expect(firstNameOf('')).toBe('');
  });
  it('shortDay builds the day from its parts (no UTC shift)', () => {
    expect(shortDay('2026-09-29')).toBe('Tue 29 Sep');
    expect(shortDay('2026-11-01')).toBe('Sun 1 Nov');
    expect(shortDay('soon')).toBe('soon');
  });
  it('assistantErrorMessage gives one line per case', () => {
    expect(assistantErrorMessage({ kind: 'network' })).toMatch(/still in the box/);
    expect(assistantErrorMessage({ kind: 'http', status: 429, code: 'daily_cap_reached' })).toMatch(/midnight/);
    expect(assistantErrorMessage({ kind: 'http', status: 429, code: 'rate_limited' })).toMatch(/wait a moment/);
    expect(assistantErrorMessage({ kind: 'http', status: 503, code: 'assistant_disabled' })).toMatch(/switched off/);
    expect(assistantErrorMessage(null)).toBeNull();
  });
});
