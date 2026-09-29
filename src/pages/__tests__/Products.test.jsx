import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const getProducts = vi.fn();
const updateProduct = vi.fn();
vi.mock('../../services/mysqlDB', () => ({
  getProducts: (...a) => getProducts(...a),
  updateProduct: (...a) => updateProduct(...a),
}));
vi.mock('../../contexts/PageTitleContext', () => ({ usePageTitle: () => ({ setPageTitle: () => {} }) }));
const toast = { success: vi.fn(), error: vi.fn() };
vi.mock('../../components/Toast/ToastProvider', () => ({ useToast: () => toast }));

import Products from '../Products';

const rows = [
  { bokun_product_id: 809836, title: 'David and Accademia Gallery VIP Tour', product_type: 'tour', duration_minutes: 60, upcoming_bookings: 12, last_date: '2026-11-02' },
  { bokun_product_id: 845665, title: 'Uffizi Reserved Ticket', product_type: 'ticket', duration_minutes: null, upcoming_bookings: 30, last_date: '2026-12-01' },
  { bokun_product_id: 900001, title: 'Uffizi Gallery Tour', product_type: 'tour', duration_minutes: null, upcoming_bookings: 4, last_date: null },
];

describe('Products page (step 7.2b)', () => {
  beforeEach(() => { getProducts.mockResolvedValue(rows); updateProduct.mockReset(); });

  it('lists tours and tickets apart and warns about missing tour durations', async () => {
    render(<Products />);
    expect(await screen.findByText('Tours (2)')).toBeInTheDocument();
    expect(screen.getByText('Tickets (1)')).toBeInTheDocument();
    expect(screen.getByText(/1 tour product has no duration/)).toBeInTheDocument();
  });

  it('moving a product to Tickets asks first, then saves the type', async () => {
    updateProduct.mockResolvedValue({ ...rows[2], product_type: 'ticket' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<Products />);
    const row = await screen.findByTestId('product-900001');
    fireEvent.click(row.querySelector('button[aria-pressed="false"]'));
    await waitFor(() => expect(updateProduct).toHaveBeenCalledWith(900001, { product_type: 'ticket' }));
    expect(await screen.findByText('Tickets (2)')).toBeInTheDocument();
  });

  it('a refused confirmation changes nothing', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<Products />);
    const row = await screen.findByTestId('product-900001');
    fireEvent.click(row.querySelector('button[aria-pressed="false"]'));
    expect(updateProduct).not.toHaveBeenCalled();
  });

  it('duration: invalid values are refused, a valid one is saved as a number', async () => {
    updateProduct.mockResolvedValue({ ...rows[2], duration_minutes: 90 });
    render(<Products />);
    const input = await screen.findByLabelText('Duration in minutes of Uffizi Gallery Tour');
    fireEvent.change(input, { target: { value: '3' } });
    expect(screen.getByText('5 to 1440 minutes')).toBeInTheDocument();
    fireEvent.change(input, { target: { value: '90' } });
    fireEvent.click(screen.getByRole('button', { name: /Save/ }));
    await waitFor(() => expect(updateProduct).toHaveBeenCalledWith(900001, { duration_minutes: 90 }));
  });
});
