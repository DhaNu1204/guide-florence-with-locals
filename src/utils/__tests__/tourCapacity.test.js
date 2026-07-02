/**
 * tourCapacity util tests
 * Florence With Locals - tourCategory classifier
 */

import { describe, it, expect } from 'vitest';
import { tourCategory } from '../tourCapacity';

describe('tourCategory', () => {
  it('classifies Uffizi tours', () => {
    expect(tourCategory('Uffizi Gallery Skip-the-Line Tour')).toBe('Uffizi');
  });

  it('classifies Accademia tours', () => {
    expect(tourCategory('Accademia Gallery Guided Tour')).toBe('Accademia');
  });

  it("classifies 'David' as Accademia", () => {
    expect(tourCategory('David & Michelangelo Masterpieces')).toBe('Accademia');
  });

  it('classifies Pitti/Boboli tours', () => {
    expect(tourCategory('Pitti Palace & Boboli Gardens')).toBe('Pitti');
  });

  it('classifies a 2+ museum combo as Combo', () => {
    expect(tourCategory('Uffizi & Accademia Walking Tour')).toBe('Combo');
  });

  it('returns Other when nothing matches', () => {
    expect(tourCategory('Ponte Vecchio Food Walk')).toBe('Other');
  });

  it('handles empty/undefined titles', () => {
    expect(tourCategory('')).toBe('Other');
    expect(tourCategory(undefined)).toBe('Other');
  });
});
