/**
 * Step 6.9: the "old Viator" chip.
 *
 * It must appear on bookings from the account he is retiring and on nothing else - a chip on a
 * new-account booking would send him checking a tour by hand that needs no checking, and a
 * missing chip on an old one is worse.
 */
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';

import ViatorLegacyChip from '../ViatorLegacyChip';

describe('ViatorLegacyChip (step 6.9)', () => {
  it('marks a booking from the old Viator account', () => {
    render(<ViatorLegacyChip account="legacy" />);
    const chip = screen.getByTestId('viator-legacy-chip');
    expect(chip).toHaveTextContent('old Viator');
    expect(chip.getAttribute('title')).toMatch(/stops updating/i);
  });

  it('says nothing about a booking on the new account', () => {
    render(<ViatorLegacyChip account="current" />);
    expect(screen.queryByTestId('viator-legacy-chip')).toBeNull();
  });

  it('says nothing about every other channel', () => {
    render(<ViatorLegacyChip account={null} />);
    expect(screen.queryByTestId('viator-legacy-chip')).toBeNull();
  });

  it('is not fooled by a value that merely looks like one', () => {
    render(<ViatorLegacyChip account="LEGACY" />);
    expect(screen.queryByTestId('viator-legacy-chip')).toBeNull();
  });
});
