import { describe, it, expect } from 'vitest';
import { pickableGuides, isGuideActive } from '../guidePicker';

const guides = [
  { id: 1, name: 'Giulia', active: 1 },
  { id: 2, name: 'Old Guide', active: 0 },
  { id: 3, name: 'Agency Uno', active: 1, is_partner_agency: 1 },
  { id: 4, name: 'Legacy row' }, // no flag yet = active
];

describe('pickableGuides (step 7.2b)', () => {
  it('leaves inactive guides out of a picker for new work', () => {
    expect(pickableGuides(guides).map((g) => g.id)).toEqual([1, 3, 4]);
  });
  it('keeps the guide already assigned, labelled inactive', () => {
    const list = pickableGuides(guides, 2);
    expect(list.map((g) => g.id)).toEqual([1, 2, 3, 4]);
    expect(list.find((g) => g.id === 2).name).toBe('Old Guide (inactive)');
  });
  it('accepts the id as a string (select values) and ignores empty ids', () => {
    expect(pickableGuides(guides, '2').some((g) => g.id === 2)).toBe(true);
    expect(pickableGuides(guides, '', null, undefined).some((g) => g.id === 2)).toBe(false);
  });
  it('partner agencies stay pickable for assignment', () => {
    expect(pickableGuides(guides).some((g) => g.id === 3)).toBe(true);
  });
  it('isGuideActive treats "0"/0 as inactive, anything else as active', () => {
    expect(isGuideActive({ active: '0' })).toBe(false);
    expect(isGuideActive({ active: 0 })).toBe(false);
    expect(isGuideActive({ active: '1' })).toBe(true);
    expect(isGuideActive({})).toBe(true);
  });
});
