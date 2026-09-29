import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const getGuides = vi.fn();
const setGuideFlags = vi.fn();
vi.mock('../../services/mysqlDB', () => ({
  getGuides: (...a) => getGuides(...a),
  setGuideFlags: (...a) => setGuideFlags(...a),
  addGuide: vi.fn(), updateGuide: vi.fn(), deleteGuide: vi.fn(),
}));
vi.mock('../../contexts/PageTitleContext', () => ({ usePageTitle: () => ({ setPageTitle: () => {} }) }));
const auth = { admin: true };
vi.mock('../../contexts/AuthContext', () => ({ useAuth: () => ({ isAdmin: () => auth.admin }) }));
const toast = { success: vi.fn(), error: vi.fn() };
vi.mock('../../components/Toast/ToastProvider', () => ({ useToast: () => toast }));

import Guides from '../Guides';

const page = (list) => ({ data: list, pagination: { current_page: 1, per_page: 20, total: list.length, total_pages: 1 } });
const giulia = { id: 1, name: 'Giulia Rossi', email: '', phone: '', languages: ['English'], active: 1, is_partner_agency: 0 };
const old = { id: 2, name: 'Old Guide', email: '', phone: '', languages: [], active: 0, is_partner_agency: 0 };

const renderIt = () => render(<MemoryRouter><Guides /></MemoryRouter>);

describe('Guides page flags (step 7.2b)', () => {
  beforeEach(() => { getGuides.mockReset(); setGuideFlags.mockReset(); auth.admin = true; });

  it('asks for active guides by default, and inactive / all on the filter', { timeout: 15000 }, async () => {
    getGuides.mockResolvedValue(page([giulia]));
    renderIt();
    await waitFor(() => expect(getGuides).toHaveBeenCalledWith(1, 20, 'active'), { timeout: 5000 });
    getGuides.mockResolvedValue(page([old]));
    fireEvent.click(screen.getByRole('button', { name: 'Inactive' }));
    await waitFor(() => expect(getGuides).toHaveBeenLastCalledWith(1, 20, 'inactive'), { timeout: 5000 });
    fireEvent.click(screen.getByRole('button', { name: 'All' }));
    await waitFor(() => expect(getGuides).toHaveBeenLastCalledWith(1, 20, null), { timeout: 5000 });
  });

  it('an admin switches a guide off: only the flag is sent, the list reloads', async () => {
    getGuides.mockResolvedValue(page([giulia]));
    setGuideFlags.mockResolvedValue({ ...giulia, active: 0 });
    renderIt();
    const sw = (await screen.findAllByRole('switch', { name: 'Active: Giulia Rossi' }))[0];
    expect(sw).toBeChecked();
    fireEvent.click(sw);
    await waitFor(() => expect(setGuideFlags).toHaveBeenCalledWith(1, { active: 0 }));
    await waitFor(() => expect(getGuides.mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it('partner agency switch sends is_partner_agency', async () => {
    getGuides.mockResolvedValue(page([giulia]));
    setGuideFlags.mockResolvedValue({ ...giulia, is_partner_agency: 1 });
    renderIt();
    fireEvent.click((await screen.findAllByRole('switch', { name: 'Partner agency: Giulia Rossi' }))[0]);
    await waitFor(() => expect(setGuideFlags).toHaveBeenCalledWith(1, { is_partner_agency: 1 }));
  });

  it('an inactive guide is greyed; a viewer sees badges, no switches', async () => {
    auth.admin = false;
    getGuides.mockResolvedValue(page([old]));
    renderIt();
    expect(await screen.findByTestId('guide-row-2')).toHaveClass('opacity-60');
    expect(screen.queryByRole('switch')).toBeNull();
    expect(screen.getAllByText('Inactive').length).toBeGreaterThanOrEqual(2); // filter button + badge
  });
});
