import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const auth = { userRole: 'admin', userName: 'dhanu' };
vi.mock('../../contexts/AuthContext', () => ({ useAuth: () => auth }));
const getAssistantStatus = vi.fn();
vi.mock('../../services/assistantStatus', () => ({
  getAssistantStatus: (...a) => getAssistantStatus(...a),
}));

import AssistantLauncher from '../assistant/AssistantLauncher';
import { __resetAssistantStore } from '../assistant/assistantStore';

const renderIt = () => render(<MemoryRouter><AssistantLauncher /></MemoryRouter>);

describe('AssistantLauncher visibility (step 7.4)', () => {
  beforeEach(() => { __resetAssistantStore(); getAssistantStatus.mockReset(); });

  it('admin + enabled -> the button shows', async () => {
    auth.userRole = 'admin';
    getAssistantStatus.mockResolvedValue({ enabled: true, can_see_money: true });
    renderIt();
    expect(await screen.findByRole('button', { name: 'Assistant' })).toBeInTheDocument();
  });

  it('admin + switched off (production) -> nothing', async () => {
    auth.userRole = 'admin';
    getAssistantStatus.mockResolvedValue({ enabled: false, can_see_money: false });
    renderIt();
    await waitFor(() => expect(getAssistantStatus).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: 'Assistant' })).toBeNull();
  });

  it('viewer -> nothing, and the status is not even asked (no 403 toast)', async () => {
    auth.userRole = 'viewer';
    renderIt();
    await new Promise((r) => setTimeout(r, 20));
    expect(getAssistantStatus).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Assistant' })).toBeNull();
  });
});
