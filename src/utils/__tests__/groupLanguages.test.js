/**
 * Step 6.12: a departure is one language (one guide speaks one language).
 */
import { describe, it, expect } from 'vitest';
import { groupMemberLanguages, isMixedLanguageGroup } from '../groupLanguages';

describe('groupLanguages (step 6.12)', () => {
  it('three English bookings are one language', () => {
    const g = { tours: [{ language: 'English' }, { language: 'English' }, { language: 'English' }] };
    expect(groupMemberLanguages(g)).toEqual(['English']);
    expect(isMixedLanguageGroup(g)).toBe(false);
  });

  it('English + Italian is mixed', () => {
    const g = { tours: [{ language: 'Italian' }, { language: 'English' }] };
    expect(groupMemberLanguages(g)).toEqual(['English', 'Italian']);
    expect(isMixedLanguageGroup(g)).toBe(true);
  });

  it('a booking with no language is not a second language', () => {
    expect(isMixedLanguageGroup({ tours: [{ language: 'English' }, { language: '' }, { language: null }, { language: 'Unknown' }] })).toBe(false);
  });

  it('a cancelled booking does not make a group mixed', () => {
    expect(isMixedLanguageGroup({ tours: [{ language: 'English' }, { language: 'Spanish', cancelled: 1 }] })).toBe(false);
  });

  it('copes with a group without members', () => {
    expect(isMixedLanguageGroup({})).toBe(false);
    expect(isMixedLanguageGroup(null)).toBe(false);
  });
});
