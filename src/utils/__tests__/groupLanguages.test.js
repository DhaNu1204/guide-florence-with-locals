/**
 * Step 6.12: a departure is one language (one guide speaks one language).
 */
import { describe, it, expect, vi } from 'vitest';
import { groupMemberLanguages, isMixedLanguageGroup, mergeLanguageWarning, confirmMergeLanguages } from '../groupLanguages';

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

// Step 6.20: a manual merge across languages asks first; Cancel = no merge.
describe('mixed-language merge confirm (step 6.20)', () => {
  const en = { id: 1, language: 'English' };
  const it1 = { id: 2, language: 'Italian' };
  const it2 = { id: 3, language: 'Italian' };

  it('names the languages in the question', () => {
    expect(mergeLanguageWarning([en, it1, it2]))
      .toBe('These bookings are in different languages (English, Italian). Merge anyway?');
  });

  it('one known language (plus none / Unknown / cancelled other language) asks nothing', () => {
    expect(mergeLanguageWarning([en, { id: 4, language: '' }, { id: 5, language: null }, { id: 6, language: 'Unknown' }])).toBeNull();
    expect(mergeLanguageWarning([en, { id: 7, language: 'Italian', cancelled: true }])).toBeNull();
    expect(mergeLanguageWarning([])).toBeNull();
  });

  it('Cancel means no merge; OK means merge; one language never asks', () => {
    const cancel = vi.fn(() => false);
    expect(confirmMergeLanguages([en, it1], cancel)).toBe(false);
    expect(cancel).toHaveBeenCalledWith('These bookings are in different languages (English, Italian). Merge anyway?');

    const ok = vi.fn(() => true);
    expect(confirmMergeLanguages([en, it1], ok)).toBe(true);

    const never = vi.fn(() => false);
    expect(confirmMergeLanguages([it1, it2], never)).toBe(true);
    expect(never).not.toHaveBeenCalled();
  });
});
