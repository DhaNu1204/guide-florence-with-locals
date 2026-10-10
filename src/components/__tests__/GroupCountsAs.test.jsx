import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

let owner = true;
vi.mock('../../contexts/AuthContext', () => ({ useAuth: () => ({ canSeePnl: () => owner }) }));
const setBillingProduct = vi.fn();
vi.mock('../../services/mysqlDB', () => ({ tourGroupsAPI: { setBillingProduct: (...a) => setBillingProduct(...a) } }));

import GroupCountsAs from '../GroupCountsAs';

const COMBO = 'Uffizi & Accademia Walking Tour with Gelato & Art Historian';
const UFFIZI = 'Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit';
const group = {
  id: 1508491, is_manual_merge: true, billing_product_id: 962885,
  tours: [{ id: 7490, product_id: 1130528, title: UFFIZI }, { id: 7717, product_id: 962885, title: COMBO }],
};

describe('GroupCountsAs (step 6.17)', () => {
  beforeEach(() => { owner = true; setBillingProduct.mockReset(); });

  it('shows the stored product to the P&L owner', () => {
    render(<GroupCountsAs group={group} />);
    expect(screen.getByTestId('counts-as').value).toBe('962885');
    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual([`Uffizi — ${UFFIZI}`, `Combo — ${COMBO}`]);
  });

  it('is not shown to anyone else', () => {
    owner = false;
    render(<GroupCountsAs group={group} />);
    expect(screen.queryByTestId('counts-as')).toBeNull();
  });

  it('switching sends the product and refreshes', async () => {
    setBillingProduct.mockResolvedValue({ success: true });
    const onRefresh = vi.fn(); const onSuccess = vi.fn();
    render(<GroupCountsAs group={group} onRefresh={onRefresh} onSuccess={onSuccess} />);
    fireEvent.change(screen.getByTestId('counts-as'), { target: { value: '1130528' } });
    await waitFor(() => expect(onRefresh).toHaveBeenCalled());
    expect(setBillingProduct).toHaveBeenCalledWith(1508491, 1130528);
    expect(onSuccess).toHaveBeenCalledWith('Counts as Uffizi');
  });

  it('a 403 is reported, nothing refreshed', async () => {
    setBillingProduct.mockRejectedValue({ response: { data: { error: 'You do not have access to this page.' } } });
    const onRefresh = vi.fn(); const onError = vi.fn();
    render(<GroupCountsAs group={group} onRefresh={onRefresh} onError={onError} />);
    fireEvent.change(screen.getByTestId('counts-as'), { target: { value: '1130528' } });
    await waitFor(() => expect(onError).toHaveBeenCalledWith('You do not have access to this page.'));
    expect(onRefresh).not.toHaveBeenCalled();
  });
});
