/**
 * Step 6.15: a mixed departure exports as its effective type plus its real composition.
 */
import { describe, it, expect, vi } from 'vitest';

vi.mock('../../contexts/PageTitleContext', () => ({
  usePageTitle: () => ({ setPageTitle: vi.fn() }),
}));
vi.mock('../../services/mysqlDB', () => ({
  getAllGuides: vi.fn().mockResolvedValue([]),
  getGuideTourReport: vi.fn().mockResolvedValue({ data: {} }),
}));

import { categoryExportLabel } from '../GuideReports';

describe('categoryExportLabel', () => {
  it('labels a mixed Combo departure with its bookings', () => {
    expect(categoryExportLabel({ category: 'Combo', composition_label: '1 Combo + 1 Uffizi booking' }))
      .toBe('Combo (mixed: 1 Combo + 1 Uffizi booking)');
  });

  it('keeps a non-mixed departure as its plain type', () => {
    expect(categoryExportLabel({ category: 'Uffizi', composition_label: '' })).toBe('Uffizi');
    expect(categoryExportLabel({ category: 'Combo' })).toBe('Combo');
    expect(categoryExportLabel({})).toBe('Other');
  });

  it('shows an undecided mix as Mixed with its bookings', () => {
    expect(categoryExportLabel({ category: 'Mixed', composition_label: '1 Uffizi + 1 Accademia booking' }))
      .toBe('Mixed (1 Uffizi + 1 Accademia booking)');
  });
});
